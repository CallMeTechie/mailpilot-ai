<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use PDO;

final class DashboardController extends BaseController
{
	public function index(array $params): void
	{
		$pdo = $this->kernel->get(PDO::class);

		$stats = [
			'tenants'  => (int)$pdo->query('SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL')->fetchColumn(),
			'users'    => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn(),
			'mailboxes' => (int)$pdo->query('SELECT COUNT(*) FROM mailboxes WHERE deleted_at IS NULL')->fetchColumn(),
			'mails_24h' => (int)$pdo->query('SELECT COUNT(*) FROM mails WHERE received_at >= (UTC_TIMESTAMP(3) - INTERVAL 24 HOUR)')->fetchColumn(),
			'scores_24h' => (int)$pdo->query('SELECT COUNT(*) FROM mail_scores WHERE scored_at >= (UTC_TIMESTAMP(3) - INTERVAL 24 HOUR)')->fetchColumn(),
			'cache_entries' => (int)$pdo->query('SELECT COUNT(*) FROM claude_cache')->fetchColumn(),
			'cache_hit_ratio' => $this->cacheHitRatio($pdo),
		];

		$labelDist = $pdo->query('SELECT label, COUNT(*) AS n
			FROM mail_scores
			WHERE scored_at >= (UTC_TIMESTAMP(3) - INTERVAL 7 DAY)
			GROUP BY label')->fetchAll(PDO::FETCH_KEY_PAIR);

		$errorJobs = $pdo->query('SELECT COUNT(*) FROM sync_jobs
			WHERE status = "error" AND created_at >= (UTC_TIMESTAMP(3) - INTERVAL 24 HOUR)')->fetchColumn();

		$this->render('dashboard', [
			'stats' => $stats,
			'labelDist' => $labelDist,
			'errorJobs' => (int)$errorJobs,
		]);
	}

	private function cacheHitRatio(PDO $pdo): float
	{
		$row = $pdo->query('SELECT SUM(hits) AS total_hits, COUNT(*) AS entries FROM claude_cache')->fetch();
		$hits = (int)($row['total_hits'] ?? 0);
		$entries = (int)($row['entries'] ?? 0);
		if ($entries === 0) return 0.0;
		// hits includes the initial insert, so reuses = hits - entries
		$reuses = max(0, $hits - $entries);
		return $hits > 0 ? round($reuses / $hits * 100, 1) : 0.0;
	}
}
