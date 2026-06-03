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
use Psr\Log\NullLogger;

/**
 * Phase 9h.3 (Marc 2026-05-21) — Tests fuer die parallele
 * inferAllFromCorrection() Methode.
 *
 * Schwerpunkte:
 *   - 3 Inferenzen mit reasoning + topic → 3 Router-Calls
 *   - Nur reasoning → 2 Calls (folder + score), topic_rule=null
 *   - Nur topic → 1 Call (topic), folder/score=null
 *   - Settings off → kein Call, alles null
 *   - LlmRouter::completeBatch behaelt Reihenfolge
 *
 * Task 7 (2026-06-02): Regel-Extraktion laeuft jetzt ueber den LlmRouter
 * (Rolle 'inference'); callClaudeBatch nutzt LlmRouter::completeBatch. Der
 * gescriptete ScriptedLlmProvider ersetzt die frueheren
 * FakeClaudeClient::scriptJson-Calls (FIFO, gleiche Reihenfolge wie zuvor:
 * folder → score → topic).
 *
 * @group integration
 */
final class InferAllFromCorrectionTest extends TestCase
{
	use SeedsInferenceRouting;

	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c9';

	protected function setUp(): void
	{
		$this->truncateAll();
		// truncateAll laesst system_settings stehen — wir muessen rule_inference
		// auf Default-Werte zuruecksetzen, sonst kontaminieren vorhergehende
		// Tests (z.B. testSettingsDisabledShortCircuits setzt rule_inference_enabled=0).
		$this->setSetting('rule_inference_enabled', '1');
		$this->setSetting('rule_inference_max_per_user_per_day', '30');
		$this->setSetting('rule_inference_backfill_range', 'last_30_days');
		$this->seedInferenceRouting(self::PROVIDER_ID, 'InferAllTestProv');
	}

	private function makeService(ScriptedLlmProvider $provider): RuleInferenceService
	{
		$pdo = $this->pdo();
		$settings = new SettingsRepository($pdo);
		$router = new LlmRouter(
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

		$provider = new ScriptedLlmProvider();
		// 3 Responses fuer 3 parallele Router-Calls — Reihenfolge entspricht
		// inferAllFromCorrection's Spec-Aufbau: folder → score → topic.
		$provider->scriptRawJson([
			'create_rule'       => true,
			'label'             => 'action',
			'sub_label'         => 'CI-Fail',
			'folder_name'       => 'GitHub/GateControl/Security',
			'match_signals'     => ['from_domain:github.com'],
			'confidence'        => 90,
			'reasoning_summary' => 'github CI alerts',
		]);
		$provider->scriptRawJson([
			'create_rule'         => true,
			'match_sender_key'    => 'github',
			'match_subject_regex' => '/codeql|alert/',
			'set_priority'        => 4,
			'confidence'          => 90,
			'reasoning_summary'   => 'github sicherheits-events sind action prio 4',
		]);
		$provider->scriptRawJson([
			'create_rule'         => true,
			'match_sender_key'    => 'github',
			'match_subject_regex' => '/gatecontrol/',
			'confidence'          => 88,
			'reasoning_summary'   => 'gatecontrol-mails in eigenen folder',
		]);

		$result = $this->makeService($provider)->inferAllFromCorrection(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'action', 'priority' => 4, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'github CI fails sind action prio 4',
			['GitHub', 'GateControl', 'Security'],
		);

		$this->assertSame(3, $provider->callCount(), 'Alle drei Inferenzen muessen einen Router-Call ausgeloest haben');
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

		$provider = new ScriptedLlmProvider();
		$provider->scriptRawJson(['create_rule' => false, 'reasoning_summary' => 'no folder pattern']);
		$provider->scriptRawJson(['create_rule' => false, 'reasoning_summary' => 'no score pattern']);

		$result = $this->makeService($provider)->inferAllFromCorrection(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'action', 'priority' => 4, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'einige reasoning',
			[],
		);

		$this->assertSame(2, $provider->callCount(), 'Ohne Topic nur folder + score (2 Calls)');
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

		$provider = new ScriptedLlmProvider();
		$provider->scriptRawJson([
			'create_rule'         => true,
			'match_sender_key'    => 'github',
			'match_subject_regex' => '/gatecontrol/',
			'confidence'          => 88,
			'reasoning_summary'   => 'fixture',
		]);

		$result = $this->makeService($provider)->inferAllFromCorrection(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'action', 'priority' => 4, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'', // kein reasoning
			['GitHub', 'GateControl'],
		);

		$this->assertSame(1, $provider->callCount(), 'Nur topic_rule sollte einen Router-Call ausloesen');
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

		$provider = new ScriptedLlmProvider();
		$result = $this->makeService($provider)->inferAllFromCorrection(
			$tenantId, $userId, $mailId,
			['label' => 'action', 'priority' => 4, 'action_required' => true],
			['label' => 'auto',   'priority' => 3, 'action_required' => false],
			'reasoning text',
			['GitHub'],
		);

		$this->assertSame(0, $provider->callCount(), 'Settings off → kein Router-Call');
		$this->assertNull($result['folder']);
		$this->assertNull($result['score_rule']);
		$this->assertNull($result['topic_rule']);
	}

	public function testRouterCompleteBatchPreservesOrder(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'noreply@github.com',
			'subject'    => '[CallMeTechie/gatecontrol] CodeQL alert',
		]);
		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'future_only');

		// Drei unterscheidbare Responses — die FIFO-Queue des Routers (über
		// completeBatch → complete pro Item) muss sie in Spec-Reihenfolge
		// (folder → score → topic) den jeweiligen Slots zuordnen.
		$provider = new ScriptedLlmProvider();
		$provider->scriptRawJson([
			'create_rule'   => true,
			'label'         => 'noise',
			'sub_label'     => 'Folder-Slot',
			'folder_name'   => 'MailPilot/Noise/Folder-Slot',
			'match_signals' => ['from_domain:github.com'],
			'confidence'    => 95,
		]);
		$provider->scriptRawJson([
			'create_rule'      => true,
			'match_sender_key' => 'github',
			'set_priority'     => 2,
			'confidence'       => 95,
		]);
		$provider->scriptRawJson([
			'create_rule'      => true,
			'match_sender_key' => 'github',
			'confidence'       => 95,
		]);

		$result = $this->makeService($provider)->inferAllFromCorrection(
			$tenantId,
			$userId,
			$mailId,
			['label' => 'noise', 'priority' => 2, 'action_required' => false],
			['label' => 'auto',  'priority' => 3, 'action_required' => false],
			'github mails sind noise',
			['GitHub', 'GateControl'],
		);

		$this->assertSame(3, $provider->callCount());
		// Slot 0 (folder) bekam die noise/Folder-Slot-Response.
		$this->assertSame('applied', $result['folder']['action']);
		$this->assertSame('Folder-Slot', $result['folder']['sub_label']);
		// Slot 1 (score) bekam die set_priority=2-Response.
		$this->assertSame('created', $result['score_rule']['action']);
		// Slot 2 (topic) bekam die letzte Response.
		$this->assertSame('created', $result['topic_rule']['action']);
	}

	private function setSetting(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES (:k, :v, "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
			->execute([':k' => $key, ':v' => $value]);
	}
}
