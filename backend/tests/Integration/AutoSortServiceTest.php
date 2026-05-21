<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\AutoSortService;
use MailPilot\Tests\Fixtures\FakeGraphClient;
use MailPilot\Tests\TestCase;

/**
 * End-to-end tests for the AutoSort move pipeline, with a Fake
 * GraphClient so the precedence logic, folder-id caching and
 * already_sorted gate are exercised without hitting Microsoft.
 *
 * @group integration
 */
final class AutoSortServiceTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
		// Sprint 6c: Bestandstests prüfen direktes-Move-Verhalten. Migration
		// 0018 seedet 'suggest' als Default — explizit auf 'auto' setzen,
		// damit applyToScoredMail durch den Graph-Pfad geht.
		$this->pdo()->prepare("UPDATE system_settings SET `value`='auto' WHERE `key`='autosort_move_mode'")
			->execute();
	}

	private function makeService(FakeGraphClient $graph): AutoSortService
	{
		$pdo = $this->pdo();
		$settings = new SettingsRepository($pdo);
		return new AutoSortService(
			$graph,
			new AutoSortRepository($pdo),
			$pdo,
			$this->logger(),
			$settings,
			new PendingActionRepository($pdo),
			null, null, null, null,
			// Phase 9m: InboxProtectionResolver fuer deterministische Schutz-Logik.
			new \MailPilot\Services\InboxProtectionResolver($pdo, $settings),
		);
	}

	private function insertScoredMail(string $tenantId, string $mailboxId, string $label, ?string $subLabel, int $priority = 2): array
	{
		$msId  = 'ms-' . substr(md5($label . ($subLabel ?? '') . microtime(true) . random_int(0, 9999)), 0, 12);
		$mailId = $this->insertMail($tenantId, $mailboxId, ['ms_message_id' => $msId]);
		$this->pdo()->prepare('INSERT INTO mail_scores
			(id, tenant_id, mail_id, label, sub_label, action_required, priority, summary, reasoning, prompt_version, model, cached)
			VALUES (:id, :t, :m, :l, :s, 0, :p, "test", "r", "P-SCORE@1.1", "haiku", 0)')
			->execute([
				':id' => $this->uuid(), ':t' => $tenantId, ':m' => $mailId,
				':l' => $label, ':s' => $subLabel, ':p' => $priority,
			]);
		return ['id' => $mailId, 'ms_message_id' => $msId];
	}

	public function testExactSubLabelMatchMovesToItsFolder(): void
	{
		$this->markTestSkipped('Phase 9m: findRule-Fallback entfernt — Sender-Pfad oder Inbox.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null,        true, 'MailPilot/Auto');
		$rules->upsert($tenantId, $userId, 'auto', 'GitHub CI', true, 'MailPilot/Auto/CI');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', 'GitHub CI');

		$graph = new FakeGraphClient();
		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'auto', 'sub_label' => 'GitHub CI', 'priority' => 2],
		);

		$this->assertTrue($res['moved']);
		$this->assertSame('MailPilot/Auto/CI', $res['folder']);
		$this->assertCount(1, $graph->moveCalls);
		$this->assertSame($mail['ms_message_id'], $graph->moveCalls[0]['message_id']);
		$this->assertSame('MailPilot/Auto/CI', $graph->folderCalls[0]['path']);

		// folder_id was cached on the exact sub-rule, NOT on the catch-all
		$exact = $rules->findRule($tenantId, $userId, 'auto', 'GitHub CI');
		$catch = $rules->findRule($tenantId, $userId, 'auto', null);
		$this->assertNotNull($exact['folder_id']);
		$this->assertNull($catch['folder_id'], 'catch-all rule must stay un-touched');
	}

	public function testUnknownSubLabelFallsBackToCatchAllFolder(): void
	{
		$this->markTestSkipped('Phase 9m: findRule-Catch-all entfernt — Sender-Pfad ist single source.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null, true, 'MailPilot/Auto');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null);

		$graph = new FakeGraphClient();
		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'auto', 'sub_label' => 'Unknown', 'priority' => 2],
		);

		$this->assertTrue($res['moved']);
		$this->assertSame('MailPilot/Auto', $res['folder']);

		// folder_id landed on the catch-all rule (which is what got matched)
		$catch = $rules->findRule($tenantId, $userId, 'auto', null);
		$this->assertNotNull($catch['folder_id']);
	}

	public function testHighPriorityDirectActionStaysInInbox(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'direct', null, true, 'MailPilot/Direct');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'direct', null, 5);

		$graph = new FakeGraphClient();
		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'direct', 'sub_label' => null, 'priority' => 5],
		);

		$this->assertFalse($res['moved']);
		// Phase 9m (Marc 2026-05-21): InboxProtectionResolver liefert jetzt
		// 'inbox_protected' als reason mit detailliertem protection_reason.
		// Funktional identisch (moved=false), neuer reason-String.
		$this->assertSame('inbox_protected', $res['reason']);
		$this->assertSame('priority_high', $res['protection_reason']);
		$this->assertSame([], $graph->moveCalls, 'Must never call Graph for protected mails');
	}

	/**
	 * 2026-05-15 Bug-Fund: Amazon-Zahlungs-Mail mit label='auto' aber
	 * priority=4 + action_required=1 + action_owner='user' wurde nach
	 * MailPilot/Auto verschoben, obwohl Marc handeln musste. Der alte
	 * Schutz prüfte nur (direct/action AND priority>=4) — label='auto'
	 * lief durch. Neuer Schutz: action_required + action_owner='user'
	 * + priority>=4 schützt unabhängig vom (KI-fehleranfälligen) Label.
	 */
	public function testUserActionRequiredProtectsRegardlessOfLabel(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null, true, 'MailPilot/Auto');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null, 4);

		$graph = new FakeGraphClient();
		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			[
				'label'           => 'auto',
				'sub_label'       => null,
				'priority'        => 4,
				'action_required' => true,
				'action_owner'    => 'user',
			],
		);

		$this->assertFalse($res['moved']);
		// Phase 9m (Marc 2026-05-21): InboxProtectionResolver schluckt Prio 4
		// schon ueber priority_high. action_owner='user'-Logik bleibt im
		// Code als weitere Schutzschicht fuer Prio<minPrio mit user-action.
		$this->assertSame('inbox_protected', $res['reason']);
		$this->assertSame('priority_high', $res['protection_reason']);
		$this->assertSame([], $graph->moveCalls,
			'User-action-required mails dürfen nicht verschoben werden, auch wenn label="auto"');
	}

	public function testDisabledRuleResultsInNoMove(): void
	{
		$this->markTestSkipped('Phase 9m: findRule-Pfad entfernt — Mails ohne Sender-Config bleiben in Inbox.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null, false, 'MailPilot/Auto');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null);

		$graph = new FakeGraphClient();
		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'auto', 'sub_label' => null, 'priority' => 2],
		);

		$this->assertFalse($res['moved']);
		$this->assertSame('rule_disabled', $res['reason']);
		$this->assertSame([], $graph->moveCalls);
	}

	public function testAlreadySortedMailIsSkipped(): void
	{
		$this->markTestSkipped('Phase 9m: already_sorted-Check im findRule-Pfad entfernt — applyManualMove macht Skip selbst.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null, true, 'MailPilot/Auto');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null);
		$this->pdo()->prepare('UPDATE mail_scores SET auto_sorted_at = UTC_TIMESTAMP(3) WHERE mail_id = :m')
			->execute([':m' => $mail['id']]);

		$graph = new FakeGraphClient();
		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'auto', 'sub_label' => null, 'priority' => 2],
		);

		$this->assertFalse($res['moved']);
		$this->assertSame('already_sorted', $res['reason']);
		$this->assertSame([], $graph->moveCalls);
	}

	public function testCachedFolderIdSkipsEnsureFolderPath(): void
	{
		$this->markTestSkipped('Phase 9m: folder_id-Cache im findRule-Pfad entfernt.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null, true, 'MailPilot/Auto');
		$rules->rememberFolderId($tenantId, $userId, 'auto', null, 'cached-folder-id');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null);

		$graph = new FakeGraphClient();
		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'auto', 'sub_label' => null, 'priority' => 2],
		);

		$this->assertTrue($res['moved']);
		$this->assertSame([], $graph->folderCalls, 'Cached folder_id ⇒ no ensureFolderPath call');
		$this->assertSame('cached-folder-id', $graph->moveCalls[0]['folder_id']);
	}

	public function test404DropsCachedFolderIdSoNextRunReResolves(): void
	{
		$this->markTestSkipped('Phase 9m: findRule-404-Handling entfernt — applyManualMove hat eigene Logik.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null, true, 'MailPilot/Auto');
		$rules->rememberFolderId($tenantId, $userId, 'auto', null, 'stale-id');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null);

		$graph = new FakeGraphClient();
		$graph->failNextMove(new \RuntimeException('Graph move failed: 404 Not Found'));

		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'auto', 'sub_label' => null, 'priority' => 2],
		);

		$this->assertFalse($res['moved']);
		$this->assertSame('graph_error', $res['reason']);

		// folder_id was dropped (back to NULL) so the next run hits
		// ensureFolderPath again instead of repeating the stale move.
		$rule = $rules->findRule($tenantId, $userId, 'auto', null);
		$this->assertNull($rule['folder_id']);

		// last_error was persisted on the catch-all
		$stmt = $this->pdo()->prepare("SELECT last_error FROM auto_sort_rules
			WHERE tenant_id = :t AND user_id = :u AND label = 'auto' AND sub_label IS NULL");
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		$this->assertStringContainsString('404', (string)$stmt->fetchColumn());
	}

	public function testNonExistentRuleProducesNoMove(): void
	{
		$this->markTestSkipped('Phase 9m: rule_disabled-Reason ersetzt durch no_sort_config.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null);

		$graph = new FakeGraphClient();
		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'auto', 'sub_label' => null, 'priority' => 2],
		);

		$this->assertFalse($res['moved']);
		$this->assertSame('rule_disabled', $res['reason']);
		$this->assertSame([], $graph->moveCalls);
	}

	public function testItemNotFoundMarksMailDeletedAndSkipsRetries(): void
	{
		$this->markTestSkipped('Phase 9m: findRule-ErrorItemNotFound-Handling entfernt — applyManualMove macht eigenes Handling.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null, true, 'MailPilot/Auto');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null);

		$graph = new FakeGraphClient();
		// Graph reply when the message-id is stale (user moved/deleted
		// the mail outside MailPilot). postJson packs the error code
		// into the exception message.
		$graph->failNextMove(new \RuntimeException('Graph POST failed: 404 (ErrorItemNotFound)'));

		$res = $this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'auto', 'sub_label' => null, 'priority' => 2],
		);

		$this->assertFalse($res['moved']);
		$this->assertSame('mail_gone', $res['reason']);

		// Mail ist als geloescht markiert → faellt aus der applyAutoSortNow-
		// Match-Query (deleted_at IS NULL) raus.
		$stmt = $this->pdo()->prepare('SELECT deleted_at FROM mails WHERE id = :m');
		$stmt->execute([':m' => $mail['id']]);
		$this->assertNotNull($stmt->fetchColumn(),
			'ErrorItemNotFound muss mails.deleted_at setzen');

		// Score-Row hat auto_sorted_at gesetzt → kein Retry mehr.
		$stmt = $this->pdo()->prepare('SELECT auto_sorted_at FROM mail_scores WHERE mail_id = :m');
		$stmt->execute([':m' => $mail['id']]);
		$this->assertNotNull($stmt->fetchColumn(),
			'ErrorItemNotFound muss mail_scores.auto_sorted_at setzen');
	}

	public function testSingleFailureBumpsAttemptsButDoesNotSkip(): void
	{
		$this->markTestSkipped('Phase 9m: Retry-Cap im findRule-Pfad entfernt.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null, true, 'MailPilot/Auto');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null);

		$graph = new FakeGraphClient();
		$graph->failNextMove(new \RuntimeException('Graph POST failed: 401'));

		$this->makeService($graph)->applyToScoredMail(
			'tok', $tenantId, $userId, $mail,
			['label' => 'auto', 'sub_label' => null, 'priority' => 2],
		);

		$row = $this->pdo()->prepare('SELECT auto_sort_attempts, auto_sorted_at
			FROM mail_scores WHERE mail_id = :m');
		$row->execute([':m' => $mail['id']]);
		$score = $row->fetch();
		$this->assertSame(1, (int)$score['auto_sort_attempts']);
		$this->assertNull($score['auto_sorted_at'], 'Mail muss noch retry-faehig sein');
	}

	public function testThirdFailureMarksMailPermanentlySkipped(): void
	{
		$this->markTestSkipped('Phase 9m: Retry-Cap im findRule-Pfad entfernt.');
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$rules = new AutoSortRepository($this->pdo());
		$rules->upsert($tenantId, $userId, 'auto', null, true, 'MailPilot/Auto');

		$mail = $this->insertScoredMail($tenantId, $mailboxId, 'auto', null);

		// Drei Graph-Failures hintereinander, je ein eigener Service-Aufruf
		// (jeder Aufruf is one applyToScoredMail() — Cycles)
		for ($i = 0; $i < 3; $i++) {
			$graph = new FakeGraphClient();
			$graph->failNextMove(new \RuntimeException('Graph POST failed: 500'));
			(new AutoSortService(
				$graph,
				new AutoSortRepository($this->pdo()),
				$this->pdo(),
				$this->logger(),
			))->applyToScoredMail(
				'tok', $tenantId, $userId, $mail,
				['label' => 'auto', 'sub_label' => null, 'priority' => 2],
			);
		}

		$row = $this->pdo()->prepare('SELECT auto_sort_attempts, auto_sorted_at
			FROM mail_scores WHERE mail_id = :m');
		$row->execute([':m' => $mail['id']]);
		$score = $row->fetch();
		$this->assertSame(3, (int)$score['auto_sort_attempts']);
		$this->assertNotNull($score['auto_sorted_at'],
			'Nach 3 Failures muss auto_sorted_at gesetzt sein (Skip-Marker)');
	}
}
