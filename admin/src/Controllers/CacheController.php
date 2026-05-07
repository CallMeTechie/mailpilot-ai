<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use PDO;

final class CacheController extends BaseController
{
	public function list(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);
		$rows = $pdo->query('SELECT content_hash, tenant_id, prompt_version, model, hits,
				created_at, last_hit_at
			FROM claude_cache
			ORDER BY last_hit_at DESC LIMIT 100')->fetchAll();

		$summary = $pdo->query('SELECT COUNT(*) AS entries, SUM(hits) AS total_hits
			FROM claude_cache')->fetch();

		$this->render('cache', ['rows' => $rows, 'summary' => $summary]);
	}

	public function purge(array $params): void
	{
		$this->verifyCsrf();
		$scope = (string)($_POST['scope'] ?? 'all');
		$pdo = $this->kernel->get(PDO::class);

		if ($scope === 'all') {
			$n = $pdo->exec('DELETE FROM claude_cache');
		} elseif ($scope === 'expired') {
			$stmt = $pdo->prepare('DELETE FROM claude_cache
				WHERE created_at < (UTC_TIMESTAMP(3) - INTERVAL 30 DAY)');
			$stmt->execute();
			$n = $stmt->rowCount();
		} else {
			$n = 0;
		}

		$pdo->prepare('INSERT INTO audit_log (event, entity, meta_json)
			VALUES ("admin.cache.purge", "cache", :meta)')
			->execute([':meta' => json_encode(['scope' => $scope, 'count' => $n])]);

		$this->flash('success', "{$n} Cache-Einträge gelöscht");
		$this->redirect('/admin/cache');
	}
}
