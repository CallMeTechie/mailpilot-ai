<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

final class DraftRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * Sprint 6f: zusätzliche Felder user_id, conversation_id, created_by.
	 * Backward-kompatibel — alte ReplyDraftService-Caller passieren keine
	 * Werte und kriegen Defaults (user_id=null, conv=null, by='user').
	 */
	public function create(
		string  $tenantId,
		string  $mailId,
		string  $text,
		?string $instruction,
		string  $promptVersion,
		string  $model,
		?string $userId = null,
		?string $conversationId = null,
		string  $createdBy = 'user',
	): string {
		$id = Uuid::v4();
		$stmt = $this->db->prepare('INSERT INTO reply_drafts
			(id, tenant_id, mail_id, draft_text, user_instruction, prompt_version,
			 model, user_id, conversation_id, created_by)
			VALUES (:id, :t, :m, :d, :i, :pv, :mo, :u, :c, :cb)');
		$stmt->execute([
			':id' => $id,
			':t'  => $tenantId,
			':m'  => $mailId,
			':d'  => $text,
			':i'  => $instruction,
			':pv' => $promptVersion,
			':mo' => $model,
			':u'  => $userId,
			':c'  => $conversationId,
			':cb' => $createdBy,
		]);
		return $id;
	}

	/**
	 * Existiert eine aktive Draft für diese Mail (non-dismissed, non-stale)?
	 * Wird vom Worker genutzt um Doppel-Generation zu verhindern, und vom
	 * Add-in um die Draft-Box im „Diese Mail"-Tab anzuzeigen.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findActiveForMail(string $tenantId, string $mailId): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM reply_drafts
			WHERE tenant_id = :t AND mail_id = :m
			  AND dismissed_at IS NULL AND stale_at IS NULL
			ORDER BY generated_at DESC LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':m' => $mailId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/** @return array<string,mixed>|null */
	public function findById(string $tenantId, string $draftId): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM reply_drafts
			WHERE tenant_id = :t AND id = :id LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':id' => $draftId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * Sprint 6f Stale-Hook: wenn neue Mail mit derselben conversation_id
	 * synct, oder wenn der Sent-Folder-Lookup eine eigene Antwort findet,
	 * markieren wir alle aktiven Drafts dieser Konversation als stale.
	 * Returnt Anzahl markierter Drafts.
	 */
	public function markStaleByConversation(string $tenantId, string $conversationId): int
	{
		$stmt = $this->db->prepare('UPDATE reply_drafts
			SET stale_at = UTC_TIMESTAMP(3)
			WHERE tenant_id = :t AND conversation_id = :c
			  AND dismissed_at IS NULL AND stale_at IS NULL');
		$stmt->execute([':t' => $tenantId, ':c' => $conversationId]);
		return $stmt->rowCount();
	}

	public function markDismissed(string $tenantId, string $draftId): bool
	{
		$stmt = $this->db->prepare('UPDATE reply_drafts
			SET dismissed_at = UTC_TIMESTAMP(3)
			WHERE tenant_id = :t AND id = :id AND dismissed_at IS NULL');
		$stmt->execute([':t' => $tenantId, ':id' => $draftId]);
		return $stmt->rowCount() > 0;
	}
}
