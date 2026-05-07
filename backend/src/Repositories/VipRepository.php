<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

final class VipRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	public function listForUser(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT id, email, display_name, created_at
			FROM vip_senders
			WHERE tenant_id = :t AND user_id = :u AND deleted_at IS NULL
			ORDER BY email ASC');
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function add(string $tenantId, string $userId, string $email, ?string $name): string
	{
		$id = Uuid::v4();
		$stmt = $this->db->prepare('INSERT INTO vip_senders (id, tenant_id, user_id, email, display_name)
			VALUES (:id, :t, :u, :e, :n)
			ON DUPLICATE KEY UPDATE
				display_name = VALUES(display_name),
				deleted_at = NULL');
		$stmt->execute([
			':id' => $id,
			':t'  => $tenantId,
			':u'  => $userId,
			':e'  => strtolower($email),
			':n'  => $name,
		]);
		return $id;
	}

	public function softDelete(string $tenantId, string $userId, string $id): void
	{
		$stmt = $this->db->prepare('UPDATE vip_senders
			SET deleted_at = UTC_TIMESTAMP(3)
			WHERE id = :id AND tenant_id = :t AND user_id = :u');
		$stmt->execute([':id' => $id, ':t' => $tenantId, ':u' => $userId]);
	}

}
