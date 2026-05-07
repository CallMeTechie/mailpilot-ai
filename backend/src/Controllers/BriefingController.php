<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\ScoreRepository;

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

		$sinceUtc = gmdate('Y-m-d 00:00:00.000');

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

		Response::json([
			'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'counters'     => $countersTotal,
			'top_priority' => $top,
		]);
	}
}
