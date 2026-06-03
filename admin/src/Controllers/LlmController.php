<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\GoldenSetRunner;
use MailPilot\Repositories\LlmCallLogRepository;
use MailPilot\Repositories\LlmGoldenRepository;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Security\SecretBox;
use PDO;
use Throwable;

/**
 * Phase 9q-F (Marc 2026-05-23) — Admin-UI für LLM-Provider-Verwaltung.
 *
 * Kapselt alle Operationen zur Multi-Provider-Schicht in einem Controller
 * (Marc-Wunsch: ein Admin-Surface für LLM, nicht 3 separate). Methoden:
 *
 *   index           — Liste aller Provider + ihrer Modelle
 *   edit            — Provider-Edit-Form
 *   save            — POST: Provider-Patch (name, base_url, enabled, priority)
 *   saveApiKey      — POST: API-Key via SecretBox verschluesseln + speichern
 *   toggleEnabled   — POST: Provider an/aus
 *   testConnection  — POST: Live-Ping mit aktueller Config (1-Mail-Score-Probe)
 *   saveModel       — POST: Per-Model-Updates (enabled, priority, cost)
 *   showRouting     — GET: Privacy-Mode + Fallback-Chain-Editor
 *   saveRouting     — POST: privacy_mode + per-role fallback_chain JSON
 */
final class LlmController extends BaseController
{
	public function index(array $params): void
	{
		$providers = $this->kernel->get(LlmProviderRepository::class)->listAll(includeDisabled: true);
		$models    = $this->kernel->get(LlmModelRepository::class);

		$enriched = [];
		foreach ($providers as $p) {
			/** @var array<string,mixed> $p */
			$enc = $p['api_key_encrypted'] ?? null;
			$p['has_api_key'] = is_string($enc) && $enc !== '';
			unset($p['api_key_encrypted']); // niemals in Views
			$p['models'] = $models->listByProvider((string)$p['id'], includeDisabled: true);
			$enriched[] = $p;
		}

		$this->render('llm/index', [
			'providers' => $enriched,
			'csrfToken' => $this->csrfToken(),
		]);
	}

	public function edit(array $params): void
	{
		$provider = $this->loadProviderOr404($params['id'] ?? '');
		$models   = $this->kernel->get(LlmModelRepository::class)
			->listByProvider((string)$provider['id'], includeDisabled: true);

		$provider['has_api_key'] = is_string($provider['api_key_encrypted'] ?? null)
			&& $provider['api_key_encrypted'] !== '';
		unset($provider['api_key_encrypted']);

		$catalog = $this->kernel->get(\MailPilot\Repositories\LlmModelCatalogRepository::class)
			->listByProvider((string)$params['id'], availableOnly: true);

		$this->render('llm/edit', [
			'provider'  => $provider,
			'models'    => $models,
			'catalog'   => $catalog,
			'csrfToken' => $this->csrfToken(),
		]);
	}

	public function save(array $params): void
	{
		$this->verifyCsrf();
		$id = (string)($params['id'] ?? '');
		$provider = $this->loadProviderOr404($id);

		$name    = trim((string)($_POST['name']    ?? $provider['name']));
		$baseUrl = trim((string)($_POST['base_url'] ?? $provider['base_url']));
		$envFb   = trim((string)($_POST['api_key_env_fallback'] ?? (string)($provider['api_key_env_fallback'] ?? '')));
		$enabled = isset($_POST['enabled']) ? 1 : 0;
		$isLocal = isset($_POST['is_local']) ? 1 : 0;
		$priority = max(0, min(1000, (int)($_POST['priority'] ?? $provider['priority'])));

		if ($name === '' || $baseUrl === '') {
			$this->flash('error', 'Name und Base-URL duerfen nicht leer sein.');
			$this->redirect('/admin/llm/' . urlencode($id));
		}

		$this->kernel->get(PDO::class)->prepare(
			'UPDATE llm_providers
			 SET name = :n, base_url = :b, api_key_env_fallback = :e,
			     enabled = :en, is_local = :il, priority = :p
			 WHERE id = :id'
		)->execute([
			':n'  => $name,
			':b'  => $baseUrl,
			':e'  => $envFb !== '' ? $envFb : null,
			':en' => $enabled,
			':il' => $isLocal,
			':p'  => $priority,
			':id' => $id,
		]);

		$this->flash('success', 'Provider gespeichert.');
		$this->redirect('/admin/llm/' . urlencode($id));
	}

	public function saveApiKey(array $params): void
	{
		$this->verifyCsrf();
		$id = (string)($params['id'] ?? '');
		$this->loadProviderOr404($id);

		$plaintext = (string)($_POST['api_key'] ?? '');
		if (trim($plaintext) === '') {
			$this->flash('error', 'API-Key darf nicht leer sein.');
			$this->redirect('/admin/llm/' . urlencode($id));
		}

		try {
			$encrypted = $this->kernel->get(SecretBox::class)->encrypt(trim($plaintext));
		} catch (Throwable $e) {
			$this->flash('error', 'Verschluesselung fehlgeschlagen: ' . $e->getMessage()
				. ' — pruefe ob LLM_MASTER_KEY in der Container-Env gesetzt ist.');
			$this->redirect('/admin/llm/' . urlencode($id));
		}

		$this->kernel->get(LlmProviderRepository::class)->updateEncryptedKey($id, $encrypted);
		$this->flash('success', 'API-Key verschluesselt gespeichert.');
		$this->redirect('/admin/llm/' . urlencode($id));
	}

	public function toggleEnabled(array $params): void
	{
		$this->verifyCsrf();
		$id = (string)($params['id'] ?? '');
		$row = $this->loadProviderOr404($id);
		$new = (int)$row['enabled'] === 1 ? 0 : 1;
		$this->kernel->get(PDO::class)->prepare(
			'UPDATE llm_providers SET enabled = :e WHERE id = :id'
		)->execute([':e' => $new, ':id' => $id]);
		$this->flash('success', $new === 1 ? 'Provider aktiviert.' : 'Provider deaktiviert.');
		$this->redirect('/admin/llm');
	}

	public function testConnection(array $params): void
	{
		$this->verifyCsrf();
		$id = (string)($params['id'] ?? '');
		$row = $this->loadProviderOr404($id);
		$kind = (string)$row['kind'];

		$provider = $this->resolveProviderInstance($kind);
		if ($provider === null) {
			$this->flash('error', "Unbekannter Provider-Kind „{$kind}\".");
			$this->redirect('/admin/llm/' . urlencode($id));
		}

		$req = new NormalizedRequest(
			systemPrompt: 'Antworte nur mit dem JSON-Objekt {"ok":true}.',
			messages:     [['role' => 'user', 'content' => 'ping']],
			maxTokens:    50,
			temperature:  0.0,
			modelHint:    $this->firstModelIdForProvider($id) ?? 'unknown',
			responseFormat: 'json_object',
		);

		try {
			$start = microtime(true);
			$resp  = $provider->complete($req);
			$ms    = (int)((microtime(true) - $start) * 1000);
			$this->flash('success', sprintf(
				'Verbindung OK (%dms, model=%s, %d/%d Tokens).',
				$ms, $resp->modelId,
				$resp->usage['inputTokens'] ?? 0,
				$resp->usage['outputTokens'] ?? 0,
			));
		} catch (Throwable $e) {
			$this->flash('error', 'Test fehlgeschlagen: ' . $e->getMessage());
		}
		$this->redirect('/admin/llm/' . urlencode($id));
	}

	public function refreshModels(array $params): void
	{
		$this->verifyCsrf();
		$id = (string)($params['id'] ?? '');
		$providerRepo = $this->kernel->get(\MailPilot\Repositories\LlmProviderRepository::class);
		$row = $providerRepo->findById($id);
		if ($row === null) {
			$this->flash('error', 'Provider nicht gefunden.');
			$this->redirect('/admin/llm');
			return;
		}
		$svc = $this->kernel->get(\MailPilot\Llm\ModelCatalogService::class);
		$res = $svc->refreshProvider($id, (string)$row['kind']);
		if ($res['error'] !== null) {
			$this->flash('error', sprintf('Discovery fehlgeschlagen: %s', $res['error']));
		} else {
			$this->flash('success', sprintf('%d Modelle entdeckt/aktualisiert.', $res['discovered']));
		}
		$this->redirect('/admin/llm/' . urlencode($id));
	}

	public function saveModel(array $params): void
	{
		$this->verifyCsrf();
		$modelRowId = (string)($params['mid'] ?? '');
		$providerId = (string)($_POST['provider_id'] ?? '');
		$pdo = $this->kernel->get(PDO::class);

		$newModelId = trim((string)($_POST['model_id'] ?? ''));
		$effort     = (string)($_POST['effort'] ?? '');
		$effort     = $effort === '' ? null : $effort;

		if ($effort !== null && $newModelId !== '') {
			$cat = $this->kernel->get(\MailPilot\Repositories\LlmModelCatalogRepository::class)
				->find($providerId, $newModelId);
			$levels = $cat !== null && $cat['effort_levels'] !== null
				? (array)json_decode((string)$cat['effort_levels'], true)
				: \MailPilot\Llm\ModelCatalogService::EFFORT_LEVELS_ALL;
			if (!in_array($effort, $levels, true)) {
				$this->flash('error', sprintf('Effort „%s" wird von %s nicht unterstützt.', $effort, $newModelId));
				$this->redirect('/admin/llm/' . urlencode($providerId));
				return;
			}
		}

		$sets = 'enabled = :en, priority = :p, cost_per_mtok_in = :ci, cost_per_mtok_out = :co, effort = :ef';
		$args = [
			':en' => isset($_POST['enabled']) ? 1 : 0,
			':p'  => max(0, min(1000, (int)($_POST['priority'] ?? 100))),
			':ci' => ($_POST['cost_in']  ?? '') !== '' ? (float)$_POST['cost_in']  : null,
			':co' => ($_POST['cost_out'] ?? '') !== '' ? (float)$_POST['cost_out'] : null,
			':ef' => $effort,
			':id' => $modelRowId,
		];
		if ($newModelId !== '') {
			$sets .= ', model_id = :mid';
			$args[':mid'] = $newModelId;
		}
		$pdo->prepare("UPDATE llm_models SET {$sets} WHERE id = :id")->execute($args);
		$this->flash('success', 'Modell aktualisiert.');
		$this->redirect('/admin/llm/' . urlencode($providerId));
	}

	public function showRouting(array $params): void
	{
		$settings = $this->kernel->get(SettingsRepository::class);
		$models   = $this->kernel->get(LlmModelRepository::class);

		$roles = \MailPilot\Llm\LlmRouter::ROLES;
		$chains = [];
		foreach ($roles as $role) {
			$json = $settings->getString("llm.{$role}.fallback_chain", '');
			$ids  = [];
			if ($json !== '') {
				$decoded = json_decode($json, true);
				if (is_array($decoded)) {
					$ids = array_values(array_filter($decoded, 'is_string'));
				}
			}
			$chains[$role] = [
				'configured' => $ids,
				'available'  => $models->listByRole($role),
			];
		}

		// Phase „Per-Role Model Selection" (Marc 2026-06): einmaliger Hinweis,
		// falls beim Upgrade routing_mode auf „router" gesetzt wurde. Flag wird
		// nach dem ersten Anzeigen verbraucht (auf '0' zurückgesetzt).
		$routingUpgradeNotice = $settings->getString('llm.routing_mode_upgrade_notice', '') === '1';
		if ($routingUpgradeNotice) {
			$settings->set('llm.routing_mode_upgrade_notice', '0');
		}

		$this->render('llm/routing', [
			'privacyMode' => $settings->getString('llm.privacy_mode',   'cloud_allowed'),
			'routingMode' => $settings->getString('llm.routing_mode',   'direct'),
			'chains'      => $chains,
			'roles'       => $roles,
			'scoringBatchSize' => $settings->getInt('scoring.batch_size', 20),
			'routingUpgradeNotice' => $routingUpgradeNotice,
			'csrfToken'   => $this->csrfToken(),
		]);
	}

	/**
	 * Normalisiert die Chain-Eingabe (Array aus Select-Slots ODER komma-getrennter
	 * String, backward-compat) zu einer geordneten, deduplizierten Provider-ID-Liste.
	 *
	 * @param  mixed $raw
	 * @return list<string>
	 */
	public static function parseChainInput(mixed $raw): array
	{
		$candidates = is_array($raw) ? $raw : preg_split('/\s*,\s*/', (string)$raw);
		$ids = [];
		foreach ($candidates as $c) {
			$c = trim((string)$c);
			if ($c !== '' && !in_array($c, $ids, true)) {
				$ids[] = $c;   // dedup, Reihenfolge erhalten
			}
		}
		return $ids;
	}

	public function saveRouting(array $params): void
	{
		$this->verifyCsrf();
		$settings = $this->kernel->get(SettingsRepository::class);

		$privacy = (string)($_POST['privacy_mode'] ?? 'cloud_allowed');
		if (!in_array($privacy, ['cloud_allowed', 'local_preferred', 'local_only'], true)) {
			$privacy = 'cloud_allowed';
		}
		$settings->set('llm.privacy_mode', $privacy);

		$routing = (string)($_POST['routing_mode'] ?? 'direct');
		if (!in_array($routing, ['direct', 'router'], true)) {
			$routing = 'direct';
		}
		$settings->set('llm.routing_mode', $routing);

		$roles = \MailPilot\Llm\LlmRouter::ROLES;
		foreach ($roles as $role) {
			$ids = self::parseChainInput($_POST["chain_{$role}"] ?? []);
			$settings->set("llm.{$role}.fallback_chain", json_encode($ids, JSON_UNESCAPED_UNICODE));
		}

		$batchSize = max(1, min(100, (int)($_POST['scoring_batch_size'] ?? 20)));
		$settings->set('scoring.batch_size', (string)$batchSize);

		$this->flash('success', 'Routing-Einstellungen gespeichert.');
		$this->redirect('/admin/llm/routing');
	}

	public function showGolden(array $params): void
	{
		$goldenRepo = $this->kernel->get(LlmGoldenRepository::class);
		$providers  = $this->kernel->get(LlmProviderRepository::class)->listAll(includeDisabled: false);
		$modelsRepo = $this->kernel->get(LlmModelRepository::class);

		// Provider → liste verfügbare Modelle pro Rolle für den Trigger-Button.
		$runnableTargets = [];
		foreach ($providers as $p) {
			foreach (['score', 'inference'] as $role) {
				$model = $modelsRepo->findForProviderAndRole((string)$p['id'], $role);
				if ($model !== null) {
					$runnableTargets[] = [
						'provider_id'   => (string)$p['id'],
						'provider_name' => (string)$p['name'],
						'model_id_str'  => (string)$model['model_id'],
						'role'          => $role,
					];
				}
			}
		}

		$this->render('llm/golden', [
			'set'              => $goldenRepo->listSet(includeDisabled: true),
			'recentRuns'       => $goldenRepo->listRecentRuns(30),
			'runnableTargets'  => $runnableTargets,
			'csrfToken'        => $this->csrfToken(),
		]);
	}

	public function runGolden(array $params): void
	{
		$this->verifyCsrf();
		$providerId = (string)($_POST['provider_id'] ?? '');
		$role       = (string)($_POST['role'] ?? 'score');
		if (!in_array($role, \MailPilot\Llm\LlmRouter::ROLES, true)) {
			$role = 'score';
		}

		try {
			$runner = $this->kernel->get(GoldenSetRunner::class);
			$runId = $runner->runForModel($providerId, $role);
			$this->flash('success', "Run abgeschlossen — siehe Details unten (run_id={$runId}).");
		} catch (Throwable $e) {
			$this->flash('error', 'Run fehlgeschlagen: ' . $e->getMessage());
		}
		$this->redirect('/admin/llm/golden');
	}

	public function deleteGolden(array $params): void
	{
		$this->verifyCsrf();
		$runId = (string)($params['rid'] ?? '');
		if ($runId === '') {
			$this->flash('error', 'Run-ID fehlt.');
			$this->redirect('/admin/llm/golden');
		}
		$repo = $this->kernel->get(LlmGoldenRepository::class);
		$ok = $repo->deleteRun($runId);
		$this->flash($ok ? 'success' : 'error', $ok ? 'Run gelöscht.' : 'Run nicht gefunden.');
		$this->redirect('/admin/llm/golden');
	}

	public function purgeGoldenOld(array $params): void
	{
		$this->verifyCsrf();
		$days = max(1, min(365, (int)($_POST['days'] ?? 30)));
		$repo = $this->kernel->get(LlmGoldenRepository::class);
		$n = $repo->deleteRunsOlderThan($days);
		$suffix = $n === 1 ? '' : 's';
		$this->flash('success', "{$n} Run{$suffix} älter als {$days}d gelöscht.");
		$this->redirect('/admin/llm/golden');
	}

	public function showUsage(array $params): void
	{
		$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
		$log  = $this->kernel->get(LlmCallLogRepository::class);
		$providers = $this->kernel->get(LlmProviderRepository::class)->listAll(includeDisabled: true);

		// Provider-ID → Display-Name für Tabellen-Joins ohne SQL.
		$nameById = [];
		foreach ($providers as $p) {
			$nameById[(string)$p['id']] = (string)$p['name'];
		}

		$perProvider = $log->aggregateByProvider($days);
		foreach ($perProvider as &$row) {
			$row['provider_name'] = $nameById[$row['provider_id']] ?? '(unbekannt)';
		}
		unset($row);

		$perModel = $log->aggregateByProviderModel($days);
		foreach ($perModel as &$row) {
			$row['provider_name'] = $nameById[(string)$row['provider_id']] ?? '(unbekannt)';
		}
		unset($row);

		// Phase 9q B5-Fix (Marc 2026-05-23): USD-Aggregat aus llm_call_log
		// in EUR konvertieren — konsistent zu /admin/usage und /admin/settings/budgets.
		// FX-Rate per Setting; Default 0.92 (Stand Q2/2026).
		$settings = $this->kernel->get(\MailPilot\Repositories\SettingsRepository::class);
		$usdToEur = (float)$settings->getString('pricing.usd_to_eur_rate', '0.92');
		if ($usdToEur <= 0.0) {
			$usdToEur = 0.92;
		}
		$totalUsd = array_sum(array_column($perProvider, 'total_usd'));

		$this->render('llm/usage', [
			'days'        => $days,
			'perProvider' => $perProvider,
			'perModel'    => $perModel,
			'totalCalls'  => array_sum(array_column($perProvider, 'calls')),
			'totalErrors' => array_sum(array_column($perProvider, 'errors')),
			'totalEur'    => $totalUsd * $usdToEur,
			'usdToEur'    => $usdToEur,
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function loadProviderOr404(string $id): array
	{
		if ($id === '') {
			http_response_code(404);
			echo '<h1>404</h1>';
			exit;
		}
		$row = $this->kernel->get(LlmProviderRepository::class)->findById($id);
		if ($row === null) {
			http_response_code(404);
			echo '<h1>404 — Provider nicht gefunden</h1>';
			exit;
		}
		return $row;
	}

	private function resolveProviderInstance(string $kind): ?LlmProvider
	{
		$classByKind = [
			'anthropic'         => \MailPilot\Llm\Providers\AnthropicProvider::class,
			'openai'            => \MailPilot\Llm\Providers\OpenAiProvider::class,
			'gemini'            => \MailPilot\Llm\Providers\GeminiProvider::class,
			'mistral'           => \MailPilot\Llm\Providers\MistralProvider::class,
			'openai_compatible' => \MailPilot\Llm\Providers\OpenAiCompatibleProvider::class,
		];
		$class = $classByKind[$kind] ?? null;
		return $class !== null ? $this->kernel->get($class) : null;
	}

	private function firstModelIdForProvider(string $providerId): ?string
	{
		$models = $this->kernel->get(LlmModelRepository::class)
			->listByProvider($providerId, includeDisabled: true);
		return $models[0]['model_id'] ?? null;
	}
}
