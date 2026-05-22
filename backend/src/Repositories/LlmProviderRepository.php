<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use PDO;

/**
 * Phase 9q-A (Marc 2026-05-22) — DB-Zugriff fuer llm_providers.
 *
 * api_key_encrypted ist mit libsodium-secretbox verschluesselt — dieses
 * Repository gibt die Encrypted-Form zurueck. Entschluesselung passiert
 * im jeweiligen Provider via SecretBox::decrypt(). Repository sieht den
 * Klartext nie.
 */
final class LlmProviderRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listAll(bool $includeDisabled = false): array
	{
		$sql = 'SELECT * FROM llm_providers WHERE deleted_at IS NULL';
		if (!$includeDisabled) {
			$sql .= ' AND enabled = 1';
		}
		$sql .= ' ORDER BY priority ASC, name ASC';
		return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function findById(string $id): ?array
	{
		$stmt = $this->db->prepare(
			'SELECT * FROM llm_providers WHERE id = :id AND deleted_at IS NULL LIMIT 1'
		);
		$stmt->execute([':id' => $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * Liefert den ersten aktiven Provider mit dem gewuenschten kind.
	 * Phase 9q-B wird das durch eine Liste ersetzen (mehrere Provider
	 * gleicher kind moeglich, z.B. 2 OpenAI-Endpoints mit unterschiedlichen
	 * API-Keys fuer Quota-Splitting).
	 *
	 * @return array<string,mixed>|null
	 */
	public function findByKind(string $kind): ?array
	{
		$stmt = $this->db->prepare(
			'SELECT * FROM llm_providers
			 WHERE kind = :k AND enabled = 1 AND deleted_at IS NULL
			 ORDER BY priority ASC, created_at ASC LIMIT 1'
		);
		$stmt->execute([':k' => $kind]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * Schreibt einen neuen Encrypted-API-Key. Benutzt von 9q-A.5 (env→DB-
	 * Migration) und vom Admin-UI (Phase 9q-F) wenn der User einen Key
	 * eintippt.
	 */
	public function updateEncryptedKey(string $id, string $encryptedToken): void
	{
		$this->db->prepare(
			'UPDATE llm_providers SET api_key_encrypted = :enc WHERE id = :id'
		)->execute([':enc' => $encryptedToken, ':id' => $id]);
	}

	/**
	 * Setzt das health-JSON nach einem erfolgreichen oder fehlgeschlagenen
	 * Call. Felder werden gemerged — partial-PATCH-Semantik:
	 *   - last_ok_at        (ISO-UTC)
	 *   - last_error_at     (ISO-UTC)
	 *   - last_error        (string, kurze Fehler-Message)
	 *   - avg_latency_ms    (EWMA, alpha=0.2)
	 *
	 * @param array<string,mixed> $patch
	 */
	public function markHealth(string $id, array $patch): void
	{
		$existing = $this->findById($id);
		$health = [];
		if ($existing !== null && $existing['health'] !== null) {
			$decoded = json_decode((string)$existing['health'], true);
			if (is_array($decoded)) {
				$health = $decoded;
			}
		}
		$merged = array_merge($health, $patch);
		$this->db->prepare(
			'UPDATE llm_providers SET health = :h WHERE id = :id'
		)->execute([
			':h' => json_encode($merged, JSON_UNESCAPED_UNICODE),
			':id' => $id,
		]);
	}
}
