<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageCounterRepository;
use MailPilot\Services\RedactionService;
use MailPilot\Services\RuleInferenceService;
use MailPilot\Tests\Fixtures\FakeClaudeClient;
use MailPilot\Tests\TestCase;
use Psr\Log\NullLogger;

/**
 * Phase 9h.3 (Marc 2026-05-21) — Tests fuer die parallele
 * inferAllFromCorrection() Methode und FakeClaudeClient::messagesBatch.
 *
 * Schwerpunkte:
 *   - 3 Inferenzen mit reasoning + topic → 3 Claude-Calls
 *   - Nur reasoning → 2 Calls (folder + score), topic_rule=null
 *   - Nur topic → 1 Call (topic), folder/score=null
 *   - Settings off → kein Call, alles null
 *   - FakeClaudeClient::messagesBatch behaelt Reihenfolge
 *
 * @group integration
 */
final class InferAllFromCorrectionTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
		// truncateAll laesst system_settings stehen — wir muessen rule_inference
		// auf Default-Werte zuruecksetzen, sonst kontaminieren vorhergehende
		// Tests (z.B. testSettingsDisabledShortCircuits setzt rule_inference_enabled=0).
		$this->setSetting('rule_inference_enabled', '1');
		$this->setSetting('rule_inference_max_per_user_per_day', '30');
		$this->setSetting('rule_inference_backfill_range', 'last_30_days');
	}

	private function makeService(FakeClaudeClient $claude): RuleInferenceService
	{
		$pdo = $this->pdo();
		$settings = new SettingsRepository($pdo);
		return new RuleInferenceService(
			$pdo,
			$claude,
			new RedactionService(),
			$settings,
			new UsageCounterRepository($pdo),
			new AutoSortRepository($pdo, $settings),
			new PendingActionRepository($pdo),
			new PromptRepository($pdo),
			new NullLogger(),
			new ScoreOverrideRepository($pdo),
			// SenderResolver bewusst null lassen — der Dedup-Check soll
			// fallen-through (kein Skip).
		);
	}

	public function testWithReasoningAndTopicAllThreeAreInferredInParallel(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'noreply@github.com',
			'subject'    => '[CallMeTechie/gatecontrol] CodeQL alert',
		]);

		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'future_only');

		$claude = new FakeClaudeClient();
		// 3 Responses fuer 3 parallele Calls — Reihenfolge entspricht
		// inferAllFromCorrection's Spec-Aufbau: folder → score → topic.
		$claude->scriptJson([
			'create_rule'       => true,
			'label'             => 'action',
			'sub_label'         => 'CI-Fail',
			'folder_name'       => 'GitHub/GateControl/Security',
			'match_signals'     => ['from_domain:github.com'],
			'confidence'        => 90,
			'reasoning_summary' => 'github CI alerts',
		]);
		$claude->scriptJson([
			'create_rule'         => true,
			'match_sender_key'    => 'github',
			'match_subject_regex' => '/codeql|alert/',
			'set_priority'        => 4,
			'confidence'          => 90,
			'reasoning_summary'   => 'github sicherheits-events sind action prio 4',
		]);
		$claude->scriptJson([
			'create_rule'         => true,
			'match_sender_key'    => 'github',
			'match_subject_regex' => '/gatecontrol/',
			'confidence'          => 88,
			'reasoning_summary'   => 'gatecontrol-mails in eigenen folder',
		]);

		$result = $this->makeService($claude)->inferAllFromCorrection(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'action', 'priority' => 4, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'github CI fails sind action prio 4',
			['GitHub', 'GateControl', 'Security'],
		);

		$this->assertSame(3, $claude->callCount(), 'Alle drei Inferenzen muessen einen Claude-Call ausgeloest haben');
		$this->assertNotNull($result['folder']);
		$this->assertNotNull($result['score_rule']);
		$this->assertNotNull($result['topic_rule']);
		$this->assertSame('created', $result['score_rule']['action']);
		$this->assertSame('created', $result['topic_rule']['action']);
	}

	public function testOnlyReasoningSkipsTopicInference(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'noreply@github.com']);
		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'future_only');

		$claude = new FakeClaudeClient();
		$claude->scriptJson(['create_rule' => false, 'reasoning_summary' => 'no folder pattern']);
		$claude->scriptJson(['create_rule' => false, 'reasoning_summary' => 'no score pattern']);

		$result = $this->makeService($claude)->inferAllFromCorrection(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'action', 'priority' => 4, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'einige reasoning',
			[],
		);

		$this->assertSame(2, $claude->callCount(), 'Ohne Topic nur folder + score (2 Calls)');
		$this->assertNotNull($result['folder']);
		$this->assertNotNull($result['score_rule']);
		$this->assertNull($result['topic_rule']);
	}

	public function testOnlyTopicSkipsReasoningInferences(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'noreply@github.com']);
		$this->setSetting('autosort_move_mode', 'auto');

		$claude = new FakeClaudeClient();
		$claude->scriptJson([
			'create_rule'         => true,
			'match_sender_key'    => 'github',
			'match_subject_regex' => '/gatecontrol/',
			'confidence'          => 88,
			'reasoning_summary'   => 'fixture',
		]);

		$result = $this->makeService($claude)->inferAllFromCorrection(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'action', 'priority' => 4, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'', // kein reasoning
			['GitHub', 'GateControl'],
		);

		$this->assertSame(1, $claude->callCount(), 'Nur topic_rule sollte einen Claude-Call ausloesen');
		$this->assertNull($result['folder']);
		$this->assertNull($result['score_rule']);
		$this->assertNotNull($result['topic_rule']);
		$this->assertSame('created', $result['topic_rule']['action']);
	}

	public function testSettingsDisabledShortCircuits(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId);
		$this->setSetting('rule_inference_enabled', '0');

		$claude = new FakeClaudeClient();
		$result = $this->makeService($claude)->inferAllFromCorrection(
			$tenantId, $userId, $mailId,
			['label' => 'action', 'priority' => 4, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'reasoning text',
			['GitHub'],
		);

		$this->assertSame(0, $claude->callCount(), 'Settings off → kein Claude-Call');
		$this->assertNull($result['folder']);
		$this->assertNull($result['score_rule']);
		$this->assertNull($result['topic_rule']);
	}

	public function testFakeClaudeClientMessagesBatchPreservesOrder(): void
	{
		$claude = new FakeClaudeClient();
		$claude->scriptJson(['slot' => 'one']);
		$claude->scriptJson(['slot' => 'two']);
		$claude->scriptJson(['slot' => 'three']);

		$results = $claude->messagesBatch([
			['model' => 'm1', 'messages' => []],
			['model' => 'm2', 'messages' => []],
			['model' => 'm3', 'messages' => []],
		]);

		$this->assertCount(3, $results);
		$parsed0 = json_decode(ClaudeClient::extractText($results[0]), true);
		$parsed1 = json_decode(ClaudeClient::extractText($results[1]), true);
		$parsed2 = json_decode(ClaudeClient::extractText($results[2]), true);
		$this->assertSame('one',   $parsed0['slot']);
		$this->assertSame('two',   $parsed1['slot']);
		$this->assertSame('three', $parsed2['slot']);
		$this->assertSame(3, $claude->callCount());
	}

	private function setSetting(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES (:k, :v, "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
			->execute([':k' => $key, ':v' => $value]);
	}
}
