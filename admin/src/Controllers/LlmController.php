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
 * Phase 9q-F (Marc 2026-05-23) — Admin-UI fuer LLM-Provider-Verwaltung.
 *
 * Kapselt alle Operationen zur Multi-Provider-Schicht in einem Controller
 * (Marc-Wunsch: ein Admin-Surface fuer LLM, nicht 3 separate). Methoden:
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

		$this->render('llm/edit', [
			'provider'  => $provider,
			'models'    => $models,
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

	public function saveModel(array $params): void
	{
		$this->verifyCsrf();
		$modelId = (string)($params['mid'] ?? '');
		$providerId = (string)($_POST['provider_id'] ?? '');

		$pdo = $this->kernel->get(PDO::class);
		$pdo->prepare(
			'UPDATE llm_models
			 SET enabled = :en, priority = :p,
			     cost_per_mtok_in  = :ci, cost_per_mtok_out = :co
			 WHERE id = :id'
		)->execute([
			':en' => isset($_POST['enabled']) ? 1 : 0,
			':p'  => max(0, min(1000, (int)($_POST['priority'] ?? 100))),
			':ci' => ($_POST['cost_in']  ?? '') !== '' ? (float)$_POST['cost_in']  : null,
			':co' => ($_POST['cost_out'] ?? '') !== '' ? (float)$_POST['cost_out'] : null,
			':id' => $modelId,
		]);
		$this->flash('success', 'Modell aktualisiert.');
		$this->redirect('/admin/llm/' . urlencode($providerId));
	}

	public function showRouting(array $params): void
	{
		$settings = $this->kernel->get(SettingsRepository::class);
		$models   = $this->kernel->get(LlmModelRepository::class);

		$roles = ['score', 'summary', 'draft', 'inference'];
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

		$this->render('llm/routing', [
			'privacyMode' => $settings->getString('llm.privacy_mode',   'cloud_allowed'),
			'routingMode' => $settings->getString('llm.routing_mode',   'direct'),
			'chains'      => $chains,
			'roles'       => $roles,
			'csrfToken'   => $this->csrfToken(),
		]);
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

		$roles = ['score', 'summary', 'draft', 'inference'];
		foreach ($roles as $role) {
			$raw = $_POST["chain_{$role}"] ?? '';
			$ids = [];
			if (is_string($raw) && $raw !== '') {
				foreach (preg_split('/\s*,\s*/', $raw) as $candidate) {
					$candidate = trim((string)$candidate);
					if ($candidate !== '') {
						$ids[] = $candidate;
					}
				}
			}
			$settings->set("llm.{$role}.fallback_chain", json_encode(array_values($ids), JSON_UNESCAPED_UNICODE));
		}

		$this->flash('success', 'Routing-Einstellungen gespeichert.');
		$this->redirect('/admin/llm/routing');
	}

	public function showGolden(array $params): void
	{
		$goldenRepo = $this->kernel->get(LlmGoldenRepository::class);
		$providers  = $this->kernel->get(LlmProviderRepository::class)->listAll(includeDisabled: false);
		$modelsRepo = $this->kernel->get(LlmModelRepository::class);

		// Provider → liste verfuegbare Modelle pro Rolle fuer den Trigger-Button.
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
		if (!in_array($role, ['score', 'summary', 'draft', 'inference'], true)) {
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

	public function showUsage(array $params): void
	{
		$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
		$log  = $this->kernel->get(LlmCallLogRepository::class);
		$providers = $this->kernel->get(LlmProviderRepository::class)->listAll(includeDisabled: true);

		// Provider-ID → Display-Name fuer Tabellen-Joins ohne SQL.
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

		$this->render('llm/usage', [
			'days'        => $days,
			'perProvider' => $perProvider,
			'perModel'    => $perModel,
			'totalCalls'  => array_sum(array_column($perProvider, 'calls')),
			'totalErrors' => array_sum(array_column($perProvider, 'errors')),
			'totalUsd'    => array_sum(array_column($perProvider, 'total_usd')),
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
