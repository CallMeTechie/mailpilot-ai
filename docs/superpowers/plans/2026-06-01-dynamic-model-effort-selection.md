# Dynamische Multi-Provider-Modell- & Effort-Auswahl — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modelle aller LLM-Provider werden live entdeckt und sind samt Effort-Level per Dropdown wählbar (nichts hardcodiert, neue Modelle erscheinen automatisch); Summary/Draft laufen über den Router auf Claude Opus 4.8 mit `effort=medium`.

**Architecture:** Jeder `LlmProvider` bekommt `listModels()` (live gegen seinen `/models`-Endpoint, 10s-Timeout). Ein `ModelCatalogService` upsertet das Ergebnis in `llm_model_catalog` (Button: 1 Provider; Worker: Voll-Sweep + Bootstrap). Admin-Dropdowns lesen aus dem Katalog; die Auswahl (model_id + effort) lebt in `llm_models`. Summary/Draft werden vom Anthropic-Direct-Pfad auf den `LlmRouter` umgestellt; `AnthropicProvider`/`OpenAiProvider` emittieren `output_config.effort` bzw. `reasoning_effort`.

**Tech Stack:** PHP 8.4 (`declare(strict_types=1)`, PSR-12 mit **Tabs**), PDO Prepared Statements (kein Query-Builder), MariaDB 11.4, PHPUnit 11, Vanilla-JS server-rendered Admin, Docker.

**Konventionen (verbindlich):**
- Commits: **als CallMeTechie**, **kein** `Co-Authored-By` (Marc-Regel — überschreibt Default). Conventional Commits.
- Migrationen: nummerierte SQL-Dateien in `backend/migrations/`, angewandt via `bin/migrate.php` (Tracking in `schema_migrations`). Nächste freie Nummer: **0060**.
- Tests: `composer test:unit` (kein DB), `composer test:integration` (MariaDB via `bin/test-db-up.sh`). PHPStan + PHP-CS-Fixer müssen sauber sein.
- Spec: `docs/superpowers/specs/2026-06-01-dynamic-model-effort-selection-design.md` (v3).

**Effort-Level-Konstante (eine Quelle, kein Streuen):** Die dokumentierte API-Gesamtmenge ist `['low','medium','high','xhigh','max']`. Sie wird als `public const EFFORT_LEVELS_ALL` in `ModelCatalogService` gehalten und nur als Validierungs-Fallback genutzt; die *tatsächlich* angebotenen Levels kommen pro Modell aus dem Katalog.

---

## Dateistruktur

**Neu:**
- `backend/src/Llm/ModelDescriptor.php` — DTO eines entdeckten Modells.
- `backend/src/Repositories/LlmModelCatalogRepository.php` — PDO-Zugriff auf `llm_model_catalog`.
- `backend/src/Llm/ModelCatalogService.php` — Discovery-Orchestrierung (refreshProvider/refreshAll).
- `backend/migrations/0060_llm_model_catalog.sql` — Katalog-Tabelle.
- `backend/migrations/0061_llm_models_effort.sql` — Effort-Spalte.
- `backend/migrations/0062_opus_4_8_summary_draft.sql` — Opus-4.8-Daten + Pricing.
- `backend/tests/Unit/Llm/ModelDescriptorTest.php`
- `backend/tests/Unit/Llm/Providers/AnthropicListModelsTest.php`
- `backend/tests/Unit/Llm/Providers/OpenAiListModelsTest.php`
- `backend/tests/Unit/Llm/Providers/GeminiListModelsTest.php`
- `backend/tests/Unit/Llm/Providers/MistralListModelsTest.php`
- `backend/tests/Unit/Llm/Providers/OpenAiCompatibleListModelsTest.php`
- `backend/tests/Unit/Llm/Providers/EffortEmissionTest.php` (Unit — statische `buildPayload`)
- `backend/tests/Integration/Llm/ModelCatalogServiceTest.php` (Integration — Projekt mockt nicht)
- `backend/tests/Integration/Llm/RouterEffortTest.php` (Integration)
- `backend/tests/Integration/Services/SummaryDraftRouterTest.php` (Integration)
- `backend/tests/Integration/Llm/LlmModelCatalogRepositoryTest.php`
- `backend/tests/Integration/Llm/EffortRoundtripTest.php`

**Geändert:**
- `backend/src/Llm/LlmProvider.php` — Interface += `listModels()`.
- `backend/src/Llm/Providers/{Anthropic,OpenAi,Gemini,Mistral,OpenAiCompatible}Provider.php` — `listModels()` + `parseModelsResponse()`; Anthropic/OpenAi zusätzlich Effort-Emission in `buildPayload()`.
- `backend/src/Llm/NormalizedRequest.php` — += `?string $effort`.
- `backend/src/Llm/LlmRouter.php` — Effort aus `llm_models`-Zeile durchreichen.
- `backend/src/Services/MailSummaryService.php` — auf Router umstellen + Safety-Net.
- `backend/src/Services/ReplyDraftService.php` — auf Router umstellen + Safety-Net.
- `backend/src/Http/Kernel.php` — Wiring: Catalog-Repo/Service; Summary/Draft erhalten Router + ClaudeProvider (Fallback).
- Zentraler HTTP-Exception-Handler — Router-Overload → 503+Retry-After.
- `backend/bin/worker.php` — Katalog-Bootstrap beim Start + täglich.
- `admin/src/Controllers/LlmController.php` — `refreshModels()` + `saveModel()` um model_id/effort erweitern.
- `admin/src/Views/llm/edit.php` — Modell+Effort-Dropdowns + „Aktualisieren"-Button + Score-Label.
- `admin/src/Views/prompt_edit.php` — hardcodierte Modell-`<option>` entfernen, Hinweis auf Routing.
- `admin/public/index.php` — Route für `refreshModels`.
- `backend/config/config.php` + `config.example.php` — `model_summary`/`model_reply` → opus-4-8 + Bedrock-Map.
- Docs: `CLAUDE.md`, `docs/PROMPTS.md`, `docs/BEDROCK.md`, `README.md`.

---

# Phase A — Discovery & Katalog

### Task A1: `ModelDescriptor` Value-Object

**Files:**
- Create: `backend/src/Llm/ModelDescriptor.php`
- Test: `backend/tests/Unit/Llm/ModelDescriptorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm;

use MailPilot\Llm\ModelDescriptor;
use PHPUnit\Framework\TestCase;

final class ModelDescriptorTest extends TestCase
{
	public function testHoldsFieldsAndDefaults(): void
	{
		$d = new ModelDescriptor(
			modelId: 'claude-opus-4-8',
			displayName: 'Claude Opus 4.8',
			effortLevels: ['low', 'medium', 'high', 'xhigh', 'max'],
		);
		self::assertSame('claude-opus-4-8', $d->modelId);
		self::assertSame(['low', 'medium', 'high', 'xhigh', 'max'], $d->effortLevels);
		self::assertNull($d->maxOutputTokens);
		self::assertNull($d->maxContextTokens);
		self::assertNull($d->releasedAt);
	}

	public function testToRowEncodesEffortLevelsAsJson(): void
	{
		$d = new ModelDescriptor('m', 'M', ['low'], 100, 200, '2026-01-01T00:00:00Z');
		$row = $d->toRow();
		self::assertSame('["low"]', $row['effort_levels']);
		self::assertSame(100, $row['max_output_tokens']);
		self::assertSame('2026-01-01T00:00:00Z', $row['released_at']);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/ModelDescriptorTest.php`
Expected: FAIL — `Class "MailPilot\Llm\ModelDescriptor" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Llm;

/**
 * Ein vom Provider entdecktes Modell (Discovery-Layer). Reines DTO.
 * effortLevels = Teilmenge von low|medium|high|xhigh|max ([] = kein Effort).
 * Token-Felder/releasedAt sind optional (Provider liefert sie nicht immer).
 */
final class ModelDescriptor
{
	/**
	 * @param list<string> $effortLevels
	 */
	public function __construct(
		public readonly string  $modelId,
		public readonly string  $displayName,
		public readonly array   $effortLevels = [],
		public readonly ?int    $maxOutputTokens = null,
		public readonly ?int    $maxContextTokens = null,
		public readonly ?string $releasedAt = null,
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toRow(): array
	{
		return [
			'model_id'           => $this->modelId,
			'display_name'       => $this->displayName,
			'effort_levels'      => json_encode($this->effortLevels, JSON_UNESCAPED_UNICODE),
			'max_output_tokens'  => $this->maxOutputTokens,
			'max_context_tokens' => $this->maxContextTokens,
			'released_at'        => $this->releasedAt,
		];
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/ModelDescriptorTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/ModelDescriptor.php backend/tests/Unit/Llm/ModelDescriptorTest.php
git commit -m "feat(llm): add ModelDescriptor value object for model discovery"
```

---

### Task A2: `listModels()` im Interface + AnthropicProvider (Effort-Auto-Erkennung)

**Files:**
- Modify: `backend/src/Llm/LlmProvider.php`
- Modify: `backend/src/Llm/Providers/AnthropicProvider.php`
- Test: `backend/tests/Unit/Llm/Providers/AnthropicListModelsTest.php`

- [ ] **Step 1: Write the failing test (pure parse method against fixture)**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\AnthropicProvider;
use PHPUnit\Framework\TestCase;

final class AnthropicListModelsTest extends TestCase
{
	public function testParsesEffortLevelsAndStripsUnsupported(): void
	{
		$json = [
			'data' => [
				[
					'id' => 'claude-opus-4-8',
					'display_name' => 'Claude Opus 4.8',
					'created_at' => '2026-05-01T00:00:00Z',
					'max_tokens' => 128000,
					'max_input_tokens' => 1000000,
					'capabilities' => ['effort' => [
						'supported' => true,
						'low' => ['supported' => true],
						'medium' => ['supported' => true],
						'high' => ['supported' => true],
						'xhigh' => ['supported' => true],
						'max' => ['supported' => true],
					]],
				],
				[
					'id' => 'claude-haiku-4-5-20251001',
					'display_name' => 'Claude Haiku 4.5',
					'created_at' => '2025-10-01T00:00:00Z',
					'max_tokens' => 64000,
					'max_input_tokens' => 200000,
					'capabilities' => ['effort' => ['supported' => false]],
				],
			],
			'has_more' => false,
			'last_id' => 'claude-haiku-4-5-20251001',
		];

		$models = AnthropicProvider::parseModelsResponse($json);

		self::assertCount(2, $models);
		self::assertSame('claude-opus-4-8', $models[0]->modelId);
		self::assertSame(['low', 'medium', 'high', 'xhigh', 'max'], $models[0]->effortLevels);
		self::assertSame(128000, $models[0]->maxOutputTokens);
		self::assertSame([], $models[1]->effortLevels, 'Haiku without effort support → []');
	}

	public function testZeroTokensBecomeNull(): void
	{
		$json = ['data' => [[
			'id' => 'x', 'display_name' => 'X', 'max_tokens' => 0, 'max_input_tokens' => 0,
			'capabilities' => ['effort' => ['supported' => false]],
		]]];
		$models = AnthropicProvider::parseModelsResponse($json);
		self::assertNull($models[0]->maxOutputTokens);
		self::assertNull($models[0]->maxContextTokens);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/AnthropicListModelsTest.php`
Expected: FAIL — `Call to undefined method ...::parseModelsResponse()`.

- [ ] **Step 3: Add `listModels()` to the interface**

In `backend/src/Llm/LlmProvider.php` Import + Methode ergänzen (nach `complete()`):

```php
use MailPilot\Llm\ModelDescriptor;
```
```php
	/**
	 * Entdeckt die beim Provider verfuegbaren (Chat-faehigen) Modelle live.
	 * Wirft LlmUnavailableException bei Transport-/Auth-Fehlern, damit der
	 * Katalog-Refresh den Provider ueberspringen kann.
	 *
	 * @return list<ModelDescriptor>
	 */
	public function listModels(): array;
```

- [ ] **Step 4: Implement in `AnthropicProvider`**

Konstante + zwei Methoden ergänzen; `resolveApiKey()` und die `/v1`-Normalisierung aus `complete()` wiederverwenden:

```php
	private const DISCOVERY_TIMEOUT_S = 10;
	private const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];
```
```php
	public function listModels(): array
	{
		$row = $this->repo->findByKind('anthropic');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver Anthropic-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://api.anthropic.com');
		if (!preg_match('#/v\d+/?$#', $baseUrl)) {
			$baseUrl = rtrim($baseUrl, '/') . '/v1';
		}

		$out     = [];
		$afterId = null;
		do {
			$url = rtrim($baseUrl, '/') . '/models?limit=1000'
				. ($afterId !== null ? '&after_id=' . rawurlencode($afterId) : '');
			$json = $this->httpGetJson($url, [
				'x-api-key: ' . $apiKey,
				'anthropic-version: ' . self::ANTHROPIC_VERSION_DEFAULT,
			]);
			foreach (self::parseModelsResponse($json) as $d) {
				$out[] = $d;
			}
			$afterId = ($json['has_more'] ?? false) === true ? ($json['last_id'] ?? null) : null;
		} while (is_string($afterId) && $afterId !== '');

		return $out;
	}

	/**
	 * @param  array<string,mixed> $json
	 * @return list<ModelDescriptor>
	 */
	public static function parseModelsResponse(array $json): array
	{
		$out = [];
		foreach (($json['data'] ?? []) as $m) {
			if (!is_array($m) || !isset($m['id'])) {
				continue;
			}
			$effortCap = $m['capabilities']['effort'] ?? [];
			$levels = [];
			if (is_array($effortCap) && ($effortCap['supported'] ?? false) === true) {
				foreach (self::EFFORT_LEVELS as $lvl) {
					if (($effortCap[$lvl]['supported'] ?? false) === true) {
						$levels[] = $lvl;
					}
				}
			}
			$maxOut = (int)($m['max_tokens'] ?? 0);
			$maxCtx = (int)($m['max_input_tokens'] ?? 0);
			$out[] = new ModelDescriptor(
				modelId:          (string)$m['id'],
				displayName:      (string)($m['display_name'] ?? $m['id']),
				effortLevels:     $levels,
				maxOutputTokens:  $maxOut > 0 ? $maxOut : null,
				maxContextTokens: $maxCtx > 0 ? $maxCtx : null,
				releasedAt:       isset($m['created_at']) ? (string)$m['created_at'] : null,
			);
		}
		return $out;
	}

	/**
	 * GET → decoded JSON. Wirft LlmUnavailableException bei Transport-/HTTP-Fehler.
	 *
	 * @param  list<string> $headers
	 * @return array<string,mixed>
	 */
	private function httpGetJson(string $url, array $headers): array
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::DISCOVERY_TIMEOUT_S,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
		]);
		$resp   = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($err !== '' || $status < 200 || $status >= 300 || !is_string($resp)) {
			throw new LlmUnavailableException(sprintf(
				'Anthropic listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
			));
		}
		$decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
		return is_array($decoded) ? $decoded : [];
	}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/AnthropicListModelsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Llm/LlmProvider.php backend/src/Llm/Providers/AnthropicProvider.php backend/tests/Unit/Llm/Providers/AnthropicListModelsTest.php
git commit -m "feat(llm): add Anthropic model discovery with per-model effort detection"
```

---

### Task A3: OpenAiProvider `listModels()` + Chat-Filter

**Files:**
- Modify: `backend/src/Llm/Providers/OpenAiProvider.php`
- Test: `backend/tests/Unit/Llm/Providers/OpenAiListModelsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\OpenAiProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiListModelsTest extends TestCase
{
	public function testKeepsChatModelsDropsEmbeddingsAndAudio(): void
	{
		$json = ['data' => [
			['id' => 'gpt-4o'],
			['id' => 'o3-mini'],
			['id' => 'chatgpt-4o-latest'],
			['id' => 'text-embedding-3-large'],
			['id' => 'whisper-1'],
			['id' => 'dall-e-3'],
			['id' => 'omni-moderation-latest'],
			['id' => 'davinci-002'],
			['id' => 'gpt-4o-transcribe'],
			['id' => 'gpt-4o-audio-preview'],
		]];
		$models = OpenAiProvider::parseModelsResponse($json);
		$ids = array_map(static fn($m) => $m->modelId, $models);

		self::assertContains('gpt-4o', $ids);
		self::assertContains('o3-mini', $ids);
		self::assertContains('chatgpt-4o-latest', $ids);
		self::assertNotContains('text-embedding-3-large', $ids);
		self::assertNotContains('whisper-1', $ids);
		self::assertNotContains('dall-e-3', $ids);
		self::assertNotContains('omni-moderation-latest', $ids);
		self::assertNotContains('davinci-002', $ids);
		// „Substring schlägt Prefix": gpt-4o-* Nicht-Chat-Varianten raus.
		self::assertNotContains('gpt-4o-transcribe', $ids);
		self::assertNotContains('gpt-4o-audio-preview', $ids);
	}

	public function testChatModelsOfferStandardEffortLevels(): void
	{
		$models = OpenAiProvider::parseModelsResponse(['data' => [['id' => 'gpt-4o']]]);
		self::assertSame(['low', 'medium', 'high'], $models[0]->effortLevels);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/OpenAiListModelsTest.php`
Expected: FAIL — undefined method `parseModelsResponse`.

- [ ] **Step 3: Implement in `OpenAiProvider`**

Konstanten + Methoden ergänzen (`resolveApiKey()` wiederverwenden), Import `use MailPilot\Llm\ModelDescriptor;`:

```php
	private const DISCOVERY_TIMEOUT_S = 10;
	// reasoning_effort-Vertrag von OpenAI. Wartbare API-Heuristik, keine
	// versions-spezifische Modell-Hardcodierung.
	private const CHAT_PREFIXES = ['gpt-', 'o1', 'o3', 'o4', 'chatgpt-'];
	private const NON_CHAT_SUBSTR = ['embedding', 'whisper', 'tts', 'transcribe', 'realtime', 'search', 'dall-e', 'moderation', 'audio', 'image', 'davinci', 'babbage'];
```
```php
	public function listModels(): array
	{
		$row = $this->repo->findByKind('openai');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver OpenAI-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://api.openai.com');
		$url     = rtrim($baseUrl, '/') . '/v1/models';

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::DISCOVERY_TIMEOUT_S,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
		]);
		$resp   = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($err !== '' || $status < 200 || $status >= 300 || !is_string($resp)) {
			throw new LlmUnavailableException(sprintf(
				'OpenAI listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
			));
		}
		$decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
		return self::parseModelsResponse(is_array($decoded) ? $decoded : []);
	}

	/**
	 * @param  array<string,mixed> $json
	 * @return list<ModelDescriptor>
	 */
	public static function parseModelsResponse(array $json): array
	{
		$out = [];
		foreach (($json['data'] ?? []) as $m) {
			if (!is_array($m) || !isset($m['id'])) {
				continue;
			}
			$id = (string)$m['id'];
			if (!self::isChatModel($id)) {
				continue;
			}
			$out[] = new ModelDescriptor(
				modelId:      $id,
				displayName:  $id,
				effortLevels: ['low', 'medium', 'high'],
			);
		}
		return $out;
	}

	private static function isChatModel(string $id): bool
	{
		$lower = strtolower($id);
		foreach (self::NON_CHAT_SUBSTR as $bad) {
			if (str_contains($lower, $bad)) {
				return false;
			}
		}
		foreach (self::CHAT_PREFIXES as $p) {
			if (str_starts_with($lower, $p)) {
				return true;
			}
		}
		return false;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/OpenAiListModelsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/Providers/OpenAiProvider.php backend/tests/Unit/Llm/Providers/OpenAiListModelsTest.php
git commit -m "feat(llm): add OpenAI model discovery with chat-model filter"
```

---

### Task A4: GeminiProvider `listModels()` (Filter `generateContent`)

**Files:**
- Modify: `backend/src/Llm/Providers/GeminiProvider.php`
- Test: `backend/tests/Unit/Llm/Providers/GeminiListModelsTest.php`

> **Verifizieren vor Implementierung:** Felder der Gemini-`models.list`-Antwort (`name`, `displayName`, `supportedGenerationMethods[]`, `inputTokenLimit`, `outputTokenLimit`). Quelle: <https://ai.google.dev/api/models#method:-models.list>

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\GeminiProvider;
use PHPUnit\Framework\TestCase;

final class GeminiListModelsTest extends TestCase
{
	public function testKeepsOnlyGenerateContentModelsAndStripsPrefix(): void
	{
		$json = ['models' => [
			[
				'name' => 'models/gemini-2.5-pro',
				'displayName' => 'Gemini 2.5 Pro',
				'supportedGenerationMethods' => ['generateContent', 'countTokens'],
				'outputTokenLimit' => 65536,
				'inputTokenLimit' => 1048576,
			],
			[
				'name' => 'models/text-embedding-004',
				'displayName' => 'Text Embedding 004',
				'supportedGenerationMethods' => ['embedContent'],
			],
		]];
		$models = GeminiProvider::parseModelsResponse($json);

		self::assertCount(1, $models);
		self::assertSame('gemini-2.5-pro', $models[0]->modelId);
		self::assertSame([], $models[0]->effortLevels);
		self::assertSame(65536, $models[0]->maxOutputTokens);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/GeminiListModelsTest.php`
Expected: FAIL — undefined method.

- [ ] **Step 3: Implement in `GeminiProvider`**

Konstante + Methoden, Import `use MailPilot\Llm\ModelDescriptor;`:

```php
	private const DISCOVERY_TIMEOUT_S = 10;
```
```php
	public function listModels(): array
	{
		$row = $this->repo->findByKind('gemini');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver Gemini-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://generativelanguage.googleapis.com');
		$url     = sprintf('%s/v1beta/models?pageSize=1000&key=%s', rtrim($baseUrl, '/'), rawurlencode($apiKey));

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::DISCOVERY_TIMEOUT_S,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
		]);
		$resp   = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($err !== '' || $status < 200 || $status >= 300 || !is_string($resp)) {
			throw new LlmUnavailableException(sprintf(
				'Gemini listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
			));
		}
		$decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
		return self::parseModelsResponse(is_array($decoded) ? $decoded : []);
	}

	/**
	 * @param  array<string,mixed> $json
	 * @return list<ModelDescriptor>
	 */
	public static function parseModelsResponse(array $json): array
	{
		$out = [];
		foreach (($json['models'] ?? []) as $m) {
			if (!is_array($m) || !isset($m['name'])) {
				continue;
			}
			$methods = $m['supportedGenerationMethods'] ?? [];
			if (!is_array($methods) || !in_array('generateContent', $methods, true)) {
				continue;
			}
			$id     = (string)preg_replace('#^models/#', '', (string)$m['name']);
			$maxOut = (int)($m['outputTokenLimit'] ?? 0);
			$maxCtx = (int)($m['inputTokenLimit'] ?? 0);
			$out[] = new ModelDescriptor(
				modelId:          $id,
				displayName:      (string)($m['displayName'] ?? $id),
				effortLevels:     [],
				maxOutputTokens:  $maxOut > 0 ? $maxOut : null,
				maxContextTokens: $maxCtx > 0 ? $maxCtx : null,
			);
		}
		return $out;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/GeminiListModelsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/Providers/GeminiProvider.php backend/tests/Unit/Llm/Providers/GeminiListModelsTest.php
git commit -m "feat(llm): add Gemini model discovery (generateContent filter)"
```

---

### Task A5: MistralProvider `listModels()` (Filter `completion_chat`)

**Files:**
- Modify: `backend/src/Llm/Providers/MistralProvider.php`
- Test: `backend/tests/Unit/Llm/Providers/MistralListModelsTest.php`

> **Verifizieren:** Mistral `GET /v1/models` → `data[].id`, `data[].capabilities.completion_chat` (bool). Quelle: <https://docs.mistral.ai/api/#tag/models>

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\MistralProvider;
use PHPUnit\Framework\TestCase;

final class MistralListModelsTest extends TestCase
{
	public function testKeepsChatCapableOnly(): void
	{
		$json = ['data' => [
			['id' => 'mistral-large-latest', 'capabilities' => ['completion_chat' => true]],
			['id' => 'mistral-embed', 'capabilities' => ['completion_chat' => false]],
		]];
		$models = MistralProvider::parseModelsResponse($json);
		$ids = array_map(static fn($m) => $m->modelId, $models);
		self::assertSame(['mistral-large-latest'], $ids);
		self::assertSame([], $models[0]->effortLevels);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/MistralListModelsTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement in `MistralProvider`**

Konstante + Methoden, Import `use MailPilot\Llm\ModelDescriptor;`:

```php
	private const DISCOVERY_TIMEOUT_S = 10;
```
```php
	public function listModels(): array
	{
		$row = $this->repo->findByKind('mistral');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver Mistral-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://api.mistral.ai');
		$url     = rtrim($baseUrl, '/') . '/v1/models';

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::DISCOVERY_TIMEOUT_S,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
		]);
		$resp   = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($err !== '' || $status < 200 || $status >= 300 || !is_string($resp)) {
			throw new LlmUnavailableException(sprintf(
				'Mistral listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
			));
		}
		$decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
		return self::parseModelsResponse(is_array($decoded) ? $decoded : []);
	}

	/**
	 * @param  array<string,mixed> $json
	 * @return list<ModelDescriptor>
	 */
	public static function parseModelsResponse(array $json): array
	{
		$out = [];
		foreach (($json['data'] ?? []) as $m) {
			if (!is_array($m) || !isset($m['id'])) {
				continue;
			}
			if (($m['capabilities']['completion_chat'] ?? false) !== true) {
				continue;
			}
			$id = (string)$m['id'];
			$out[] = new ModelDescriptor(
				modelId:      $id,
				displayName:  (string)($m['name'] ?? $id),
				effortLevels: [],
			);
		}
		return $out;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/MistralListModelsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/Providers/MistralProvider.php backend/tests/Unit/Llm/Providers/MistralListModelsTest.php
git commit -m "feat(llm): add Mistral model discovery (completion_chat filter)"
```

---

### Task A6: OpenAiCompatibleProvider `listModels()` (alle, lokal kuratiert)

**Files:**
- Modify: `backend/src/Llm/Providers/OpenAiCompatibleProvider.php`
- Test: `backend/tests/Unit/Llm/Providers/OpenAiCompatibleListModelsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\OpenAiCompatibleProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiCompatibleListModelsTest extends TestCase
{
	public function testReturnsAllModelsNoEffort(): void
	{
		$json = ['data' => [['id' => 'qwen3:32b'], ['id' => 'llama3.1:8b']]];
		$models = OpenAiCompatibleProvider::parseModelsResponse($json);
		$ids = array_map(static fn($m) => $m->modelId, $models);
		self::assertSame(['qwen3:32b', 'llama3.1:8b'], $ids);
		self::assertSame([], $models[0]->effortLevels);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/OpenAiCompatibleListModelsTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement in `OpenAiCompatibleProvider`**

Konstante + Methoden, Import `use MailPilot\Llm\ModelDescriptor;`:

```php
	private const DISCOVERY_TIMEOUT_S = 10;
```
```php
	public function listModels(): array
	{
		$row = $this->repo->findByKind('openai_compatible');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver openai_compatible-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? '');
		if ($baseUrl === '') {
			throw new LlmUnavailableException('openai_compatible-Provider hat keinen base_url');
		}
		$url = rtrim($baseUrl, '/') . '/v1/models';

		$headers = ['Content-Type: application/json'];
		if ($apiKey !== '') {
			$headers[] = 'Authorization: Bearer ' . $apiKey;
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::DISCOVERY_TIMEOUT_S,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_HTTPHEADER     => $headers,
		]);
		$resp   = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($err !== '' || $status < 200 || $status >= 300 || !is_string($resp)) {
			throw new LlmUnavailableException(sprintf(
				'openai_compatible listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
			));
		}
		$decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
		return self::parseModelsResponse(is_array($decoded) ? $decoded : []);
	}

	/**
	 * @param  array<string,mixed> $json
	 * @return list<ModelDescriptor>
	 */
	public static function parseModelsResponse(array $json): array
	{
		$out = [];
		foreach (($json['data'] ?? []) as $m) {
			if (!is_array($m) || !isset($m['id'])) {
				continue;
			}
			$id = (string)$m['id'];
			$out[] = new ModelDescriptor(modelId: $id, displayName: $id, effortLevels: []);
		}
		return $out;
	}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/OpenAiCompatibleListModelsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/Providers/OpenAiCompatibleProvider.php backend/tests/Unit/Llm/Providers/OpenAiCompatibleListModelsTest.php
git commit -m "feat(llm): add OpenAI-compatible (local) model discovery"
```

---

### Task A7: Migration `0060_llm_model_catalog.sql`

**Files:**
- Create: `backend/migrations/0060_llm_model_catalog.sql`

- [ ] **Step 1: Write the migration**

```sql
-- 0060_llm_model_catalog.sql
--
-- Discovery-Katalog: pro Provider live entdeckte Modelle (speist nur die
-- Admin-Dropdowns; NICHT die Quelle der Wahrheit fuer Routing/Pricing —
-- das bleibt llm_models). available=0 = beim letzten erfolgreichen Refresh
-- nicht mehr gesehen.

CREATE TABLE llm_model_catalog (
	id                 CHAR(36) NOT NULL PRIMARY KEY,
	provider_id        CHAR(36) NOT NULL,
	model_id           VARCHAR(160) NOT NULL,
	display_name       VARCHAR(190) NOT NULL,
	effort_levels      JSON NULL,
	max_output_tokens  INT NULL,
	max_context_tokens INT NULL,
	released_at        DATETIME NULL,
	available          TINYINT(1) NOT NULL DEFAULT 1,
	last_seen_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	discovered_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	UNIQUE KEY uq_catalog_provider_model (provider_id, model_id),
	KEY idx_catalog_provider_avail (provider_id, available),
	CONSTRAINT fk_catalog_provider FOREIGN KEY (provider_id)
		REFERENCES llm_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Apply against the test DB**

Run: `cd backend && bash bin/test-db-up.sh && source /tmp/mailpilot-test-db.env && php bin/migrate.php`
Expected: `✓ 0060_llm_model_catalog` in der Ausgabe, keine Fehler.

- [ ] **Step 3: Commit**

```bash
git add backend/migrations/0060_llm_model_catalog.sql
git commit -m "feat(db): migration 0060 — llm_model_catalog discovery table"
```

---

### Task A8: `LlmModelCatalogRepository`

**Files:**
- Create: `backend/src/Repositories/LlmModelCatalogRepository.php`
- Test: `backend/tests/Integration/Llm/LlmModelCatalogRepositoryTest.php`

> **Konventionen (Review-verifiziert):** Integrationstests erweitern `MailPilot\Tests\TestCase`, holen die DB via **`$this->pdo()`** (Instanzmethode, NICHT `self::pdo()`), nutzen `$this->truncateAll()` in `setUp()` und seeden `llm_providers`/`llm_models`/`system_settings` per direktem SQL (Vorbild: `tests/Integration/LlmRouterFailoverTest.php`). UUIDs via **`MailPilot\Util\Uuid::v4()`** (es gibt **kein** `ramsey/uuid`). `llm_model_catalog` steht **nicht** in `truncateAll()` → im Test manuell aufräumen. Test-Namespace: `MailPilot\Tests\Integration\Llm` (autoload-dev `MailPilot\Tests\ → tests/`).

- [ ] **Step 1: Write the failing integration test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Llm\ModelDescriptor;
use MailPilot\Repositories\LlmModelCatalogRepository;
use MailPilot\Tests\TestCase;

/**
 * @group integration
 */
final class LlmModelCatalogRepositoryTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c1';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		// llm_model_catalog steht nicht in truncateAll → Test-Rows + Test-Provider
		// manuell aufraeumen und einen minimalen Provider seedeen (FK-Ziel).
		$pdo->exec("DELETE FROM llm_model_catalog WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			 VALUES (?, 'TestCatalog', 'anthropic', 'http://catalog.test', 0, 1, 10)"
		)->execute([self::PROVIDER_ID]);
	}

	public function testUpsertMarkStaleAndDatetime(): void
	{
		$repo = new LlmModelCatalogRepository($this->pdo());

		$repo->upsert(self::PROVIDER_ID, new ModelDescriptor(
			'claude-opus-4-8', 'Opus 4.8', ['low', 'high'], 128000, 1000000, '2026-05-01T00:00:00Z',
		));
		$repo->upsert(self::PROVIDER_ID, new ModelDescriptor('claude-haiku-4-5-20251001', 'Haiku 4.5', []));

		self::assertCount(2, $repo->listByProvider(self::PROVIDER_ID));

		// ISO-8601 created_at wurde in ein DATETIME-taugliches Format konvertiert.
		$opus = $repo->find(self::PROVIDER_ID, 'claude-opus-4-8');
		self::assertNotNull($opus);
		self::assertSame('2026-05-01 00:00:00', (string)$opus['released_at']);
		self::assertSame(['low', 'high'], json_decode((string)$opus['effort_levels'], true));

		// Re-Discovery sieht Haiku nicht mehr → available=0.
		$repo->markStale(self::PROVIDER_ID, ['claude-opus-4-8']);
		$available = $repo->listByProvider(self::PROVIDER_ID, availableOnly: true);
		self::assertCount(1, $available);
		self::assertSame('claude-opus-4-8', $available[0]['model_id']);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && source /tmp/mailpilot-test-db.env && vendor/bin/phpunit tests/Integration/Llm/LlmModelCatalogRepositoryTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the repository**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Llm\ModelDescriptor;
use MailPilot\Util\Uuid;
use PDO;

/**
 * Zugriff auf llm_model_catalog. Upsert via ON DUPLICATE KEY; markStale setzt
 * available=0 fuer Modelle, die beim letzten erfolgreichen Refresh fehlten.
 */
final class LlmModelCatalogRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	public function upsert(string $providerId, ModelDescriptor $d): void
	{
		$row = $d->toRow();
		$stmt = $this->db->prepare(
			'INSERT INTO llm_model_catalog
				(id, provider_id, model_id, display_name, effort_levels,
				 max_output_tokens, max_context_tokens, released_at, available, last_seen_at)
			 VALUES (:id, :pid, :mid, :dn, :el, :mo, :mc, :ra, 1, CURRENT_TIMESTAMP(3))
			 ON DUPLICATE KEY UPDATE
				display_name = VALUES(display_name),
				effort_levels = VALUES(effort_levels),
				max_output_tokens = VALUES(max_output_tokens),
				max_context_tokens = VALUES(max_context_tokens),
				released_at = VALUES(released_at),
				available = 1,
				last_seen_at = CURRENT_TIMESTAMP(3)'
		);
		$stmt->execute([
			':id'  => Uuid::v4(),
			':pid' => $providerId,
			':mid' => $row['model_id'],
			':dn'  => $row['display_name'],
			':el'  => $row['effort_levels'],
			':mo'  => $row['max_output_tokens'],
			':mc'  => $row['max_context_tokens'],
			':ra'  => self::toMysqlDatetime($row['released_at']),
		]);
	}

	/**
	 * ISO-8601 (z.B. "2026-05-01T00:00:00Z") → MariaDB-DATETIME ("Y-m-d H:i:s").
	 * Ungueltige/leere Werte → null (statt INSERT-Fehler unter STRICT_TRANS_TABLES).
	 */
	private static function toMysqlDatetime(?string $iso): ?string
	{
		if ($iso === null || $iso === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($iso))->format('Y-m-d H:i:s');
		} catch (\Exception) {
			return null;
		}
	}

	/**
	 * @param list<string> $seenModelIds
	 */
	public function markStale(string $providerId, array $seenModelIds): void
	{
		if ($seenModelIds === []) {
			$this->db->prepare('UPDATE llm_model_catalog SET available = 0 WHERE provider_id = :p')
				->execute([':p' => $providerId]);
			return;
		}
		$ph = implode(',', array_fill(0, count($seenModelIds), '?'));
		$stmt = $this->db->prepare(
			"UPDATE llm_model_catalog SET available = 0
			 WHERE provider_id = ? AND model_id NOT IN ($ph)"
		);
		$stmt->execute(array_merge([$providerId], $seenModelIds));
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listByProvider(string $providerId, bool $availableOnly = false): array
	{
		$sql = 'SELECT * FROM llm_model_catalog WHERE provider_id = :p';
		if ($availableOnly) {
			$sql .= ' AND available = 1';
		}
		$sql .= ' ORDER BY released_at DESC, model_id ASC';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':p' => $providerId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Alle verfuegbaren Modelle gruppiert nach enabled Provider (fuer Dropdowns).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listAvailableGrouped(): array
	{
		$stmt = $this->db->query(
			'SELECT c.*, p.name AS provider_name, p.kind AS provider_kind
			 FROM llm_model_catalog c
			 INNER JOIN llm_providers p ON p.id = c.provider_id
			 WHERE c.available = 1 AND p.enabled = 1 AND p.deleted_at IS NULL
			 ORDER BY p.priority ASC, c.released_at DESC, c.model_id ASC'
		);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find(string $providerId, string $modelId): ?array
	{
		$stmt = $this->db->prepare(
			'SELECT * FROM llm_model_catalog WHERE provider_id = :p AND model_id = :m LIMIT 1'
		);
		$stmt->execute([':p' => $providerId, ':m' => $modelId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && source /tmp/mailpilot-test-db.env && vendor/bin/phpunit tests/Integration/Llm/LlmModelCatalogRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repositories/LlmModelCatalogRepository.php backend/tests/Integration/Llm/LlmModelCatalogRepositoryTest.php
git commit -m "feat(llm): add LlmModelCatalogRepository (upsert/markStale/list/find)"
```

---

### Task A9: `ModelCatalogService` (resilientes Refresh)

**Files:**
- Create: `backend/src/Llm/ModelCatalogService.php`
- Test: `backend/tests/Integration/Llm/ModelCatalogServiceTest.php` (**Integration** — echte Repos + Test-DB, anonymer `LlmProvider` statt Mock; das Projekt mockt nicht)

- [ ] **Step 1: Write the failing test (Integration: echte Repos + anonymer Provider)**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmUnavailableException;
use MailPilot\Llm\ModelCatalogService;
use MailPilot\Llm\ModelDescriptor;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmModelCatalogRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Tests\TestCase;
use Psr\Log\NullLogger;

/**
 * @group integration
 */
final class ModelCatalogServiceTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c2';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_model_catalog WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			 VALUES (?, 'TestCat', 'anthropic', 'http://cat.test', 0, 1, 10)"
		)->execute([self::PROVIDER_ID]);
	}

	/** @param list<ModelDescriptor>|null $models */
	private function provider(?array $models, bool $throw = false): LlmProvider
	{
		return new class($models, $throw) implements LlmProvider {
			/** @param list<ModelDescriptor>|null $models */
			public function __construct(private readonly ?array $models, private readonly bool $throw) {}
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse {
				return new NormalizedResponse('', ['inputTokens' => 0, 'outputTokens' => 0], 'stop', $r->modelHint, 'anthropic');
			}
			public function listModels(): array {
				if ($this->throw) { throw new LlmUnavailableException('down'); }
				return $this->models ?? [];
			}
		};
	}

	private function service(LlmProvider $p): ModelCatalogService
	{
		$pdo = $this->pdo();
		return new ModelCatalogService(
			['anthropic' => $p],
			new LlmModelCatalogRepository($pdo),
			new LlmProviderRepository($pdo),
			new NullLogger(),
		);
	}

	public function testOkProviderUpsertsAndMarksStale(): void
	{
		$repo = new LlmModelCatalogRepository($this->pdo());
		$repo->upsert(self::PROVIDER_ID, new ModelDescriptor('old-model', 'Old', []));

		$res = $this->service($this->provider([new ModelDescriptor('m1', 'M1', ['low'])]))
			->refreshProvider(self::PROVIDER_ID, 'anthropic');

		self::assertSame(1, $res['discovered']);
		self::assertNull($res['error']);
		$available = array_column($repo->listByProvider(self::PROVIDER_ID, availableOnly: true), 'model_id');
		self::assertSame(['m1'], $available, 'm1 verfuegbar, old-model auf available=0');
	}

	public function testFailingProviderDoesNotEmptyCatalog(): void
	{
		$repo = new LlmModelCatalogRepository($this->pdo());
		$repo->upsert(self::PROVIDER_ID, new ModelDescriptor('keep-me', 'Keep', []));

		$res = $this->service($this->provider(null, throw: true))
			->refreshProvider(self::PROVIDER_ID, 'anthropic');

		self::assertSame(0, $res['discovered']);
		self::assertNotNull($res['error']);
		self::assertCount(1, $repo->listByProvider(self::PROVIDER_ID, availableOnly: true),
			'transienter Fehler darf den Katalog NICHT leeren (kein markStale)');
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && source /tmp/mailpilot-test-db.env && vendor/bin/phpunit tests/Integration/Llm/ModelCatalogServiceTest.php`
Expected: FAIL — `MailPilot\Llm\ModelCatalogService` class not found.

- [ ] **Step 3: Implement the service**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Llm;

use MailPilot\Repositories\LlmModelCatalogRepository;
use MailPilot\Repositories\LlmProviderRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Orchestriert die Modell-Discovery. refreshProvider() fragt EINEN Provider
 * ab (Admin-Button); refreshAll() macht den Voll-Sweep (Worker). Ein Provider-
 * Fehler leert den Katalog NIE (markStale nur bei erfolgreichem Listing).
 */
final class ModelCatalogService
{
	/** Dokumentierte Effort-Gesamtmenge — nur Validierungs-Fallback. */
	public const EFFORT_LEVELS_ALL = ['low', 'medium', 'high', 'xhigh', 'max'];

	/**
	 * @param array<string, LlmProvider> $providersByKind
	 */
	public function __construct(
		private readonly array $providersByKind,
		private readonly LlmModelCatalogRepository $catalog,
		private readonly LlmProviderRepository $providers,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{discovered:int, error:?string}
	 */
	public function refreshProvider(string $providerId, string $kind): array
	{
		$provider = $this->providersByKind[$kind] ?? null;
		if ($provider === null) {
			return ['discovered' => 0, 'error' => 'unknown provider kind: ' . $kind];
		}
		try {
			$models = $provider->listModels();
			$seen = [];
			foreach ($models as $d) {
				$this->catalog->upsert($providerId, $d);
				$seen[] = $d->modelId;
			}
			$this->catalog->markStale($providerId, $seen);
			return ['discovered' => count($seen), 'error' => null];
		} catch (Throwable $e) {
			// Fehlertext defensiv kuerzen (kein Key-Leak), KEIN markStale.
			$msg = substr($e->getMessage(), 0, 200);
			$this->logger->warning('llm.catalog.refresh_failed', [
				'provider_id' => $providerId, 'kind' => $kind, 'err' => $msg,
			]);
			return ['discovered' => 0, 'error' => $msg];
		}
	}

	/**
	 * @return array<string, array{discovered:int, error:?string}>
	 */
	public function refreshAll(): array
	{
		$out = [];
		foreach ($this->providers->listAll(includeDisabled: false) as $row) {
			$id   = (string)$row['id'];
			$kind = (string)$row['kind'];
			$out[$id] = $this->refreshProvider($id, $kind);
		}
		return $out;
	}
}
```

> **Hinweis:** `LlmProviderRepository::listAll(bool $includeDisabled)` existiert bereits (von `LlmController::showGolden()` genutzt). Falls die Signatur abweicht, an die vorhandene anpassen.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && source /tmp/mailpilot-test-db.env && vendor/bin/phpunit tests/Integration/Llm/ModelCatalogServiceTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/ModelCatalogService.php backend/tests/Integration/Llm/ModelCatalogServiceTest.php
git commit -m "feat(llm): add ModelCatalogService with per-provider refresh resilience"
```

---

### Task A10: Kernel-Wiring für Katalog-Repo + Service

**Files:**
- Modify: `backend/src/Http/Kernel.php` (LLM-Block, nach `LlmRouter`-Definition ~Zeile 278)

- [ ] **Step 1: Add the wiring**

Nach dem `LlmRouter::class`-Eintrag einfügen:

```php
				\MailPilot\Repositories\LlmModelCatalogRepository::class =>
					new \MailPilot\Repositories\LlmModelCatalogRepository($this->get(PDO::class)),
				\MailPilot\Llm\ModelCatalogService::class =>
					new \MailPilot\Llm\ModelCatalogService(
						[
							'anthropic'         => $this->get(\MailPilot\Llm\Providers\AnthropicProvider::class),
							'openai'            => $this->get(\MailPilot\Llm\Providers\OpenAiProvider::class),
							'openai_compatible' => $this->get(\MailPilot\Llm\Providers\OpenAiCompatibleProvider::class),
							'gemini'            => $this->get(\MailPilot\Llm\Providers\GeminiProvider::class),
							'mistral'           => $this->get(\MailPilot\Llm\Providers\MistralProvider::class),
						],
						$this->get(\MailPilot\Repositories\LlmModelCatalogRepository::class),
						$this->get(\MailPilot\Repositories\LlmProviderRepository::class),
						$this->get(Logger::class),
					),
```

- [ ] **Step 2: Verify the container resolves (smoke)**

Run: `cd backend && php -r 'require "vendor/autoload.php"; $c=require "config/config.php"; $k=new MailPilot\Http\Kernel($c); $k->get(MailPilot\Llm\ModelCatalogService::class); echo "ok\n";'`
Expected: `ok` (kein Fatal). *(Falls Kernel-Konstruktor abweicht, an `public/index.php`-Bootstrap anpassen.)*

- [ ] **Step 3: Commit**

```bash
git add backend/src/Http/Kernel.php
git commit -m "chore(di): wire LlmModelCatalogRepository + ModelCatalogService"
```

---

### Task A11: Admin — Refresh-Route, Controller-Action, Button

**Files:**
- Modify: `admin/public/index.php` (Route, bei den `/(?P<id>...)`-POST-Routen)
- Modify: `admin/src/Controllers/LlmController.php` (`refreshModels()`)
- Modify: `admin/src/Views/llm/edit.php` (Button)

- [ ] **Step 1: Add the route**

In `admin/public/index.php`, im `$routes`-Array neben `/test` (vor der GET/POST-Catch-all-Reihe `#^/admin/llm/(?P<id>[^/]+)$#`):

```php
	['POST', '#^/admin/llm/(?P<id>[^/]+)/models/refresh$#',  LlmController::class, 'refreshModels'],
```

- [ ] **Step 2: Add the controller action**

In `admin/src/Controllers/LlmController.php`:

```php
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
```

- [ ] **Step 3: Add the button to `edit.php`**

In der „Modelle"-Section von `admin/src/Views/llm/edit.php`, oberhalb der Tabelle:

```php
	<form method="post" action="/admin/llm/<?= $h($pid) ?>/models/refresh" style="margin-bottom:1rem">
		<input type="hidden" name="_csrf" value="<?= $h($csrfToken) ?>">
		<button type="submit" class="btn btn-sm">Modelle aktualisieren</button>
		<span class="muted">Fragt die Models-API dieses Providers live ab.</span>
	</form>
```

- [ ] **Step 4: Manual smoke (optional, requires running admin)**

Im Admin-Panel auf einer Provider-Edit-Seite „Modelle aktualisieren" klicken → Flash mit Count erscheint, `llm_model_catalog` füllt sich (`SELECT COUNT(*) FROM llm_model_catalog`).

- [ ] **Step 5: Commit**

```bash
git add admin/public/index.php admin/src/Controllers/LlmController.php admin/src/Views/llm/edit.php
git commit -m "feat(admin): per-provider 'refresh models' button + route"
```

---

### Task A12: Worker — Bootstrap beim Start + täglicher Refresh

**Files:**
- Modify: `backend/bin/worker.php` (Bootstrap vor `while (true)`; Refresh im täglichen Housekeeping-Block ~Zeile 208)

- [ ] **Step 1: Add the bootstrap refresh (once, before the main loop)**

Direkt vor `while (true) {` einfügen:

```php
// Phase 9q-Katalog: Bootstrap — Modell-Katalog beim Start einmal fuellen,
// damit die Admin-Dropdowns sofort nach Deploy bestueckt sind.
try {
	$bootRes = $kernel->get(\MailPilot\Llm\ModelCatalogService::class)->refreshAll();
	$log->info('worker.model_catalog_bootstrap', ['providers' => count($bootRes)]);
} catch (\Throwable $e) {
	$log->warning('worker.model_catalog_bootstrap_failed', ['err' => $e->getMessage()]);
}
```

- [ ] **Step 2: Add the daily refresh (inside the housekeeping block)**

Im täglichen Housekeeping-Block (vor der `$log->info('worker.housekeeping', [...])`-Zeile) einfügen:

```php
			// Phase 9q-Katalog: taeglicher Modell-Refresh — neue Modelle
			// erscheinen automatisch ohne Code-Aenderung.
			$catalogProviders = 0;
			try {
				$catalogProviders = count(
					$kernel->get(\MailPilot\Llm\ModelCatalogService::class)->refreshAll()
				);
			} catch (\Throwable $e) {
				$log->warning('worker.model_catalog_refresh_failed', ['err' => $e->getMessage()]);
			}
```

Und im `worker.housekeeping`-Log-Array ergänzen: `'model_catalog_providers' => $catalogProviders,`.

- [ ] **Step 3: Lint / syntax check**

Run: `cd backend && php -l bin/worker.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add backend/bin/worker.php
git commit -m "feat(worker): bootstrap + daily model catalog refresh"
```

---

# Phase B — Effort & Router-Umbau

### Task B1: Migration `0061_llm_models_effort.sql`

**Files:**
- Create: `backend/migrations/0061_llm_models_effort.sql`

- [ ] **Step 1: Write the migration**

```sql
-- 0061_llm_models_effort.sql
--
-- Effort pro (Provider, Modell, Rolle). NULL = kein output_config/reasoning_effort
-- (Provider-Default high). Erlaubte Werte App-seitig gegen den Katalog validiert.

ALTER TABLE llm_models ADD COLUMN effort VARCHAR(12) NULL AFTER max_context;
```

- [ ] **Step 2: Apply against test DB**

Run: `cd backend && source /tmp/mailpilot-test-db.env && php bin/migrate.php`
Expected: `✓ 0061_llm_models_effort`.

- [ ] **Step 3: Commit**

```bash
git add backend/migrations/0061_llm_models_effort.sql
git commit -m "feat(db): migration 0061 — llm_models.effort column"
```

---

### Task B2: `NormalizedRequest.effort`

**Files:**
- Modify: `backend/src/Llm/NormalizedRequest.php`

- [ ] **Step 1: Add the field**

Im `NormalizedRequest`-Konstruktor als **letzten** Parameter ergänzen (backward-compatible):

```php
		public readonly array  $cacheSegments  = [],
		public readonly ?string $effort         = null,
```

Im Doc-Block ergänzen: `effort: low|medium|high|xhigh|max oder null (provider-spezifisch gemappt).`

- [ ] **Step 2: Syntax check**

Run: `cd backend && php -l src/Llm/NormalizedRequest.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add backend/src/Llm/NormalizedRequest.php
git commit -m "feat(llm): add optional effort field to NormalizedRequest"
```

---

### Task B3 + B4: Effort-Emission (Anthropic `output_config`, OpenAI `reasoning_effort`)

**Files:**
- Modify: `backend/src/Llm/Providers/AnthropicProvider.php` (`buildPayload`)
- Modify: `backend/src/Llm/Providers/OpenAiProvider.php` (`buildPayload`)
- Test: `backend/tests/Unit/Llm/Providers/EffortEmissionTest.php`

> **Fix (Review):** `buildPayload()` wird `public static` (nutzt kein `$this`) — dann testet der Emissions-Test per direktem statischem Aufruf, **ohne** den Provider zu konstruieren. Das umgeht das Problem, dass `LlmProviderRepository`/`SecretBox` `final` sind und nicht gestubbt werden können (das Projekt mockt grundsätzlich nicht). Der Aufruf in `complete()` wird von `$this->buildPayload(...)` auf `self::buildPayload(...)` umgestellt.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\Providers\AnthropicProvider;
use MailPilot\Llm\Providers\OpenAiProvider;
use PHPUnit\Framework\TestCase;

final class EffortEmissionTest extends TestCase
{
	private function req(?string $effort): NormalizedRequest
	{
		return new NormalizedRequest(
			systemPrompt: 'sys', messages: [['role' => 'user', 'content' => 'hi']],
			maxTokens: 400, temperature: 0.3, modelHint: 'claude-opus-4-8',
			responseFormat: null, cacheSegments: [], effort: $effort,
		);
	}

	public function testAnthropicEmitsOutputConfigWhenEffortSet(): void
	{
		$with = AnthropicProvider::buildPayload($this->req('medium'));
		self::assertSame(['effort' => 'medium'], $with['output_config']);

		$without = AnthropicProvider::buildPayload($this->req(null));
		self::assertArrayNotHasKey('output_config', $without);
	}

	public function testOpenAiEmitsReasoningEffortWhenSet(): void
	{
		$with = OpenAiProvider::buildPayload($this->req('high'));
		self::assertSame('high', $with['reasoning_effort']);

		$without = OpenAiProvider::buildPayload($this->req(null));
		self::assertArrayNotHasKey('reasoning_effort', $without);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/EffortEmissionTest.php`
Expected: FAIL — `output_config`/`reasoning_effort` fehlen.

- [ ] **Step 3a: AnthropicProvider `buildPayload()` → `public static` + Effort**

Signatur `private function buildPayload(NormalizedRequest $request): array` → `public static function buildPayload(NormalizedRequest $request): array` ändern. In `complete()` den Aufruf `$payload = $this->buildPayload($request);` → `$payload = self::buildPayload($request);`. Den `return [...]`-Block ans Ende ersetzen durch:

```php
		$payload = [
			'model'       => $request->modelHint,
			'max_tokens'  => $request->maxTokens,
			'temperature' => $request->temperature,
			'system'      => $systemSegments,
			'messages'    => $messages,
		];
		if ($request->effort !== null) {
			$payload['output_config'] = ['effort' => $request->effort];
		}
		return $payload;
```

- [ ] **Step 3b: OpenAiProvider `buildPayload()` → `public static` + Effort**

Signatur `private function buildPayload(...)` → `public static function buildPayload(...)`. In `complete()` `$payload = $this->buildPayload($request);` → `$payload = self::buildPayload($request);`. Vor `return $payload;` ergänzen:

```php
		if ($request->effort !== null) {
			$payload['reasoning_effort'] = $request->effort;
		}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && vendor/bin/phpunit tests/Unit/Llm/Providers/EffortEmissionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/Providers/AnthropicProvider.php backend/src/Llm/Providers/OpenAiProvider.php backend/tests/Unit/Llm/Providers/EffortEmissionTest.php
git commit -m "feat(llm): emit effort (Anthropic output_config / OpenAI reasoning_effort)"
```

---

### Task B5: LlmRouter reicht `effort` aus `llm_models` durch

**Files:**
- Modify: `backend/src/Llm/LlmRouter.php` (Model-Resolve-Block, ~Zeile 84-102)
- Test: `backend/tests/Integration/Llm/RouterEffortTest.php` (**Integration** — echte Repos + Test-DB; Muster: `LlmRouterFailoverTest`)

- [ ] **Step 1: Write the failing test**

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

/**
 * @group integration
 */
final class RouterEffortTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c3';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			 VALUES (?, 'TestEffort', 'anthropic', 'http://eff.test', 0, 1, 10)"
		)->execute([self::PROVIDER_ID]);
		$pdo->prepare(
			"INSERT INTO llm_models (id, provider_id, model_id, role, effort, enabled, priority)
			 VALUES (?, ?, 'claude-opus-4-8', 'summary', 'medium', 1, 10)"
		)->execute([Uuid::v4(), self::PROVIDER_ID]);
		foreach ([['llm.primary_provider_id', self::PROVIDER_ID], ['llm.privacy_mode', 'cloud_allowed']] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`) VALUES (?, ?, "string")
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
		}
	}

	public function testRouterPassesEffortFromModelRow(): void
	{
		$capture = new class implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse {
				$this->seen = $r;
				return new NormalizedResponse('ok', ['inputTokens' => 1, 'outputTokens' => 1], 'end_turn', $r->modelHint, 'anthropic');
			}
			public function listModels(): array { return []; }
		};

		$pdo = $this->pdo();
		$router = new LlmRouter(
			['anthropic' => $capture],
			new LlmProviderRepository($pdo),
			new SettingsRepository($pdo),
			new NullLogger(),
			new LlmModelRepository($pdo),
		);
		// leerer modelHint → Router resolved Modell + Effort aus llm_models.
		$router->complete(new NormalizedRequest('sys', [['role' => 'user', 'content' => 'x']], 400, 0.3, ''), 'summary');

		self::assertNotNull($capture->seen);
		self::assertSame('claude-opus-4-8', $capture->seen->modelHint);
		self::assertSame('medium', $capture->seen->effort);
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && source /tmp/mailpilot-test-db.env && vendor/bin/phpunit tests/Integration/Llm/RouterEffortTest.php`
Expected: FAIL — `$capture->seen->effort` ist `null` (Router reicht Effort noch nicht durch).

- [ ] **Step 3: Modify the Router rebuild**

Im `if ($request->modelHint === '' && $this->models !== null)`-Block den rekonstruierten `NormalizedRequest` um `effort` ergänzen:

```php
				$effectiveRequest = new NormalizedRequest(
					systemPrompt:   $request->systemPrompt,
					messages:       $request->messages,
					maxTokens:      $request->maxTokens,
					temperature:    $request->temperature,
					modelHint:      (string)$modelRow['model_id'],
					responseFormat: $request->responseFormat,
					cacheSegments:  $request->cacheSegments,
					effort:         isset($modelRow['effort']) && $modelRow['effort'] !== null
						? (string)$modelRow['effort'] : $request->effort,
				);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && source /tmp/mailpilot-test-db.env && vendor/bin/phpunit tests/Integration/Llm/RouterEffortTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Llm/LlmRouter.php backend/tests/Integration/Llm/RouterEffortTest.php
git commit -m "feat(llm): router passes per-role effort from llm_models into request"
```

---

### Task B6: MailSummaryService → Router (+ Safety-Net)

**Files:**
- Modify: `backend/src/Services/MailSummaryService.php`
- Modify: `backend/src/Http/Kernel.php` (Wiring von `MailSummaryService`)
- Test: `backend/tests/Integration/Services/SummaryDraftRouterTest.php` (**Integration** — echte Collaborators + Test-DB; das Projekt mockt nicht)

- [ ] **Step 1: Write the failing test**

> Muster: `tests/Integration/MailScoringServiceTest.php`. Es gibt eine `FakeClaudeClient`-Fixture (`MailPilot\Tests\Fixtures\FakeClaudeClient`) und die Basis-Helfer `insertTenantAndUser()`/`insertMailbox()`/`insertMail()`. Falls `insertMail()` keine ID zurückgibt (wie in MailScoringServiceTest), die Mail-ID danach via `MailRepository::findUnscoredForMailbox()` holen.

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\SummaryRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Services\BudgetService;
use MailPilot\Services\MailSummaryService;
use MailPilot\Services\RedactionService;
use MailPilot\Tests\Fixtures\FakeClaudeClient;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * @group integration
 */
final class SummaryDraftRouterTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c4';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'TestSum', 'anthropic', 'http://sum.test', 0, 1, 10)")->execute([self::PROVIDER_ID]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, effort, enabled, priority)
			VALUES (?, ?, 'claude-opus-4-8', 'summary', 'medium', 1, 10)")->execute([Uuid::v4(), self::PROVIDER_ID]);
		foreach ([['llm.primary_provider_id', self::PROVIDER_ID], ['llm.privacy_mode', 'cloud_allowed']] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`,`value`,`type`) VALUES (?,?,"string")
				ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$k, $v]);
		}
	}

	public function testSummaryRoutesAndPersistsResolvedModel(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'a@x.de', 'from_name' => 'A', 'subject' => 'Betreff', 'body_text' => 'Hallo Welt',
		]);
		$pdo = $this->pdo();
		$mailId = (new MailRepository($pdo))->findUnscoredForMailbox($tenantId, $mailboxId)[0]['id'];

		$fakeAnthropic = new class implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse {
				$this->seen = $r;
				return new NormalizedResponse('Kurz-Zusammenfassung', ['inputTokens' => 10, 'outputTokens' => 5], 'end_turn', $r->modelHint, 'anthropic');
			}
			public function listModels(): array { return []; }
		};

		$router = new LlmRouter(
			['anthropic' => $fakeAnthropic],
			new LlmProviderRepository($pdo),
			new SettingsRepository($pdo),
			new NullLogger(),
			new LlmModelRepository($pdo),
		);
		$budget = new BudgetService(
			new SettingsRepository($pdo), new UsageRepository($pdo), new PricingRepository($pdo), new NullLogger(),
		);
		$service = new MailSummaryService(
			$router,
			new MailRepository($pdo),
			new SummaryRepository($pdo),
			new RedactionService(),
			$budget,
			new PromptRepository($pdo),
			new FakeClaudeClient(), // Safety-Net-Fallback (hier ungenutzt)
		);

		$text = $service->summarize($tenantId, $mailId, 'a@x.de', 'de', $userId);

		self::assertSame('Kurz-Zusammenfassung', $text);
		self::assertSame('', $fakeAnthropic->seen->modelHint, 'Service laesst den Router das Modell resolven');
		self::assertSame([], $fakeAnthropic->seen->cacheSegments, 'kein Caching (DA-R2-2)');
		$row = (new SummaryRepository($pdo))->findByMailId($tenantId, $mailId);
		self::assertNotNull($row);
		self::assertSame('claude-opus-4-8', $row['model'], 'Persistiert das vom Router aufgeloeste Modell');
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && source /tmp/mailpilot-test-db.env && vendor/bin/phpunit tests/Integration/Services/SummaryDraftRouterTest.php`
Expected: FAIL — Konstruktor erwartet noch `ClaudeProvider`, kein `LlmRouter` (bzw. `MailSummaryService` ist noch nicht umgestellt).

- [ ] **Step 3: Refactor `MailSummaryService`**

Konstruktor: `ClaudeProvider $claude` → `LlmRouter $router`, plus optionaler `?ClaudeProvider $claudeFallback = null` (Safety-Net). Importe: `use MailPilot\Llm\LlmRouter; use MailPilot\Llm\NormalizedRequest; use MailPilot\Llm\LlmAllProvidersDownException;`.

Den Inferenz-Block ersetzen:

```php
		$start = microtime(true);
		try {
			try {
				$req = new NormalizedRequest(
					systemPrompt:   $system,
					messages:       [['role' => 'user', 'content' => $user]],
					maxTokens:      $maxTokens,
					temperature:    0.3,
					modelHint:      '',     // Router resolved Modell+Effort pro Rolle
					responseFormat: null,
					cacheSegments:  [],     // wie bisher: KEIN Caching (DA-R2-2)
				);
				$resp      = $this->router->complete($req, 'summary');
				$text      = $resp->content;
				$usageRaw  = $resp->usage['raw'] ?? [];
				$usedModel = $resp->modelId;
			} catch (LlmAllProvidersDownException $e) {
				// Safety-Net (DA-R2-5): leere/kaputte Chain → Legacy-Direct-Anthropic.
				if ($this->claudeFallback === null) {
					throw $e;
				}
				$legacy    = $this->claudeFallback->messages([
					'model' => $model, 'max_tokens' => $maxTokens,
					'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]],
				]);
				$text      = ClaudeClient::extractText($legacy);
				$usageRaw  = $legacy['usage'] ?? [];
				$usedModel = $model;
			}
		} catch (\Throwable $e) {
			$this->budget->recordUsage([
				'tenant_id' => $tenantId, 'user_id' => $userId,
				'mailbox_id' => $mailboxId, 'mail_id' => $mailId,
				'prompt_version' => $promptVersionTag, 'model' => $model,
				'input_tokens' => 0, 'output_tokens' => 0,
				'cache_read_tokens' => 0, 'cache_creation_tokens' => 0,
				'duration_ms' => (int)((microtime(true) - $start) * 1000),
				'status' => 'error', 'error_text' => substr($e->getMessage(), 0, 500),
			]);
			throw $e;
		}

		$this->budget->recordUsage([
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'mailbox_id' => $mailboxId, 'mail_id' => $mailId,
			'prompt_version' => $promptVersionTag, 'model' => $usedModel,
			'input_tokens'          => (int)($usageRaw['input_tokens']                ?? 0),
			'output_tokens'         => (int)($usageRaw['output_tokens']               ?? 0),
			'cache_read_tokens'     => (int)($usageRaw['cache_read_input_tokens']     ?? 0),
			'cache_creation_tokens' => (int)($usageRaw['cache_creation_input_tokens'] ?? 0),
			'duration_ms' => (int)((microtime(true) - $start) * 1000),
			'status' => 'success', 'error_text' => null,
		]);

		$this->summaries->create($tenantId, $mailId, $text, $promptVersionTag, $usedModel);
		return $text;
```

Den `use MailPilot\Claude\ClaudeProvider;`-Import behalten (für den Fallback-Typ), `ClaudeClient` ist bereits importiert.

> **Safety-Net-Modell (Review medium):** Der Legacy-Fallback nutzt `$model` aus `prompt_versions`. Damit der Fallback dasselbe Modell wie der Router fährt, aktualisiert Migration 0062 zusätzlich die aktive P-SUMMARY/P-REPLY-Prompt-Version auf `claude-opus-4-8` (siehe Task C2). So gibt es keine stille Modell-/Kostendivergenz, falls die Chain leer ist.

- [ ] **Step 4: Update Kernel wiring**

> **Hinweis (Review):** Der `LlmRouter` ist im Kernel ohnehin ein regulärer, jederzeit via `get()` auflösbarer `match`-Arm (kein „wird bei direct-Mode nie gebaut" — `MailScoringService` injiziert ihn bereits). Es ist also **kein** zusätzlicher Kernel-Umbau nötig, nur der Konstruktor-Tausch unten.

```php
				MailSummaryService::class => new MailSummaryService(
					$this->get(\MailPilot\Llm\LlmRouter::class),
					$this->get(MailRepository::class),
					$this->get(SummaryRepository::class),
					$this->get(RedactionService::class),
					$this->get(BudgetService::class),
					$this->get(PromptRepository::class),
					$this->get(ClaudeProvider::class), // Safety-Net-Fallback
				),
```

- [ ] **Step 5: Run test + Suites**

Run: `cd backend && source /tmp/mailpilot-test-db.env && vendor/bin/phpunit tests/Integration/Services/SummaryDraftRouterTest.php && composer test:unit`
Expected: PASS; Unit-Suite weiterhin grün.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Services/MailSummaryService.php backend/src/Http/Kernel.php backend/tests/Integration/Services/SummaryDraftRouterTest.php
git commit -m "refactor(summary): route MailSummaryService through LlmRouter with direct fallback"
```

---

### Task B7: ReplyDraftService → Router (+ Safety-Net, kein Failover)

**Files:**
- Modify: `backend/src/Services/ReplyDraftService.php`
- Modify: `backend/src/Http/Kernel.php` (Wiring von `ReplyDraftService`)

- [ ] **Step 1: Refactor analog zu Task B6**

Konstruktor: `ClaudeProvider $claude` → `LlmRouter $router`; `?RedactionRepository $redactionRules = null` bleibt; zusätzlich `?ClaudeProvider $claudeFallback = null` als **letzten** Parameter. Inferenz-Block in `draft()` ersetzen: `NormalizedRequest` mit `modelHint=''`, `cacheSegments=[]`, `temperature` 0.3, Aufruf `$this->router->complete($req, 'draft')`. Output-Redaction (`$scopedRedactor->redact($draft)`) bleibt **nach** dem Call. `drafts->create(..., $usedModel, ...)` mit `$resp->modelId`. Safety-Net (Legacy-Direct-Anthropic bei `LlmAllProvidersDownException`) identisch zu B6.

> `draft` hat per Default **keine** Multi-Provider-Chain (nur score+summary in 0051 geseedet) → Router nutzt allein `primary_provider_id` = Single-Provider, **kein** Failover (DA-R2-4). Kein zusätzlicher Code nötig — nur sicherstellen, dass keine `llm.draft.fallback_chain` mit mehreren Providern geseedet wird.

- [ ] **Step 2: Update Kernel wiring**

```php
				ReplyDraftService::class  => new ReplyDraftService(
					$this->get(\MailPilot\Llm\LlmRouter::class),
					$this->get(MailRepository::class),
					$this->get(DraftRepository::class),
					$this->get(RedactionService::class),
					$this->get(BudgetService::class),
					$this->get(PromptRepository::class),
					$this->get(RedactionRepository::class),
					$this->get(ClaudeProvider::class), // Safety-Net-Fallback
				),
```

- [ ] **Step 3: Run full unit suite**

Run: `cd backend && composer test:unit`
Expected: grün.

- [ ] **Step 4: Commit**

```bash
git add backend/src/Services/ReplyDraftService.php backend/src/Http/Kernel.php
git commit -m "refactor(draft): route ReplyDraftService through LlmRouter (single-provider, direct fallback)"
```

---

### Task B8: HTTP-Mapping — Router-Overload → 503 `AI_OVERLOADED` + Retry-After

**Files:**
- Modify: `backend/src/Http/Router.php` (Dispatch-`try/catch`, ~Zeile 120-153)

> **Review-Fix:** Das bestehende Overload→503-Mapping liegt im **Dispatch-`try/catch` von `Router.php`** (nicht in `Kernel.php`) und nutzt `Response::error(503, 'AI_OVERLOADED', …, ['retry_after' => …])` plus `header('Retry-After: …')` — das Add-in erwartet exakt diese `AI_OVERLOADED`-JSON-Struktur für seinen Toast. Wir spiegeln dieses Verhalten für den Router-Pfad.

- [ ] **Step 1: Add a catch for `LlmAllProvidersDownException`**

Im Dispatch-`try/catch` von `Router.php` einen neuen `catch` **zwischen** dem `AnthropicOverloadedException`- und dem generischen `\Throwable`-Catch einfügen:

```php
		} catch (\MailPilot\Llm\LlmAllProvidersDownException $e) {
			// Router hat alle Provider durch — war die Ursache ein Overload,
			// signalisieren wir 503 + Retry-After (wie beim Anthropic-Overload),
			// damit das Add-in den "KI-Anbieter ueberlastet"-Toast zeigt.
			$prev = $e->getPrevious();
			$retryAfter = $prev instanceof \MailPilot\Llm\LlmOverloadedException
				? max(1, $prev->retryAfterSeconds) : 30;
			$this->kernel->get(\Monolog\Logger::class)->warning('dispatch.router_all_down', [
				'handler' => $handler, 'err' => $e->getMessage(),
			]);
			header('Retry-After: ' . $retryAfter);
			Response::error(503, 'AI_OVERLOADED', $e->getMessage(), ['retry_after' => $retryAfter]);
		}
```

(`LlmOverloadedException::$retryAfterSeconds` ist `public readonly int` — vorab gegen die echte Property prüfen.)

- [ ] **Step 2: Verify**

Run: `cd backend && php -l src/Http/Router.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add backend/src/Http/Router.php
git commit -m "feat(http): map router all-providers-down(overload) to 503 AI_OVERLOADED + Retry-After"
```

---

### Task B9: Admin `llm/edit.php` — Modell+Effort-Dropdowns + Validierung + Score-Label

**Files:**
- Modify: `admin/src/Controllers/LlmController.php` (`edit()` lädt Katalog; `saveModel()` validiert)
- Modify: `admin/src/Views/llm/edit.php` (Dropdowns + Vanilla-JS)

- [ ] **Step 1: `edit()` — Katalog an die View geben**

In `LlmController::edit()` zusätzlich laden und an `render('llm/edit', [...])` übergeben:

```php
		$catalog = $this->kernel->get(\MailPilot\Repositories\LlmModelCatalogRepository::class)
			->listByProvider((string)$params['id'], availableOnly: true);
```
… `'catalog' => $catalog`. (`edit(array $params)` erhält die Provider-ID als `$params['id']` — keine undefinierte `$id`-Variable verwenden.)

- [ ] **Step 2: `saveModel()` — model_id + effort persistieren & validieren**

```php
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
```

- [ ] **Step 3: View — Dropdowns + JS**

Vor der Tabelle ein Daten-Blob der Katalog-Modelle ausgeben:

```php
<?php
$catalogJs = [];
foreach (($catalog ?? []) as $c) {
	$catalogJs[(string)$c['model_id']] = $c['effort_levels'] !== null
		? (array)json_decode((string)$c['effort_levels'], true) : [];
}
?>
<script>window.MP_CATALOG = <?= json_encode($catalogJs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
```

Die `model_id`-Zelle als `<select>` (inkl. aktueller Auswahl, falls nicht im Katalog) + Effort-Zelle:

```php
		<td>
			<select name="model_id" class="mp-model">
				<?php
				$current = (string)$m['model_id'];
				$ids = array_keys($catalogJs);
				if (!in_array($current, $ids, true)) { array_unshift($ids, $current); }
				foreach ($ids as $mid): ?>
					<option value="<?= $h($mid) ?>" <?= $mid === $current ? 'selected' : '' ?>><?= $h($mid) ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ((string)$m['role'] === 'score'): ?>
				<span class="muted" title="Score nutzt im direct-Mode den gebatchten Pfad">⚠ nur bei routing_mode=router</span>
			<?php endif; ?>
		</td>
		<td>
			<select name="effort" class="mp-effort">
				<option value="">— (Default)</option>
				<?php foreach (['low','medium','high','xhigh','max'] as $lvl): ?>
					<option value="<?= $lvl ?>" <?= (string)($m['effort'] ?? '') === $lvl ? 'selected' : '' ?>><?= $lvl ?></option>
				<?php endforeach; ?>
			</select>
		</td>
```

Am Seitenende das Vanilla-JS, das das Effort-`<select>` an die Levels des gewählten Modells anpasst:

```php
<script>
document.querySelectorAll('.mp-model').forEach(function (sel) {
	function sync() {
		var levels = (window.MP_CATALOG[sel.value] || []);
		var eff = sel.closest('tr').querySelector('.mp-effort');
		var cur = eff.value;
		eff.replaceChildren(); // kein innerHTML — XSS-sicher
		var def = document.createElement('option');
		def.value = ''; def.textContent = '— (Default)';
		eff.appendChild(def);
		levels.forEach(function (l) {
			var o = document.createElement('option');
			o.value = l; o.textContent = l; if (l === cur) o.selected = true;
			eff.appendChild(o);
		});
		eff.disabled = levels.length === 0;
	}
	sel.addEventListener('change', sync);
	sync();
});
</script>
```

> Die Tabellen-Kopfzeile in `edit.php` um `<th>Effort</th>` ergänzen und die alte `<code>`-Modell-Zelle entfernen.

- [ ] **Step 4: Manual smoke**

Provider-Edit öffnen → Modell-Dropdown zeigt Katalog-Modelle; Effort-Dropdown passt sich an; Speichern persistiert `model_id`+`effort`; ungültiger Effort wird abgelehnt; Score-Zeile zeigt den Hinweis.

- [ ] **Step 5: Commit**

```bash
git add admin/src/Controllers/LlmController.php admin/src/Views/llm/edit.php
git commit -m "feat(admin): dynamic model + effort dropdowns per role with validation"
```

---

### Task B10: Prompt-Editor — hardcodierte Modellwahl entfernen

**Files:**
- Modify: `admin/src/Views/prompt_edit.php`
- Modify: `admin/src/Controllers/PromptController.php`

> **Review-Realität:** `prompt_edit.php` nutzt ein **`<input type="text" name="model" list="model-suggestions">` + `<datalist>`** mit drei hartkodierten `<option>` (inkl. `claude-opus-4-7`), beim Editieren `disabled`. `PromptController::store()` ist **create-only**, liest `$_POST['model']` inline und insertet (es gibt **keinen** Edit-Save-Pfad für `model`; `$keyName`/`$existingModel` existieren nicht). Beide Stellen entsprechend anpassen.

- [ ] **Step 1: Modell-Feld in `prompt_edit.php` durch Hinweis ersetzen**

Den kompletten `<label class="settings-field">`-Block mit `<input … name="model" …>` + `<datalist id="model-suggestions">…</datalist>` (inkl. der drei hartkodierten Optionen) ersetzen durch:

```php
	<p class="muted">
		<strong>Modell &amp; Effort</strong> werden pro Rolle in der
		<a href="/admin/llm">LLM-Provider-Ansicht</a> gesetzt (dynamisch aus dem
		Provider-Katalog). Dieser Editor steuert nur Prompt-Text, max_tokens und temperature.
	</p>
```

- [ ] **Step 2: `PromptController::store()` — Modell aus der Rolle ableiten (kein `$_POST['model']`)**

In `store()` das Modell nicht mehr aus `$_POST['model']` (jetzt leer → würde NOT-NULL `model` mit `''` füllen) lesen, sondern aus dem Rollen-Modell. Vor dem `INSERT` einfügen und die `':m'`-Bindung anpassen:

```php
		$keyName = (string)($_POST['key_name'] ?? '');
		$roleByKey = ['P-SUMMARY' => 'summary', 'P-REPLY' => 'draft', 'P-SCORE' => 'score'];
		$role = $roleByKey[$keyName] ?? 'summary';
		$byRole = $this->kernel->get(\MailPilot\Repositories\LlmModelRepository::class)->listByRole($role);
		$model = (string)($byRole[0]['model_id'] ?? 'claude-opus-4-8');
```
und in der `execute([...])`-Bindung `':m' => (string)($_POST['model'] ?? ''),` → `':m' => $model,`.

- [ ] **Step 3: Manual smoke**

Prompt-Editor zeigt keinen Modell-Input mehr, sondern den Hinweis; „Prompt-Version anlegen" funktioniert weiter (NOT-NULL `model` bleibt aus dem Rollen-Default befüllt, keine leere Modell-ID).

- [ ] **Step 4: Commit**

```bash
git add admin/src/Views/prompt_edit.php admin/src/Controllers/PromptController.php
git commit -m "refactor(admin): remove hardcoded model picker from prompt editor (model lives in routing)"
```

---

### Task B11: Integration — Effort-Roundtrip

**Files:**
- Test: `backend/tests/Integration/Llm/EffortRoundtripTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

/**
 * @group integration
 */
final class EffortRoundtripTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c5';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'TestRt', 'anthropic', 'http://rt.test', 0, 1, 10)")->execute([self::PROVIDER_ID]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, effort, enabled, priority)
			VALUES (?, ?, 'claude-opus-4-8', 'summary', NULL, 1, 10)")->execute([Uuid::v4(), self::PROVIDER_ID]);
	}

	public function testEffortColumnPersistsAndIsReadByModelRepo(): void
	{
		$pdo = $this->pdo();
		$pdo->prepare("UPDATE llm_models SET effort = 'medium' WHERE provider_id = :p AND role = 'summary'")
			->execute([':p' => self::PROVIDER_ID]);

		$row = (new LlmModelRepository($pdo))->findForProviderAndRole(self::PROVIDER_ID, 'summary');
		self::assertNotNull($row);
		self::assertSame('medium', $row['effort']);
	}
}
```

- [ ] **Step 2: Run (requires 0061 applied)**

Run: `cd backend && source /tmp/mailpilot-test-db.env && php bin/migrate.php && vendor/bin/phpunit tests/Integration/Llm/EffortRoundtripTest.php`
Expected: PASS (`findForProviderAndRole` macht `SELECT *` → enthält `effort`).

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Integration/Llm/EffortRoundtripTest.php
git commit -m "test(llm): integration roundtrip for llm_models.effort"
```

---

# Phase C — Opus-4.8-Daten, Pricing, Config, Doku

### Task C1: EU-Bedrock-ID verifizieren

- [ ] **Step 1: Recherche**

WebFetch: <https://docs.aws.amazon.com/bedrock/latest/userguide/model-card-anthropic-claude-opus-4-8.html> — exakte EU-Inference-Profile-ID für Opus 4.8 bestätigen (Muster `eu.anthropic.claude-opus-4-8-v1:0`). Ergebnis für Task C3 notieren.

---

### Task C2: Migration `0062_opus_4_8_summary_draft.sql`

**Files:**
- Create: `backend/migrations/0062_opus_4_8_summary_draft.sql`

- [ ] **Step 1: Write the migration**

```sql
-- 0062_opus_4_8_summary_draft.sql
--
-- Opus-4.8-Migration: summary/draft auf claude-opus-4-8 + effort=medium.
-- Targeting ueber Rolle (nicht ueber alten Modellwert). Pricing: model_pricing
-- in EUR (~0.92-Kurs), llm_models.cost_per_mtok in USD. Opus-4-7-Zeilen bleiben
-- fuer historische Kosten.

UPDATE llm_models
	SET model_id = 'claude-opus-4-8', effort = 'medium',
	    cost_per_mtok_in = 5.00, cost_per_mtok_out = 25.00
	WHERE role IN ('summary', 'draft')
	  AND provider_id = '00000000-0000-4000-8000-000000000050'   -- Anthropic (0050)
	  AND deleted_at IS NULL;

INSERT IGNORE INTO model_pricing
	(model, input_eur_per_1m, output_eur_per_1m, cache_read_eur_per_1m, cache_creation_eur_per_1m)
VALUES
	('claude-opus-4-8', 4.6000, 23.0000, 0.4600, 5.7500);

-- Safety-Net-Konsistenz (Review): der Legacy-Direct-Fallback in MailSummary/
-- ReplyDraftService nutzt das Modell der aktiven Prompt-Version. Damit der
-- Fallback dasselbe Modell wie der Router fährt, die aktiven P-SUMMARY/P-REPLY
-- ebenfalls auf claude-opus-4-8 setzen. (Score/P-SCORE bleibt unangetastet → Haiku.)
UPDATE prompt_versions
	SET model = 'claude-opus-4-8'
	WHERE key_name IN ('P-SUMMARY', 'P-REPLY') AND active = 1;
```

- [ ] **Step 2: Apply against test DB**

Run: `cd backend && source /tmp/mailpilot-test-db.env && php bin/migrate.php`
Expected: `✓ 0062_opus_4_8_summary_draft`.
Verify: `SELECT model_id, role, effort FROM llm_models WHERE role IN ('summary','draft')` → `claude-opus-4-8 / medium`.

- [ ] **Step 3: Commit**

```bash
git add backend/migrations/0062_opus_4_8_summary_draft.sql
git commit -m "feat(db): migration 0062 — summary/draft to claude-opus-4-8 (effort=medium) + pricing"
```

---

### Task C3: `config.php` / `config.example.php`

**Files:**
- Modify: `backend/config/config.php`
- Modify: `backend/config/config.example.php`

- [ ] **Step 1: Update both files identically**

In der Bedrock-Map ergänzen (exakte ID aus C1) und `model_summary`/`model_reply` setzen:

```php
				'claude-haiku-4-5-20251001' => 'eu.anthropic.claude-haiku-4-5-v1:0',
				'claude-opus-4-7'           => 'eu.anthropic.claude-opus-4-7-v1:0',
				'claude-opus-4-8'           => 'eu.anthropic.claude-opus-4-8-v1:0',
```
```php
		'model_summary' => 'claude-opus-4-8',
		'model_reply'   => 'claude-opus-4-8',
```

- [ ] **Step 2: Lint**

Run: `cd backend && php -l config/config.php && php -l config/config.example.php`
Expected: keine Syntaxfehler.

- [ ] **Step 3: Commit**

```bash
git add backend/config/config.php backend/config/config.example.php
git commit -m "feat(config): default summary/reply model to claude-opus-4-8 + Bedrock map"
```

---

### Task C4: Doku

**Files:**
- Modify: `CLAUDE.md` (§5), `docs/PROMPTS.md`, `docs/BEDROCK.md`, `README.md`

- [ ] **Step 1: CLAUDE.md §5**

Default-Modelle aktualisieren (`summary`/`draft` → `claude-opus-4-8`); Regel ergänzen: „Effort pro Rolle aus `llm_models` (Anthropic `output_config.effort`, OpenAI `reasoning_effort`); Modelle kommen aus dem live-entdeckten `llm_model_catalog` — keine hardcodierten Modell-IDs in Services/UI. Summary/Draft laufen über den `LlmRouter`. **Cost-Tracking:** `api_usage` (EUR-Budget) und `llm_call_log` (USD-Ops) bleiben getrennt — kein Wert summiert beide für denselben Call (DA-R2-3)."

- [ ] **Step 2: docs/PROMPTS.md**

`Model: claude-opus-4-7`-Zeilen für P-SUMMARY/P-REPLY ersetzen durch „Modell/Effort: pro Rolle in der LLM-Provider-Ansicht (dynamischer Katalog)".

- [ ] **Step 3: docs/BEDROCK.md**

Opus-4-8-ARNs/Inference-Profile ergänzen (aus C1).

- [ ] **Step 4: README.md**

Modell-/Architektur-Referenzen: „LLM-Router + dynamischer Modellkatalog; Opus 4.8 für Summary/Draft".

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md docs/PROMPTS.md docs/BEDROCK.md README.md
git commit -m "docs: dynamic model catalog + effort + Opus 4.8 for summary/draft"
```

---

### Task C5: Volllauf & CI

- [ ] **Step 1: Lint + statische Analyse**

Run: `cd backend && composer cs-check && vendor/bin/phpstan analyse`
Expected: sauber. (composer-Skripte heißen `cs-check`/`cs-fix`; ein `phpstan`-Skript existiert NICHT → direkt `vendor/bin/phpstan analyse`, Config `phpstan.neon`, Level 5.)

- [ ] **Step 2: Unit + Integration grün**

Run: `cd backend && composer test:unit && composer test:integration`
Expected: alle grün (inkl. der neuen Tests). Teardown danach: `bash bin/test-db-up.sh --down`.

- [ ] **Step 3: Push & CI beobachten**

```bash
git push
```
CI-Run beobachten bis grün; bei Fehlern fixen, dann Build+Release (GHCR) prüfen.

---

## Self-Review (durchgeführt)

**Spec-Coverage:** Jede §-Anforderung der Spec v3 hat eine Task:
- Discovery/listModels (§5.2) → A2–A6; Katalog-Tabelle (§5.3) → A7; Repo (§5.4) → A8; Service (§5.5) → A9; Refresh-Trigger (§5.6) → A11/A12; Effort-Spalte (§5.7) → B1; Emission + Router-Umbau (§5.8) → B2–B8; Admin-UI (§5.9) → B9/B10; Opus-Daten (§5.10) → C2/C3; Doku (§5.11) → C4; Sicherheit (§6: CSRF/Escaping/Timeout/Key) → in A11/B9/A2-A6; Tests (§7) → über alle Tasks + B11.
- DA-R2-Punkte: R2-1 → B9 (Score-Label); R2-2 → B6 (`cacheSegments=[]`); R2-3 → C4 (Cost-Trennung in CLAUDE.md); R2-4 → B7/B8; R2-5 → B6/B7 (Safety-Net) + C5 (Smoke/CI).

**Platzhalter-Scan:** Drei bewusst markierte Verifikations-Schritte (Gemini-Felder A4, Mistral-Felder A5, EU-Bedrock-ID C1) — jeweils mit Quelle + konkretem Ziel, keine offenen Code-TODOs. Service-Test B6 ist vollständig ausformuliert mit Fallback-Hinweis für nicht-mockbare Collaborators.

**Typ-Konsistenz:** `ModelDescriptor`-Felder, `parseModelsResponse(array): list<ModelDescriptor>` (statisch, alle 5 Provider), `LlmModelCatalogRepository::{upsert,markStale,listByProvider,listAvailableGrouped,find}`, `ModelCatalogService::{refreshProvider,refreshAll,EFFORT_LEVELS_ALL}` und `NormalizedRequest.effort` sind über alle Tasks konsistent benannt und signiert.

---

## Review-Fixes (Devils-Advocate-Workflow, 2026-06-01)

Ein 6-Reviewer-Workflow hat den Plan gegen den echten Code geprüft (35 Findings, 7 Blocker, Verdikt „Rework plan"). Eingearbeitet:

| Finding | Fix im Plan |
|---|---|
| **B1 (crit):** `final`-Klassen nicht mockbar; Projekt mockt **gar nicht** | Test-Strategie projektkonform: reine Logik → Unit (statische Methoden/`new`), DB/Repo/Router/Service → **Integration** (`MailPilot\Tests\TestCase`, `$this->pdo()`, `truncateAll()`, anonyme `LlmProvider`-Klassen, `FakeClaudeClient`). Betrifft A8, A9, B3/B4, B5, B6, B11. |
| **B2 (crit):** `ramsey/uuid` nicht vorhanden | `MailPilot\Util\Uuid::v4()` (A8). |
| **B3 (crit):** falsche Test-Basisklasse + statisches `self::pdo()` | `MailPilot\Tests\TestCase` + `$this->pdo()` (A8, B11). |
| **B4 (crit):** ISO-8601 → DATETIME-Schreibfehler | `LlmModelCatalogRepository::toMysqlDatetime()` konvertiert vor dem Bind (A8). |
| **B5 (high):** B8 falsche Datei/API | B8 → `Router.php`-Dispatch-Catch, `Response::error(503,'AI_OVERLOADED',…)`. |
| **B6 (high):** B10 falsche Strukturen | B10 → `<input list=datalist>` entfernen, `PromptController::store()` (create-only) Modell aus Rolle ableiten. |
| **B7 (high):** `composer cs:check`/`phpstan` existieren nicht | C5 → `composer cs-check && vendor/bin/phpstan analyse`. |
| **medium:** Safety-Net-Fallback-Modell-Divergenz | 0062 setzt aktive P-SUMMARY/P-REPLY ebenfalls auf opus-4-8 (C2). |
| **medium/low:** Effort-Emission als `public static`; Katalog-Bootstrap; OpenAI-Filter `transcribe/audio`; A11-`$params['id']`; „Router-nie-gebaut"-Fehlannahme korrigiert | jeweils inline gefixt. |

**Bewusst akzeptiert / dokumentiert (nicht blockierend):** OpenAI `reasoning_effort`-400 auf Nicht-Reasoning-Modellen → opt-in Effort (Default null), im Router-Fallback als 503/Toast sichtbar; Doppel-Logging-Invariante (`api_usage` EUR vs. `llm_call_log` USD) per Doku statt Test fixiert; Score-Effort bleibt im router-Mode `NULL` (Haiku, kein Reasoning) — gewollt; Gemini/Mistral-Chat-Filterfelder + EU-Bedrock-ID werden bei Implementierung (A4/A5/C1) gegen die Live-APIs verifiziert.
