<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

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
use MailPilot\Tests\Fixtures\ScriptedLlmProvider;
use MailPilot\Tests\Support\SeedsInferenceRouting;
use MailPilot\Tests\TestCase;
use PDO;
use Psr\Log\NullLogger;

/**
 * Task 8 (Spec 2, 2026-06-03) — Update-in-place statt Sibling-Anlage.
 *
 * Aufbau analog InferAllFromCorrectionTest, aber mit injiziertem
 * SenderResolver (damit sender_key='github' aufgeloest wird und der
 * Slot-Lookup greift). Zwei Korrekturen desselben Absenders → genau EINE
 * user-derived Regel pro (sender_key, Set-Feld), origin_correction_id
 * gesetzt, zweite Korrektur UPDATET (Match-Breite / Feld) statt eine
 * zweite Zeile anzulegen.
 *
 * @group integration
 */
final class InferenceUpdateInPlaceTest extends TestCase
{
	use SeedsInferenceRouting;

	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000ca';

	protected function setUp(): void
	{
		$this->truncateAll();
		$this->setSetting('rule_inference_enabled', '1');
		$this->setSetting('rule_inference_max_per_user_per_day', '30');
		$this->setSetting('rule_inference_backfill_range', 'future_only');
		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('learning.score_rules_soft_cap', '200');
		$this->seedInferenceRouting(self::PROVIDER_ID, 'InferUpdateProv');
	}

	private function makeService(ScriptedLlmProvider $provider): RuleInferenceService
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
			new NullLogger(),
			new ScoreOverrideRepository($pdo),
		);
	}

	public function testSecondCorrectionUpdatesRuleNotCreatesSibling(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'noreply@github.com',
			'subject'    => '[CallMeTechie/gatecontrol] CodeQL alert',
		]);

		// --- 1. Korrektur: hohe Confidence → breite Regel (nur match_sender_key) ---
		$this->recordCorrection($tenantId, $userId, $mailId, 'action', 4);

		$provider1 = new ScriptedLlmProvider();
		// Spec-Reihenfolge in inferAllFromCorrection: folder → score. Mit
		// reasoning werden BEIDE Slots gefeuert, also brauchen wir 2 Responses.
		// Folder-Slot: kein Pattern (wir testen hier den score_rule-Pfad).
		$provider1->scriptRawJson(['create_rule' => false, 'reasoning_summary' => 'kein folder pattern']);
		// score_rule-Response (set_priority=4, hohe Confidence → breit).
		$provider1->scriptRawJson([
			'create_rule'       => true,
			'match_sender_key'  => 'github',
			'set_priority'      => 4,
			'confidence'        => 92,
			'reasoning_summary' => 'github security alerts sind action prio 4',
		]);

		$result1 = $this->makeService($provider1)->inferAllFromCorrection(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'action', 'priority' => 4, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'github security alerts sind action prio 4',
			[], // kein Topic — wir testen den score_rule-Pfad
		);

		$this->assertNotNull($result1['score_rule']);
		$this->assertSame('created', $result1['score_rule']['action'], 'Erste Korrektur legt eine Regel an');
		$ruleId1 = (string)$result1['score_rule']['rule_id'];

		$rules = $this->priorityRulesFor($tenantId, $userId, 'github');
		$this->assertCount(1, $rules, 'Nach 1. Korrektur genau EINE priority-Regel');
		$this->assertNotNull($rules[0]['origin_correction_id'], 'origin_correction_id muss gesetzt sein');
		$this->assertNull($rules[0]['match_from_local'], 'Hohe Confidence → breite Regel ohne match_from_local');

		// --- 2. Korrektur: niedrige Confidence → soll dieselbe Regel UPDATEN ---
		$this->recordCorrection($tenantId, $userId, $mailId, 'action', 5);

		$provider2 = new ScriptedLlmProvider();
		$provider2->scriptRawJson(['create_rule' => false, 'reasoning_summary' => 'kein folder pattern']);
		$provider2->scriptRawJson([
			'create_rule'       => true,
			'match_sender_key'  => 'github',
			'match_from_local'  => 'noreply',
			'set_priority'      => 5,
			'confidence'        => 55, // niedrig → enger (match_from_local)
			'reasoning_summary' => 'doch prio 5',
		]);

		$result2 = $this->makeService($provider2)->inferAllFromCorrection(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'action', 'priority' => 5, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'doch prio 5',
			[],
		);

		$this->assertNotNull($result2['score_rule']);
		$this->assertSame('updated', $result2['score_rule']['action'], 'Zweite Korrektur UPDATET statt anzulegen');
		$this->assertSame($ruleId1, (string)$result2['score_rule']['rule_id'], 'Update trifft dieselbe Regel-Row');

		// Immer noch genau EINE Regel, kein Sibling.
		$rules = $this->priorityRulesFor($tenantId, $userId, 'github');
		$this->assertCount(1, $rules, 'Nach 2. Korrektur weiterhin genau EINE priority-Regel (kein Sibling)');
		$this->assertSame(5, (int)$rules[0]['set_priority'], 'set_priority wurde auf 5 aktualisiert');
		$this->assertSame('noreply', $rules[0]['match_from_local'], 'Niedrige Confidence → match_from_local verengt die Regel');
		$this->assertNotNull($rules[0]['origin_correction_id']);
	}

	/** @return list<array<string,mixed>> */
	private function priorityRulesFor(string $tenantId, string $userId, string $senderKey): array
	{
		$stmt = $this->pdo()->prepare(
			'SELECT id, match_sender_key, match_from_local, set_priority, origin_correction_id, enabled
			 FROM score_override_rules
			 WHERE tenant_id = :t AND user_id = :u AND match_sender_key = :sk
			   AND set_priority IS NOT NULL AND deleted_at IS NULL
			 ORDER BY created_at ASC'
		);
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':sk' => $senderKey]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	private function recordCorrection(string $tenantId, string $userId, string $mailId, string $label, int $priority): void
	{
		// uq_correction_per_mail (tenant_id, mail_id) → ON DUPLICATE KEY UPDATE.
		$this->pdo()->prepare('INSERT INTO mail_score_corrections
			(id, tenant_id, user_id, mail_id, corrected_label, corrected_priority, corrected_action)
			VALUES (:id, :t, :u, :m, :cl, :cp, 1)
			ON DUPLICATE KEY UPDATE corrected_label = VALUES(corrected_label),
				corrected_priority = VALUES(corrected_priority)')
			->execute([
				':id' => $this->uuid(), ':t' => $tenantId, ':u' => $userId, ':m' => $mailId,
				':cl' => $label, ':cp' => $priority,
			]);
	}

	private function setSetting(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES (:k, :v, "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
			->execute([':k' => $key, ':v' => $value]);
	}
}
