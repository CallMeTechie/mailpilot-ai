<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

final class MailRepository
{
	private const MAX_BODY_BYTES = 1_000_000;

	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function findById(string $tenantId, string $mailId): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM mails
			WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL
			LIMIT 1');
		$stmt->execute([':id' => $mailId, ':t' => $tenantId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row === false) {
			return null;
		}
		$row['to_json'] = json_decode((string)($row['to_json'] ?? '[]'), true) ?? [];
		$row['cc_json'] = json_decode((string)($row['cc_json'] ?? '[]'), true) ?? [];
		return $row;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function findByMsMessageId(string $tenantId, string $msMessageId): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM mails
			WHERE tenant_id = :t AND ms_message_id = :m AND deleted_at IS NULL
			LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':m' => $msMessageId]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	public function findUnscoredForMailbox(string $tenantId, string $mailboxId, int $limit = 200): array
	{
		// Pick mails with no score at all, or with a deprecated preset-
		// only score that bypassed Claude. Latter need to be re-classified
		// by the model after the pre-filter was removed.
		$sql = 'SELECT m.*
				FROM mails m
				LEFT JOIN mail_scores s ON s.mail_id = m.id
				WHERE m.tenant_id = :t
				  AND m.mailbox_id = :mb
				  AND m.deleted_at IS NULL
				  AND (s.id IS NULL OR s.model = "preset_deprecated")
				ORDER BY m.received_at DESC
				LIMIT :limit';
		$stmt = $this->db->prepare($sql);
		$stmt->bindValue(':t', $tenantId);
		$stmt->bindValue(':mb', $mailboxId);
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as &$row) {
			$row['to_json'] = json_decode((string)($row['to_json'] ?? '[]'), true) ?? [];
			$row['cc_json'] = json_decode((string)($row['cc_json'] ?? '[]'), true) ?? [];
		}
		return $rows;
	}

	/**
	 * Upsert a mail from a Microsoft Graph message resource.
	 *
	 * @param array<string, mixed> $msg
	 */
	public function upsertFromGraph(string $tenantId, string $mailboxId, array $msg): string
	{
		$id = Uuid::v4();

		$from       = $msg['from']['emailAddress'] ?? [];
		$fromEmail  = (string)($from['address'] ?? '');
		$fromName   = (string)($from['name'] ?? '');
		$to         = array_map(fn($r) => $r['emailAddress']['address'] ?? '', $msg['toRecipients'] ?? []);
		$cc         = array_map(fn($r) => $r['emailAddress']['address'] ?? '', $msg['ccRecipients'] ?? []);

		$headers = $msg['internetMessageHeaders'] ?? [];
		$listUnsub = false;
		foreach ($headers as $h) {
			if (strcasecmp((string)($h['name'] ?? ''), 'List-Unsubscribe') === 0) {
				$listUnsub = true;
				break;
			}
		}

		$bodyText = '';
		if (isset($msg['body']['content'])) {
			$raw = (string)$msg['body']['content'];
			$bodyText = ((string)($msg['body']['contentType'] ?? 'text') === 'html')
				? trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
				: $raw;
			if (strlen($bodyText) > self::MAX_BODY_BYTES) {
				$bodyText = substr($bodyText, 0, self::MAX_BODY_BYTES);
			}
			// Graph occasionally returns bodies with stray non-UTF-8 bytes
			// (Windows-1252 punctuation, embedded PDF fragments). Storing
			// raw is fine; passing to json_encode later kills the whole
			// batch with JSON_ERROR_UTF8. Strip them at the boundary.
			$bodyText = \MailPilot\Util\Utf8::sanitize($bodyText);
		}

		$receivedAt = isset($msg['receivedDateTime'])
			? gmdate('Y-m-d H:i:s.000', strtotime((string)$msg['receivedDateTime']))
			: gmdate('Y-m-d H:i:s.000');

		// Sprint 6d Move-Detection: parent_folder_id ist Graph's
		// parentFolderId-Pointer. Bei jedem Sync wird er aktualisiert;
		// MoveDetectionService vergleicht den ALTEN DB-Wert gegen den
		// NEUEN Graph-Wert (via SyncService-Hook vor dem Upsert).
		$parentFolderId = (string)($msg['parentFolderId'] ?? '');
		$parentFolderId = $parentFolderId !== '' ? $parentFolderId : null;

		$sql = 'INSERT INTO mails
			(id, tenant_id, mailbox_id, parent_folder_id, ms_message_id, conversation_id, internet_msg_id,
			 from_email, from_name, to_json, cc_json, subject, body_preview, body_text,
			 has_attachment, is_reply, list_unsubscribe, received_at)
			VALUES
			(:id, :t, :mb, :pf, :msid, :cid, :imid,
			 :fe, :fn, :toj, :ccj, :sub, :prev, :body,
			 :att, :rep, :lu, :rcv)
			ON DUPLICATE KEY UPDATE
				parent_folder_id = VALUES(parent_folder_id),
				subject = VALUES(subject),
				body_preview = VALUES(body_preview),
				body_text = VALUES(body_text),
				has_attachment = VALUES(has_attachment),
				list_unsubscribe = VALUES(list_unsubscribe)';

		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':id'   => $id,
			':t'    => $tenantId,
			':mb'   => $mailboxId,
			':pf'   => $parentFolderId,
			':msid' => (string)($msg['id'] ?? ''),
			':cid'  => (string)($msg['conversationId'] ?? ''),
			':imid' => (string)($msg['internetMessageId'] ?? ''),
			':fe'   => \MailPilot\Util\Utf8::sanitize($fromEmail),
			':fn'   => \MailPilot\Util\Utf8::sanitize($fromName),
			':toj'  => json_encode($to, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			':ccj'  => json_encode($cc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
			':sub'  => \MailPilot\Util\Utf8::sanitize(substr((string)($msg['subject'] ?? ''), 0, 500)),
			':prev' => \MailPilot\Util\Utf8::sanitize(substr((string)($msg['bodyPreview'] ?? ''), 0, 500)),
			':body' => $bodyText,
			':att'  => (int)(bool)($msg['hasAttachments'] ?? false),
			':rep'  => str_starts_with((string)($msg['subject'] ?? ''), 'Re:') ? 1 : 0,
			':lu'   => $listUnsub ? 1 : 0,
			':rcv'  => $receivedAt,
		]);
		return $id;
	}

	/**
	 * Sprint 6d — liefert (mail_id, parent_folder_id) der zuletzt
	 * gespeicherten Version, damit der MoveDetectionService den
	 * VOR-Sync-Stand vor dem Upsert lesen kann. Mail-ID-Lookup via
	 * (tenant, mailbox, ms_message_id) wie UNIQUE-Key.
	 *
	 * @return array{id:string, parent_folder_id:?string}|null
	 */
	public function findIdAndParentByMsId(string $tenantId, string $mailboxId, string $msMessageId): ?array
	{
		$stmt = $this->db->prepare('SELECT id, parent_folder_id FROM mails
			WHERE tenant_id = :t AND mailbox_id = :mb AND ms_message_id = :ms
			LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':mb' => $mailboxId, ':ms' => $msMessageId]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if ($row === false) return null;
		return [
			'id' => (string)$row['id'],
			'parent_folder_id' => $row['parent_folder_id'] !== null ? (string)$row['parent_folder_id'] : null,
		];
	}

	/**
	 * Purge body_text older than retention days.
	 */
	public function purgeOldBodies(int $retentionDays): int
	{
		$sql = 'UPDATE mails
				SET body_text = NULL, body_purged_at = UTC_TIMESTAMP(3)
				WHERE body_text IS NOT NULL
				  AND received_at < (UTC_TIMESTAMP(3) - INTERVAL :d DAY)';
		$stmt = $this->db->prepare($sql);
		$stmt->bindValue(':d', $retentionDays, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}
}
