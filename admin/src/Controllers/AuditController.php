<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use PDO;

final class AuditController extends BaseController
{
	public function list(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);
		$event = trim((string)($_GET['event'] ?? ''));

		if ($event !== '') {
			$stmt = $pdo->prepare('SELECT * FROM audit_log WHERE event LIKE :e
				ORDER BY created_at DESC LIMIT 200');
			$stmt->execute([':e' => '%' . $event . '%']);
		} else {
			$stmt = $pdo->query('SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 200');
		}

		$this->render('audit', ['entries' => $stmt->fetchAll(), 'filter' => $event]);
	}
}
