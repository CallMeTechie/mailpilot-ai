<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Services\JobRecoveryService;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * Pin-Test für die Stale-Job-Recovery aus worker.php (jetzt
 * JobRecoveryService). Verhindert die Regression des 2026-05-12-Bugs,
 * wo ein crashed worker einen Job für 2 Tage in 'running' liegen ließ
 * und alle weiteren Background-Syncs damit blockierte.
 *
 * @group integration
 */
final class JobRecoveryServiceTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	private function insertSyncJob(string $tenantId, string $mailboxId, string $status, int $startedMinutesAgo): string
	{
		$id = Uuid::v4();
		$startedAt = $startedMinutesAgo > 0
			? "UTC_TIMESTAMP(3) - INTERVAL {$startedMinutesAgo} MINUTE"
			: 'UTC_TIMESTAMP(3)';
		$this->pdo()->prepare("INSERT INTO sync_jobs
			(id, tenant_id, mailbox_id, status, started_at)
			VALUES (:id, :t, :m, :s, {$startedAt})")
			->execute([':id' => $id, ':t' => $tenantId, ':m' => $mailboxId, ':s' => $status]);
		return $id;
	}

	public function testRecoversJobsOlderThanThreshold(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		// Stale: 15 min in 'running' — über 10-min-Schwelle.
		$staleId  = $this->insertSyncJob($tenantId, $mailboxId, 'running', 15);

		$svc = new JobRecoveryService($this->pdo(), new NullLogger());
		$count = $svc->recoverStaleRunningJobs(10);

		$this->assertSame(1, $count);
		$row = $this->pdo()->query("SELECT status, error_text, finished_at FROM sync_jobs WHERE id = " . $this->pdo()->quote($staleId))->fetch();
		$this->assertSame('error', $row['status']);
		$this->assertStringContainsString('auto-recovered', (string)$row['error_text']);
		$this->assertNotNull($row['finished_at']);
	}

	public function testLeavesFreshRunningJobsAlone(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		// Fresh: vor 2 min gestartet, völlig normal noch laufend.
		$freshId = $this->insertSyncJob($tenantId, $mailboxId, 'running', 2);

		$svc = new JobRecoveryService($this->pdo(), new NullLogger());
		$count = $svc->recoverStaleRunningJobs(10);

		$this->assertSame(0, $count);
		$status = $this->pdo()->query("SELECT status FROM sync_jobs WHERE id = " . $this->pdo()->quote($freshId))->fetchColumn();
		$this->assertSame('running', $status, 'Fresh running job darf nicht angefasst werden');
	}

	public function testIgnoresQueuedAndDoneJobs(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		// Auch alte queued/done dürfen NICHT recovered werden — nur 'running'.
		$queuedId = $this->insertSyncJob($tenantId, $mailboxId, 'queued', 60);
		$doneId   = $this->insertSyncJob($tenantId, $mailboxId, 'done',   60);

		$svc = new JobRecoveryService($this->pdo(), new NullLogger());
		$count = $svc->recoverStaleRunningJobs(10);

		$this->assertSame(0, $count);
		$this->assertSame('queued', $this->pdo()->query("SELECT status FROM sync_jobs WHERE id = " . $this->pdo()->quote($queuedId))->fetchColumn());
		$this->assertSame('done',   $this->pdo()->query("SELECT status FROM sync_jobs WHERE id = " . $this->pdo()->quote($doneId))->fetchColumn());
	}
}
