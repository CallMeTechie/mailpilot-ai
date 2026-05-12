<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Per-user auto-sort rules. listForUser() always returns six rows
 * (one per label) — when a row doesn't exist yet it's materialised
 * with sensible defaults so the add-in always sees a complete grid.
 *
 * The default folder name follows the "MailPilot/<Label>" pattern;
 * the user can overwrite it on save. enabled starts at 0 — nothing
 * is moved until the user explicitly opts in.
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
	 * @return list<array{label:string, enabled:bool, folder_name:string, folder_id:?string, last_error:?string, updated_at:string}>
	 */
	public function listForUser(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT label, enabled, folder_name, folder_id, last_error, updated_at
			FROM auto_sort_rules WHERE tenant_id = :t AND user_id = :u');
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		$existing = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$existing[(string)$r['label']] = $r;
		}

		$out = [];
		foreach (self::LABELS as $label) {
			if (isset($existing[$label])) {
				$r = $existing[$label];
				$out[] = [
					'label'       => $label,
					'enabled'     => (bool)$r['enabled'],
					'folder_name' => (string)$r['folder_name'],
					'folder_id'   => $r['folder_id'] !== null ? (string)$r['folder_id'] : null,
					'last_error'  => $r['last_error'] !== null ? (string)$r['last_error'] : null,
					'updated_at'  => (string)$r['updated_at'],
				];
			} else {
				$out[] = [
					'label'       => $label,
					'enabled'     => false,
					'folder_name' => self::DEFAULT_FOLDER[$label],
					'folder_id'   => null,
					'last_error'  => null,
					'updated_at'  => '',
				];
			}
		}
		return $out;
	}

	/**
	 * Returns the rule used by the worker — null if the user never
	 * configured anything for this label (treated as disabled).
	 *
	 * @return array{enabled:bool, folder_name:string, folder_id:?string}|null
	 */
	public function findRule(string $tenantId, string $userId, string $label): ?array
	{
		$stmt = $this->db->prepare('SELECT enabled, folder_name, folder_id FROM auto_sort_rules
			WHERE tenant_id = :t AND user_id = :u AND label = :l LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label]);
		$r = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($r === false) return null;
		return [
			'enabled'     => (bool)$r['enabled'],
			'folder_name' => (string)$r['folder_name'],
			'folder_id'   => $r['folder_id'] !== null ? (string)$r['folder_id'] : null,
		];
	}

	public function upsert(
		string $tenantId,
		string $userId,
		string $label,
		bool $enabled,
		string $folderName,
	): void {
		if (!in_array($label, self::LABELS, true)) {
			throw new \InvalidArgumentException("Unknown label: {$label}");
		}
		$folderName = trim($folderName);
		if ($folderName === '') {
			$folderName = self::DEFAULT_FOLDER[$label];
		}

		$id = Uuid::v4();
		$stmt = $this->db->prepare('INSERT INTO auto_sort_rules
			(id, tenant_id, user_id, label, enabled, folder_name)
			VALUES (:id, :t, :u, :l, :e, :f)
			ON DUPLICATE KEY UPDATE
				enabled     = VALUES(enabled),
				folder_name = VALUES(folder_name)');
		$stmt->execute([
			':id' => $id, ':t' => $tenantId, ':u' => $userId,
			':l'  => $label, ':e' => $enabled ? 1 : 0, ':f' => $folderName,
		]);
	}

	/**
	 * Worker side: persist the resolved Graph folder id so the next
	 * move call doesn't have to look it up / create it again.
	 * Clears last_error on success.
	 */
	public function rememberFolderId(string $tenantId, string $userId, string $label, string $folderId): void
	{
		$stmt = $this->db->prepare('UPDATE auto_sort_rules
			SET folder_id = :fid, last_error = NULL
			WHERE tenant_id = :t AND user_id = :u AND label = :l');
		$stmt->execute([':fid' => $folderId, ':t' => $tenantId, ':u' => $userId, ':l' => $label]);
	}

	public function rememberError(string $tenantId, string $userId, string $label, string $error): void
	{
		$stmt = $this->db->prepare('UPDATE auto_sort_rules
			SET last_error = :err
			WHERE tenant_id = :t AND user_id = :u AND label = :l');
		$stmt->execute([':err' => substr($error, 0, 500), ':t' => $tenantId, ':u' => $userId, ':l' => $label]);
	}
}
