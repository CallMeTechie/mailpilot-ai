<?php
declare(strict_types=1);

namespace MailPilot\Admin\Controllers;

use MailPilot\Repositories\UsageRepository;
use PDO;

/**
 * Token + cost dashboard. Read-only — settings live in BudgetController.
 *
 * All time windows are UTC days so they line up with the usage_daily
 * primary-key date column.
 */
final class UsageController extends BaseController
{
	public function index(array $params): void
	{
		$usage = $this->kernel->get(UsageRepository::class);
		$pdo   = $this->kernel->get(PDO::class);

		$today  = gmdate('Y-m-d');
		$d7     = gmdate('Y-m-d', strtotime('-6 days', strtotime((string)$today)));
		$d30    = gmdate('Y-m-d', strtotime('-29 days', strtotime((string)$today)));

		$cards = [
			'today' => $usage->totalsSince($today),
			'd7'    => $usage->totalsSince($d7),
			'd30'   => $usage->totalsSince($d30),
		];

		$trend       = $usage->dailyTrend($d30);
		$byPrompt    = $usage->breakdownByPromptSince($d30);
		$topUsersRaw = $usage->topUsersSince($d30, 20);

		// Resolve user_id → email for display
		$userMap = [];
		if ($topUsersRaw !== []) {
			$ids   = array_map(static fn(array $u): string => $u['user_id'], $topUsersRaw);
			$place = implode(',', array_fill(0, count($ids), '?'));
			$stmt  = $pdo->prepare("SELECT id, email FROM users WHERE id IN ($place)");
			$stmt->execute($ids);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$userMap[(string)$r['id']] = (string)$r['email'];
			}
		}
		$topUsers = array_map(static fn(array $u): array => $u + [
			'email' => $userMap[$u['user_id']] ?? '—',
		], $topUsersRaw);

		$recent = $pdo->query('SELECT u.created_at, u.tenant_id, u.user_id, usr.email AS user_email,
				u.prompt_version, u.model, u.input_tokens, u.output_tokens,
				u.cost_eur, u.duration_ms, u.status, u.error_text
			FROM api_usage u
			LEFT JOIN users usr ON usr.id = u.user_id
			ORDER BY u.created_at DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);

		$this->render('usage', [
			'cards'    => $cards,
			'trend'    => $trend,
			'byPrompt' => $byPrompt,
			'topUsers' => $topUsers,
			'recent'   => $recent,
		]);
	}
}
