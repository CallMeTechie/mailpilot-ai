<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

final class RedactionRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	public function listForUser(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT id, pattern, description, enabled, created_at
			FROM redaction_rules
			WHERE tenant_id = :t AND (user_id = :u OR user_id IS NULL) AND deleted_at IS NULL
			ORDER BY created_at DESC');
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function add(string $tenantId, string $userId, string $pattern, ?string $description): string
	{
		$id = Uuid::v4();
		$stmt = $this->db->prepare('INSERT INTO redaction_rules
			(id, tenant_id, user_id, pattern, description, enabled)
			VALUES (:id, :t, :u, :p, :d, 1)');
		$stmt->execute([
			':id' => $id,
			':t'  => $tenantId,
			':u'  => $userId,
			':p'  => $pattern,
			':d'  => $description,
		]);
		return $id;
	}

	public function softDelete(string $tenantId, string $userId, string $id): void
	{
		$stmt = $this->db->prepare('UPDATE redaction_rules
			SET deleted_at = UTC_TIMESTAMP(3)
			WHERE id = :id AND tenant_id = :t AND (user_id = :u OR user_id IS NULL)');
		$stmt->execute([':id' => $id, ':t' => $tenantId, ':u' => $userId]);
	}

	/**
	 * Used by scoring pipeline to build RedactionService.
	 * @return list<array{pattern:string, description:?string}>
	 */
	public function enabledPatterns(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT pattern, description
			FROM redaction_rules
			WHERE tenant_id = :t AND (user_id = :u OR user_id IS NULL)
			  AND enabled = 1 AND deleted_at IS NULL');
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

}
