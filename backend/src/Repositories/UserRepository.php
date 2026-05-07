<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

final class UserRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * Creates or finds user + their own tenant (single-tenant-per-user for MVP).
	 *
	 * @return array{0: string, 1: string}  [tenant_id, user_id]
	 */
	public function upsertTenantAndUser(string $email, string $displayName): array
	{
		$this->db->beginTransaction();
		try {
			$stmt = $this->db->prepare('SELECT id FROM users WHERE email = :e AND deleted_at IS NULL LIMIT 1');
			$stmt->execute([':e' => $email]);
			$userRow = $stmt->fetch(PDO::FETCH_ASSOC);

			if ($userRow !== false) {
				$userId = (string)$userRow['id'];

				$stmt = $this->db->prepare('SELECT tenant_id FROM tenant_user WHERE user_id = :u LIMIT 1');
				$stmt->execute([':u' => $userId]);
				$tuRow = $stmt->fetch(PDO::FETCH_ASSOC);
				if ($tuRow !== false) {
					$this->db->commit();
					return [(string)$tuRow['tenant_id'], $userId];
				}
			}

			$userId   = $userRow !== false ? (string)$userRow['id'] : Uuid::v4();
			$tenantId = Uuid::v4();

			if ($userRow === false) {
				$this->db->prepare('INSERT INTO users (id, email, display_name)
					VALUES (:id, :e, :n)')
					->execute([':id' => $userId, ':e' => $email, ':n' => $displayName ?: null]);
			}

			$this->db->prepare('INSERT INTO tenants (id, name, plan)
				VALUES (:id, :n, "free")')
				->execute([':id' => $tenantId, ':n' => $displayName !== '' ? $displayName : $email]);

			$this->db->prepare('INSERT INTO tenant_user (tenant_id, user_id, role)
				VALUES (:t, :u, "owner")')
				->execute([':t' => $tenantId, ':u' => $userId]);

			$this->db->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP(3) WHERE id = :id')
				->execute([':id' => $userId]);

			$this->db->commit();
			return [$tenantId, $userId];
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function updatePreferences(string $userId, ?string $language, ?string $timezone, ?int $briefingHour): void
	{
		$sets = [];
		$p    = [':id' => $userId];
		if ($language !== null)     { $sets[] = 'language = :l';        $p[':l']  = $language; }
		if ($timezone !== null)     { $sets[] = 'timezone = :tz';       $p[':tz'] = $timezone; }
		if ($briefingHour !== null) { $sets[] = 'briefing_hour = :bh';  $p[':bh'] = $briefingHour; }
		if ($sets === []) {
			return;
		}
		$sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
		$this->db->prepare($sql)->execute($p);
	}

	public function replaceKeywords(string $tenantId, string $userId, array $keywords): void
	{
		$this->db->beginTransaction();
		try {
			$this->db->prepare('UPDATE project_keywords SET deleted_at = UTC_TIMESTAMP(3)
				WHERE user_id = :u AND deleted_at IS NULL')
				->execute([':u' => $userId]);

			$stmt = $this->db->prepare('INSERT INTO project_keywords (id, tenant_id, user_id, keyword)
				VALUES (:id, :t, :u, :k)
				ON DUPLICATE KEY UPDATE deleted_at = NULL');
			foreach (array_unique(array_filter(array_map('trim', $keywords))) as $kw) {
				$stmt->execute([
					':id' => Uuid::v4(),
					':t'  => $tenantId,
					':u'  => $userId,
					':k'  => $kw,
				]);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

}
