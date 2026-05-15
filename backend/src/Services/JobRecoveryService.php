<?php
declare(strict_types=1);

namespace MailPilot\Services;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Recovery für stuck-running sync_jobs.
 *
 * Worker-Prozesse, die durch OOM, SIGKILL oder Container-Restart
 * abgewürgt werden, lassen ihren laufenden Job in status='running'
 * zurück. scheduleBackgroundSync prüft beim nächsten Tick aber
 * `IN ('queued','running')` und überspringt die Mailbox — der Sync
 * bleibt für immer blockiert (Marc's reales Bug 2026-05-12).
 *
 * Diese Klasse setzt Jobs, die länger als $thresholdMinutes in
 * 'running' hängen, auf 'error' zurück. Zum Worker-Start und alle
 * 5 Min via worker.php aufgerufen.
 */
final class JobRecoveryService
{
	public function __construct(
		private readonly PDO             $db,
		private readonly LoggerInterface $logger,
	) {}

	/**
	 * Markiert alle „running" sync_jobs mit started_at älter als
	 * $thresholdMinutes als „error". Returnt Anzahl recoverter Jobs.
	 */
	public function recoverStaleRunningJobs(int $thresholdMinutes): int
	{
		$stmt = $this->db->prepare('UPDATE sync_jobs
			SET status = "error",
			    finished_at = UTC_TIMESTAMP(3),
			    error_text = :err
			WHERE status = "running"
			  AND started_at < (UTC_TIMESTAMP(3) - INTERVAL :m MINUTE)');
		$stmt->bindValue(':err', sprintf('auto-recovered: stale running > %d min', $thresholdMinutes));
		$stmt->bindValue(':m', $thresholdMinutes, PDO::PARAM_INT);
		$stmt->execute();
		$recovered = $stmt->rowCount();
		if ($recovered > 0) {
			$this->logger->warning('worker.stale_jobs_recovered', [
				'count'             => $recovered,
				'threshold_minutes' => $thresholdMinutes,
			]);
		}
		return $recovered;
	}
}
