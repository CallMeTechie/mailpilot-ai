<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Llm\LlmRouter;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageCounterRepository;
use MailPilot\Services\RedactionService;
use MailPilot\Services\RuleInferenceService;
use MailPilot\Services\RuleMatchService;
use MailPilot\Services\Scoring\MatchScorer;
use MailPilot\Services\ScoreOverrideService;
use MailPilot\Tests\Fixtures\ScriptedLlmProvider;
use MailPilot\Tests\Support\SeedsInferenceRouting;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use PDO;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

/**
 * Task 10 (Spec 2, D8) — Observability der stillen Degradationen.
 *
 * Jede stille Degradation muss einen sprechenden Marker emittieren, damit der
 * Betreiber sie im Log sieht statt sie schweigend hinzunehmen:
 *   - score_override.lru_disabled                   (RuleInferenceService, Task 8)
 *   - rule_match.unavailable_fallback_deterministic (RuleMatchService, Task 5)
 *   - rule_match.budget_exceeded_fallback           (ScoreOverrideService, Task 10)
 *
 * KEIN Mock: ein realer anonymer Psr\Log\AbstractLogger sammelt die Records
 * (level/message/context) und die Tests assertieren die Marker.
 *
 * @group integration
 */
final class ObservabilityLogTest extends TestCase
{
	use SeedsInferenceRouting;

	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000d8';

	/**
	 * Realer (kein Mock) Sammel-Logger: speichert jede Log-Zeile als Tupel.
	 */
	private function recordingLogger(): object
	{
		return new class extends AbstractLogger {
			/** @var list<array{level:mixed, message:string, context:array<mixed>}> */
			public array $records = [];

			public function log($level, $message, array $context = []): void
			{
				$this->records[] = [
					'level'   => $level,
					'message' => (string)$message,
					'context' => $context,
				];
			}

			/** @return list<array{level:mixed, message:string, context:array<mixed>}> */
			public function withMarker(string $marker): array
			{
				return array_values(array_filter(
					$this->records,
					static fn(array $r): bool => $r['message'] === $marker,
				));
			}
		};
	}

	private function setSetting(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES (:k, :v, "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
			->execute([':k' => $key, ':v' => $value]);
	}

	// ========================================================================
	// 1) score_override.lru_disabled
	// ========================================================================

	public function testLruDisableEmitsMarker(): void
	{
		$this->truncateAll();
		$this->setSetting('rule_inference_enabled', '1');
		$this->setSetting('rule_inference_max_per_user_per_day', '30');
		// Soft-Cap = 1 → schon die zweite aktive user-derived Regel sprengt ihn.
		$this->setSetting('learning.score_rules_soft_cap', '1');
		// Auto-Enable-Schwelle niedrig → die inferierte Regel wird enabled angelegt
		// und zaehlt damit in countUserDerived() mit.
		$this->setSetting('score_rule_auto_enable_threshold', '50');
		$this->seedInferenceRouting(self::PROVIDER_ID, 'ObsLruProv');

		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'noreply@github.com',
			'subject'    => '[acme] alert',
		]);

		// Bereits EINE aktive user-derived Regel vorhanden (origin_correction_id
		// gesetzt + enabled). Zusammen mit der gleich inferierten zweiten Regel
		// → count=2 > soft_cap=1 → enforceSoftCap deaktiviert die LRU-Regel.
		$repo = new ScoreOverrideRepository($this->pdo());
		$repo->create($tenantId, $userId, [
			'match_sender_key'     => 'existing-sender',
			'set_priority'         => 3,
			'enabled'              => 1,
			'source'               => 'ki_inferred',
			'origin_correction_id' => Uuid::v4(),
		]);

		// Korrektur-Row (origin_correction_id-Aufloesung beim CREATE).
		$this->pdo()->prepare('INSERT INTO mail_score_corrections
			(id, tenant_id, user_id, mail_id, corrected_label, corrected_priority, corrected_action)
			VALUES (:id, :t, :u, :m, :cl, :cp, 1)')
			->execute([
				':id' => $this->uuid(), ':t' => $tenantId, ':u' => $userId,
				':m' => $mailId, ':cl' => 'action', ':cp' => 4,
			]);

		$logger   = $this->recordingLogger();
		$provider = new ScriptedLlmProvider();
		// Eine neue, breite priority-Regel fuer einen ANDEREN sender_key → CREATE
		// (kein Slot-Update), damit countUserDerived hochzaehlt.
		$provider->scriptRawJson([
			'create_rule'       => true,
			'match_sender_key'  => 'github',
			'set_priority'      => 5,
			'confidence'        => 95,
			'reasoning_summary' => 'lru-fixture',
		]);

		$service = $this->makeInferenceService($provider, $logger);
		$result  = $service->inferScoreRule(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'action', 'priority' => 5, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'github alerts sind prio 5',
		);

		$this->assertSame('created', $result['action'], 'Inferenz legt die zweite Regel an');

		$hits = $logger->withMarker('score_override.lru_disabled');
		$this->assertCount(1, $hits, 'Soft-Cap-Ueberschreitung emittiert score_override.lru_disabled');
		$this->assertArrayHasKey('rule_id', $hits[0]['context']);
		$this->assertSame(1, $hits[0]['context']['soft_cap'] ?? null);
	}

	private function makeInferenceService(ScriptedLlmProvider $provider, object $logger): RuleInferenceService
	{
		$pdo      = $this->pdo();
		$settings = new SettingsRepository($pdo);
		$router   = new LlmRouter(
			['anthropic' => $provider],
			new LlmProviderRepository($pdo),
			$settings,
			new NullLogger(),
			new LlmModelRepository($pdo),
		);
		return new RuleInferenceService(
			$pdo,
			$router,
			new RedactionService(),
			$settings,
			new UsageCounterRepository($pdo),
			new AutoSortRepository($pdo, $settings),
			new PendingActionRepository($pdo),
			new PromptRepository($pdo),
			$logger,
			new ScoreOverrideRepository($pdo),
		);
	}

	// ========================================================================
	// 2) rule_match.unavailable_fallback_deterministic
	// ========================================================================

	public function testUnavailableFallbackEmitsMarker(): void
	{
		$this->truncateAll();
		// Leere match-Chain → LlmRouter::complete wirft LlmAllProvidersDownException
		// → RuleMatchService faengt + loggt den Marker + returnt null (Fallback auf
		//   den deterministischen Score).
		$this->setSetting('llm.routing_mode', 'router');
		$this->setSetting('llm.privacy_mode', 'cloud_allowed');
		$this->setSetting('llm.match.fallback_chain', '[]');

		$pdo    = $this->pdo();
		$router = new LlmRouter(
			[],
			new LlmProviderRepository($pdo),
			new SettingsRepository($pdo),
			new NullLogger(),
			new LlmModelRepository($pdo),
		);

		$logger = $this->recordingLogger();
		$svc    = new RuleMatchService($router, new RedactionService(), $logger);

		$rule = ['id' => 'r1', 'match_sender_key' => 'sk:acme', 'set_priority' => 4];
		$mail = ['from_email' => 'a@acme.de', 'subject' => 'Hallo', 'body_text' => 'x'];

		$score = $svc->scoreMatch($rule, $mail);

		$this->assertNull($score, 'Leere Chain → null (deterministischer Fallback)');
		$hits = $logger->withMarker('rule_match.unavailable_fallback_deterministic');
		$this->assertCount(1, $hits, 'Unavailable-Fallback emittiert den Marker');
		$this->assertArrayHasKey('err', $hits[0]['context']);
	}

	// ========================================================================
	// 3) rule_match.budget_exceeded_fallback
	// ========================================================================

	public function testBudgetExceededEmitsMarker(): void
	{
		$this->truncateAll();
		// hybrid-Modus → LLM-Verfeinerung im suggest-Band aktiv, ABER Budget=0
		// → consumeMatchBudget() liefert false → stiller Fallback + Marker.
		$this->setSetting('learning.match_mode', 'hybrid');
		$this->setSetting('learning.match_per_batch_budget', '0');

		[$tenantId, $userId] = $this->insertTenantAndUser('budget@test.de');
		$repo = new ScoreOverrideRepository($this->pdo());

		// SUGGEST-Band: gleiche Domain (35) + Betreff-Match (30) = 65 → band=suggest.
		$repo->create($tenantId, $userId, [
			'match_sender_key'     => 'noreply@github.com',
			'match_subject_regex'  => '/build/i',
			'set_label'            => 'newsletter',
			'origin_correction_id' => Uuid::v4(),
		]);

		$logger   = $this->recordingLogger();
		$settings = new SettingsRepository($this->pdo());
		// RuleMatchService wird im Budget==0-Pfad NICHT aufgerufen (kurzgeschlossen),
		// daher genuegt ein Router mit leerer Provider-Map.
		$router   = new LlmRouter(
			[],
			new LlmProviderRepository($this->pdo()),
			$settings,
			new NullLogger(),
			new LlmModelRepository($this->pdo()),
		);
		$ruleMatch = new RuleMatchService($router, new RedactionService(), new NullLogger());

		$svc = new ScoreOverrideService(
			$repo,
			$logger,
			new MatchScorer(80, 50),
			$ruleMatch,
			$settings,
			new PendingActionRepository($this->pdo()),
		);

		$mail   = ['id' => 'm-budget', 'subject' => 'Build #42 failed', 'from_email' => 'notifications@github.com'];
		$score  = ['label' => 'auto', 'priority' => 2, 'action_required' => false];
		$bucket = ['sender_key' => 'notifications@github.com'];

		// Cache-Miss (wasCacheHit=false) → der LLM-Pfad WUERDE feuern, scheitert
		// aber am Budget==0.
		$svc->apply($tenantId, $userId, $mail, $score, $bucket, false);

		$hits = $logger->withMarker('rule_match.budget_exceeded_fallback');
		$this->assertCount(1, $hits, 'Budget==0 im suggest-Band emittiert rule_match.budget_exceeded_fallback');
		$this->assertSame('hybrid', $hits[0]['context']['mode'] ?? null);
		$this->assertSame('m-budget', $hits[0]['context']['mail_id'] ?? null);
		$this->assertArrayHasKey('rule_id', $hits[0]['context']);
	}

	/**
	 * Negativ-Kontrolle: im suggest-Band mit AUSREICHENDEM Budget darf KEIN
	 * budget_exceeded_fallback-Marker entstehen (Marker flutet nicht).
	 */
	public function testBudgetAvailableEmitsNoMarker(): void
	{
		$this->truncateAll();
		$this->setSetting('learning.match_mode', 'hybrid');
		$this->setSetting('learning.match_per_batch_budget', '5');

		[$tenantId, $userId] = $this->insertTenantAndUser('budget-ok@test.de');
		$repo = new ScoreOverrideRepository($this->pdo());
		$repo->create($tenantId, $userId, [
			'match_sender_key'     => 'noreply@github.com',
			'match_subject_regex'  => '/build/i',
			'set_label'            => 'newsletter',
			'origin_correction_id' => Uuid::v4(),
		]);

		$logger   = $this->recordingLogger();
		$settings = new SettingsRepository($this->pdo());
		$pid      = '00000000-0000-4000-8000-0000000000d9';
		$this->pdo()->prepare('DELETE FROM llm_models WHERE provider_id = ?')->execute([$pid]);
		$this->pdo()->prepare('DELETE FROM llm_providers WHERE id = ?')->execute([$pid]);
		$this->pdo()->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'ObsBudgetProv', 'anthropic', 'http://x', 0, 1, 10)")->execute([$pid]);
		$this->pdo()->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (?, ?, 'claude-haiku-4-5-20251001', 'match', 1, 10)")->execute([Uuid::v4(), $pid]);
		foreach ([['llm.routing_mode', 'router'], ['llm.privacy_mode', 'cloud_allowed'],
			['llm.match.fallback_chain', '["' . $pid . '"]']] as [$k, $v]) {
			$this->setSetting($k, $v);
		}

		$provider = new ScriptedLlmProvider();
		$provider->scriptRawJson(['score' => 65]); // LLM bestaetigt suggest-Band
		$router = new LlmRouter(
			['anthropic' => $provider],
			new LlmProviderRepository($this->pdo()),
			$settings,
			new NullLogger(),
			new LlmModelRepository($this->pdo()),
		);
		$ruleMatch = new RuleMatchService($router, new RedactionService(), new NullLogger());

		$svc = new ScoreOverrideService(
			$repo,
			$logger,
			new MatchScorer(80, 50),
			$ruleMatch,
			$settings,
			new PendingActionRepository($this->pdo()),
		);

		$mail   = ['id' => 'm-okbudget', 'subject' => 'Build #1 ok', 'from_email' => 'notifications@github.com'];
		$score  = ['label' => 'auto', 'priority' => 2, 'action_required' => false];
		$bucket = ['sender_key' => 'notifications@github.com'];

		$svc->apply($tenantId, $userId, $mail, $score, $bucket, false);

		$this->assertCount(
			0,
			$logger->withMarker('rule_match.budget_exceeded_fallback'),
			'Mit Budget darf kein budget_exceeded_fallback-Marker entstehen',
		);
		$this->assertGreaterThanOrEqual(1, $provider->callCount(), 'LLM-Verfeinerung wurde tatsaechlich aufgerufen');
	}
}
