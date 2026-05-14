<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\SettingsRepository;
use PDO;

/**
 * Editable budgets + pricing. Backed by system_settings and model_pricing
 * (migration 0005). Save POSTs split by form section so a half-completed
 * form never overwrites the other half.
 */
final class BudgetController extends BaseController
{
	/**
	 * Mapping HTML-Field-Name → system_settings-Key.
	 *
	 * Hintergrund: PHP wandelt Punkte in $_POST-Keys automatisch zu Unterstrichen
	 * um ("budget.global.daily_tokens" → unerreichbar via $_POST). Wir nutzen
	 * daher Form-seitig den Underscore-Namen und mappen hier auf den echten
	 * Setting-Key. Marc-Bug 2026-05-14: bisher leerer audit_log + Werte nicht
	 * gespeichert, weil isset($_POST['budget.global.daily_tokens']) immer false.
	 *
	 * @var array<string,string>
	 */
	private const BUDGET_FIELD_MAP = [
		'budget_global_daily_tokens' => 'budget.global.daily_tokens',
		'budget_tenant_daily_tokens' => 'budget.tenant.daily_tokens',
		'budget_user_daily_tokens'   => 'budget.user.daily_tokens',
		'budget_enforcement_mode'    => 'budget.enforcement_mode',
	];

	/** @var list<string> */
	private const BUDGET_KEYS = [
		'budget.global.daily_tokens',
		'budget.tenant.daily_tokens',
		'budget.user.daily_tokens',
		'budget.enforcement_mode',
	];

	public function show(array $params): void
	{
		$settings = $this->kernel->get(SettingsRepository::class);
		$pricing  = $this->kernel->get(PricingRepository::class);

		$budgets = [];
		foreach (self::BUDGET_KEYS as $k) {
			$budgets[$k] = $settings->getString($k, '');
		}

		$prompts = $this->kernel->get(PDO::class)
			->query('SELECT id, key_name, version, model, max_tokens, active
				FROM prompt_versions ORDER BY key_name, version DESC')
			->fetchAll(PDO::FETCH_ASSOC);

		$this->render('budget_settings', [
			'budgets'    => $budgets,
			'prices'     => $pricing->all(),
			'prompts'    => $prompts,
			'csrfToken'  => $this->csrfToken(),
		]);
	}

	public function saveBudgets(array $params): void
	{
		$this->verifyCsrf();
		$settings = $this->kernel->get(SettingsRepository::class);

		$applied = [];
		foreach (self::BUDGET_FIELD_MAP as $field => $settingKey) {
			if (!isset($_POST[$field])) continue;
			$raw = trim((string)$_POST[$field]);
			if ($settingKey === 'budget.enforcement_mode') {
				$raw = in_array($raw, ['enforce', 'log_only'], true) ? $raw : 'enforce';
			} else {
				$raw = (string)max(0, (int)$raw);
			}
			$settings->set($settingKey, $raw);
			$applied[$settingKey] = $raw;
		}

		$this->kernel->get(PDO::class)
			->prepare('INSERT INTO audit_log (event, entity, meta_json) VALUES ("admin.budget.update", "settings", :m)')
			->execute([':m' => json_encode($applied, JSON_UNESCAPED_UNICODE)]);

		$this->flash('success', count($applied) . ' Budget-Einstellungen aktualisiert');
		$this->redirect('/admin/settings/budgets');
	}

	public function savePricing(array $params): void
	{
		$this->verifyCsrf();
		$pricing = $this->kernel->get(PricingRepository::class);

		$rows = $_POST['pricing'] ?? [];
		if (!is_array($rows)) { $rows = []; }
		$count = 0;
		$applied = [];
		foreach ($rows as $row) {
			if (!is_array($row) || empty($row['model'])) continue;
			$model = (string)$row['model'];
			$entry = [
				'input'          => (float)str_replace(',', '.', (string)($row['input']        ?? '0')),
				'output'         => (float)str_replace(',', '.', (string)($row['output']       ?? '0')),
				'cache_read'     => $row['cache_read']     === '' || $row['cache_read']     === null ? null : (float)str_replace(',', '.', (string)$row['cache_read']),
				'cache_creation' => $row['cache_creation'] === '' || $row['cache_creation'] === null ? null : (float)str_replace(',', '.', (string)$row['cache_creation']),
			];
			$pricing->upsert($model, $entry['input'], $entry['output'], $entry['cache_read'], $entry['cache_creation']);
			$applied[$model] = $entry;
			$count++;
		}

		$this->kernel->get(PDO::class)
			->prepare('INSERT INTO audit_log (event, entity, meta_json) VALUES ("admin.pricing.update", "model_pricing", :m)')
			->execute([':m' => json_encode($applied, JSON_UNESCAPED_UNICODE)]);

		$this->flash('success', "{$count} Modell-Preise aktualisiert");
		$this->redirect('/admin/settings/budgets');
	}

	public function savePromptTokens(array $params): void
	{
		$this->verifyCsrf();
		$pdo = $this->kernel->get(PDO::class);
		$rows = $_POST['prompt_max_tokens'] ?? [];
		if (!is_array($rows)) { $rows = []; }
		$count = 0;
		$applied = [];
		$stmt = $pdo->prepare('UPDATE prompt_versions SET max_tokens = :mt WHERE id = :id');
		foreach ($rows as $id => $mt) {
			$mt = max(0, (int)$mt);
			if ($mt === 0) continue;
			$stmt->execute([':mt' => $mt, ':id' => (string)$id]);
			$applied[(string)$id] = $mt;
			$count++;
		}

		$pdo->prepare('INSERT INTO audit_log (event, entity, meta_json) VALUES ("admin.prompt_tokens.update", "prompt_versions", :m)')
			->execute([':m' => json_encode($applied, JSON_UNESCAPED_UNICODE)]);

		$this->flash('success', "{$count} Prompt-Tokenlimits aktualisiert");
		$this->redirect('/admin/settings/budgets');
	}
}
