<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\MailRepository;
use MailPilot\Tests\TestCase;

/**
 * Pin-Test für markDeletedByMsId — den Soft-Delete-Pfad, der vom
 * SyncService bei @removed-Tombstones gerufen wird.
 *
 * Hintergrund: vor dem Tombstone-Fix (commit 9eae45e) wurden in Outlook
 * gelöschte Mails einfach geskippt, deleted_at NULL → 94 Phantom-Mails
 * in Marc's Inbox. Dieser Test pinnt:
 *   1. matching ms_message_id → deleted_at gesetzt
 *   2. andere Mails unangetastet
 *   3. idempotent (zweiter Call returnt false)
 *   4. Multi-Tenancy-Isolation
 *
 * @group integration
 */
final class MailRepositoryTombstoneTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testMarkDeletedByMsIdSetsDeletedAt(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['ms_message_id' => 'AQMk-target']);
		$otherId   = $this->insertMail($tenantId, $mailboxId, ['ms_message_id' => 'AQMk-other']);

		$repo = new MailRepository($this->pdo());
		$result = $repo->markDeletedByMsId($tenantId, $mailboxId, 'AQMk-target');

		$this->assertTrue($result);

		$row = $this->pdo()->query("SELECT deleted_at FROM mails WHERE id = " . $this->pdo()->quote($mailId))->fetch();
		$this->assertNotNull($row['deleted_at'], 'Target-Mail muss als gelöscht markiert sein');

		$otherRow = $this->pdo()->query("SELECT deleted_at FROM mails WHERE id = " . $this->pdo()->quote($otherId))->fetch();
		$this->assertNull($otherRow['deleted_at'], 'Andere Mail darf nicht mitgelöscht werden');
	}

	public function testIdempotentOnAlreadyDeletedRow(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, ['ms_message_id' => 'AQMk-target']);

		$repo = new MailRepository($this->pdo());
		$this->assertTrue($repo->markDeletedByMsId($tenantId, $mailboxId, 'AQMk-target'));
		$this->assertFalse($repo->markDeletedByMsId($tenantId, $mailboxId, 'AQMk-target'),
			'Zweiter Call auf bereits gelöschte Row liefert false (idempotent)');
	}

	public function testNoMatchReturnsFalse(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, ['ms_message_id' => 'AQMk-existing']);

		$repo = new MailRepository($this->pdo());
		$this->assertFalse($repo->markDeletedByMsId($tenantId, $mailboxId, 'AQMk-nonexistent'),
			'Tombstone für unbekannte ms_message_id ist no-op');
	}

	public function testTenantIsolation(): void
	{
		[$tenantA, $userA] = $this->insertTenantAndUser('a@x.de');
		[$tenantB, $userB] = $this->insertTenantAndUser('b@x.de');
		$mailboxA = $this->insertMailbox($tenantA, $userA);
		$mailboxB = $this->insertMailbox($tenantB, $userB);
		// Gleiche ms_message_id in zwei Tenants (theoretisch möglich)
		$mailA = $this->insertMail($tenantA, $mailboxA, ['ms_message_id' => 'AQMk-shared']);
		$mailB = $this->insertMail($tenantB, $mailboxB, ['ms_message_id' => 'AQMk-shared']);

		$repo = new MailRepository($this->pdo());
		$repo->markDeletedByMsId($tenantA, $mailboxA, 'AQMk-shared');

		$rowA = $this->pdo()->query("SELECT deleted_at FROM mails WHERE id = " . $this->pdo()->quote($mailA))->fetch();
		$rowB = $this->pdo()->query("SELECT deleted_at FROM mails WHERE id = " . $this->pdo()->quote($mailB))->fetch();
		$this->assertNotNull($rowA['deleted_at'], 'Tenant-A-Mail gelöscht');
		$this->assertNull($rowB['deleted_at'], 'Tenant-B-Mail unangetastet (Multi-Tenancy-Isolation)');
	}
}
