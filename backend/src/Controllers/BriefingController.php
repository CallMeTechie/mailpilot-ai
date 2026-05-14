<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageRepository;

final class BriefingController extends BaseController
{
	public function today(array $params, array $body): void
	{
		$ctx = $this->requireAuth();

		$mailboxes = $this->kernel->get(MailboxRepository::class)
			->findByUser($ctx['tenant_id'], $ctx['user_id']);

		if ($mailboxes === []) {
			throw HttpException::preconditionFailed('MAILBOX_NOT_CONNECTED', 'Kein Postfach verbunden');
		}

		$scores = $this->kernel->get(ScoreRepository::class);

		// Last 7 days — covers the realistic "what's in my inbox right now"
		// window. A pure "today UTC" filter looked empty for users whose
		// initial sync brought in mostly older mail.
		$sinceUtc = gmdate('Y-m-d H:i:s.000', time() - 7 * 86400);

		$countersTotal = ['direct' => 0, 'action' => 0, 'cc' => 0, 'newsletter' => 0, 'auto' => 0, 'noise' => 0];
		$top = [];
		foreach ($mailboxes as $mb) {
			$c = $scores->countByLabelSince($ctx['tenant_id'], (string)$mb['id'], $sinceUtc);
			foreach ($c as $k => $v) {
				$countersTotal[$k] = ($countersTotal[$k] ?? 0) + $v;
			}
			$top = array_merge($top, $scores->topPrioritySince($ctx['tenant_id'], (string)$mb['id'], $sinceUtc, 5));
		}

		// Sort merged top-list
		usort($top, static function (array $a, array $b): int {
			return ($b['priority'] <=> $a['priority'])
				?: (strcmp((string)$b['received_at'], (string)$a['received_at']));
		});
		$top = array_slice($top, 0, 10);

		// Budget + worker info so the add-in footer can show a live
		// "12k / 100k Tokens" badge and a worker-alive indicator. Both
		// are cheap reads from settings/usage_daily.
		$settings = $this->kernel->get(SettingsRepository::class);
		$usage    = $this->kernel->get(UsageRepository::class);

		$userLimit = $settings->getInt('budget.user.daily_tokens', 0);
		$userUsed  = $usage->outputTokensToday($ctx['tenant_id'], $ctx['user_id']);
		$pct       = $userLimit > 0 ? min(100, (int)round(($userUsed / $userLimit) * 100)) : 0;

		$lastSeen   = $settings->getString('worker.last_seen', '');
		$workerOk   = false;
		if ($lastSeen !== '') {
			$age = time() - strtotime($lastSeen);
			// Schwelle via Admin-Panel editierbar (Migration 0014):
			// worker.heartbeat_threshold_seconds. Default 300s deckt
			// einen Score-Batch mit Anthropic-Latenz ab.
			$threshold = max(60, $settings->getInt('worker.heartbeat_threshold_seconds', 300));
			$workerOk = $age >= 0 && $age < $threshold;
		}

		Response::json([
			'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'counters'     => $countersTotal,
			'top_priority' => $top,
			'budget'       => [
				'user_used'  => $userUsed,
				'user_limit' => $userLimit,
				'percent'    => $pct,
				'enforcement_mode' => $settings->getString('budget.enforcement_mode', 'enforce'),
			],
			'worker'       => [
				'last_seen' => $lastSeen,
				'healthy'   => $workerOk,
			],
		]);
	}
}
