<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Per-user free-form sub labels under one of the six primary labels.
 * Used by the scoring prompt as context ("here are the buckets the
 * user thinks of") and by AutoSort to route into dedicated folders.
 */
final class SubLabelRepository
{
	public const PRIMARIES = ['direct', 'action', 'cc', 'newsletter', 'auto', 'noise'];

	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @return list<array{id:string, parent:string, name:string, description:?string, color:?string, created_by:string, updated_at:string}>
	 */
	public function listForUser(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT id, parent, name, description, color, created_by, updated_at
			FROM user_sublabels
			WHERE tenant_id = :t AND user_id = :u
			ORDER BY parent, name');
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		return array_map(static fn(array $r): array => [
			'id'          => (string)$r['id'],
			'parent'      => (string)$r['parent'],
			'name'        => (string)$r['name'],
			'description' => $r['description'] !== null ? (string)$r['description'] : null,
			'color'       => $r['color']       !== null ? (string)$r['color']       : null,
			'created_by'  => (string)($r['created_by'] ?? 'user'),
			'updated_at'  => (string)$r['updated_at'],
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	public function create(string $tenantId, string $userId, string $parent, string $name, ?string $description, ?string $color, string $createdBy = 'user'): string
	{
		if (!in_array($parent, self::PRIMARIES, true)) {
			throw new \InvalidArgumentException("Unknown primary label: {$parent}");
		}
		if (!in_array($createdBy, ['user', 'ki'], true)) {
			throw new \InvalidArgumentException("Unknown created_by: {$createdBy}");
		}
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('Sub-label name required');
		}
		$id = Uuid::v4();
		$stmt = $this->db->prepare('INSERT INTO user_sublabels
			(id, tenant_id, user_id, parent, name, description, color, created_by)
			VALUES (:id, :t, :u, :p, :n, :d, :c, :cb)
			ON DUPLICATE KEY UPDATE
				description = VALUES(description),
				color       = VALUES(color)');
		$stmt->execute([
			':id' => $id,
			':t'  => $tenantId,
			':u'  => $userId,
			':p'  => $parent,
			':n'  => $name,
			':d'  => $description !== null ? substr($description, 0, 500) : null,
			':c'  => $color,
			':cb' => $createdBy,
		]);
		return $id;
	}

	public function update(string $tenantId, string $userId, string $id, string $name, ?string $description, ?string $color): bool
	{
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('Sub-label name required');
		}
		$stmt = $this->db->prepare('UPDATE user_sublabels
			SET name = :n, description = :d, color = :c
			WHERE id = :id AND tenant_id = :t AND user_id = :u');
		$stmt->execute([
			':id' => $id, ':t' => $tenantId, ':u' => $userId,
			':n'  => $name,
			':d'  => $description !== null ? substr($description, 0, 500) : null,
			':c'  => $color,
		]);
		return $stmt->rowCount() > 0;
	}

	public function delete(string $tenantId, string $userId, string $id): bool
	{
		$stmt = $this->db->prepare('DELETE FROM user_sublabels
			WHERE id = :id AND tenant_id = :t AND user_id = :u');
		$stmt->execute([':id' => $id, ':t' => $tenantId, ':u' => $userId]);
		return $stmt->rowCount() > 0;
	}

	/**
	 * Look a sub-label up by id, scoped to (tenant, user). Used by
	 * the cascade-delete flow in SettingsController which needs the
	 * (parent, name) pair to identify the auto_sort_rules rows that
	 * point at this sub-label.
	 *
	 * @return array{id:string, parent:string, name:string}|null
	 */
	public function findById(string $tenantId, string $userId, string $id): ?array
	{
		$stmt = $this->db->prepare('SELECT id, parent, name
			FROM user_sublabels
			WHERE id = :id AND tenant_id = :t AND user_id = :u
			LIMIT 1');
		$stmt->execute([':id' => $id, ':t' => $tenantId, ':u' => $userId]);
		$r = $stmt->fetch(\PDO::FETCH_ASSOC);
		if ($r === false) {
			return null;
		}
		return [
			'id'     => (string)$r['id'],
			'parent' => (string)$r['parent'],
			'name'   => (string)$r['name'],
		];
	}
}
