<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\RescoreJobRepository;
use MailPilot\Tests\TestCase;

/**
 * Phase 9l (Marc 2026-05-21) — Async-Worker-Job-Queue.
 *
 * Schwerpunkte:
 *   - enqueue + findById Round-Trip mit Tenant-Filter.
 *   - claimNextQueued ist transactional, setzt status="running".
 *   - markDone/markFailed schreiben finished_at + capped korrekt.
 *   - findActiveByFolder erkennt offene Jobs (Doppel-Klick-Schutz).
 *   - recoverStaleRunning verschiebt running > N min auf failed.
 *   - purgeOlderThan löscht alte done/failed Records.
 */
final class RescoreJobRepositoryTest extends TestCase
{
	private RescoreJobRepository $repo;
	private string $tenantId;
	private string $userId;

	protected function setUp(): void
	{
		$this->truncateAll();
		[$this->tenantId, $this->userId] = $this->insertTenantAndUser();
		$this->repo = new RescoreJobRepository($this->pdo());
	}

	public function testEnqueueAndFindByIdRoundtrip(): void
	{
		$id = $this->repo->enqueue($this->tenantId, $this->userId, 'AAMkAD-Folder-X');

		$job = $this->repo->findById($this->tenantId, $id);
		$this->assertNotNull($job);
		$this->assertSame('queued', $job['status']);
		$this->assertSame('AAMkAD-Folder-X', $job['folder_id']);
		$this->assertSame(0, $job['total']);
		$this->assertSame(0, $job['processed']);
		$this->assertFalse($job['capped']);
		$this->assertNull($job['finished_at']);
	}

	public function testFindByIdRespectsTenantIsolation(): void
	{
		$id = $this->repo->enqueue($this->tenantId, $this->userId, 'AAMkAD-Folder-X');
		// Anderer Tenant darf den Job nicht sehen.
		$otherTenant = $this->uuid();
		$this->assertNull($this->repo->findById($otherTenant, $id));
	}

	public function testClaimNextQueuedSetsRunning(): void
	{
		$id = $this->repo->enqueue($this->tenantId, $this->userId, 'AAMkAD-Folder-X');
		$claimed = $this->repo->claimNextQueued();
		$this->assertNotNull($claimed);
		$this->assertSame($id, $claimed['id']);
		$this->assertSame($this->tenantId, $claimed['tenant_id']);
		$this->assertSame('AAMkAD-Folder-X', $claimed['folder_id']);

		$after = $this->repo->findById($this->tenantId, $id);
		$this->assertSame('running', $after['status']);
		$this->assertNotNull($after['created_at']);
	}

	public function testClaimNextQueuedReturnsNullWhenEmpty(): void
	{
		$this->assertNull($this->repo->claimNextQueued());
	}

	public function testMarkDoneSetsCounters(): void
	{
		$id = $this->repo->enqueue($this->tenantId, $this->userId, 'AAMkAD-Folder-X');
		$this->repo->claimNextQueued();
		$this->repo->markDone($id, 42, 50, true);

		$job = $this->repo->findById($this->tenantId, $id);
		$this->assertSame('done', $job['status']);
		$this->assertSame(42, $job['processed']);
		$this->assertSame(50, $job['total']);
		$this->assertTrue($job['capped']);
		$this->assertNotNull($job['finished_at']);
	}

	public function testMarkFailedStoresErrorText(): void
	{
		$id = $this->repo->enqueue($this->tenantId, $this->userId, 'AAMkAD-Folder-X');
		$this->repo->claimNextQueued();
		$this->repo->markFailed($id, 'graph_403_forbidden');

		$job = $this->repo->findById($this->tenantId, $id);
		$this->assertSame('failed', $job['status']);
		$this->assertSame('graph_403_forbidden', $job['error_text']);
		$this->assertNotNull($job['finished_at']);
	}

	public function testFindActiveByFolderDetectsExisting(): void
	{
		$id = $this->repo->enqueue($this->tenantId, $this->userId, 'AAMkAD-Folder-X');
		$found = $this->repo->findActiveByFolder($this->tenantId, $this->userId, 'AAMkAD-Folder-X');
		$this->assertSame($id, $found);

		// Andere Folder → null
		$this->assertNull($this->repo->findActiveByFolder($this->tenantId, $this->userId, 'AAMkAD-Folder-Other'));

		// Nach done → nicht mehr als "aktiv"
		$this->repo->claimNextQueued();
		$this->repo->markDone($id, 0, 0, false);
		$this->assertNull($this->repo->findActiveByFolder($this->tenantId, $this->userId, 'AAMkAD-Folder-X'));
	}

	public function testUpdateProgressIsMonotonicallyVisible(): void
	{
		$id = $this->repo->enqueue($this->tenantId, $this->userId, 'AAMkAD-Folder-X');
		$this->repo->claimNextQueued();
		$this->repo->updateProgress($id, 5, 100);
		$mid = $this->repo->findById($this->tenantId, $id);
		$this->assertSame(5, $mid['processed']);
		$this->assertSame(100, $mid['total']);

		$this->repo->updateProgress($id, 50, 100);
		$later = $this->repo->findById($this->tenantId, $id);
		$this->assertSame(50, $later['processed']);
	}

	public function testRecoverStaleRunningMovesToFailed(): void
	{
		$id = $this->repo->enqueue($this->tenantId, $this->userId, 'AAMkAD-Folder-X');
		$this->repo->claimNextQueued();

		// Backdate started_at by 60 minutes to simulate a hung job.
		$this->pdo()->prepare('UPDATE rescore_jobs
			SET started_at = (UTC_TIMESTAMP(3) - INTERVAL 60 MINUTE)
			WHERE id = :id')->execute([':id' => $id]);

		$recovered = $this->repo->recoverStaleRunning(30);
		$this->assertSame(1, $recovered);

		$job = $this->repo->findById($this->tenantId, $id);
		$this->assertSame('failed', $job['status']);
		$this->assertSame('stale_running_recovered', $job['error_text']);
	}

	public function testPurgeOlderThanDeletesFinishedJobs(): void
	{
		$id = $this->repo->enqueue($this->tenantId, $this->userId, 'AAMkAD-Folder-X');
		$this->repo->claimNextQueued();
		$this->repo->markDone($id, 5, 5, false);

		// Backdate finished_at by 30 days.
		$this->pdo()->prepare('UPDATE rescore_jobs
			SET finished_at = (UTC_TIMESTAMP(3) - INTERVAL 30 DAY)
			WHERE id = :id')->execute([':id' => $id]);

		$purged = $this->repo->purgeOlderThan(14);
		$this->assertSame(1, $purged);
		$this->assertNull($this->repo->findById($this->tenantId, $id));
	}
}
