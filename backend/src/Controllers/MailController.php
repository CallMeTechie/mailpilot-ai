<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Request;
use MailPilot\Http\Response;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Services\MailScoringService;
use MailPilot\Services\MailSummaryService;
use MailPilot\Services\ReplyDraftService;

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
