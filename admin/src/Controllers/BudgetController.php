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

		foreach (self::BUDGET_KEYS as $k) {
			if (!isset($_POST[$k])) continue;
			$raw = trim((string)$_POST[$k]);
			if ($k === 'budget.enforcement_mode') {
				$raw = in_array($raw, ['enforce', 'log_only'], true) ? $raw : 'enforce';
			} else {
				$raw = (string)max(0, (int)$raw);
			}
			$settings->set($k, $raw);
		}

		$this->kernel->get(PDO::class)
			->prepare('INSERT INTO audit_log (event, entity, meta_json) VALUES ("admin.budget.update", "settings", :m)')
			->execute([':m' => json_encode(array_intersect_key($_POST, array_flip(self::BUDGET_KEYS)))]);

		$this->flash('success', 'Budgets aktualisiert');
		$this->redirect('/admin/settings/budgets');
	}

	public function savePricing(array $params): void
	{
		$this->verifyCsrf();
		$pricing = $this->kernel->get(PricingRepository::class);

		$rows = $_POST['pricing'] ?? [];
		if (!is_array($rows)) { $rows = []; }
		$count = 0;
		foreach ($rows as $row) {
			if (!is_array($row) || empty($row['model'])) continue;
			$pricing->upsert(
				(string)$row['model'],
				(float)str_replace(',', '.', (string)($row['input']        ?? '0')),
				(float)str_replace(',', '.', (string)($row['output']       ?? '0')),
				$row['cache_read']     === '' || $row['cache_read']     === null ? null : (float)str_replace(',', '.', (string)$row['cache_read']),
				$row['cache_creation'] === '' || $row['cache_creation'] === null ? null : (float)str_replace(',', '.', (string)$row['cache_creation']),
			);
			$count++;
		}

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
		$stmt = $pdo->prepare('UPDATE prompt_versions SET max_tokens = :mt WHERE id = :id');
		foreach ($rows as $id => $mt) {
			$mt = max(0, (int)$mt);
			if ($mt === 0) continue;
			$stmt->execute([':mt' => $mt, ':id' => (string)$id]);
			$count++;
		}
		$this->flash('success', "{$count} Prompt-Tokenlimits aktualisiert");
		$this->redirect('/admin/settings/budgets');
	}
}
