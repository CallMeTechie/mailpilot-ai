<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Graph\GraphClient;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Request;
use MailPilot\Http\Response;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Services\MailScoringService;
use MailPilot\Services\MailSummaryService;
use MailPilot\Services\ReplyDraftService;
use MailPilot\Services\TokenService;

final class MailController extends BaseController
{
	public function list(array $params, array $body): void
	{
		$ctx = $this->requireAuth();

		$since = Request::query('since');
		$label = Request::query('label');
		$msMsgId = Request::query('ms_message_id');
		$limit = max(1, min(200, (int)(Request::query('limit', '50'))));

		$pdo = $this->kernel->get(\PDO::class);
		$sql = 'SELECT m.id, m.from_email, m.from_name, m.subject, m.received_at, m.ms_message_id,
					   s.label, s.action_required, s.priority, s.summary, s.scored_at
				FROM mails m
				LEFT JOIN mail_scores s ON s.mail_id = m.id
				WHERE m.tenant_id = :t AND m.deleted_at IS NULL';
		$p = [':t' => $ctx['tenant_id']];

		if ($since !== null) { $sql .= ' AND m.received_at >= :since'; $p[':since'] = $since; }
		if ($label !== null) { $sql .= ' AND s.label = :lbl';          $p[':lbl']   = $label; }
		if ($msMsgId !== null) { $sql .= ' AND m.ms_message_id = :mid'; $p[':mid']  = $msMsgId; }

		$sql .= ' ORDER BY m.received_at DESC LIMIT ' . $limit;

		$stmt = $pdo->prepare($sql);
		$stmt->execute($p);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		$items = array_map(static function (array $r): array {
			return [
				'id'             => $r['id'],
				'ms_message_id'  => $r['ms_message_id'],
				'from_email'     => $r['from_email'],
				'from_name'      => $r['from_name'],
				'subject'        => $r['subject'],
				'received_at'    => $r['received_at'],
				'score'          => $r['label'] === null ? null : [
					'label'           => $r['label'],
					'action_required' => (bool)$r['action_required'],
					'priority'        => (int)$r['priority'],
					'summary'         => $r['summary'],
					'scored_at'       => $r['scored_at'],
				],
			];
		}, $rows);

		Response::json(['items' => $items, 'next_cursor' => null]);
	}

	public function summarize(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$userRow = $this->fetchUser($ctx['user_id']);
		$summary = $this->kernel->get(MailSummaryService::class)
			->summarize(
				$ctx['tenant_id'],
				(string)$params['id'],
				$ctx['email'],
				(string)($userRow['language'] ?? 'de'),
			);
		Response::json(['summary' => $summary]);
	}

	public function draftReply(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$instruction = isset($body['instruction']) ? (string)$body['instruction'] : null;
		$draft = $this->kernel->get(ReplyDraftService::class)
			->draft($ctx['tenant_id'], (string)$params['id'], $instruction);
		Response::json(['draft' => $draft]);
	}

	public function rescore(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$mail = $this->kernel->get(MailRepository::class)->findById($ctx['tenant_id'], (string)$params['id']);
		if ($mail === null) {
			throw HttpException::notFound('NOT_FOUND', 'Mail nicht gefunden');
		}
		$userRow = $this->fetchUser($ctx['user_id']);
		$profile = $this->buildUserProfile($ctx, $userRow);

		$this->kernel->get(MailScoringService::class)
			->scoreBatch($ctx['tenant_id'], $profile, [$mail]);

		Response::json(['ok' => true]);
	}

	/**
	 * Bulk action on all mails matching a label within a time window.
	 *
	 * Action (route param):
	 *   mark-read  → PATCH isRead:true via Graph
	 *   archive    → POST /move destinationId=archive via Graph
	 *   delete     → DELETE via Graph (moves to Deleted Items) + soft-delete in DB
	 *   hide       → soft-delete in our DB only, Outlook untouched
	 *
	 * Body: { label: <enum>, since?: 'YYYY-MM-DD HH:MM:SS.000', limit?: 50 }
	 */
	public function bulkAction(array $params, array $body): void
	{
		$ctx    = $this->requireAuth();
		$action = (string)($params['action'] ?? '');
		$valid  = ['mark-read', 'archive', 'delete', 'hide'];
		if (!in_array($action, $valid, true)) {
			throw HttpException::badRequest('VALIDATION', 'Ungültige Aktion');
		}

		$label = isset($body['label']) ? (string)$body['label'] : null;
		if ($label === null || $label === '') {
			throw HttpException::badRequest('VALIDATION', 'label fehlt');
		}
		$sinceUtc = isset($body['since']) ? (string)$body['since']
			: gmdate('Y-m-d H:i:s.000', time() - 7 * 86400);
		$limit = max(1, min(200, (int)($body['limit'] ?? 50)));

		$pdo = $this->kernel->get(\PDO::class);
		$stmt = $pdo->prepare('SELECT m.id, m.ms_message_id, m.mailbox_id
			FROM mails m
			JOIN mail_scores s ON s.mail_id = m.id
			WHERE m.tenant_id = :t
			  AND m.deleted_at IS NULL
			  AND s.label = :l
			  AND m.received_at >= :since
			ORDER BY m.received_at DESC
			LIMIT ' . $limit);
		$stmt->execute([':t' => $ctx['tenant_id'], ':l' => $label, ':since' => $sinceUtc]);
		$mails = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		if ($mails === []) {
			Response::json(['processed' => 0, 'failed' => []]);
			return;
		}

		// "hide" is DB-only: mark rows deleted, Outlook untouched.
		if ($action === 'hide') {
			$ids = array_column($mails, 'id');
			$placeholders = implode(',', array_fill(0, count($ids), '?'));
			$update = $pdo->prepare("UPDATE mails SET deleted_at = UTC_TIMESTAMP(3)
				WHERE tenant_id = ? AND id IN ($placeholders)");
			$update->execute(array_merge([$ctx['tenant_id']], $ids));
			Response::json(['processed' => count($ids), 'failed' => []]);
			return;
		}

		// Graph-backed actions — fresh access token per mailbox.
		$mailboxes = $this->kernel->get(MailboxRepository::class)
			->findByUser($ctx['tenant_id'], $ctx['user_id']);
		$tokenByMb = [];
		$tokenService = $this->kernel->get(TokenService::class);
		foreach ($mailboxes as $mb) {
			try {
				$tokenByMb[(string)$mb['id']] = $tokenService->ensureFreshAccessToken($mb);
			} catch (\Throwable) {
				// Refresh failed → its mails will end up in `failed`.
			}
		}

		$graph     = $this->kernel->get(GraphClient::class);
		$processed = 0;
		$failed    = [];

		foreach ($mails as $mail) {
			$token = $tokenByMb[(string)$mail['mailbox_id']] ?? null;
			if ($token === null) {
				$failed[] = $mail['id'];
				continue;
			}
			try {
				if ($action === 'mark-read') {
					$graph->markAsRead($token, (string)$mail['ms_message_id']);
				} elseif ($action === 'archive') {
					$graph->moveToFolder($token, (string)$mail['ms_message_id'], 'archive');
				} elseif ($action === 'delete') {
					$graph->deleteMessage($token, (string)$mail['ms_message_id']);
					$pdo->prepare('UPDATE mails SET deleted_at = UTC_TIMESTAMP(3) WHERE id = :id')
						->execute([':id' => $mail['id']]);
				}
				$processed++;
			} catch (\Throwable) {
				$failed[] = $mail['id'];
			}
		}

		Response::json(['processed' => $processed, 'failed' => $failed]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchUser(string $userId): array
	{
		$stmt = $this->kernel->get(\PDO::class)->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
		$stmt->execute([':id' => $userId]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row === false ? [] : $row;
	}

	/**
	 * @param array{tenant_id:string, user_id:string, email:string} $ctx
	 * @param array<string, mixed> $userRow
	 * @return array<string, mixed>
	 */
	private function buildUserProfile(array $ctx, array $userRow): array
	{
		$pdo = $this->kernel->get(\PDO::class);

		$vipStmt = $pdo->prepare('SELECT email FROM vip_senders
			WHERE user_id = :u AND deleted_at IS NULL');
		$vipStmt->execute([':u' => $ctx['user_id']]);
		$vips = array_column($vipStmt->fetchAll(\PDO::FETCH_ASSOC), 'email');

		$kwStmt = $pdo->prepare('SELECT keyword FROM project_keywords
			WHERE user_id = :u AND deleted_at IS NULL');
		$kwStmt->execute([':u' => $ctx['user_id']]);
		$kws = array_column($kwStmt->fetchAll(\PDO::FETCH_ASSOC), 'keyword');

		return [
			'email'            => $ctx['email'],
			'language'         => (string)($userRow['language'] ?? 'de'),
			'vip_senders'      => $vips,
			'project_keywords' => $kws,
			'user_role'        => '',
		];
	}
}
