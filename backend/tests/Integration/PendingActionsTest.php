<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

/**
 * Sprint 6c — Modus-Schalter + Pending-Queue.
 *
 * Pinnt die DA-Findings 1, 2, 4 und PRD §3.1 (Topic-Children-Kopplung):
 *   #1: created_under_mode wird beim Erzeugen eingefroren, kein Drift.
 *   #2: rememberError + retry_count; status bleibt 'pending'.
 *   #4: Cursor-Pagination liefert next_cursor sauber.
 *
 * @group integration
 */
final class PendingActionsTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
		// pending_actions wird nicht von truncateAll erfasst — wir wischen
		// hier explizit, damit unsere Tests sauber starten.
		$this->pdo()->exec('DELETE FROM pending_actions');
	}

	private function seedUser(): array
	{
		$tenantId = Uuid::v4();
		$userId   = Uuid::v4();
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO tenants (id, name) VALUES (:id, "T")')->execute([':id' => $tenantId]);
		$pdo->prepare('INSERT INTO users (id, email, display_name) VALUES (:id, "marc@example.de", "Marc")')->execute([':id' => $userId]);
		$pdo->prepare('INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (:t, :u, "owner")')
			->execute([':t' => $tenantId, ':u' => $userId]);
		return [$tenantId, $userId];
	}

	public function testCreatedUnderModeIsFrozenAtCreationTime(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new PendingActionRepository($this->pdo());

		$id = $repo->create($tenantId, $userId, 'move', ['mail_id' => 'm1'], 'suggest');

		$found = $repo->findById($tenantId, $userId, $id);
		$this->assertNotNull($found);
		$this->assertSame('suggest', $found['created_under_mode'],
			'DA-Finding 1: created_under_mode darf sich NICHT mit Setting-Wechsel ändern');
	}

	public function testAgeOutMarksOldPendingAsAgedOut(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new PendingActionRepository($this->pdo());

		$old = $repo->create($tenantId, $userId, 'move', ['mail_id' => 'old'], 'suggest');
		$new = $repo->create($tenantId, $userId, 'move', ['mail_id' => 'new'], 'suggest');

		// Backdate die alte Row 60 Tage zurück
		$this->pdo()->prepare('UPDATE pending_actions SET created_at = (UTC_TIMESTAMP(3) - INTERVAL 60 DAY)
			WHERE id = :id')->execute([':id' => $old]);

		$affected = $repo->ageOut(30);
		$this->assertSame(1, $affected);

		$oldRow = $repo->findById($tenantId, $userId, $old);
		$newRow = $repo->findById($tenantId, $userId, $new);
		$this->assertSame('aged_out', $oldRow['status']);
		$this->assertSame('pending',  $newRow['status']);
	}

	public function testRememberErrorIncrementsRetryAndKeepsPending(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new PendingActionRepository($this->pdo());
		$id = $repo->create($tenantId, $userId, 'move', ['mail_id' => 'fail'], 'suggest');

		$repo->rememberError($tenantId, $userId, $id, 'Graph 404: ErrorFolderNotFound');
		$repo->rememberError($tenantId, $userId, $id, 'Graph 500: TransientServerError');

		$row = $repo->findById($tenantId, $userId, $id);
		$this->assertSame(2, $row['retry_count'], 'retry_count wird inkrementiert');
		$this->assertStringContainsString('TransientServerError', $row['last_error'],
			'last_error speichert die JÜNGSTE Fehlermeldung');
		$this->assertSame('pending', $row['status'],
			'DA-Finding 2: Fehlgeschlagene Action bleibt pending, nicht silent approved');
	}

	public function testTopicAndChildrenAreCoupledViaParentPendingId(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new PendingActionRepository($this->pdo());

		$topicId = $repo->create($tenantId, $userId, 'create_topic', [
			'primary' => 'auto', 'sub_label' => 'GitHub CI',
			'folder_path' => 'MailPilot/Auto/GitHub CI',
		], 'suggest');

		for ($i = 1; $i <= 3; $i++) {
			$repo->create($tenantId, $userId, 'move_to_pending_topic', [
				'mail_id' => "m{$i}", 'target_folder' => 'MailPilot/Auto/GitHub CI',
			], 'suggest', $topicId);
		}

		$children = $repo->findChildrenOfTopic($tenantId, $userId, $topicId);
		$this->assertCount(3, $children, 'PRD §3.1: alle Children kommen via parent_pending_id zurück');
	}

	public function testFindPendingTopicIdLooksUpByLabelAndSubLabel(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new PendingActionRepository($this->pdo());

		$id = $repo->create($tenantId, $userId, 'create_topic', [
			'primary' => 'auto', 'sub_label' => 'Stripe Payments',
			'folder_path' => 'MailPilot/Auto/Stripe Payments',
		], 'suggest');

		$this->assertSame($id, $repo->findPendingTopicId($tenantId, $userId, 'auto', 'Stripe Payments'));
		$this->assertNull($repo->findPendingTopicId($tenantId, $userId, 'auto', 'GitHub CI'),
			'Nicht-existierender Topic liefert null');
	}

	public function testListPaginatesByIdCursor(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new PendingActionRepository($this->pdo());

		for ($i = 1; $i <= 5; $i++) {
			$repo->create($tenantId, $userId, 'move', ['mail_id' => "m{$i}"], 'suggest');
		}

		$page1 = $repo->listPendingForUser($tenantId, $userId, null, null, 2);
		$this->assertCount(2, $page1);

		$page2 = $repo->listPendingForUser($tenantId, $userId, null, $page1[1]['id'], 2);
		$this->assertCount(2, $page2);
		$this->assertNotSame($page1[0]['id'], $page2[0]['id'],
			'Cursor-Pagination liefert disjunkte Seiten');
	}

	public function testCountByKindReturnsAllFourKindsAndTotal(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new PendingActionRepository($this->pdo());

		$repo->create($tenantId, $userId, 'move',         ['m' => 1], 'suggest');
		$repo->create($tenantId, $userId, 'move',         ['m' => 2], 'suggest');
		$repo->create($tenantId, $userId, 'create_topic', ['t' => 1], 'suggest');

		$c = $repo->countByKind($tenantId, $userId);
		$this->assertSame(2, $c['move']);
		$this->assertSame(1, $c['create_topic']);
		$this->assertSame(0, $c['reply_draft']);
		$this->assertSame(3, $c['total']);
	}
}
