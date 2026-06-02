# Lern-Loop: Korrekturen wirken aufs Scoring — Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (empfohlen) oder superpowers:executing-plans. Steps nutzen Checkbox-Syntax (`- [ ]`).

**Goal:** Händische Korrekturen (inkl. Begründung) beeinflussen zuverlässig das künftige Scoring ähnlicher / gleicher-Absender-Mails — über dauerhafte, gebundene Regeln + einen Per-Mail-Match-Score mit Bändern (auto/Vorschlag/ignorieren) + geschlossene Feedback-Schleife.

**Architecture:** Eine User-Korrektur erzeugt/aktualisiert **in-place** genau eine Regel pro `(Match-Key, Set-Feld)` (markiert via `origin_correction_id`, vom Autoclean geschützt). Pro eingehender Mail berechnet ein **MatchScorer** (deterministisch, gewichtete Features) einen Score 0–100; `match_mode` (`deterministic`|`llm`|`hybrid`) entscheidet, ob zusätzlich ein **RuleMatchService** (LLM, Rolle `match`, **nur bei Cache-Miss**, redacted, budgetiert) urteilt. Bänder: hoch→anwenden, mittel→Vorschlag (`pending_actions`), niedrig→ignorieren. Korrektur invalidiert den `claude_cache`-Eintrag der Mail. Alle stillen Degradationen werden sichtbar geloggt.

**Tech Stack:** PHP 8.4 (PSR-12 Tabs, `declare(strict_types=1)`), PDO, MariaDB, PHPUnit (**kein Mocking**; `MailPilot\Tests\TestCase` + `$this->pdo()`/`truncateAll()`; anon `LlmProvider` mit **`kind()`/`isHealthy()`/`complete()`/`listModels()`**). Vanilla-JS-Add-in. **Voraussetzung:** [Spec-1-Plan](2026-06-02-per-role-model-selection.md) ist umgesetzt (liefert `LlmRouter::ROLES`, `routing_mode`=Failover, `inference` über Router) — Plan 2 setzt darauf auf. Spec: [`2026-06-02-correction-learning-loop-design.md`](../specs/2026-06-02-correction-learning-loop-design.md).

**Verifizierter Ist-Stand (2026-06-02):**
- `ScoreOverrideRepository` hat `create/listEnabledForMatching/listForUser/recordApply/hasSimilarPriorityRule/hasSimilarTopicRule/findConflicts/softDelete` — **kein** Update-by-id. `score_override_rules` hat `source ENUM('user_manual','ki_inferred')`, `match_sender_key` NULLABLE, mehrere Set-Felder pro Row.
- `MailController::correctScore()` (Z. 478) ist der Korrektur-Endpoint; ruft `CorrectionRepository::record()` (Z. 534).
- `claude_cache` ist per `content_hash` (PK) + tenant ge-keyed; `MailScoringService::scoreBatch()` prüft Cache vor dem Prompt; `enrichScoresWithSender()` wendet `ScoreOverrideService::apply()` auf **alle** Score-Rows (Cache-Hits + frische) an.
- `pending_actions` (Migrationen 0018/0019) + `PendingActionRepository` (create/listPendingForUser/setStatus/...). Add-in: `addin/src/scripts/06-drafts-pending.js` rendert Pending-Items.
- `llm_models.role` ist `ENUM('score','summary','draft','inference')` → `match` braucht ein `ALTER`.
- `ScoreOverrideCleanupService::cleanup()` löscht `enabled=0` (bei `delete_disabled`) ODER `applies_count=0 AND created_at < now-INTERVAL N DAY`.

---

## Dateistruktur

| Datei | Verantwortung | Änderung |
|---|---|---|
| `backend/migrations/0065_*.sql` | DB | `origin_correction_id`; `learning.match_*`-Settings; `llm_models.role`-ENUM um `match`; `match`-Modell-Row + `llm.match.fallback_chain` |
| `backend/src/Services/Scoring/MatchScorer.php` | **NEU** | deterministischer Feature-Match-Score + Band-Einordnung |
| `backend/src/Services/RuleMatchService.php` | **NEU** | LLM/Hybrid-Match via Router (`match`), redacted, Cache-Miss-only, budgetiert, `local_only`→deterministic |
| `backend/src/Repositories/ScoreOverrideRepository.php` | Regel-Persistenz | `findUserDerivedSlot()`, `updateFields()`, `origin_correction_id` in `create()`, `disableLeastRecentlyUsed()`, `countUserDerived()` |
| `backend/src/Services/ScoreOverrideService.php` | Regel-Anwendung | Match-Score + Bänder; Vorschläge → `pending_actions` |
| `backend/src/Services/ScoreOverrideCleanupService.php` | Autoclean | user-derived (`origin_correction_id IS NOT NULL`) schützen |
| `backend/src/Services/RuleInferenceService.php` | Inferenz | Confidence→Match-Breite; Update-in-place; Vorschlag trägt `rule_id` |
| `backend/src/Controllers/MailController.php` | Korrektur + Feedback | Cache-Invalidierung bei Korrektur; Vorschlag bestätigen/verwerfen → Regel mutieren |
| `admin/src/Controllers/LlmController.php` + Views | Admin-UI | `match_mode`-Setting + Konsequenz-Text; `match`-Modell-Dropdown; Vorschlags-Review |
| `addin/src/scripts/06-drafts-pending.js` | Add-in | Vorschläge anzeigen + bestätigen/ändern/verwerfen |

---

## Task 1: Migration — Schema-Grundlage

**Files:**
- Create: `backend/migrations/0065_learning_loop.sql`
- Test: `backend/tests/Integration/MigrationLearningLoopTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\SettingsRepository;
use MailPilot\Tests\TestCase;

final class MigrationLearningLoopTest extends TestCase
{
	public function testSchemaAndSettingsSeeded(): void
	{
		$pdo = $this->pdo();
		$col = $pdo->query("SHOW COLUMNS FROM score_override_rules LIKE 'origin_correction_id'")->fetch();
		self::assertNotFalse($col, 'origin_correction_id fehlt');
		$role = $pdo->query("SHOW COLUMNS FROM llm_models LIKE 'role'")->fetch();
		self::assertStringContainsString("'match'", (string)$role['Type']);
		$set = new SettingsRepository($pdo);
		self::assertSame('deterministic', $set->getString('learning.match_mode', ''));
		self::assertSame('80', $set->getString('learning.match_auto_threshold', ''));
		self::assertSame('50', $set->getString('learning.match_suggest_threshold', ''));
		self::assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM llm_models WHERE role='match'")->fetchColumn());
	}
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd backend && bash bin/test-db-up.sh --down; composer test:integration -- --filter MigrationLearningLoopTest
```

- [ ] **Step 3: Implement** — `backend/migrations/0065_learning_loop.sql`:

```sql
-- Spec 2 (Marc 2026-06-02) — Lern-Loop: dauerhafte Regeln + Per-Mail-Match.

-- 1) Schutz-Markierung für aus User-Korrekturen abgeleitete Regeln.
ALTER TABLE score_override_rules
	ADD COLUMN origin_correction_id CHAR(36) NULL AFTER source,
	ADD KEY idx_sor_origin (origin_correction_id);

-- 2) llm_models.role um 'match' erweitern (Per-Mail-LLM-Bewertung).
ALTER TABLE llm_models
	MODIFY COLUMN role ENUM('score','summary','draft','inference','match') NOT NULL;

-- 3) match-Modell-Row (Anthropic Haiku — billig/schnell; läuft pro Mail).
INSERT INTO llm_models
	(id, provider_id, model_id, role, cost_per_mtok_in, cost_per_mtok_out, supports_caching, max_context, enabled, priority)
VALUES
	('00000000-0000-4000-8001-000000000054',
	 '00000000-0000-4000-8000-000000000050',
	 'claude-haiku-4-5-20251001', 'match', 0.80, 4.00, 1, 200000, 1, 10);

-- 4) Settings: Match-Modus (Default deterministic) + Bänder + Budget + Soft-Cap + match-Chain.
INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('learning.match_mode', 'deterministic', 'string',
	 'Spec 2: deterministic | llm | hybrid. Wie eine Mail gegen gelernte Regeln gematcht wird. LLM/hybrid nur bei Cache-Miss.'),
	('learning.match_auto_threshold', '80', 'int', 'Spec 2: Score >= → Regel automatisch anwenden.'),
	('learning.match_suggest_threshold', '50', 'int', 'Spec 2: suggest <= Score < auto → Vorschlag. < suggest → ignorieren.'),
	('learning.match_per_batch_budget', '5', 'int', 'Spec 2: max LLM-Match-Calls pro Scoring-Batch; darüber deterministischer Fallback.'),
	('learning.score_rules_soft_cap', '200', 'int', 'Spec 2: max aktive user-derived Override-Regeln pro User; darüber LRU-Deaktivierung.'),
	('llm.match.fallback_chain', '["00000000-0000-4000-8000-000000000050"]', 'json',
	 'Spec 2: Failover-Chain für die match-Rolle. Initial nur Anthropic.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
```

> **Hinweis:** `system_settings.type` ist ein ENUM mit `'string'/'int'/'json'` (vgl. 0051/0052) — gültig. Falls `llm_call_log.role` ebenfalls ein ENUM ist, in derselben Migration um `'match'` erweitern — **vor der Umsetzung per `SHOW COLUMNS FROM llm_call_log LIKE 'role'` prüfen** und nur dann altern.

- [ ] **Step 4: Run — expect PASS**

```bash
cd backend && bash bin/test-db-up.sh --down; composer test:integration -- --filter MigrationLearningLoopTest
```

- [ ] **Step 5: Commit**

```bash
git add backend/migrations/0065_learning_loop.sql backend/tests/Integration/MigrationLearningLoopTest.php
git commit -m "feat(migration): 0065 Lern-Loop-Schema (origin_correction_id, match-Rolle/Modell, match-Settings)"
```

---

## Task 2: `ScoreOverrideRepository` — Update-in-place + Soft-Cap

**Files:**
- Modify: `backend/src/Repositories/ScoreOverrideRepository.php`
- Test: `backend/tests/Integration/Repositories/ScoreOverrideRepositoryUpdateTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Repositories;

use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

final class ScoreOverrideRepositoryUpdateTest extends TestCase
{
	public function testTwoCorrectionsSameSenderAndFieldYieldOneRule(): void
	{
		$this->truncateAll();
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());

		$repo->create($tenantId, $userId, [
			'match_sender_key' => 'sk:acme', 'set_priority' => 4,
			'source' => 'ki_inferred', 'origin_correction_id' => Uuid::v4(), 'enabled' => true,
		]);
		$slot = $repo->findUserDerivedSlot($tenantId, $userId, 'sk:acme', 'priority');
		self::assertNotNull($slot, 'erste Regel angelegt');

		$repo->updateFields($tenantId, (string)$slot['id'], ['set_priority' => 2]);
		$rows = $repo->listForUser($tenantId, $userId);
		$priorityRules = array_values(array_filter($rows, static fn(array $r): bool => $r['match_sender_key'] === 'sk:acme' && $r['set_priority'] !== null));
		self::assertCount(1, $priorityRules, 'genau eine priority-Regel pro (sender_key,Feld)');
		self::assertSame(2, (int)$priorityRules[0]['set_priority']);
	}
}
```

- [ ] **Step 2: Run — expect FAIL** (`findUserDerivedSlot`/`updateFields` fehlen; `create` kennt `origin_correction_id` noch nicht)

```bash
cd backend && composer test:integration -- --filter ScoreOverrideRepositoryUpdateTest
```

- [ ] **Step 3: Implement** — in `ScoreOverrideRepository.php`:

(a) `create()` um `origin_correction_id` erweitern (INSERT-Spaltenliste + Values; bind aus `$data['origin_correction_id'] ?? null`).

(b) Neue Methoden:

```php
	/**
	 * Findet die EINE user-derived Regel für (sender_key, Set-Feld), falls vorhanden.
	 * @return array<string,mixed>|null
	 */
	public function findUserDerivedSlot(string $tenantId, string $userId, string $senderKey, string $field): ?array
	{
		$col = match ($field) {
			'priority'        => 'set_priority',
			'action_required' => 'set_action_required',
			'label'           => 'set_label',
			'folder_segments' => 'set_folder_segments',
			default           => throw new \InvalidArgumentException("unknown field $field"),
		};
		$stmt = $this->db->prepare(
			"SELECT * FROM score_override_rules
			 WHERE tenant_id = :t AND user_id = :u AND match_sender_key = :sk
			   AND origin_correction_id IS NOT NULL AND $col IS NOT NULL AND deleted_at IS NULL
			 ORDER BY created_at ASC LIMIT 1"
		);
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':sk' => $senderKey]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * Aktualisiert Set-/Match-Felder einer bestehenden Regel in-place.
	 * @param array<string,mixed> $fields
	 */
	public function updateFields(string $tenantId, string $ruleId, array $fields): void
	{
		$allowed = ['set_priority', 'set_action_required', 'set_label', 'set_folder_segments',
			'match_subject_regex', 'match_from_local', 'enabled'];
		$sets = [];
		$params = [':id' => $ruleId, ':t' => $tenantId];
		foreach ($fields as $k => $v) {
			if (!in_array($k, $allowed, true)) { continue; }
			$sets[] = "`$k` = :$k";
			$params[":$k"] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v;
		}
		if ($sets === []) { return; }
		$this->db->prepare(
			'UPDATE score_override_rules SET ' . implode(', ', $sets)
			. ', updated_at = UTC_TIMESTAMP(3) WHERE id = :id AND tenant_id = :t'
		)->execute($params);
	}

	/** Anzahl aktiver user-derived Regeln (für Soft-Cap). */
	public function countUserDerived(string $tenantId, string $userId): int
	{
		$stmt = $this->db->prepare(
			'SELECT COUNT(*) FROM score_override_rules
			 WHERE tenant_id = :t AND user_id = :u
			   AND origin_correction_id IS NOT NULL AND enabled = 1 AND deleted_at IS NULL'
		);
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		return (int)$stmt->fetchColumn();
	}

	/** Deaktiviert (nicht löscht) die am längsten nicht angewandte user-derived Regel. */
	public function disableLeastRecentlyUsed(string $tenantId, string $userId): ?string
	{
		$stmt = $this->db->prepare(
			'SELECT id FROM score_override_rules
			 WHERE tenant_id = :t AND user_id = :u
			   AND origin_correction_id IS NOT NULL AND enabled = 1 AND deleted_at IS NULL
			 ORDER BY last_applied_at IS NULL DESC, last_applied_at ASC, created_at ASC LIMIT 1'
		);
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		$id = $stmt->fetchColumn();
		if ($id === false) { return null; }
		$this->db->prepare('UPDATE score_override_rules SET enabled = 0, updated_at = UTC_TIMESTAMP(3) WHERE id = :id')
			->execute([':id' => $id]);
		return (string)$id;
	}
```

- [ ] **Step 4: Run — expect PASS**

```bash
cd backend && composer test:integration -- --filter ScoreOverrideRepositoryUpdateTest
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repositories/ScoreOverrideRepository.php backend/tests/Integration/Repositories/ScoreOverrideRepositoryUpdateTest.php
git commit -m "feat(rules): ScoreOverrideRepository update-in-place + origin_correction_id + Soft-Cap-Helfer"
```

---

## Task 3: `ScoreOverrideCleanupService` — user-derived Regeln schützen

**Files:**
- Modify: `backend/src/Services/ScoreOverrideCleanupService.php` (`cleanup()`)
- Test: `backend/tests/Integration/ScoreOverrideCleanupProtectTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\ScoreOverrideCleanupService;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

final class ScoreOverrideCleanupProtectTest extends TestCase
{
	public function testUserDerivedRuleSurvivesAutoclean(): void
	{
		$this->truncateAll();
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$pdo = $this->pdo();
		$id = (new ScoreOverrideRepository($pdo))->create($tenantId, $userId, [
			'match_sender_key' => 'sk:x', 'set_priority' => 4,
			'source' => 'ki_inferred', 'origin_correction_id' => Uuid::v4(), 'enabled' => false,
		]);
		$pdo->prepare("UPDATE score_override_rules SET created_at = (UTC_TIMESTAMP(3) - INTERVAL 30 DAY) WHERE id = :id")->execute([':id' => $id]);

		$set = new SettingsRepository($pdo);
		$set->set('score_rule_autoclean.enabled', '1');
		$set->set('score_rule_autoclean.delete_disabled', '1');
		$set->set('score_rule_autoclean.delete_unused_after_days', '7');

		(new ScoreOverrideCleanupService($pdo, $set, $this->logger()))->cleanup();

		$alive = $pdo->prepare('SELECT deleted_at FROM score_override_rules WHERE id = :id');
		$alive->execute([':id' => $id]);
		self::assertNull($alive->fetchColumn(), 'user-derived Regel darf NICHT gelöscht werden');
	}
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd backend && composer test:integration -- --filter ScoreOverrideCleanupProtectTest
```

- [ ] **Step 3: Implement** — in `ScoreOverrideCleanupService::cleanup()` dem Lösch-`UPDATE` `AND origin_correction_id IS NULL` hinzufügen:

```php
		$sql = 'UPDATE score_override_rules
				SET deleted_at = UTC_TIMESTAMP(3)
				WHERE deleted_at IS NULL
				  AND origin_correction_id IS NULL
				  AND (' . implode(' OR ', $conditions) . ')';
```

(user-derived Regeln werden so vom Autoclean ausgenommen; ihre Begrenzung übernimmt der Soft-Cap/LRU.)

- [ ] **Step 4: Run — expect PASS + bestehende Cleanup-Tests grün**

```bash
cd backend && composer test:integration -- --filter "ScoreOverrideCleanup"
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Services/ScoreOverrideCleanupService.php backend/tests/Integration/ScoreOverrideCleanupProtectTest.php
git commit -m "fix(rules): Autoclean schützt user-derived Regeln (origin_correction_id)"
```

---

## Task 4: `MatchScorer` (deterministisch)

**Files:**
- Create: `backend/src/Services/Scoring/MatchScorer.php`
- Test: `backend/tests/Unit/Scoring/MatchScorerTest.php`

- [ ] **Step 1: Failing test (Unit, reine Funktion)**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Scoring;

use MailPilot\Services\Scoring\MatchScorer;
use PHPUnit\Framework\TestCase;

final class MatchScorerTest extends TestCase
{
	public function testExactSenderScoresHighAndBandsResolve(): void
	{
		$scorer = new MatchScorer(autoThreshold: 80, suggestThreshold: 50);
		$rule = ['match_sender_key' => 'sk:acme', 'match_from_local' => null, 'match_subject_regex' => null];
		$mail = ['sender_key' => 'sk:acme', 'from_local' => 'billing', 'subject' => 'Rechnung 5'];

		$score = $scorer->score($rule, $mail);
		self::assertGreaterThanOrEqual(80, $score);
		self::assertSame('auto', $scorer->band($score));
		self::assertSame('ignore', $scorer->band(10));
		self::assertSame('suggest', $scorer->band(60));
	}

	public function testNoSignalScoresZero(): void
	{
		$scorer = new MatchScorer(80, 50);
		$rule = ['match_sender_key' => 'sk:other', 'match_from_local' => null, 'match_subject_regex' => null];
		$mail = ['sender_key' => 'sk:acme', 'from_local' => 'x', 'subject' => 'y'];
		self::assertSame(0, $scorer->score($rule, $mail));
	}
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd backend && composer test:unit -- --filter MatchScorerTest
```

- [ ] **Step 3: Implement** — `backend/src/Services/Scoring/MatchScorer.php`:

```php
<?php
declare(strict_types=1);

namespace MailPilot\Services\Scoring;

/**
 * Spec 2 — deterministischer Per-Mail-Match einer Regel. Gewichtete Features
 * → Score 0..100 (gekappt). Billig + erklärbar; kein LLM. Gewichte sind
 * Defaults (YAGNI: erst global, später tunebar).
 */
final class MatchScorer
{
	private const W_SENDER_EXACT  = 70;
	private const W_DOMAIN        = 35;
	private const W_FROM_LOCAL    = 25;
	private const W_SUBJECT_REGEX = 30;

	public function __construct(
		private readonly int $autoThreshold = 80,
		private readonly int $suggestThreshold = 50,
	) {
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $mail  mit sender_key, from_local, subject
	 */
	public function score(array $rule, array $mail): int
	{
		$ruleSender = $rule['match_sender_key'] !== null ? (string)$rule['match_sender_key'] : '';
		$mailSender = (string)($mail['sender_key'] ?? '');
		$points = 0;

		if ($ruleSender !== '' && $mailSender !== '') {
			if ($ruleSender === $mailSender) {
				$points += self::W_SENDER_EXACT;
			} elseif ($this->sameDomain($ruleSender, $mailSender)) {
				$points += self::W_DOMAIN;
			}
		}
		if ($rule['match_from_local'] !== null
			&& (string)$rule['match_from_local'] === strtolower((string)($mail['from_local'] ?? ''))) {
			$points += self::W_FROM_LOCAL;
		}
		if ($rule['match_subject_regex'] !== null) {
			$pattern = (string)$rule['match_subject_regex'];
			set_error_handler(static fn(): bool => true);
			try {
				$hit = @preg_match($pattern, (string)($mail['subject'] ?? ''));
			} finally {
				restore_error_handler();
			}
			if ($hit === 1) {
				$points += self::W_SUBJECT_REGEX;
			}
		}
		return min(100, $points);
	}

	/** @return 'auto'|'suggest'|'ignore' */
	public function band(int $score): string
	{
		if ($score >= $this->autoThreshold) { return 'auto'; }
		if ($score >= $this->suggestThreshold) { return 'suggest'; }
		return 'ignore';
	}

	private function sameDomain(string $a, string $b): bool
	{
		$da = strstr($a, '@') ?: $a;
		$db = strstr($b, '@') ?: $b;
		return $da !== '' && $da === $db;
	}
}
```

- [ ] **Step 4: Run — expect PASS**

```bash
cd backend && composer test:unit -- --filter MatchScorerTest
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Services/Scoring/MatchScorer.php backend/tests/Unit/Scoring/MatchScorerTest.php
git commit -m "feat(learning): deterministischer MatchScorer (gewichtete Features + Bänder)"
```

---

## Task 5: `RuleMatchService` (LLM/Hybrid-Match)

**Files:**
- Create: `backend/src/Services/RuleMatchService.php`
- Test: `backend/tests/Integration/Services/RuleMatchServiceTest.php`

- [ ] **Step 1: Failing test** — anon `LlmProvider` (Rolle `match`) fängt den `NormalizedRequest`; prüft `match`-Modell + **redacted** Payload (keine Roh-IBAN).

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
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\RedactionService;
use MailPilot\Services\RuleMatchService;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

final class RuleMatchServiceTest extends TestCase
{
	private const PID = '00000000-0000-4000-8000-0000000000f0';

	public function testLlmMatchUsesMatchModelAndRedactsPayload(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PID . "'");
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'M', 'anthropic', 'http://x', 0, 1, 10)")->execute([self::PID]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (?, ?, 'claude-haiku-4-5-20251001', 'match', 1, 10)")->execute([Uuid::v4(), self::PID]);
		foreach ([['llm.routing_mode', 'router'], ['llm.privacy_mode', 'cloud_allowed'],
			['llm.match.fallback_chain', '["' . self::PID . '"]']] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`,`value`,`type`) VALUES (?,?,"string")
				ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$k, $v]);
		}

		$capture = new class implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				$this->seen = $r;
				return new NormalizedResponse('{"score":90}', ['inputTokens' => 5, 'outputTokens' => 2], 'end_turn', $r->modelHint, 'anthropic');
			}
			public function listModels(): array { return []; }
		};
		$router = new LlmRouter(['anthropic' => $capture], new LlmProviderRepository($pdo),
			new SettingsRepository($pdo), new NullLogger(), new LlmModelRepository($pdo));

		$svc = new RuleMatchService($router, new RedactionService(), new SettingsRepository($pdo), new NullLogger());
		$rule = ['id' => 'r1', 'match_sender_key' => 'sk:acme', 'set_priority' => 4];
		$mail = ['sender_key' => 'sk:acme', 'from_email' => 'a@acme.de', 'subject' => 'IBAN DE89370400440532013000', 'body_text' => 'x'];

		$score = $svc->scoreMatch($rule, $mail);

		self::assertSame(90, $score);
		self::assertNotNull($capture->seen);
		self::assertSame('claude-haiku-4-5-20251001', $capture->seen->modelHint);
		$payload = $capture->seen->systemPrompt . ' ' . json_encode($capture->seen->messages);
		self::assertStringNotContainsString('DE89370400440532013000', $payload, 'IBAN muss redacted sein');
	}
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd backend && composer test:integration -- --filter RuleMatchServiceTest
```

- [ ] **Step 3: Implement** — `backend/src/Services/RuleMatchService.php`:

```php
<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Llm\LlmAllProvidersDownException;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Repositories\SettingsRepository;
use Psr\Log\LoggerInterface;

/**
 * Spec 2 — LLM-gestützter Per-Mail-Match (Rolle 'match'). Nur im match_mode
 * 'llm'/'hybrid' und NUR bei Cache-Miss aufgerufen. Payload redacted; bei
 * leerer/ausgefallener Chain (z.B. local_only ohne lokales match-Modell)
 * liefert er null → Caller fällt auf den deterministischen MatchScorer zurück.
 */
final class RuleMatchService
{
	public function __construct(
		private readonly LlmRouter $router,
		private readonly RedactionService $redactor,
		private readonly SettingsRepository $settings,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $mail
	 * @return int|null  0..100 oder null (→ deterministic-Fallback)
	 */
	public function scoreMatch(array $rule, array $mail): ?int
	{
		$domain  = strstr((string)($mail['from_email'] ?? ''), '@') ?: '(unknown)';
		$subject = $this->redactor->redact((string)($mail['subject'] ?? ''));
		$ruleDesc = $this->describeRule($rule);

		$system = 'Du bewertest, wie gut eine E-Mail zu einer gelernten Sortier-Regel passt. '
			. 'Antworte NUR mit JSON {"score": <0-100>}. 0 = passt nicht, 100 = passt perfekt.';
		$user = "REGEL:\n$ruleDesc\n\nMAIL:\nAbsender-Domain: $domain\nBetreff: $subject";

		$req = new NormalizedRequest(
			systemPrompt: $system,
			messages:     [['role' => 'user', 'content' => $user]],
			maxTokens:    50,
			temperature:  0.0,
			modelHint:    '',
			responseFormat: 'json_object',
		);
		try {
			$resp = $this->router->complete($req, 'match');
		} catch (LlmAllProvidersDownException $e) {
			$this->logger->info('rule_match.unavailable_fallback_deterministic', ['err' => $e->getMessage()]);
			return null;
		}
		$parsed = json_decode($resp->content, true);
		if (!is_array($parsed) || !isset($parsed['score'])) {
			return null;
		}
		return max(0, min(100, (int)$parsed['score']));
	}

	/** @param array<string,mixed> $rule */
	private function describeRule(array $rule): string
	{
		$parts = [];
		if (($rule['match_sender_key'] ?? null) !== null)    { $parts[] = 'Absender=' . $rule['match_sender_key']; }
		if (($rule['match_from_local'] ?? null) !== null)    { $parts[] = 'from_local=' . $rule['match_from_local']; }
		if (($rule['match_subject_regex'] ?? null) !== null) { $parts[] = 'Betreff-Muster=' . $rule['match_subject_regex']; }
		if (($rule['set_label'] ?? null) !== null)           { $parts[] = '→ Label=' . $rule['set_label']; }
		if (($rule['set_priority'] ?? null) !== null)        { $parts[] = '→ Priority=' . $rule['set_priority']; }
		return $parts === [] ? '(leer)' : implode(', ', $parts);
	}
}
```

> **`local_only`:** greift implizit über den Router-Privacy-Filter. Ohne lokales `match`-Modell wirft der Router `LlmAllProvidersDownException` → `null` → Caller nutzt den deterministischen Score (Test in Task 6).

- [ ] **Step 4: Run — expect PASS**

```bash
cd backend && composer test:integration -- --filter RuleMatchServiceTest
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Services/RuleMatchService.php backend/tests/Integration/Services/RuleMatchServiceTest.php
git commit -m "feat(learning): RuleMatchService (LLM-Match via Router-Rolle match, redacted, Fallback null)"
```

---

## Task 6: `ScoreOverrideService` — Match-Score + Bänder + Vorschläge

**Files:**
- Modify: `backend/src/Services/ScoreOverrideService.php` (Konstruktor + `apply()`), `backend/src/Services/MailScoringService.php` (`$wasCacheHit` durchreichen)
- Modify: `backend/src/Http/Kernel.php` (DI)
- Test: `backend/tests/Integration/Services/ScoreOverrideBandsTest.php`

- [ ] **Step 1: Failing test** — am bestehenden `ScoreOverrideServiceTest` orientieren; `markTestIncomplete`-Skelett für rote Stufe ohne erfundene API:

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Tests\TestCase;

final class ScoreOverrideBandsTest extends TestCase
{
	public function testHighMatchApplies_MidMatchSuggests_LowIgnores(): void
	{
		self::markTestIncomplete(
			'Aufbau analog backend/tests/Integration/Services/ScoreOverrideServiceTest.php: '
			. 'Tenant/User + drei user-derived Regeln mit unterschiedlich starkem Match zur '
			. 'Test-Mail (exakter sender_key → auto, nur Domain → suggest, kein Signal → ignore), '
			. 'match_mode=deterministic; ScoreOverrideService::apply() aufrufen. Asserts: '
			. 'auto → $score mutiert; suggest → pending_actions-Row (kind=score_suggestion) + $score unverändert; '
			. 'ignore → keine Änderung/kein pending.'
		);
	}
}
```

- [ ] **Step 2: Run — expect INCOMPLETE**

```bash
cd backend && composer test:integration -- --filter ScoreOverrideBandsTest
```

- [ ] **Step 3: Implement**

(a) `ScoreOverrideService`-Konstruktor um **optionale** Abhängigkeiten am Ende erweitern (defaulten null → bricht bestehende Aufrufer NICHT):

```php
	public function __construct(
		private readonly ScoreOverrideRepository $rules,
		private readonly LoggerInterface $logger,
		private readonly ?\MailPilot\Services\Scoring\MatchScorer $matchScorer = null,
		private readonly ?RuleMatchService $ruleMatch = null,
		private readonly ?SettingsRepository $settings = null,
		private readonly ?\MailPilot\Repositories\PendingActionRepository $pending = null,
	) {
	}
```

(b) In `apply()` die binäre `matches()`-Schleife durch Match-Score + Band ersetzen. Sind die optionalen Deps `null` (Alt-Aufrufer) → **heutiges binäres Verhalten** beibehalten (Rückwärtskompatibilität). Sonst pro enabled Regel: `score = matchScorer->score($rule, $mailFeatures)`; im `match_mode` ∈ {llm,hybrid} **und** `$wasCacheHit === false` **und** `band==suggest` **und** Batch-Budget übrig → `ruleMatch->scoreMatch()` als Verfeinerung (`null` → deterministischen Score behalten). Dann:
- `auto` → bestehende `applySetFieldsOrthogonal()` (orthogonal, Sticky-Schutz unverändert).
- `suggest` → `pending->create(kind:'score_suggestion', payload:{rule_id, mail_id, proposed:{label/priority/...}})`; **kein** Score-Write.
- `ignore` → nichts.

(c) Signatur `apply(..., bool $wasCacheHit = false)` ergänzen; `MailScoringService::enrichScoresWithSender()` reicht pro Row durch, ob sie ein Cache-Hit war (aus `scoreBatch`-`$cacheHits`).

(d) Kernel: `ScoreOverrideService` mit `MatchScorer`(aus `learning.match_*`-Settings), `RuleMatchService`, `SettingsRepository`, `PendingActionRepository` konstruieren. `pending_actions.kind`: falls ENUM → in Migration 0065 um `'score_suggestion'` erweitern (**vorher `SHOW COLUMNS FROM pending_actions LIKE 'kind'` prüfen**).

- [ ] **Step 4: Run — expect PASS + bestehende `ScoreOverrideServiceTest` grün**

```bash
cd backend && composer test:integration -- --filter "ScoreOverride"
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Services/ScoreOverrideService.php backend/src/Services/MailScoringService.php backend/src/Http/Kernel.php backend/tests/Integration/Services/ScoreOverrideBandsTest.php
git commit -m "feat(learning): Match-Score + Bänder (auto/Vorschlag/ignorieren) in ScoreOverrideService"
```

---

## Task 7: Cache-Invalidierung bei Korrektur

**Files:**
- Modify: `backend/src/Repositories/CacheRepository.php` (`purgeByContentHash()`)
- Modify: `backend/src/Controllers/MailController.php` (`correctScore()`), `backend/src/Services/MailScoringService.php` (`contentHash` → `public static` heben)
- Test: `backend/tests/Integration/CorrectionInvalidatesCacheTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\CacheRepository;
use MailPilot\Tests\TestCase;

final class CorrectionInvalidatesCacheTest extends TestCase
{
	public function testPurgeByContentHashRemovesOnlyThatEntry(): void
	{
		$this->truncateAll();
		[$tenantId] = $this->insertTenantAndUser();
		$cache = new CacheRepository($this->pdo(), 30);
		$cache->put($tenantId, 'hash-A', 'P-SCORE@1.6+code1', 'm', ['label' => 'newsletter']);
		$cache->put($tenantId, 'hash-B', 'P-SCORE@1.6+code1', 'm', ['label' => 'direct']);

		$removed = $cache->purgeByContentHash($tenantId, 'hash-A');

		self::assertSame(1, $removed);
		self::assertNull($cache->get($tenantId, 'hash-A', 'P-SCORE@1.6+code1'));
		self::assertNotNull($cache->get($tenantId, 'hash-B', 'P-SCORE@1.6+code1'), 'andere Mail unberührt');
	}
}
```

- [ ] **Step 2: Run — expect FAIL**

```bash
cd backend && composer test:integration -- --filter CorrectionInvalidatesCacheTest
```

- [ ] **Step 3: Implement**

(a) `CacheRepository`:

```php
	/** Invalidiert gezielt alle Cache-Rows einer Mail (alle Prompt-Versionen). */
	public function purgeByContentHash(string $tenantId, string $contentHash): int
	{
		$stmt = $this->db->prepare('DELETE FROM claude_cache WHERE tenant_id = :t AND content_hash = :h');
		$stmt->execute([':t' => $tenantId, ':h' => $contentHash]);
		return $stmt->rowCount();
	}
```

(b) `MailScoringService::contentHash()` von `private` auf `public static` heben (gleiche Formel; `maxBodyBytes` als Parameter mitgeben oder Default 2048). (c) `MailController::correctScore()` nach `CorrectionRepository::record(...)`: den `content_hash` der Mail über die gemeinsame Formel bilden und `CacheRepository::purgeByContentHash($tenantId, $hash)` aufrufen. **DRY:** beide nutzen exakt dieselbe Hash-Funktion.

- [ ] **Step 4: Run — expect PASS + Unit grün**

```bash
cd backend && composer test:integration -- --filter CorrectionInvalidatesCacheTest && composer test:unit
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repositories/CacheRepository.php backend/src/Controllers/MailController.php backend/src/Services/MailScoringService.php backend/tests/Integration/CorrectionInvalidatesCacheTest.php
git commit -m "feat(learning): Korrektur invalidiert claude_cache der Mail (gezielt per content_hash)"
```

---

## Task 8: `RuleInferenceService` — Confidence→Breite + Update-in-place + Feedback

**Files:**
- Modify: `backend/src/Services/RuleInferenceService.php`, `backend/src/Controllers/MailController.php` (Feedback-Endpoint)
- Test: `backend/tests/Integration/InferenceUpdateInPlaceTest.php`

- [ ] **Step 1: Failing test** (Skelett am bestehenden `InferAllFromCorrectionTest`)

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Tests\TestCase;

final class InferenceUpdateInPlaceTest extends TestCase
{
	public function testSecondCorrectionUpdatesRuleNotCreatesSibling(): void
	{
		self::markTestIncomplete(
			'Aufbau analog InferAllFromCorrectionTest (mit Router-Injektion nach Spec 1): '
			. 'zwei Korrekturen desselben Absenders inferieren; assert: genau EINE user-derived '
			. 'Regel pro (sender_key,Set-Feld), origin_correction_id gesetzt, Confidence-Bump statt zweiter Zeile.'
		);
	}
}
```

- [ ] **Step 2: Run — expect INCOMPLETE**

- [ ] **Step 3: Implement**

(a) In den Regel-Erzeugungspfaden (`inferScoreRule`/`inferAllFromCorrection` → `scoreOverrides->create`): vor dem Anlegen `findUserDerivedSlot($tenant,$user,$senderKey,$field)`; existiert ein Slot → `updateFields()` statt `create()`. `create()` immer mit `origin_correction_id` (auslösende `mail_score_corrections.id`) + `source='ki_inferred'`. Confidence → Match-Breite: hoch → `match_sender_key` (Domain erlaubt); niedrig → zusätzlich `match_from_local` (enger).
(b) **Soft-Cap:** nach `create()` `countUserDerived()`; über `learning.score_rules_soft_cap` → `disableLeastRecentlyUsed()` + Log `score_override.lru_disabled` (Task 10).
(c) **Feedback-Endpoint** (`MailController`): ein bestätigter/verworfener `score_suggestion` trägt `rule_id` im Payload → bestätigen: `recordApply` + `updateFields(enabled:1)`; verwerfen: `updateFields` verengen / `enabled:0`. **Keine** neue Regel. `pending_actions` via `setStatus` abschließen.

- [ ] **Step 4: Run — expect PASS + bestehende Inferenz-Tests grün**

```bash
cd backend && composer test:integration -- --filter "Inference|InferAll"
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Services/RuleInferenceService.php backend/src/Controllers/MailController.php backend/tests/Integration/InferenceUpdateInPlaceTest.php
git commit -m "feat(learning): Inferenz update-in-place (Confidence→Breite, origin_correction_id, Soft-Cap, Feedback)"
```

---

## Task 9: Admin- + Add-in-UI

**Files:**
- Modify: `admin/src/Controllers/LlmController.php` + `admin/src/Views/llm/routing.php` (`match_mode`-Select + Konsequenz-Text; `match`-Modell-Dropdown via Spec-1-`ROLES`)
- Modify: Admin-Pending-Review-View (Liste `pending_actions` `kind=score_suggestion`)
- Modify: `addin/src/scripts/06-drafts-pending.js` + `bash addin/build-bundle.sh`

- [ ] **Step 1: Admin `match_mode`** — Select auf `/admin/llm/routing` (`deterministic`/`llm`/`hybrid`) mit Konsequenz-Text aus der Spec-Tabelle; `saveRouting()` persistiert. `match` erscheint dank Spec-1-`ROLES` automatisch in Chain-/Modell-UI → Hinweis „läuft pro Mail".
- [ ] **Step 2: Admin Vorschlags-Review** — Sektion, die `PendingActionRepository::listPendingForUser(kind:'score_suggestion')` rendert; Buttons rufen den Feedback-Endpoint (Task 8c).
- [ ] **Step 3: Add-in** — `06-drafts-pending.js` um `score_suggestion`-Items + Annehmen/Verwerfen erweitern (gleicher API-Pfad wie Draft-Pending). Danach `bash addin/build-bundle.sh` (Bundle neu) — `taskpane.js` **niemals** direkt editieren; Drift-Tests grün.
- [ ] **Step 4: Lint/Smoke**

```bash
cd backend && composer cs-check
cd ../addin && bash build-bundle.sh
```

- [ ] **Step 5: Commit**

```bash
git add admin/src/Controllers/LlmController.php admin/src/Views/ addin/src/scripts/06-drafts-pending.js addin/src/taskpane.js
git commit -m "feat(learning,ui): match_mode-Setting + Vorschlags-Review (Admin + Add-in)"
```

---

## Task 10: Observability der stillen Degradationen (D8)

**Files:**
- Modify: `ScoreOverrideService`, `RuleMatchService`, `RuleInferenceService` + Admin-Banner
- Test: `backend/tests/Integration/Services/ObservabilityLogTest.php`

- [ ] **Step 1: Failing test** (anon `Psr\Log\AbstractLogger`, der Records sammelt — reale Klasse, kein Mock)

```php
<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Tests\TestCase;

final class ObservabilityLogTest extends TestCase
{
	public function testLruDisableEmitsMarker(): void
	{
		self::markTestIncomplete(
			'anon AbstractLogger sammelt Records; Soft-Cap überschreiten → Record mit '
			. 'Marker "score_override.lru_disabled". Analog rule_match.unavailable_fallback_deterministic '
			. 'und rule_match.budget_exceeded_fallback.'
		);
	}
}
```

- [ ] **Step 2: Run — expect INCOMPLETE**

- [ ] **Step 3: Implement** — jede stille Degradation emittiert einen sprechenden Marker: `score_override.lru_disabled`, `rule_match.unavailable_fallback_deterministic` (Task 5), `rule_match.budget_exceeded_fallback`. Plus der einmalige Admin-Hinweis aus der Spec-1-Migration. Optional kleine „Lern-Loop-Status"-Admin-Sektion (deaktivierte Regeln, letzter Budget-Fallback).

- [ ] **Step 4: Run grün** · **Step 5: Commit**

```bash
git add backend/src/Services/ backend/tests/Integration/Services/ObservabilityLogTest.php
git commit -m "feat(learning): Observability-Marker für stille Degradationen (LRU/Budget/local_only)"
```

---

## Abschluss

- [ ] **Voller Lauf grün:** `cd backend && composer test:unit && composer test:integration && composer cs-check && vendor/bin/phpstan analyse`
- [ ] **Add-in-Bundle** neu gebaut + Drift-Tests grün.
- [ ] **Finaler Code-Review**, dann `superpowers:finishing-a-development-branch`.

## Spec-Abdeckung (Self-Review)

| Spec-Punkt | Task |
|---|---|
| D1 Korrektur=dauerhafte, gebundene Regel | T1, T2, T8 |
| D2 Per-Mail-Match-Score + Modi | T4, T5, T6 |
| D3 Bänder auto/suggest/ignore | T6 |
| D4 Feedback (mutiert, erzeugt nicht) | T8 |
| D5 Cache-Invalidierung bei Korrektur | T7 |
| D6 neue Rolle `match` | T1, T5, T9 |
| D7 Privacy/Redaction + local_only→deterministic | T5, T6 |
| D8 Observability | T10 |

**Hinweise:** Service-/UI-Tests, die ein komplexes DI-Setup brauchen (T6/T8/T10), sind als `markTestIncomplete`-Skelette angelegt und werden in Step 3 am Muster der genannten Bestandstests (`ScoreOverrideServiceTest`, `InferAllFromCorrectionTest`) zu echten Tests ausgebaut — bewusst, um keine erfundene Harness-API zu zementieren. ENUM-Erweiterungen (`pending_actions.kind`, evtl. `llm_call_log.role`) vor der Umsetzung per `SHOW COLUMNS` verifizieren.
