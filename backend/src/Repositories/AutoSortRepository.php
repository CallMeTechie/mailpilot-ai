<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Per-user auto-sort rules. listForUser() returns the six catch-all
 * rows (one per primary label, sub_label = NULL) — when a row does
 * not exist yet it is materialised on the fly with sensible defaults
 * so the add-in always sees a complete grid. On top of those, every
 * user-defined sub-label rule is appended.
 *
 * Routing precedence (resolved by findRule):
 *   1. exact (label, sub_label) match — "auto + GitHub CI" → that folder
 *   2. catch-all (label, sub_label IS NULL) — "auto + anything else"
 *   3. null  — no rule at all → mail stays in inbox
 */
final class AutoSortRepository
{
	public const LABELS = ['direct', 'action', 'cc', 'newsletter', 'auto', 'noise'];

	private const DEFAULT_FOLDER = [
		'direct'     => 'MailPilot/Direct',
		'action'     => 'MailPilot/Aktion',
		'cc'         => 'MailPilot/CC',
		'newsletter' => 'MailPilot/Newsletter',
		'auto'       => 'MailPilot/Auto',
		'noise'      => 'MailPilot/Noise',
	];

	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * Six catch-all defaults (sub_label = null) plus every explicit
	 * sub-label rule. Catch-alls are emitted even when the user has
	 * not stored a row yet — keeps the UI grid stable.
	 *
	 * @return list<array{label:string, sub_label:?string, enabled:bool, folder_name:string, folder_id:?string, last_error:?string, updated_at:string}>
	 */
	public function listForUser(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT label, sub_label, enabled, folder_name, folder_id, last_error, updated_at
			FROM auto_sort_rules WHERE tenant_id = :t AND user_id = :u');
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);

		$catchAll = [];   // label → row
		$subRules = [];   // raw extra rows
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$label = (string)$r['label'];
			if ($r['sub_label'] === null) {
				$catchAll[$label] = $r;
			} else {
				$subRules[] = $r;
			}
		}

		$out = [];
		foreach (self::LABELS as $label) {
			if (isset($catchAll[$label])) {
				$out[] = $this->hydrate($catchAll[$label]);
			} else {
				$out[] = [
					'label'       => $label,
					'sub_label'   => null,
					'enabled'     => false,
					'folder_name' => self::DEFAULT_FOLDER[$label],
					'folder_id'   => null,
					'last_error'  => null,
					'updated_at'  => '',
				];
			}
		}
		foreach ($subRules as $r) {
			$out[] = $this->hydrate($r);
		}
		return $out;
	}

	/**
	 * Returns the rule the worker should apply for this (label, sub_label).
	 * Tries the exact pair first, falls back to the catch-all
	 * (sub_label IS NULL). Returns null when neither exists.
	 *
	 * @return array{enabled:bool, folder_name:string, folder_id:?string, sub_label:?string}|null
	 */
	public function findRule(string $tenantId, string $userId, string $label, ?string $subLabel): ?array
	{
		// 1) exact match
		if ($subLabel !== null && $subLabel !== '') {
			$stmt = $this->db->prepare('SELECT enabled, folder_name, folder_id, sub_label FROM auto_sort_rules
				WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label = :s LIMIT 1');
			$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label, ':s' => $subLabel]);
			$r = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($r !== false) {
				return [
					'enabled'     => (bool)$r['enabled'],
					'folder_name' => (string)$r['folder_name'],
					'folder_id'   => $r['folder_id'] !== null ? (string)$r['folder_id'] : null,
					'sub_label'   => (string)$r['sub_label'],
				];
			}
		}

		// 2) catch-all (sub_label IS NULL)
		$stmt = $this->db->prepare('SELECT enabled, folder_name, folder_id FROM auto_sort_rules
			WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label IS NULL LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label]);
		$r = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($r === false) {
			return null;
		}
		return [
			'enabled'     => (bool)$r['enabled'],
			'folder_name' => (string)$r['folder_name'],
			'folder_id'   => $r['folder_id'] !== null ? (string)$r['folder_id'] : null,
			'sub_label'   => null,
		];
	}

	public function upsert(
		string $tenantId,
		string $userId,
		string $label,
		?string $subLabel,
		bool $enabled,
		string $folderName,
	): void {
		if (!in_array($label, self::LABELS, true)) {
			throw new \InvalidArgumentException("Unknown label: {$label}");
		}
		$subLabel = $subLabel !== null ? trim($subLabel) : null;
		if ($subLabel === '') {
			$subLabel = null;
		}
		$folderName = trim($folderName);
		if ($folderName === '') {
			$folderName = $subLabel === null
				? self::DEFAULT_FOLDER[$label]
				: self::DEFAULT_FOLDER[$label] . '/' . $subLabel;
		}

		// MariaDB's UNIQUE on (tenant, user, label, sub_label) treats two
		// NULL sub_labels as distinct — that would let us create endless
		// catch-all duplicates. Resolve by hand: look up first, then
		// INSERT or UPDATE deliberately.
		$existingId = $this->findExistingId($tenantId, $userId, $label, $subLabel);
		if ($existingId !== null) {
			$this->db->prepare('UPDATE auto_sort_rules
				SET enabled = :e, folder_name = :f WHERE id = :id')
				->execute([':id' => $existingId, ':e' => $enabled ? 1 : 0, ':f' => $folderName]);
			return;
		}

		$this->db->prepare('INSERT INTO auto_sort_rules
			(id, tenant_id, user_id, label, sub_label, enabled, folder_name)
			VALUES (:id, :t, :u, :l, :s, :e, :f)')
			->execute([
				':id' => Uuid::v4(), ':t' => $tenantId, ':u' => $userId,
				':l'  => $label, ':s' => $subLabel,
				':e'  => $enabled ? 1 : 0, ':f' => $folderName,
			]);
	}

	public function delete(string $tenantId, string $userId, string $label, ?string $subLabel): bool
	{
		if ($subLabel === null) {
			$stmt = $this->db->prepare('DELETE FROM auto_sort_rules
				WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label IS NULL');
			$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label]);
		} else {
			$stmt = $this->db->prepare('DELETE FROM auto_sort_rules
				WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label = :s');
			$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label, ':s' => $subLabel]);
		}
		return $stmt->rowCount() > 0;
	}

	/**
	 * Worker side: persist the resolved Graph folder id so the next
	 * move call doesn't have to look it up / create it again.
	 * Clears last_error on success.
	 */
	public function rememberFolderId(string $tenantId, string $userId, string $label, ?string $subLabel, string $folderId): void
	{
		$existingId = $this->findExistingId($tenantId, $userId, $label, $subLabel);
		if ($existingId === null) {
			return;
		}
		$this->db->prepare('UPDATE auto_sort_rules
			SET folder_id = :fid, last_error = NULL WHERE id = :id')
			->execute([':fid' => $folderId, ':id' => $existingId]);
	}

	public function rememberError(string $tenantId, string $userId, string $label, ?string $subLabel, string $error): void
	{
		$existingId = $this->findExistingId($tenantId, $userId, $label, $subLabel);
		if ($existingId === null) {
			return;
		}
		$this->db->prepare('UPDATE auto_sort_rules
			SET last_error = :err WHERE id = :id')
			->execute([':err' => substr($error, 0, 500), ':id' => $existingId]);
	}

	private function findExistingId(string $tenantId, string $userId, string $label, ?string $subLabel): ?string
	{
		if ($subLabel === null) {
			$stmt = $this->db->prepare('SELECT id FROM auto_sort_rules
				WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label IS NULL LIMIT 1');
			$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label]);
		} else {
			$stmt = $this->db->prepare('SELECT id FROM auto_sort_rules
				WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label = :s LIMIT 1');
			$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label, ':s' => $subLabel]);
		}
		$id = $stmt->fetchColumn();
		return $id === false ? null : (string)$id;
	}

	/**
	 * @param array<string, mixed> $r
	 */
	private function hydrate(array $r): array
	{
		return [
			'label'       => (string)$r['label'],
			'sub_label'   => $r['sub_label'] !== null ? (string)$r['sub_label'] : null,
			'enabled'     => (bool)$r['enabled'],
			'folder_name' => (string)$r['folder_name'],
			'folder_id'   => $r['folder_id'] !== null ? (string)$r['folder_id'] : null,
			'last_error'  => $r['last_error'] !== null ? (string)$r['last_error'] : null,
			'updated_at'  => (string)$r['updated_at'],
		];
	}
}
