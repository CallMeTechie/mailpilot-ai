<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use PDO;

/**
 * Stores Claude scoring results keyed by content hash.
 * TTL enforced at read time (cache_ttl_days).
 */
final class CacheRepository
{
	public function __construct(
		private readonly PDO $db,
		private readonly int $ttlDays,
	) {
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get(string $tenantId, string $hash, string $promptVersion): ?array
	{
		$sql = 'SELECT result_json
				FROM claude_cache
				WHERE content_hash = :h
				  AND tenant_id = :t
				  AND prompt_version = :pv
				  AND created_at >= (UTC_TIMESTAMP(3) - INTERVAL :ttl DAY)
				LIMIT 1';
		$stmt = $this->db->prepare($sql);
		$stmt->bindValue(':h', $hash);
		$stmt->bindValue(':t', $tenantId);
		$stmt->bindValue(':pv', $promptVersion);
		$stmt->bindValue(':ttl', $this->ttlDays, PDO::PARAM_INT);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row === false) {
			return null;
		}

		$this->markHit($hash);
		try {
			return json_decode((string)$row['result_json'], true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
	}

	public function put(string $tenantId, string $hash, string $promptVersion, string $model, array $result): void
	{
		$sql = 'INSERT INTO claude_cache (content_hash, tenant_id, result_json, prompt_version, model)
				VALUES (:h, :t, :r, :pv, :m)
				ON DUPLICATE KEY UPDATE
					result_json = VALUES(result_json),
					last_hit_at = UTC_TIMESTAMP(3)';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([
			':h'  => $hash,
			':t'  => $tenantId,
			':r'  => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
			':pv' => $promptVersion,
			':m'  => $model,
		]);
	}

	/**
	 * Removes every cache entry for one tenant. Called when the user
	 * mutates their sub-labels (Sprint 0 fix): without a wipe, the
	 * next score-call returns the old cached row that was computed
	 * before the new sub-label existed, so the new pool never gets
	 * a chance to influence the result.
	 *
	 * Returns the number of rows removed (useful for the API
	 * response so the UI can show a toast).
	 */
	public function purgeForTenant(string $tenantId): int
	{
		$stmt = $this->db->prepare('DELETE FROM claude_cache WHERE tenant_id = :t');
		$stmt->execute([':t' => $tenantId]);
		return $stmt->rowCount();
	}

	private function markHit(string $hash): void
	{
		$stmt = $this->db->prepare('UPDATE claude_cache
			SET hits = hits + 1, last_hit_at = UTC_TIMESTAMP(3)
			WHERE content_hash = :h');
		$stmt->execute([':h' => $hash]);
	}
}
