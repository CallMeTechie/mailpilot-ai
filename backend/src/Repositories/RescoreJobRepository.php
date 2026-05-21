<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Phase 9l (Marc 2026-05-21) — Async-Worker-Job-Queue fuer Bulk-Rescore.
 *
 * Job-Lifecycle:
 *   1. Controller ruft enqueue() bei POST /mails/rescore-folder.
 *   2. Worker (bin/worker.php) ruft claim() per SELECT FOR UPDATE SKIP LOCKED.
 *   3. Worker ruft updateProgress() nach jedem Chunk (live progress fuer UI).
 *   4. Worker ruft markDone() oder markFailed() am Ende.
 *   5. Add-in pollt findById() alle paar Sek bis status in {done,failed}.
 *
 * Multi-Tenant: tenant_id Pflicht in allen Read-Pfaden (CLAUDE.md §6).
 */
final class RescoreJobRepository
{
	/** @var list<string> */
	public const STATUSES = ['queued', 'running', 'done', 'failed'];

	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * Legt einen neuen Job an und gibt die UUID zurueck.
	 */
	public function enqueue(
		string $tenantId,
		string $userId,
		string $folderId,
		?string $mailboxId = null,
	): string {
		$id = Uuid::v4();
		$stmt = $this->db->prepare(
			'INSERT INTO rescore_jobs (id, tenant_id, user_id, mailbox_id, folder_id, status)
			 VALUES (:id, :t, :u, :m, :f, "queued")'
		);
		$stmt->execute([
			':id' => $id,
			':t'  => $tenantId,
			':u'  => $userId,
			':m'  => $mailboxId,
			':f'  => $folderId,
		]);
		return $id;
	}

	/**
	 * Claim eines queued-Jobs (FOR UPDATE SKIP LOCKED). Returnt Job-Row oder
	 * null wenn keiner verfuegbar.
	 *
	 * @return array{id:string, tenant_id:string, user_id:string, mailbox_id:?string, folder_id:string}|null
	 */
	public function claimNextQueued(): ?array
	{
		$this->db->beginTransaction();
		try {
			$stmt = $this->db->query(
				'SELECT id, tenant_id, user_id, mailbox_id, folder_id
				 FROM rescore_jobs
				 WHERE status = "queued"
				 ORDER BY created_at ASC
				 LIMIT 1
				 FOR UPDATE SKIP LOCKED'
			);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($row === false) {
				$this->db->rollBack();
				return null;
			}
			$this->db->prepare(
				'UPDATE rescore_jobs
				 SET status = "running", started_at = UTC_TIMESTAMP(3)
				 WHERE id = :id'
			)->execute([':id' => $row['id']]);
			$this->db->commit();
			return [
				'id'         => (string)$row['id'],
				'tenant_id'  => (string)$row['tenant_id'],
				'user_id'    => (string)$row['user_id'],
				'mailbox_id' => $row['mailbox_id'] !== null ? (string)$row['mailbox_id'] : null,
				'folder_id'  => (string)$row['folder_id'],
			];
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Live-Progress-Update fuer das UI-Polling. Setzt total + processed.
	 */
	public function updateProgress(string $jobId, int $processed, int $total): void
	{
		$this->db->prepare(
			'UPDATE rescore_jobs
			 SET processed = :p, total = :t
			 WHERE id = :id'
		)->execute([
			':p'  => max(0, $processed),
			':t'  => max(0, $total),
			':id' => $jobId,
		]);
	}

	public function markDone(string $jobId, int $processed, int $total, bool $capped = false): void
	{
		$this->db->prepare(
			'UPDATE rescore_jobs
			 SET status = "done",
			     finished_at = UTC_TIMESTAMP(3),
			     processed = :p, total = :t, capped = :c
			 WHERE id = :id'
		)->execute([
			':p'  => $processed,
			':t'  => $total,
			':c'  => $capped ? 1 : 0,
			':id' => $jobId,
		]);
	}

	public function markFailed(string $jobId, string $errorText): void
	{
		$this->db->prepare(
			'UPDATE rescore_jobs
			 SET status = "failed",
			     finished_at = UTC_TIMESTAMP(3),
			     error_text = :err
			 WHERE id = :id'
		)->execute([
			':err' => substr($errorText, 0, 500),
			':id'  => $jobId,
		]);
	}

	/**
	 * Liefert Job-Status fuer Polling (tenant-scoped). Null wenn nicht
	 * gefunden ODER zu anderem Tenant gehoerend.
	 *
	 * @return array{id:string, status:string, total:int, processed:int, capped:bool, error_text:?string, folder_id:string, created_at:string, finished_at:?string}|null
	 */
	public function findById(string $tenantId, string $jobId): ?array
	{
		$stmt = $this->db->prepare(
			'SELECT id, status, total, processed, capped, error_text, folder_id, created_at, finished_at
			 FROM rescore_jobs
			 WHERE id = :id AND tenant_id = :t
			 LIMIT 1'
		);
		$stmt->execute([':id' => $jobId, ':t' => $tenantId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row === false) {
			return null;
		}
		return [
			'id'          => (string)$row['id'],
			'status'      => (string)$row['status'],
			'total'       => (int)$row['total'],
			'processed'   => (int)$row['processed'],
			'capped'      => (bool)$row['capped'],
			'error_text'  => $row['error_text'] !== null ? (string)$row['error_text'] : null,
			'folder_id'   => (string)$row['folder_id'],
			'created_at'  => (string)$row['created_at'],
			'finished_at' => $row['finished_at'] !== null ? (string)$row['finished_at'] : null,
		];
	}

	/**
	 * Pruefen ob es bereits einen queued/running Job auf demselben Folder gibt.
	 * Schuetzt vor Doppel-Klicks und parallelen Worker-Picks (defense in depth).
	 */
	public function findActiveByFolder(string $tenantId, string $userId, string $folderId): ?string
	{
		$stmt = $this->db->prepare(
			'SELECT id FROM rescore_jobs
			 WHERE tenant_id = :t AND user_id = :u AND folder_id = :f
			   AND status IN ("queued","running")
			 ORDER BY created_at DESC LIMIT 1'
		);
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':f' => $folderId]);
		$id = $stmt->fetchColumn();
		return $id === false ? null : (string)$id;
	}

	/**
	 * Housekeeping: alte done/failed Jobs purgen. Wird vom Worker im
	 * Daily-Housekeeping aufgerufen.
	 */
	public function purgeOlderThan(int $days): int
	{
		$stmt = $this->db->prepare(
			'DELETE FROM rescore_jobs
			 WHERE status IN ("done","failed")
			   AND finished_at < (UTC_TIMESTAMP(3) - INTERVAL :d DAY)'
		);
		$stmt->bindValue(':d', max(1, $days), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Stale-Recovery: Jobs die laenger als $minutes in running stecken,
	 * werden auf failed gesetzt. Verhindert dass ein gekillter Worker einen
	 * Job fuer immer blockiert.
	 */
	public function recoverStaleRunning(int $minutes): int
	{
		$stmt = $this->db->prepare(
			'UPDATE rescore_jobs
			 SET status = "failed",
			     finished_at = UTC_TIMESTAMP(3),
			     error_text = "stale_running_recovered"
			 WHERE status = "running"
			   AND started_at < (UTC_TIMESTAMP(3) - INTERVAL :m MINUTE)'
		);
		$stmt->bindValue(':m', max(1, $minutes), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}
}
