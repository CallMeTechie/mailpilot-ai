# Modell-/Effort-/Provider-Auswahl pro Rolle — Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (empfohlen) oder superpowers:executing-plans, um diesen Plan Task für Task umzusetzen. Steps nutzen Checkbox-Syntax (`- [ ]`).

**Goal:** `llm_models` wird die einzige Wahrheit für Provider+Modell+Effort pro Rolle (score/summary/draft/inference) — in **jedem** Modus; `routing_mode` wird zum reinen Failover-Schalter.

**Architecture:** Alle vier Rollen laufen über `LlmRouter->complete($req, $role)` mit `modelHint=''` (Router resolved Modell+Effort aus `llm_models`). `routing_mode=direct` kürzt die Chain auf den Primary (kein Failover), `router` nutzt die ganze Chain. `score` verliert seinen Direct-Anthropic-Sonderpfad; `inference` läuft erstmals über den Router. Das Prompt-`model`-Feld wird nicht mehr fürs Routing gelesen (UI ist bereits modellfrei).

**Tech Stack:** PHP 8.4 (PSR-12 mit **Tabs**, `declare(strict_types=1)`), PDO Prepared Statements, MariaDB, PHPUnit (Unit + Integration, **kein Mocking** — reale Objekte/Reflection, anonyme `LlmProvider`-Klassen, `MailPilot\Tests\TestCase` mit `$this->pdo()`/`truncateAll()`). Spec: [`docs/superpowers/specs/2026-06-02-per-role-model-selection-design.md`](../specs/2026-06-02-per-role-model-selection-design.md).

**Wichtiger Ist-Stand (verifiziert 2026-06-02):**
- `summary`/`draft` laufen **schon immer** über den Router mit `modelHint=''` (nur der `LlmAllProvidersDownException`-Legacy-Fallback liest noch `prompt.model`).
- Das Prompt-Edit-UI (`admin/src/Views/prompt_edit.php`) hat **kein** Modell-Feld mehr (Zeilen 30-34); `PromptController::store()` leitet `model` schon aus `llm_models` ab. → D5 ist UI-seitig erledigt; offen ist nur, dass Services `prompt.model` nicht mehr fürs Routing lesen.
- Die `llm_models`-Zeile `role='inference'` (Anthropic Haiku) existiert bereits (Migration `0052_llm_models.sql`). → D6 = nur noch Chain-Setting + Verifikation.
- `callClaudeBatch` bündelt **≤ 3** Regel-Extraktions-Payloads (kein per-Mail-LLM). → `completeBatch()` darf sequenziell sein.

---

## Dateistruktur (was wird angefasst)

| Datei | Verantwortung | Änderung |
|---|---|---|
| `backend/src/Repositories/LlmModelRepository.php` | Modell-Auflösung pro Rolle | + `primaryModelIdForRole()` |
| `backend/src/Llm/LlmRouter.php` | Dispatch + Failover | `ROLES`-Konstante, `routing_mode`-Failover, `modelId`-Garantie, `completeBatch()` |
| `backend/src/Services/MailScoringService.php` | Scoring | immer Router, Direct-Pfad + Mirror raus |
| `backend/src/Services/MailSummaryService.php` | Summary | Fallback-Modell aus `llm_models` |
| `backend/src/Services/ReplyDraftService.php` | Draft | Fallback-Modell aus `llm_models` |
| `backend/src/Services/RuleInferenceService.php` | Regel-Inferenz | Extraktion über Router (`complete`/`completeBatch`) |
| `backend/src/Http/Kernel.php` | DI | `LlmRouter` in `RuleInferenceService`; stalen Kommentar (~Z. 199) bereinigen |
| `admin/src/Views/llm/routing.php` | Routing-UI | Banner/Labels „Failover-Schalter" für alle Rollen |
| `backend/migrations/0064_*.sql` | DB | `routing_mode='router'` für Bestand + `inference`/`draft` fallback_chain + Upgrade-Notice-Flag |

---

## Task 1: `LlmModelRepository::primaryModelIdForRole()`

Liefert die Modell-ID des höchstprioren Modells einer Rolle (cross-Provider) — Basis für den Legacy-Fallback in Summary/Draft, damit dort `prompt.model` entfällt.

**Files:**
- Modify: `backend/src/Repositories/LlmModelRepository.php` (nach `listByRole()`)
- Test: `backend/tests/Integration/Repositories/LlmModelRepositoryPrimaryModelTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Repositories;

use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

final class LlmModelRepositoryPrimaryModelTest extends TestCase
{
	public function testPrimaryModelIdForRoleReturnsHighestPriorityEnabledModel(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$providerId = Uuid::v4();
		$pdo->prepare('INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (:id, "Anthropic", "anthropic", "https://api.anthropic.com", 0, 1, 10)')
			->execute([':id' => $providerId]);
		// Zwei Score-Modelle: priority 5 (niedriger=höher) gewinnt gegen 20.
		foreach ([['m-a', 20], ['m-b', 5]] as [$mid, $prio]) {
			$pdo->prepare('INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
				VALUES (:id, :p, :m, "score", 1, :prio)')
				->execute([':id' => Uuid::v4(), ':p' => $providerId, ':m' => $mid, ':prio' => $prio]);
		}

		$repo = new LlmModelRepository($pdo);
		self::assertSame('m-b', $repo->primaryModelIdForRole('score'));
		self::assertNull($repo->primaryModelIdForRole('summary'));
	}
}
```

- [ ] **Step 2: Run — expect FAIL** (`Call to undefined method ...primaryModelIdForRole`)

```bash
cd backend && composer test:integration -- --filter LlmModelRepositoryPrimaryModelTest
```

- [ ] **Step 3: Implement** — in `LlmModelRepository.php` nach `listByRole()` einfügen:

```php
	/**
	 * Modell-ID des höchstprioren aktiven Modells einer Rolle (cross-Provider).
	 * Genutzt als Legacy-Fallback-Modell, wenn der Router-Pfad nicht greift.
	 */
	public function primaryModelIdForRole(string $role): ?string
	{
		$rows = $this->listByRole($role);
		return $rows === [] ? null : (string)$rows[0]['model_id'];
	}
```

- [ ] **Step 4: Run — expect PASS**

```bash
cd backend && composer test:integration -- --filter LlmModelRepositoryPrimaryModelTest
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repositories/LlmModelRepository.php backend/tests/Integration/Repositories/LlmModelRepositoryPrimaryModelTest.php
git commit -m "feat(llm): LlmModelRepository::primaryModelIdForRole für Rollen-Fallback-Modell"
```

---

## Task 2: `LlmRouter` — `ROLES`-Konstante + `routing_mode`-Failover

`routing_mode=direct` → nur Primary (kein Failover); `router` → ganze Chain. Zentrale Rollen-Liste für die UI.

**Files:**
- Modify: `backend/src/Llm/LlmRouter.php` (Klassen-Konstante; Ende von `resolveChain()` ~Z. 186-188)
- Test: `backend/tests/Integration/Llm/LlmRouterRoutingModeTest.php`

- [ ] **Step 1: Failing test** — nutzt reale Repos (Test-PDO) + anonyme `LlmProvider`, einer wirft `LlmOverloadedException`.

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Llm\LlmOverloadedException;
use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

final class LlmRouterRoutingModeTest extends TestCase
{
	private const PID_A = '00000000-0000-4000-8000-0000000000a0';
	private const PID_B = '00000000-0000-4000-8000-0000000000b0';

	private function seedTwoProviderChain(string $mode): SettingsRepository
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		// truncateAll() leert llm_providers/llm_models NICHT → fixe UUIDs vorher
		// löschen, sonst Duplicate-Key beim zweiten Lauf gegen dieselbe DB.
		$pdo->exec("DELETE FROM llm_models WHERE provider_id IN ('" . self::PID_A . "','" . self::PID_B . "')");
		$pdo->exec("DELETE FROM llm_providers WHERE id IN ('" . self::PID_A . "','" . self::PID_B . "')");
		foreach ([[self::PID_A, 'Anthropic', 'anthropic', 10], [self::PID_B, 'OpenAI', 'openai', 20]] as [$id, $name, $kind, $prio]) {
			$pdo->prepare('INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
				VALUES (:id, :n, :k, "https://x", 0, 1, :p)')->execute([':id' => $id, ':n' => $name, ':k' => $kind, ':p' => $prio]);
			$pdo->prepare('INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
				VALUES (:id, :p, :m, "score", 1, 10)')->execute([':id' => Uuid::v4(), ':p' => $id, ':m' => $kind . '-model']);
		}
		$set = new SettingsRepository($pdo);
		$set->set('llm.routing_mode', $mode);
		$set->set('llm.score.fallback_chain', json_encode([self::PID_A, self::PID_B]));
		$set->set('llm.privacy_mode', 'cloud_allowed');
		return $set;
	}

	/** @param array<string,int> $calls */
	private function provider(string $kind, bool $overloaded, array &$calls): LlmProvider
	{
		return new class($kind, $overloaded, $calls) implements LlmProvider {
			/** @param array<string,int> $calls */
			public function __construct(private string $kind, private bool $overloaded, private array &$calls) {}
			public function kind(): string { return $this->kind; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				$this->calls[$this->kind] = ($this->calls[$this->kind] ?? 0) + 1;
				if ($this->overloaded) { throw new LlmOverloadedException($this->kind . ' overloaded'); }
				return new NormalizedResponse('ok', ['inputTokens' => 1, 'outputTokens' => 1], 'stop', $r->modelHint, $this->kind);
			}
			public function isHealthy(): bool { return true; }
			public function listModels(): array { return []; }
		};
	}

	public function testDirectModeUsesOnlyPrimaryNoFailover(): void
	{
		$calls = [];
		$set = $this->seedTwoProviderChain('direct');
		$router = new LlmRouter(
			['anthropic' => $this->provider('anthropic', true, $calls), 'openai' => $this->provider('openai', false, $calls)],
			new LlmProviderRepository($this->pdo()), $set, new NullLogger(), new LlmModelRepository($this->pdo()), null,
		);
		$req = new NormalizedRequest('sys', [['role' => 'user', 'content' => 'x']], 100, 0.1, '');
		$this->expectException(\MailPilot\Llm\LlmAllProvidersDownException::class);
		try {
			$router->complete($req, 'score');
		} finally {
			self::assertSame(1, $calls['anthropic'] ?? 0, 'Primary einmal versucht');
			self::assertArrayNotHasKey('openai', $calls, 'direct: KEIN Failover auf OpenAI');
		}
	}

	public function testRouterModeFailsOverToSecond(): void
	{
		$calls = [];
		$set = $this->seedTwoProviderChain('router');
		$router = new LlmRouter(
			['anthropic' => $this->provider('anthropic', true, $calls), 'openai' => $this->provider('openai', false, $calls)],
			new LlmProviderRepository($this->pdo()), $set, new NullLogger(), new LlmModelRepository($this->pdo()), null,
		);
		$req = new NormalizedRequest('sys', [['role' => 'user', 'content' => 'x']], 100, 0.1, '');
		$resp = $router->complete($req, 'score');
		self::assertSame('openai', $resp->providerKind);
		self::assertSame(1, $calls['anthropic'] ?? 0);
		self::assertSame(1, $calls['openai'] ?? 0);
	}
}
```

- [ ] **Step 2: Run — expect FAIL** (direct-Test failt: heute läuft Failover auch im direct-Mode → OpenAI wird aufgerufen)

```bash
cd backend && composer test:integration -- --filter LlmRouterRoutingModeTest
```

- [ ] **Step 3: Implement** — in `LlmRouter.php`:

(a) Klassen-Konstante direkt nach `final class LlmRouter {`:

```php
	/** Kanonische Rollen-Liste — Single Source für UI-Iteration (Routing-Chain + Modell-Dropdowns). */
	public const ROLES = ['score', 'summary', 'draft', 'inference'];
```

(b) Ende von `resolveChain()` — die letzte Zeile

```php
		// Privacy-Mode-Filter (Phase 9q-C).
		$mode = $this->settings->getString('llm.privacy_mode', 'cloud_allowed');
		return $this->applyPrivacyMode($chain, $mode);
```

ersetzen durch:

```php
		// Privacy-Mode-Filter (Phase 9q-C).
		$mode  = $this->settings->getString('llm.privacy_mode', 'cloud_allowed');
		$chain = $this->applyPrivacyMode($chain, $mode);

		// routing_mode: „direct" = nur der Primary (kein Failover), „router"
		// = ganze Chain. Default „router" (Migration 0064 setzt Bestand darauf;
		// fehlt das Setting, ist die sichere Wahl die volle Chain).
		if ($this->settings->getString('llm.routing_mode', 'router') !== 'router') {
			return array_slice($chain, 0, 1);
		}
		return $chain;
```

- [ ] **Step 4: Run — expect PASS**

```bash
cd backend && composer test:integration -- --filter LlmRouterRoutingModeTest
```

- [ ] **Step 4b: Bestehende Failover-Tests selbst-pinnen** — Der Seed-Default ist `routing_mode='direct'` (Migration 0051), und `truncateAll()` leert `system_settings` **nicht**. Der neue Slice würde `LlmRouterFailoverTest` und `RouterEffortTest` rot machen (sie erwarten Failover über die volle Chain), solange Migration 0064 (Task 9) den Seed noch nicht auf `router` geflippt hat. Damit die Per-Task-Green-Bar hält: in **beiden** Tests im `setUp()` / vor dem Router-Aufruf explizit `routing_mode='router'` setzen:

```php
$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`) VALUES ("llm.routing_mode", "router", "string")
	ON DUPLICATE KEY UPDATE `value` = "router"')->execute();
```

Run beide Tests grün:

```bash
cd backend && composer test:integration -- --filter "LlmRouterFailoverTest|RouterEffortTest"
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/LlmRouter.php backend/tests/Integration/Llm/LlmRouterRoutingModeTest.php backend/tests/Integration/Llm/LlmRouterFailoverTest.php backend/tests/Integration/Llm/RouterEffortTest.php
git commit -m "feat(llm): routing_mode wird reiner Failover-Schalter (direct=Primary-only) + ROLES-Konstante"
```

---

## Task 3: `LlmRouter` — nicht-leere `modelId`-Garantie

Liefert ein Provider eine leere `modelId`, vergiftet das den Cost-Pfad (`llm_call_log`/`api_usage`/Pricing). Der Router füllt sie mit dem resolvten Modell.

**Files:**
- Modify: `backend/src/Llm/LlmRouter.php` (in `complete()`, direkt nach dem erfolgreichen `$response = $provider->complete($effectiveRequest);`)
- Test: `backend/tests/Integration/Llm/LlmRouterModelIdGuaranteeTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

final class LlmRouterModelIdGuaranteeTest extends TestCase
{
	public function testEmptyModelIdIsBackfilledFromResolvedModel(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pid = Uuid::v4();
		$pdo->prepare('INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (:id, "Local", "openai_compatible", "http://x", 1, 1, 10)')->execute([':id' => $pid]);
		$pdo->prepare('INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (:id, :p, "qwen3:32b", "score", 1, 10)')->execute([':id' => Uuid::v4(), ':p' => $pid]);
		$set = new SettingsRepository($pdo);
		$set->set('llm.routing_mode', 'router');
		$set->set('llm.score.fallback_chain', json_encode([$pid]));
		$set->set('llm.privacy_mode', 'cloud_allowed');

		$emptyModelProvider = new class implements LlmProvider {
			public function kind(): string { return 'openai_compatible'; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{ return new NormalizedResponse('ok', ['inputTokens' => 1, 'outputTokens' => 1], 'stop', '', 'openai_compatible'); }
			public function isHealthy(): bool { return true; }
			public function listModels(): array { return []; }
		};

		$router = new LlmRouter(
			['openai_compatible' => $emptyModelProvider],
			new LlmProviderRepository($pdo), $set, new NullLogger(), new LlmModelRepository($pdo), null,
		);
		$resp = $router->complete(new NormalizedRequest('s', [['role' => 'user', 'content' => 'x']], 50, 0.1, ''), 'score');
		self::assertSame('qwen3:32b', $resp->modelId, 'leerer modelId → resolvtes Modell');
	}
}
```

- [ ] **Step 2: Run — expect FAIL** (`modelId` ist `''`)

```bash
cd backend && composer test:integration -- --filter LlmRouterModelIdGuaranteeTest
```

- [ ] **Step 3: Implement** — in `complete()` die Zeile

```php
				$response = $provider->complete($effectiveRequest);
				$latencyMs = (int)((microtime(true) - $start) * 1000);
```

ersetzen durch:

```php
				$response = $provider->complete($effectiveRequest);
				// modelId-Garantie: manche Provider (OpenAI-kompatibel/lokal)
				// liefern leeren/abweichenden model — der Cost-Pfad keyt aber
				// auf model. Leere modelId mit dem resolvten Modell füllen.
				if ($response->modelId === '' && $effectiveRequest->modelHint !== '') {
					$response = new NormalizedResponse(
						$response->content, $response->usage, $response->finishReason,
						$effectiveRequest->modelHint, $response->providerKind,
					);
				}
				$latencyMs = (int)((microtime(true) - $start) * 1000);
```

- [ ] **Step 4: Run — expect PASS**

```bash
cd backend && composer test:integration -- --filter LlmRouterModelIdGuaranteeTest
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/LlmRouter.php backend/tests/Integration/Llm/LlmRouterModelIdGuaranteeTest.php
git commit -m "fix(llm): Router garantiert nicht-leere modelId (Cost-Dashboard-Integrität)"
```

---

## Task 4: `LlmRouter::completeBatch()`

Sequenzielle Batch-Variante (≤ 3 Items) mit per-Item-Failover; gescheitertes Item → `null`-Slot.

**Files:**
- Modify: `backend/src/Llm/LlmRouter.php` (neue Methode nach `complete()`)
- Test: `backend/tests/Integration/Llm/LlmRouterCompleteBatchTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

final class LlmRouterCompleteBatchTest extends TestCase
{
	public function testCompleteBatchReturnsPerItemAndNullOnError(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pid = Uuid::v4();
		$pdo->prepare('INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (:id, "Anthropic", "anthropic", "https://x", 0, 1, 10)')->execute([':id' => $pid]);
		$pdo->prepare('INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (:id, :p, "claude-haiku-4-5-20251001", "inference", 1, 10)')->execute([':id' => Uuid::v4(), ':p' => $pid]);
		$set = new SettingsRepository($pdo);
		$set->set('llm.routing_mode', 'router');
		$set->set('llm.inference.fallback_chain', json_encode([$pid]));
		$set->set('llm.privacy_mode', 'cloud_allowed');

		// Provider wirft bei content "boom" eine generische Exception (kein Failover-Typ).
		$provider = new class implements LlmProvider {
			public function kind(): string { return 'anthropic'; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				if ($r->messages[0]['content'] === 'boom') { throw new \RuntimeException('boom'); }
				return new NormalizedResponse('ok:' . $r->messages[0]['content'], ['inputTokens' => 1, 'outputTokens' => 1], 'stop', $r->modelHint, 'anthropic');
			}
			public function isHealthy(): bool { return true; }
			public function listModels(): array { return []; }
		};
		$router = new LlmRouter(['anthropic' => $provider], new LlmProviderRepository($pdo), $set, new NullLogger(), new LlmModelRepository($pdo), null);

		$mk = static fn(string $c): NormalizedRequest => new NormalizedRequest('s', [['role' => 'user', 'content' => $c]], 50, 0.1, '');
		$out = $router->completeBatch([$mk('a'), $mk('boom'), $mk('c')], 'inference');

		self::assertCount(3, $out);
		self::assertSame('ok:a', $out[0]?->content);
		self::assertNull($out[1], 'Fehler-Item → null-Slot');
		self::assertSame('ok:c', $out[2]?->content);
	}
}
```

- [ ] **Step 2: Run — expect FAIL** (`Call to undefined method ...completeBatch`)

```bash
cd backend && composer test:integration -- --filter LlmRouterCompleteBatchTest
```

- [ ] **Step 3: Implement** — neue Methode direkt nach `complete()` in `LlmRouter.php`:

```php
	/**
	 * Sequenzielle Batch-Variante (genutzt von RuleInferenceService für die
	 * ≤3 Regel-Extraktions-Calls). Jedes Item läuft über complete() mit
	 * vollem per-Item-Failover; ein gescheitertes Item → null-Slot (kein
	 * Abbruch des Batches), analog zum bisherigen ClaudeClient::messagesBatch.
	 *
	 * @param  list<NormalizedRequest> $requests
	 * @return list<NormalizedResponse|null>
	 */
	public function completeBatch(array $requests, string $taskType = 'inference'): array
	{
		$out = [];
		foreach ($requests as $i => $req) {
			try {
				$out[] = $this->complete($req, $taskType);
			} catch (\Throwable $e) {
				$this->logger->warning('llm.router.batch_item_failed', [
					'task' => $taskType, 'slot' => $i, 'err' => $e->getMessage(),
				]);
				$out[] = null;
			}
		}
		return $out;
	}
```

- [ ] **Step 4: Run — expect PASS**

```bash
cd backend && composer test:integration -- --filter LlmRouterCompleteBatchTest
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/LlmRouter.php backend/tests/Integration/Llm/LlmRouterCompleteBatchTest.php
git commit -m "feat(llm): LlmRouter::completeBatch (sequenziell, per-Item-Failover, null-Slot)"
```

---

## Task 5: `MailScoringService` — immer über den Router

`useRouter`-Gate raus; der direkte `AnthropicClient`-Pfad + `mirrorDirectCallToLlmLog` entfallen (der Router loggt selbst).

**Files:**
- Modify: `backend/src/Services/MailScoringService.php` (`callClaude()`, der `try`-Block + die `catch`-Mirror-Zeile)
- Test: `backend/tests/Integration/Services/ScoreAlwaysRouterTest.php`

- [ ] **Step 1: Failing test** — `routing_mode=direct`, score-Modell in `llm_models` ≠ Prompt-Modell; erwarte, dass der Router (und damit das `llm_models`-Modell) genutzt wird. (Baut auf vorhandenem `ScoreRouterModelTest`-Muster auf.)

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Services\BudgetService;
use MailPilot\Services\MailScoringService;
use MailPilot\Services\RedactionService;
use MailPilot\Tests\Fixtures\FakeClaudeClient;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * Spec 1: score läuft IMMER über den Router — auch im routing_mode='direct'.
 * Im direct-Mode wird die Chain auf den Primary gekürzt, das Modell kommt aber
 * weiterhin aus llm_models (Rolle 'score'), NICHT aus dem P-SCORE-Prompt.
 * Muster 1:1 wie backend/tests/Integration/Llm/ScoreRouterModelTest.php.
 */
final class ScoreAlwaysRouterTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000e1';

	public function testDirectModeStillUsesLlmModelsModelViaRouter(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'TestScoreDirect', 'anthropic', 'http://x', 0, 1, 10)")->execute([self::PROVIDER_ID]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (?, ?, 'claude-haiku-4-5-20251001', 'score', 1, 10)")->execute([Uuid::v4(), self::PROVIDER_ID]);
		foreach ([
			['llm.routing_mode', 'direct'],   // direct! — trotzdem muss der Router genutzt werden
			['llm.privacy_mode', 'cloud_allowed'],
			['llm.score.fallback_chain', '["' . self::PROVIDER_ID . '"]'],
		] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`) VALUES (?, ?, "string")
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
		}

		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, ['from_email' => 'a@example.com', 'subject' => 'Hi', 'body_text' => 'Test.']);
		$mails = (new MailRepository($pdo))->findUnscoredForMailbox($tenantId, $mailboxId);

		$capture = new class ($mails[0]['id']) implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function __construct(private string $mailId) {}
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				$this->seen = $r;
				$json = json_encode(['results' => [[
					'id' => $this->mailId, 'label' => 'direct', 'action_required' => false,
					'priority' => 3, 'summary' => 's', 'reasoning' => 'r',
				]]], JSON_THROW_ON_ERROR);
				return new NormalizedResponse($json, ['inputTokens' => 10, 'outputTokens' => 5], 'end_turn', $r->modelHint, 'anthropic');
			}
			public function listModels(): array { return []; }
		};

		$router = new LlmRouter(
			['anthropic' => $capture], new LlmProviderRepository($pdo),
			new SettingsRepository($pdo), new NullLogger(), new LlmModelRepository($pdo),
		);
		$service = $this->makeServiceWithRouter(new FakeClaudeClient(), $router);
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		self::assertCount(1, $scores);
		self::assertNotNull($capture->seen, 'score MUSS auch im direct-Mode über den Router laufen');
		self::assertSame('claude-haiku-4-5-20251001', $capture->seen->modelHint, 'Modell aus llm_models, nicht aus dem Prompt');
	}

	private function makeServiceWithRouter(FakeClaudeClient $claude, LlmRouter $router): MailScoringService
	{
		$pdo = $this->pdo();
		$budget = new BudgetService(new SettingsRepository($pdo), new UsageRepository($pdo), new PricingRepository($pdo), $this->logger());
		return new MailScoringService(
			$claude, new MailRepository($pdo), new ScoreRepository($pdo), new CacheRepository($pdo, 30),
			new RedactionService(), $budget, new CorrectionRepository($pdo), new SubLabelRepository($pdo),
			new AutoSortRepository($pdo, new SettingsRepository($pdo)), new PromptRepository($pdo),
			new SettingsRepository($pdo), 20, 2048, $this->logger(), new PendingActionRepository($pdo),
			null, null, null, null, $router,
		);
	}
}
```

> **Vorlage:** identisch zum bestehenden `backend/tests/Integration/Llm/ScoreRouterModelTest.php` (anon `LlmProvider` mit `$seen` + `kind()`, `makeServiceWithRouter`). `$llmRouter` ist der **letzte, optionale** Ctor-Param von `MailScoringService`; `FakeClaudeClient` bleibt nur als (ungenutzter) Direct-Fallback dabei.

- [ ] **Step 2: Run — expect FAIL** (heute: direct-Mode nutzt den Direct-Anthropic-Pfad mit Prompt-Modell, kein Router-Call)

```bash
cd backend && composer test:integration -- --filter ScoreAlwaysRouterTest
```

- [ ] **Step 3: Implement** — in `MailScoringService::callClaude()` den Block

```php
		$start = microtime(true);
		// Phase 9q-B (Marc 2026-05-22): wenn routing_mode='router' und Router
		// verdrahtet ist, geht der Call über den LlmRouter mit Failover-Chain.
		// Andernfalls bleibt der direkte AnthropicClient-Pfad — zero behavior
		// change für alle Tests + initial nach Deploy.
		// Pre-try definiert, damit der catch-Block ihn ebenfalls sehen kann.
		$useRouter = $this->llmRouter !== null
			&& $this->settings->getString('llm.routing_mode', 'direct') === 'router';
		try {
			if ($useRouter) {
				$response = $this->callViaRouter($systemSegments, $user, $model, $maxTokens, (float)($activePrompt['temperature'] ?? 0.1));
			} else {
				$response = $this->claude->messages([
					'model'      => $model,
					'max_tokens' => $maxTokens,
					'system'     => $systemSegments,
					'messages'   => [['role' => 'user', 'content' => $user]],
				]);
			}
		} catch (\Throwable $e) {
			$latency = (int)((microtime(true) - $start) * 1000);
			$this->recordCall($tenantId, $userId, $mailboxId, [], $latency, 'error', $e->getMessage(), $promptVersionTag, $model);
			if (!$useRouter) {
				$this->mirrorDirectCallToLlmLog($model, 'score', null, [], $latency, 'error', $e->getMessage());
			}
			throw $e;
		}
		$latency = (int)((microtime(true) - $start) * 1000);
		$this->recordCall($tenantId, $userId, $mailboxId, $response['usage'] ?? [], $latency, 'success', null, $promptVersionTag, (string)($response['model'] ?? $model));

		// Phase 9q B-Fix (Marc 2026-05-23): direct-Mode-Calls auch ins
		// llm_call_log spiegeln, sonst zeigt /admin/llm/usage = 0 für
		// alle Production-Calls (LlmCallLogger wird sonst nur vom Router
		// getriggert). Router-Pfad logged sich selbst.
		if (!$useRouter) {
			$this->mirrorDirectCallToLlmLog($model, 'score', $response['model'] ?? $model, $response['usage'] ?? [], $latency, 'ok', null);
		}
```

ersetzen durch:

```php
		$start = microtime(true);
		// Spec 1 (2026-06-02): Scoring läuft IMMER über den LlmRouter — Modell
		// + Effort kommen aus llm_models (Rolle 'score'), routing_mode steuert
		// nur noch Failover (direct=Primary-only). Der Router loggt selbst ins
		// llm_call_log; ein Direct-AnthropicClient-Pfad existiert nicht mehr.
		if ($this->llmRouter === null) {
			throw new \RuntimeException('LlmRouter nicht verdrahtet — Scoring nicht möglich.');
		}
		try {
			$response = $this->callViaRouter($systemSegments, $user, $model, $maxTokens, (float)($activePrompt['temperature'] ?? 0.1));
		} catch (\Throwable $e) {
			$latency = (int)((microtime(true) - $start) * 1000);
			$this->recordCall($tenantId, $userId, $mailboxId, [], $latency, 'error', $e->getMessage(), $promptVersionTag, $model);
			throw $e;
		}
		$latency = (int)((microtime(true) - $start) * 1000);
		$this->recordCall($tenantId, $userId, $mailboxId, $response['usage'] ?? [], $latency, 'success', null, $promptVersionTag, (string)($response['model'] ?? $model));
```

> **Hinweis (Review-Korrektur):** Der erste Ctor-Param `$claude` ist vom Typ **`ClaudeProvider`** (nicht `ClaudeClient`) und bleibt **required** — er wird im Konstruktor an `new ActionOwnerResolver($claude, …)` durchgereicht, ist also NICHT ungenutzt. `ClaudeClient::extractText` ist statisch und braucht den injizierten Wert nicht. Es ändert sich nur der `?LlmRouter`-Pfad; `$llmRouter` ist der **letzte, optionale** Ctor-Param (Produktion: vom Kernel verdrahtet). Ungenutzt werden NUR `mirrorDirectCallToLlmLog()`/`resolveAnthropicProviderId()`/`$anthropicProviderIdCached` **sowie** die Ctor-Params `$callLogger` + `$llmProviders` (s. Step 3b).

- [ ] **Step 3b: Toten Code + ungenutzte Ctor-Params entfernen** — `mirrorDirectCallToLlmLog()`, `resolveAnthropicProviderId()` und Property `$anthropicProviderIdCached` löschen. **Außerdem** werden die Ctor-Parameter `$callLogger` (`?LlmCallLogger`) und `$llmProviders` (`?LlmProviderRepository`) ungenutzt (waren nur Leser des Mirror-Pfads) → aus dem `MailScoringService`-Konstruktor entfernen **und** die `MailScoringService`-Konstruktion in `backend/src/Http/Kernel.php` (~Z. 350) um diese zwei Argumente kürzen. Danach `vendor/bin/phpstan analyse` (Level 5); falls `phpstan-baseline.neon` die alten Properties referenziert, Baseline neu erzeugen.

- [ ] **Step 3c: ALLE bestehenden Tests anpassen, die `scoreBatch` über den Direct-Pfad treiben (sonst rot)** — Nach Entfernen des Direct-Pfads werfen **alle** Tests, die `MailScoringService` ohne `llmRouter` konstruieren, `RuntimeException('LlmRouter nicht verdrahtet …')`. Betroffen (alle unter `backend/tests/Integration/`): **`MailScoringServiceTest.php`** (~14 scoreBatch-Tests), **`TopicDiscoveryTest.php`**, **`ActionOwnerTest.php`**, **`Services/Sender/ScoringFolderSegmentsTest.php`**, **`Services/Sender/ScoringSenderEnrichmentTest.php`**. Je `makeService()` analog `ScoreRouterModelTest::makeServiceWithRouter` umbauen (echter `LlmRouter` + aufzeichnender anon `LlmProvider` mit kanonischem `{"results":[…]}`-JSON; im Setup `routing_mode='router'` + `llm.score.fallback_chain` + Provider/`llm_models`-`score`-Row seeden). **Hinweis `ActionOwnerTest`:** der ActionOwner-Mini-Call läuft über `ActionOwnerResolver($claude,…)` (NICHT über den Router) → `FakeClaudeClient` bleibt dort für DIESEN Pfad nötig; nur der `scoreBatch`-Transport wandert auf den Router. Lauf grün:

```bash
cd backend && composer test:integration -- --filter "MailScoringServiceTest|TopicDiscoveryTest|ActionOwnerTest|ScoringFolderSegmentsTest|ScoringSenderEnrichmentTest"
```

- [ ] **Step 4: Run — Unit + Integration grün**

```bash
cd backend && composer test:integration -- --filter ScoreAlwaysRouterTest && composer test:unit
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Services/MailScoringService.php backend/tests/Integration/Services/ScoreAlwaysRouterTest.php
git commit -m "refactor(scoring): score läuft immer über den Router (Direct-Pfad + Mirror entfernt)"
```

---

## Task 6: `MailSummaryService` + `ReplyDraftService` — Fallback-Modell aus `llm_models`

Der `LlmAllProvidersDownException`-Fallback nutzt nicht mehr `prompt.model`, sondern das Primary-Modell der Rolle aus `llm_models`.

**Files:**
- Modify: `backend/src/Services/MailSummaryService.php`, `backend/src/Services/ReplyDraftService.php` (Konstruktor + `$model`-Herkunft)
- Modify: `backend/src/Http/Kernel.php` (beide Konstruktionen)
- Test: `backend/tests/Integration/Services/SummaryFallbackModelTest.php`

- [ ] **Step 1: Failing test** — Router-Chain leer → Legacy-Fallback feuert; das geloggte Modell stammt aus `llm_models` (`summary`-Primary), nicht aus `prompt.model`.

**Test-Aufbau** — als `backend/tests/Integration/Services/SummaryFallbackModelTest.php`, **1:1 am Muster von `backend/tests/Integration/Services/SummaryDraftRouterTest.php`** (anon `LlmProvider` mit `$seen` + `MailSummaryService` direkt konstruiert; **kein** Mock). Deltas:
- Seed: Provider + `llm_models`-`summary`-Row `model_id='sentinel-summary-model'`; `routing_mode='router'`; **leere** Summary-Chain `llm.summary.fallback_chain='[]'`, damit der Router `LlmAllProvidersDownException` wirft und der Legacy-Fallback feuert.
- `MailSummaryService` mit dem **neuen** `LlmModelRepository`-Arg (Position vor `$claudeFallback`) + einem aufzeichnenden anon `ClaudeProvider` als `claudeFallback` konstruieren (genau wie der anon Provider in `SummaryDraftRouterTest`, nur über die `claudeFallback`-Position).
- `summarize(...)` aufrufen; assert: der in `summaries` persistierte `model` (bzw. der vom Fallback geloggte `model`) ist **`sentinel-summary-model`** (aus `llm_models`), **nicht** das P-SUMMARY-Prompt-Modell.

```php
self::assertSame('sentinel-summary-model', $persistedSummaryModel,
    'Fallback-Modell stammt aus llm_models (Rolle summary), nicht aus prompt.model');
```

- [ ] **Step 2: Run — expect FAIL** (heute loggt der Fallback `prompt-only`)

```bash
cd backend && composer test:integration -- --filter SummaryFallbackModelTest
```

- [ ] **Step 3: Implement (Summary)** — in `MailSummaryService`:

(a) Konstruktor um `LlmModelRepository` erweitern (vor `$claudeFallback`):

```php
	public function __construct(
		private readonly LlmRouter $router,
		private readonly MailRepository $mails,
		private readonly SummaryRepository $summaries,
		private readonly RedactionService $redactor,
		private readonly BudgetService $budget,
		private readonly PromptRepository $prompts,
		private readonly \MailPilot\Repositories\LlmModelRepository $models,
		private readonly ?ClaudeProvider $claudeFallback = null,
	) {
	}
```

(b) Die Zeile `$model = $activePrompt['model'];` ersetzen durch:

```php
		// Spec 1: Modell kommt aus llm_models (Rolle 'summary'), nicht mehr aus
		// dem Prompt. Wird nur noch für den Legacy-Direct-Fallback + Budget-/
		// Error-Logging gebraucht (der Router-Pfad resolved es selbst).
		$model = $this->models->primaryModelIdForRole('summary')
			?? (string)$activePrompt['model'];
```

- [ ] **Step 3b: Implement (Draft)** — in `ReplyDraftService`: Konstruktor um `private readonly \MailPilot\Repositories\LlmModelRepository $models,` erweitern (vor `$redactionRules`), und `$model = $activePrompt['model'];` ersetzen durch:

```php
		$model = $this->models->primaryModelIdForRole('draft')
			?? (string)$activePrompt['model'];
```

- [ ] **Step 3c: Kernel-Wiring** — in `backend/src/Http/Kernel.php` die `MailSummaryService`- und `ReplyDraftService`-Konstruktion um `$this->get(\MailPilot\Repositories\LlmModelRepository::class)` an der **exakt** zum Konstruktor passenden Argument-Position erweitern (Summary: nach `PromptRepository`, vor `claudeFallback`; Draft: vor `RedactionRepository`).

- [ ] **Step 3d: Bestehende positionale Konstruktionen anpassen (sonst rot)** — Der neue **required** `LlmModelRepository`-Ctor-Param verschiebt die positionalen Argumente bestehender Aufrufer: `backend/tests/Integration/Services/SummaryDraftRouterTest.php` (baut `MailSummaryService` positional mit `FakeClaudeClient` als letztem Arg) und `backend/tests/Integration/AutoReplyServiceTest.php` (baut `ReplyDraftService` positional). In beiden den `LlmModelRepository`-Arg an der neuen Position einfügen (Summary: vor `$claudeFallback`; Draft: vor `$redactionRules`). (`FakeClaudeClient` ist via `extends ClaudeClient implements ClaudeProvider` ein gültiger `ClaudeProvider` — reicht als `claudeFallback`.) Lauf grün:

```bash
cd backend && composer test:integration -- --filter "SummaryDraftRouterTest|AutoReplyServiceTest"
```

- [ ] **Step 4: Run — expect PASS + Unit grün**

```bash
cd backend && composer test:integration -- --filter SummaryFallbackModelTest && composer test:unit
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Services/MailSummaryService.php backend/src/Services/ReplyDraftService.php backend/src/Http/Kernel.php backend/tests/Integration/Services/SummaryFallbackModelTest.php
git commit -m "refactor(summary,draft): Fallback-Modell aus llm_models statt prompt.model"
```

---

## Task 7: `RuleInferenceService` — Extraktion über den Router

`callClaude()`/`callClaudeBatch()` nutzen den `LlmRouter` (Rolle `inference`) statt `ClaudeClient`. Modell/Effort kommen aus `llm_models`.

**Files:**
- Modify: `backend/src/Services/RuleInferenceService.php` (Konstruktor + `callClaude`/`callClaudeBatch` + neuer Helfer)
- Test: `backend/tests/Integration/Services/InferenceViaRouterTest.php`

- [ ] **Step 1: Failing test** — Inferenz-Extraktion läuft über einen aufzeichnenden Router-Provider; das `inference`-Modell aus `llm_models` wird angefragt.

**Test-Aufbau** — als `backend/tests/Integration/Services/InferenceViaRouterTest.php`, anon-`LlmProvider`-Muster wie in `ScoreRouterModelTest` (mit `kind()` + `$seen`), Service-Konstruktion wie im **aktualisierten** `RuleInferenceServiceTest` (s. Step 3e). Deltas:
- Seed: Anthropic-Provider + `llm_models`-`inference`-Row `model_id='claude-haiku-4-5-20251001'`; `routing_mode='router'`; `llm.inference.fallback_chain='["<provider-id>"]'`.
- `RuleInferenceService` mit echtem `LlmRouter` konstruieren; der anon `LlmProvider` (Rolle `inference`) fängt den `NormalizedRequest` in `$seen` und liefert valides Extraktions-JSON (z. B. `{"folder_segments":["Projekte","Foo"],"confidence":90}` passend zum `P-RULE-EXTRACT`-Schema).
- Eine Korrektur inferieren (`infer(...)` bzw. `inferAllFromCorrection(...)`); assert:

```php
self::assertNotNull($capture->seen, 'Inferenz MUSS über den Router laufen');
self::assertSame('claude-haiku-4-5-20251001', $capture->seen->modelHint,
    'inference-Modell aus llm_models (Rolle inference)');
```

- [ ] **Step 2: Run — expect FAIL** (heute geht Inferenz direkt über `ClaudeClient`, kein Router-Call)

```bash
cd backend && composer test:integration -- --filter InferenceViaRouterTest
```

- [ ] **Step 3: Implement** — in `RuleInferenceService`:

(a) Konstruktor: `LlmRouter` injizieren (direkt nach `$claude`):

```php
		private readonly ClaudeClient            $claude,
		private readonly \MailPilot\Llm\LlmRouter $router,
```

(b) Neuen privaten Helfer ergänzen, der eine Anthropic-Payload (aus `buildClaudePayload`) über den Router schickt und das Anthropic-Response-Shape rekonstruiert (damit `parseClaudeResponse` unverändert weiterläuft):

```php
	/**
	 * Schickt eine buildClaudePayload()-Payload über den LlmRouter (Rolle
	 * 'inference', modelHint='' → Modell/Effort aus llm_models). Liefert das
	 * Anthropic-Response-Shape zurück, das parseClaudeResponse() erwartet.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function routeInferencePayload(array $payload): array
	{
		$req = new \MailPilot\Llm\NormalizedRequest(
			systemPrompt:   (string)($payload['system'] ?? ''),
			messages:       $payload['messages'] ?? [],
			maxTokens:      (int)($payload['max_tokens'] ?? 1024),
			temperature:    (float)($payload['temperature'] ?? 0.1),
			modelHint:      '',
			responseFormat: 'json_object',
			cacheSegments:  [],
		);
		$resp = $this->router->complete($req, 'inference');
		return ['content' => [['type' => 'text', 'text' => $resp->content]]];
	}
```

(c) `callClaude()` umstellen:

```php
	private function callClaude(array $vars, string $promptKey = 'P-RULE-EXTRACT'): ?array
	{
		$payload = $this->buildClaudePayload($vars, $promptKey);
		try {
			$response = $this->routeInferencePayload($payload);
		} catch (\Throwable $e) {
			$this->logger->warning('rule_inference.claude_failed', ['err' => $e->getMessage()]);
			return null;
		}
		return $this->parseClaudeResponse($response);
	}
```

(d) `callClaudeBatch()` umstellen — `messagesBatch` durch `LlmRouter::completeBatch()` ersetzen:

```php
	private function callClaudeBatch(array $specs): array
	{
		if ($specs === []) {
			return [];
		}
		$requests = [];
		foreach ($specs as $spec) {
			$p = $this->buildClaudePayload($spec['vars'], $spec['promptKey']);
			$requests[] = new \MailPilot\Llm\NormalizedRequest(
				systemPrompt:   (string)($p['system'] ?? ''),
				messages:       $p['messages'] ?? [],
				maxTokens:      (int)($p['max_tokens'] ?? 1024),
				temperature:    (float)($p['temperature'] ?? 0.1),
				modelHint:      '',
				responseFormat: 'json_object',
				cacheSegments:  [],
			);
		}
		$responses = $this->router->completeBatch($requests, 'inference');
		$results = [];
		foreach ($responses as $resp) {
			$results[] = $resp === null
				? null
				: $this->parseClaudeResponse(['content' => [['type' => 'text', 'text' => $resp->content]]]);
		}
		return $results;
	}
```

> `buildClaudePayload()` bleibt unverändert (liefert weiter `model`/`max_tokens`/`temperature`/`system`/`messages`); das `model` darin wird vom Router-Pfad ignoriert (`modelHint=''`). `ClaudeClient` bleibt injiziert (für `extractText` in `parseClaudeResponse`).

- [ ] **Step 3e: Bestehende Inferenz-Tests anpassen (sonst rot)** — `backend/tests/Integration/RuleInferenceServiceTest.php` und `backend/tests/Integration/InferAllFromCorrectionTest.php` konstruieren `RuleInferenceService` **positional** mit `FakeClaudeClient` und treiben die Extraktion über `$claude->scriptJson(...)` → `$this->claude->messages()`. Das Einfügen des **required** `LlmRouter` an Ctor-Position 3 (direkt nach `ClaudeClient`) verschiebt die Argumente → beide Konstruktionen brechen, und das Routing geht nicht mehr über `$this->claude`. Beide `makeService()` umbauen: echten `LlmRouter` mit aufzeichnendem anon `LlmProvider` (Rolle `inference`) injizieren, der das bisher per `scriptJson` gelieferte Extraktions-JSON zurückgibt, und im Setup `routing_mode='router'` + `llm.inference.fallback_chain` + eine `llm_models`-`inference`-Row seeden. (Kernel-seitig wird der neue Arg in Task 8 ergänzt.) Lauf grün:

```bash
cd backend && composer test:integration -- --filter "RuleInferenceServiceTest|InferAllFromCorrectionTest"
```

- [ ] **Step 4: Run — expect PASS + Unit grün**

```bash
cd backend && composer test:integration -- --filter InferenceViaRouterTest && composer test:unit
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Services/RuleInferenceService.php backend/tests/Integration/Services/InferenceViaRouterTest.php
git commit -m "feat(inference): Regel-Extraktion läuft über den LlmRouter (Rolle inference)"
```

---

## Task 8: Kernel-Wiring für `RuleInferenceService` + stale Kommentar

**Files:**
- Modify: `backend/src/Http/Kernel.php` (`RuleInferenceService`-Konstruktion ~Z. 392; stale Kommentar ~Z. 199)

- [ ] **Step 1: Implement** — in der `RuleInferenceService::class => new RuleInferenceService(`-Konstruktion das `LlmRouter`-Argument an der Konstruktor-Position (direkt nach dem `ClaudeClient`-Argument) einfügen:

```php
				$this->get(\MailPilot\Llm\LlmRouter::class),
```

- [ ] **Step 2: Stale Kommentar bereinigen** — den Kommentar bei ~Z. 199, der behauptet „Solange routing_mode='direct' ist, wird LlmRouter nie gebaut", ersetzen durch:

```php
			// LlmRouter wird eager gebaut und für ALLE Rollen genutzt
			// (Spec 1, 2026-06-02). routing_mode steuert nur noch Failover.
```

- [ ] **Step 3: Run — voller Lauf grün**

```bash
cd backend && composer test:unit && composer test:integration && composer cs-check && vendor/bin/phpstan analyse
```

- [ ] **Step 4: Commit**

```bash
git add backend/src/Http/Kernel.php
git commit -m "chore(kernel): LlmRouter in RuleInferenceService verdrahten; stalen direct-Kommentar bereinigen"
```

---

## Task 9: Migration — `routing_mode='router'` für Bestand + Chains + Upgrade-Notice

**Files:**
- Create: `backend/migrations/0064_routing_failover_and_chains.sql`
- Test: `backend/tests/Integration/MigrationRoutingFailoverTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\SettingsRepository;
use MailPilot\Tests\TestCase;

final class MigrationRoutingFailoverTest extends TestCase
{
	public function testUpgradeSetsRouterAndSeedsInferenceChainAndNotice(): void
	{
		$set = new SettingsRepository($this->pdo());
		self::assertSame('router', $set->getString('llm.routing_mode', ''), 'Bestand auf router migriert');
		self::assertNotSame('', $set->getString('llm.inference.fallback_chain', ''), 'inference-Chain geseedet');
		self::assertSame('1', $set->getString('llm.routing_mode_upgrade_notice', ''), 'Upgrade-Notice-Flag gesetzt');
	}
}
```

- [ ] **Step 2: Run — expect FAIL** (Test-DB neu aufsetzen, damit die Migration läuft)

```bash
cd backend && bash bin/test-db-up.sh --down; composer test:integration -- --filter MigrationRoutingFailoverTest
```

- [ ] **Step 3: Implement** — `backend/migrations/0064_routing_failover_and_chains.sql`:

```sql
-- Spec 1 (Marc 2026-06-02) — routing_mode wird reiner Failover-Schalter.
--
-- Mit Spec 1 verliert `direct` das Failover für ALLE Rollen. Heute laufen
-- summary/draft immer über die volle Chain (haben also Failover). Damit beim
-- Upgrade kein stiller Failover-Verlust entsteht, wird der Bestand auf
-- `router` gesetzt. Ein einmaliges Notice-Flag triggert einen Admin-Hinweis.
UPDATE system_settings SET `value` = 'router' WHERE `key` = 'llm.routing_mode';

INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('llm.inference.fallback_chain',
	 '["00000000-0000-4000-8000-000000000050"]',
	 'json',
	 'Spec 1: Failover-Chain fuer inference-Calls (Regel-Extraktion). Initial nur Anthropic.'),
	('llm.draft.fallback_chain',
	 '["00000000-0000-4000-8000-000000000050"]',
	 'json',
	 'Spec 1: Failover-Chain fuer draft-Calls. Initial nur Anthropic.'),
	('llm.routing_mode_upgrade_notice',
	 '1',
	 'string',
	 'Spec 1: einmaliges Flag → Admin-Hinweis „routing_mode beim Upgrade auf router gesetzt". Vom Admin-Banner auf 0 gesetzt nach Anzeige.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
```

> **Hinweis:** Die `llm_models`-Zeile `role='inference'` existiert bereits (Migration `0052_llm_models.sql`, Anthropic Haiku) — keine neue Modell-Zeile nötig. Die Anthropic-Provider-UUID `...050` stammt aus Migration `0050_llm_providers.sql`.

- [ ] **Step 4: Run — expect PASS**

```bash
cd backend && bash bin/test-db-up.sh --down; composer test:integration -- --filter MigrationRoutingFailoverTest
```

- [ ] **Step 5: Commit**

```bash
git add backend/migrations/0064_routing_failover_and_chains.sql backend/tests/Integration/MigrationRoutingFailoverTest.php
git commit -m "feat(migration): 0064 routing_mode=router für Bestand + inference/draft-Chains + Upgrade-Notice"
```

---

## Task 10: Admin-UI — Routing-Banner/Labels + einmaliger Upgrade-Hinweis

**Files:**
- Modify: `admin/src/Views/llm/routing.php` (Banner-/Erklärtexte: „Failover-Schalter" für alle Rollen)
- Modify: `admin/src/Controllers/LlmController.php` (`showRouting()`: Upgrade-Notice lesen + nach Anzeige auf `0` setzen)

- [ ] **Step 1: Banner-Text korrigieren** — in `routing.php` die direct/router-Erklärtexte so anpassen, dass sie **alle Rollen** (score/summary/draft/inference) betreffen und `direct` als „nur Primary, kein Failover" / `router` als „ganze Failover-Chain" beschreiben (nicht mehr „score-only"). Den Abschnitt „Routing-Modus" + den oberen Status-Banner entsprechend umformulieren (die Texte erwähnen aktuell nur `score`/`inference`).

- [ ] **Step 1b: Hartkodierte Rollen-Listen auf `LlmRouter::ROLES` umstellen (D7)** — `admin/src/Controllers/LlmController.php` hält die Liste `['score','summary','draft','inference']` an **drei** Stellen hart (in `showRouting()`, `saveRouting()` und der Rollen-Validierung). Durch `\MailPilot\Llm\LlmRouter::ROLES` ersetzen, damit Spec 2 die 5. Rolle `match` nur an EINER Stelle ergänzen muss. (Die separate `['score','inference']`-Liste in `showGolden()` ist Golden-Set-spezifisch und bleibt unangetastet.)

- [ ] **Step 2: Upgrade-Hinweis** — in `LlmController::showRouting()` das Flag `llm.routing_mode_upgrade_notice` via `SettingsRepository::getString(...)` lesen; ist es `'1'`, eine Banner-Variable an die View geben („`routing_mode` wurde beim Upgrade auf `router` gesetzt — hier prüfen.") **und** das Flag via `set('llm.routing_mode_upgrade_notice', '0')` zurücksetzen (einmalig). In `routing.php` die Variable rendern, wenn gesetzt.

- [ ] **Step 3: CS-Check + manueller Render-Smoke** — Admin-Routing-Seite lokal aufrufen, prüfen: keine PHP-Fehler, Hinweis erscheint einmalig und ist beim erneuten Laden weg.

```bash
cd backend && composer cs-check
```

- [ ] **Step 4: Commit**

```bash
git add admin/src/Views/llm/routing.php admin/src/Controllers/LlmController.php
git commit -m "feat(admin): Routing-Banner als Failover-Schalter (alle Rollen) + einmaliger Upgrade-Hinweis"
```

---

## Abschluss

- [ ] **Voller Testlauf + Linting grün:**

```bash
cd backend && composer test:unit && composer test:integration && composer cs-check && vendor/bin/phpstan analyse
```

- [ ] **Finaler Review** (subagent-driven-development: abschließender Code-Reviewer über die gesamte Implementierung), dann `superpowers:finishing-a-development-branch`.

## Spec-Abdeckung (Self-Review)

| Spec-Punkt | Task |
|---|---|
| D1 llm_models = einzige Wahrheit | T2/T5/T6/T7 (alle Rollen via Router, `modelHint=''`) |
| D2 routing_mode = Failover-Schalter | T2 |
| D3 score immer über Router | T5 |
| D4 inference über Router (+ completeBatch) | T4, T7 |
| D5 Prompt-`model` raus / Fallback aus llm_models / modelId-Garantie | T1, T3, T6 (UI bereits modellfrei) |
| D6 inference-Chain/Modell sichergestellt | T9 (Modell-Row existiert bereits) |
| D7 Rollen-Erweiterbarkeit (`ROLES`) | T2 |
| D8 Upgrade-Migration + Admin-Hinweis + stale Kommentar | T8, T9, T10 |

**Bewusst NICHT im Plan (Spec 2):** `match`-Rolle, Match-Score, Lern-Loop, Cache-Invalidierung bei Korrektur.
