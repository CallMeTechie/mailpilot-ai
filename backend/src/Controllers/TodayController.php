<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use PDO;

/**
 * Sprint 6e — „MailPilot Heute"-Dashboard (PRD-Phase-6 §6 Sprint-6e).
 *
 * Liefert drei Sektionen:
 *   important: action_owner='user' AND action_required=1 AND cleared_at IS NULL
 *              → „Du musst dich kümmern"
 *   unclear:   action_owner='unsure' AND cleared_at IS NULL
 *              → „Bitte korrigieren oder ignorieren"
 *   done:      cleared_at IS NOT NULL AND action_required=0
 *              → „Aus dem Weg, von KI oder dir selbst sortiert"
 *
 * Filter ?range=today|week|all schränkt received_at ein.
 * Pagination: Cursor pro Sektion via mail_id, 50 Items/Section.
 */
final class TodayController extends BaseController
{
	private const SECTION_LIMIT = 50;

	public function today(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pdo = $this->kernel->get(PDO::class);

		$range = (string)($_GET['range'] ?? 'today');
		$sinceClause = $this->rangeToSinceClause($range);

		$cursors = [
			'important' => isset($_GET['cursor_important']) ? (string)$_GET['cursor_important'] : null,
			'unclear'   => isset($_GET['cursor_unclear'])   ? (string)$_GET['cursor_unclear']   : null,
			'done'      => isset($_GET['cursor_done'])      ? (string)$_GET['cursor_done']      : null,
		];

		$important = $this->fetchSection($pdo, $ctx['tenant_id'], $ctx['user_id'],
			"s.action_owner = 'user' AND s.action_required = 1 AND s.cleared_at IS NULL",
			$sinceClause, $cursors['important']);
		$unclear   = $this->fetchSection($pdo, $ctx['tenant_id'], $ctx['user_id'],
			"s.action_owner = 'unsure' AND s.cleared_at IS NULL",
			$sinceClause, $cursors['unclear']);
		$done      = $this->fetchSection($pdo, $ctx['tenant_id'], $ctx['user_id'],
			"s.cleared_at IS NOT NULL AND s.action_required = 0",
			$sinceClause, $cursors['done']);

		Response::json([
			'range'     => $range,
			'important' => [
				'items'       => $important,
				'next_cursor' => $important !== [] ? end($important)['mail_id'] : null,
			],
			'unclear' => [
				'items'       => $unclear,
				'next_cursor' => $unclear !== [] ? end($unclear)['mail_id'] : null,
			],
			'done' => [
				'items'       => $done,
				'next_cursor' => $done !== [] ? end($done)['mail_id'] : null,
			],
		]);
	}

	/**
	 * Owner-Korrektur: User markiert eine Mail mit korrektem action_owner.
	 * Sticky-Effekt nur auf action_owner via user_corrected_fields-SET
	 * (DA-Pre-Impl Finding 3); label/priority bleiben Claude-refreshbar.
	 */
	public function correctOwner(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$mailId = (string)($params['id'] ?? '');
		$owner  = (string)($body['action_owner'] ?? '');
		if (!in_array($owner, ['user','other','group','unsure'], true)) {
			throw HttpException::badRequest('INVALID_OWNER',
				'action_owner muss user|other|group|unsure sein');
		}

		$pdo = $this->kernel->get(PDO::class);
		// FIND_IN_SET-safe concat: bestehende user_corrected_fields plus
		// 'action_owner' (dedupliziert via separate Set-Manipulation).
		$stmt = $pdo->prepare('UPDATE mail_scores
			SET action_owner = :ao,
			    action_owner_source = "user_corrected",
			    action_owner_confidence = 100,
			    user_corrected_at = UTC_TIMESTAMP(3),
			    user_corrected_fields =
			        IF(user_corrected_fields IS NULL,
			            "action_owner",
			            IF(FIND_IN_SET("action_owner", user_corrected_fields),
			                user_corrected_fields,
			                CONCAT(user_corrected_fields, ",action_owner")))
			WHERE mail_id = :m AND tenant_id = :t');
		$stmt->execute([':ao' => $owner, ':m' => $mailId, ':t' => $ctx['tenant_id']]);
		if ($stmt->rowCount() === 0) {
			throw HttpException::notFound('SCORE_NOT_FOUND',
				'Score-Zeile nicht gefunden oder keine Änderung nötig');
		}
		Response::json(['ok' => true, 'action_owner' => $owner]);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	private function fetchSection(PDO $pdo, string $tenantId, string $userId, string $where, string $sinceClause, ?string $afterMailId): array
	{
		$sql = "SELECT m.id AS mail_id, m.ms_message_id, m.subject, m.from_email, m.from_name,
				m.received_at,
				s.label, s.sub_label, s.priority, s.action_required,
				s.action_owner, s.action_owner_confidence, s.action_owner_source,
				s.summary, s.cleared_at, s.auto_sorted_at
			FROM mail_scores s
			INNER JOIN mails m ON m.id = s.mail_id
			INNER JOIN mailboxes mb ON mb.id = m.mailbox_id
			WHERE s.tenant_id = :t
			  AND mb.user_id = :u
			  AND m.deleted_at IS NULL
			  AND {$where}
			  {$sinceClause}";
		$params = [':t' => $tenantId, ':u' => $userId];
		if ($afterMailId !== null && $afterMailId !== '') {
			$sql .= ' AND m.id > :after';
			$params[':after'] = $afterMailId;
		}
		$sql .= ' ORDER BY m.received_at DESC, m.id ASC LIMIT :lim';
		$stmt = $pdo->prepare($sql);
		foreach ($params as $k => $v) $stmt->bindValue($k, $v);
		$stmt->bindValue(':lim', self::SECTION_LIMIT, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	private function rangeToSinceClause(string $range): string
	{
		switch ($range) {
			case 'all':   return '';
			case 'week':  return 'AND m.received_at >= (UTC_TIMESTAMP(3) - INTERVAL 7 DAY)';
			case 'today':
			default:      return 'AND m.received_at >= (UTC_TIMESTAMP(3) - INTERVAL 1 DAY)';
		}
	}
}
