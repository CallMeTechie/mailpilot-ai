<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

final class MailboxRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	public function findById(string $tenantId, string $id): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM mailboxes
			WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL
			LIMIT 1');
		$stmt->execute([':id' => $id, ':t' => $tenantId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	public function findByUser(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT * FROM mailboxes
			WHERE user_id = :u AND tenant_id = :t AND deleted_at IS NULL
			ORDER BY created_at ASC');
		$stmt->execute([':u' => $userId, ':t' => $tenantId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function updateTokens(string $id, string $accessEnc, string $refreshEnc, string $expiresUtc): void
	{
		$stmt = $this->db->prepare('UPDATE mailboxes
			SET access_token_enc = :at,
				refresh_token_enc = :rt,
				access_token_expires = :exp,
				updated_at = UTC_TIMESTAMP(3)
			WHERE id = :id');
		$stmt->bindValue(':at',  $accessEnc,  PDO::PARAM_LOB);
		$stmt->bindValue(':rt',  $refreshEnc, PDO::PARAM_LOB);
		$stmt->bindValue(':exp', $expiresUtc);
		$stmt->bindValue(':id',  $id);
		$stmt->execute();
	}

	public function upsert(
		string $tenantId,
		string $userId,
		string $email,
		?string $displayName,
		string $accessEnc,
		string $refreshEnc,
		string $expiresUtc,
		string $scopes,
		?string $msTenantId = null,
		?string $msUserId = null,
	): string {
		$id = Uuid::v4();
		$stmt = $this->db->prepare('INSERT INTO mailboxes
			(id, tenant_id, user_id, email, display_name, ms_tenant_id, ms_user_id,
			 access_token_enc, refresh_token_enc, access_token_expires, scopes)
			VALUES (:id, :t, :u, :e, :n, :mst, :msu, :at, :rt, :exp, :sc)
			ON DUPLICATE KEY UPDATE
				access_token_enc = VALUES(access_token_enc),
				refresh_token_enc = VALUES(refresh_token_enc),
				access_token_expires = VALUES(access_token_expires),
				scopes = VALUES(scopes),
				display_name = VALUES(display_name),
				ms_tenant_id = COALESCE(VALUES(ms_tenant_id), ms_tenant_id),
				ms_user_id   = COALESCE(VALUES(ms_user_id),   ms_user_id),
				deleted_at = NULL,
				updated_at = UTC_TIMESTAMP(3)');
		$stmt->bindValue(':id',  $id);
		$stmt->bindValue(':t',   $tenantId);
		$stmt->bindValue(':u',   $userId);
		$stmt->bindValue(':e',   $email);
		$stmt->bindValue(':n',   $displayName);
		$stmt->bindValue(':mst', $msTenantId);
		$stmt->bindValue(':msu', $msUserId);
		$stmt->bindValue(':at',  $accessEnc,  PDO::PARAM_LOB);
		$stmt->bindValue(':rt',  $refreshEnc, PDO::PARAM_LOB);
		$stmt->bindValue(':exp', $expiresUtc);
		$stmt->bindValue(':sc',  $scopes);
		$stmt->execute();
		return $id;
	}

	public function updateDeltaAndSyncAt(string $id, ?string $deltaToken): void
	{
		$stmt = $this->db->prepare('UPDATE mailboxes
			SET delta_token = :d,
				last_sync_at = UTC_TIMESTAMP(3),
				updated_at = UTC_TIMESTAMP(3)
			WHERE id = :id');
		$stmt->execute([':d' => $deltaToken, ':id' => $id]);
	}
}
