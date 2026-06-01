<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Llm\ModelDescriptor;
use MailPilot\Util\Uuid;
use PDO;

/**
 * Zugriff auf llm_model_catalog. Upsert via ON DUPLICATE KEY; markStale setzt
 * available=0 fuer Modelle, die beim letzten erfolgreichen Refresh fehlten.
 */
final class LlmModelCatalogRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	public function upsert(string $providerId, ModelDescriptor $d): void
	{
		$row = $d->toRow();
		$stmt = $this->db->prepare(
			'INSERT INTO llm_model_catalog
				(id, provider_id, model_id, display_name, effort_levels,
				 max_output_tokens, max_context_tokens, released_at, available, last_seen_at)
			 VALUES (:id, :pid, :mid, :dn, :el, :mo, :mc, :ra, 1, CURRENT_TIMESTAMP(3))
			 ON DUPLICATE KEY UPDATE
				display_name = VALUES(display_name),
				effort_levels = VALUES(effort_levels),
				max_output_tokens = VALUES(max_output_tokens),
				max_context_tokens = VALUES(max_context_tokens),
				released_at = VALUES(released_at),
				available = 1,
				last_seen_at = CURRENT_TIMESTAMP(3)'
		);
		$stmt->execute([
			':id'  => Uuid::v4(),
			':pid' => $providerId,
			':mid' => $row['model_id'],
			':dn'  => $row['display_name'],
			':el'  => $row['effort_levels'],
			':mo'  => $row['max_output_tokens'],
			':mc'  => $row['max_context_tokens'],
			':ra'  => self::toMysqlDatetime($row['released_at']),
		]);
	}

	/**
	 * @param list<string> $seenModelIds
	 */
	public function markStale(string $providerId, array $seenModelIds): void
	{
		if ($seenModelIds === []) {
			$this->db->prepare('UPDATE llm_model_catalog SET available = 0 WHERE provider_id = :p')
				->execute([':p' => $providerId]);
			return;
		}
		$ph = implode(',', array_fill(0, count($seenModelIds), '?'));
		$stmt = $this->db->prepare(
			"UPDATE llm_model_catalog SET available = 0
			 WHERE provider_id = ? AND model_id NOT IN ($ph)"
		);
		$stmt->execute(array_merge([$providerId], $seenModelIds));
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listByProvider(string $providerId, bool $availableOnly = false): array
	{
		$sql = 'SELECT * FROM llm_model_catalog WHERE provider_id = :p';
		if ($availableOnly) {
			$sql .= ' AND available = 1';
		}
		$sql .= ' ORDER BY released_at DESC, model_id ASC';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':p' => $providerId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Alle verfuegbaren Modelle gruppiert nach enabled Provider (fuer Dropdowns).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listAvailableGrouped(): array
	{
		$stmt = $this->db->query(
			'SELECT c.*, p.name AS provider_name, p.kind AS provider_kind
			 FROM llm_model_catalog c
			 INNER JOIN llm_providers p ON p.id = c.provider_id
			 WHERE c.available = 1 AND p.enabled = 1 AND p.deleted_at IS NULL
			 ORDER BY p.priority ASC, c.released_at DESC, c.model_id ASC'
		);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find(string $providerId, string $modelId): ?array
	{
		$stmt = $this->db->prepare(
			'SELECT * FROM llm_model_catalog WHERE provider_id = :p AND model_id = :m LIMIT 1'
		);
		$stmt->execute([':p' => $providerId, ':m' => $modelId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * ISO-8601 (z.B. "2026-05-01T00:00:00Z") → MariaDB-DATETIME ("Y-m-d H:i:s").
	 * Ungueltige/leere Werte → null (statt INSERT-Fehler unter STRICT_TRANS_TABLES).
	 */
	private static function toMysqlDatetime(?string $iso): ?string
	{
		if ($iso === null || $iso === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($iso))->format('Y-m-d H:i:s');
		} catch (\Exception) {
			return null;
		}
	}
}
