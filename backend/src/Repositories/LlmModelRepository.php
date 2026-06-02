<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use PDO;

/**
 * Phase 9q-C (Marc 2026-05-23) — Pro Provider verfuegbare Modelle/Rollen.
 *
 * Genutzt vom LlmRouter um aus (provider_id, task-role) die konkrete
 * model_id (z.B. "claude-haiku-4-5-20251001", "gpt-4o-mini", "qwen3:32b")
 * aufzuloesen. Damit muss der Caller (MailScoringService) keine Provider-
 * spezifischen Model-IDs mehr kennen — er sagt nur „score" oder „summary".
 */
final class LlmModelRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * Liefert das hoechst-prioritisierte aktive Modell fuer (provider, role).
	 *
	 * @return array<string,mixed>|null
	 */
	public function findForProviderAndRole(string $providerId, string $role): ?array
	{
		$stmt = $this->db->prepare(
			'SELECT * FROM llm_models
			 WHERE provider_id = :p AND role = :r
			   AND enabled = 1 AND deleted_at IS NULL
			 ORDER BY priority ASC, created_at ASC
			 LIMIT 1'
		);
		$stmt->execute([':p' => $providerId, ':r' => $role]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listByProvider(string $providerId, bool $includeDisabled = false): array
	{
		$sql = 'SELECT * FROM llm_models WHERE provider_id = :p AND deleted_at IS NULL';
		if (!$includeDisabled) {
			$sql .= ' AND enabled = 1';
		}
		$sql .= ' ORDER BY role ASC, priority ASC';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':p' => $providerId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Alle aktiven Modelle die eine bestimmte Rolle koennen (cross-Provider).
	 * Genutzt im Admin-UI fuer den Fallback-Chain-Builder pro Role (9q-F).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listByRole(string $role): array
	{
		$stmt = $this->db->prepare(
			'SELECT m.*, p.name AS provider_name, p.kind AS provider_kind, p.is_local
			 FROM llm_models m
			 INNER JOIN llm_providers p ON p.id = m.provider_id
			 WHERE m.role = :r AND m.enabled = 1 AND m.deleted_at IS NULL
			   AND p.enabled = 1 AND p.deleted_at IS NULL
			 ORDER BY p.priority ASC, m.priority ASC'
		);
		$stmt->execute([':r' => $role]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Modell-ID des höchstprioren aktiven Modells einer Rolle (cross-Provider).
	 * Genutzt als Legacy-Fallback-Modell, wenn der Router-Pfad nicht greift.
	 */
	public function primaryModelIdForRole(string $role): ?string
	{
		$rows = $this->listByRole($role);
		return $rows === [] ? null : (string)$rows[0]['model_id'];
	}
}
