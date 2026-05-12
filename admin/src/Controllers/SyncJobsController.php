<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use PDO;

/**
 * Read-only view onto the sync_jobs table. The dashboard's "X Sync-Jobs
 * mit Fehlern" pill linked at the audit log, which doesn't carry sync
 * events at all — this controller is what that link should actually
 * point at.
 */
final class SyncJobsController extends BaseController
{
	public function list(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);

		$status = (string)($_GET['status'] ?? '');
		$allowed = ['queued', 'running', 'done', 'error'];
		$where = '';
		$args  = [];
		if (in_array($status, $allowed, true)) {
			$where = ' WHERE j.status = :s';
			$args[':s'] = $status;
		}

		$sql = 'SELECT j.id, j.tenant_id, j.mailbox_id, j.status, j.total, j.processed,
				j.error_text, j.started_at, j.finished_at, j.created_at,
				mb.email AS mailbox_email,
				t.name AS tenant_name
			FROM sync_jobs j
			LEFT JOIN mailboxes mb ON mb.id = j.mailbox_id
			LEFT JOIN tenants   t  ON t.id  = j.tenant_id'
			. $where . '
			ORDER BY j.created_at DESC
			LIMIT 100';
		$stmt = $pdo->prepare($sql);
		$stmt->execute($args);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$counts = $pdo->query('SELECT status, COUNT(*) AS n
			FROM sync_jobs
			WHERE created_at >= (UTC_TIMESTAMP(3) - INTERVAL 24 HOUR)
			GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

		$this->render('sync_jobs', [
			'rows'           => $rows,
			'filterStatus'   => $status,
			'counts24h'      => $counts,
		]);
	}
}
