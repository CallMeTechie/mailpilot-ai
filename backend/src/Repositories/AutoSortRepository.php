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

	// Hardcoded Fallback — die echten Defaults stehen seit Migration 0014
	// in system_settings (folder_default.<label>) und werden vom optional
	// injizierten SettingsRepository gelesen. Settings-Repo bleibt optional,
	// damit Tests und Migrations-Setup ohne DI-Wiring laufen.
	private const FALLBACK_FOLDER = [
		'direct'     => 'MailPilot/Direct',
		'action'     => 'MailPilot/Aktion',
		'cc'         => 'MailPilot/CC',
		'newsletter' => 'MailPilot/Newsletter',
		'auto'       => 'MailPilot/Auto',
		'noise'      => 'MailPilot/Noise',
	];

	public function __construct(
		private readonly PDO $db,
		private readonly ?SettingsRepository $settings = null,
	) {
	}

	private function defaultFolder(string $label): string
	{
		if ($this->settings !== null) {
			$v = $this->settings->getString('folder_default.' . $label, '');
			if ($v !== '') return $v;
		}
		return self::FALLBACK_FOLDER[$label] ?? 'MailPilot';
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
		$stmt = $this->db->prepare('SELECT label, sub_label, enabled, folder_name, folder_id, last_error, created_by, updated_at
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
					'folder_name' => $this->defaultFolder($label),
					'folder_id'   => null,
					'last_error'  => null,
					'created_by'  => 'user',
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
	/**
	 * Sprint 6g (DA-R1 Finding 3) — Fuzzy-Match auf bestehende Sub-Labels
	 * desselben Labels, damit KI-extrahierte Vorschläge wie „CI" / „ci" /
	 * „CI Pipeline" nicht drei separate Regeln + Outlook-Ordner anlegen.
	 *
	 * Schwelle nimmt `topics.fuzzy_merge_levenshtein_max` (default 3) —
	 * dieselbe Mechanik wie Sprint 6b für Topic-Vorschläge
	 * (MailScoringService:975). Reihenfolge: exakter Normalized-Match
	 * gewinnt vor Levenshtein-Match.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findFuzzyMatchSubLabel(string $tenantId, string $userId, string $label, string $candidate): ?array
	{
		$candidateNorm = mb_strtolower(trim($candidate));
		if ($candidateNorm === '') {
			return null;
		}

		$stmt = $this->db->prepare('SELECT label, sub_label, enabled, folder_name, folder_id, last_error, created_by, updated_at
			FROM auto_sort_rules
			WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label IS NOT NULL');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label]);
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// Pass 1 — exakter Normalized-Match.
		foreach ($rows as $row) {
			if (mb_strtolower(trim((string)$row['sub_label'])) === $candidateNorm) {
				return $this->hydrate($row);
			}
		}

		// Pass 2 — Levenshtein. levenshtein() arbeitet auf Bytes,
		// limitiert auf 255 — defensiv abfangen.
		$cap = $this->settings !== null
			? max(0, $this->settings->getInt('topics.fuzzy_merge_levenshtein_max', 3))
			: 3;
		foreach ($rows as $row) {
			$existingNorm = mb_strtolower(trim((string)$row['sub_label']));
			if (strlen($existingNorm) > 255 || strlen($candidateNorm) > 255) {
				continue;
			}
			if (levenshtein($candidateNorm, $existingNorm) <= $cap) {
				return $this->hydrate($row);
			}
		}
		return null;
	}

	public function findRule(string $tenantId, string $userId, string $label, ?string $subLabel): ?array
	{
		// 1) exact match
		if ($subLabel !== null && $subLabel !== '') {
			$stmt = $this->db->prepare('SELECT enabled, folder_name, folder_id, sub_label FROM auto_sort_rules
				WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label = :s LIMIT 1');
			$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label, ':s' => $subLabel]);
			$r = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($r !== false) {
				// Carry DA-Impl 6b-3: NULL → lazy default. AutoSortService
				// nutzt das Ergebnis als Folder-Pfad für ensureFolderPath.
				$fn = $r['folder_name'] !== null && (string)$r['folder_name'] !== ''
					? (string)$r['folder_name']
					: $this->resolveDefaultFolderName($label, (string)$r['sub_label']);
				return [
					'enabled'     => (bool)$r['enabled'],
					'folder_name' => $fn,
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
		$fn = $r['folder_name'] !== null && (string)$r['folder_name'] !== ''
			? (string)$r['folder_name']
			: $this->resolveDefaultFolderName($label, null);
		return [
			'enabled'     => (bool)$r['enabled'],
			'folder_name' => $fn,
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
				? $this->defaultFolder($label)
				: $this->defaultFolder($label) . '/' . $subLabel;
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

	/**
	 * How many rules exist for an exact (label, sub_label) pair?
	 * Used by the cascade-delete flow when a sub-label is removed:
	 * the UI shows the user which AutoSort rules will go with it.
	 */
	public function countBySubLabel(string $tenantId, string $userId, string $label, string $subLabel): int
	{
		$stmt = $this->db->prepare('SELECT COUNT(*) FROM auto_sort_rules
			WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label = :s');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label, ':s' => $subLabel]);
		return (int)$stmt->fetchColumn();
	}

	/**
	 * Same shape, returns the rows themselves so the UI can preview
	 * folder names + enabled flags before confirming the cascade.
	 *
	 * @return list<array{folder_name:string, enabled:bool}>
	 */
	public function listBySubLabel(string $tenantId, string $userId, string $label, string $subLabel): array
	{
		$stmt = $this->db->prepare('SELECT folder_name, enabled FROM auto_sort_rules
			WHERE tenant_id = :t AND user_id = :u AND label = :l AND sub_label = :s
			ORDER BY folder_name');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label, ':s' => $subLabel]);
		return array_map(static fn(array $r): array => [
			'folder_name' => (string)$r['folder_name'],
			'enabled'     => (bool)$r['enabled'],
		], $stmt->fetchAll(\PDO::FETCH_ASSOC));
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
	 *
	 * A non-empty folder_id implies a successful resolve and clears
	 * last_error. Passing an empty string is the "drop the cache,
	 * force re-resolve on next run" signal (used after a stale-folder
	 * 404) — in that case last_error stays put so the UI can still
	 * show why the previous attempt blew up.
	 */
	public function rememberFolderId(string $tenantId, string $userId, string $label, ?string $subLabel, string $folderId): void
	{
		$existingId = $this->findExistingId($tenantId, $userId, $label, $subLabel);
		if ($existingId === null) {
			return;
		}
		if ($folderId === '') {
			$this->db->prepare('UPDATE auto_sort_rules
				SET folder_id = NULL WHERE id = :id')
				->execute([':id' => $existingId]);
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
		$label    = (string)$r['label'];
		$subLabel = $r['sub_label'] !== null ? (string)$r['sub_label'] : null;
		// Carry-Over DA-Impl 6b-3: folder_name NULL → lazy resolve aus
		// aktuellem folder_default.<label>-Setting. So zeigen KI-Vorschläge
		// immer den Pfad, der bei Aktivierung tatsächlich angewendet wird.
		$folderName = $r['folder_name'] !== null && (string)$r['folder_name'] !== ''
			? (string)$r['folder_name']
			: $this->resolveDefaultFolderName($label, $subLabel);
		return [
			'label'       => $label,
			'sub_label'   => $subLabel,
			'enabled'     => (bool)$r['enabled'],
			'folder_name' => $folderName,
			'folder_id'   => $r['folder_id'] !== null ? (string)$r['folder_id'] : null,
			'last_error'  => $r['last_error'] !== null ? (string)$r['last_error'] : null,
			'created_by'  => isset($r['created_by']) ? (string)$r['created_by'] : 'user',
			'updated_at'  => (string)$r['updated_at'],
		];
	}

	/**
	 * Sprint 6b: legt eine KI-vorgeschlagene Rule an, ohne sie zu aktivieren.
	 *
	 * Trigger: MailScoringService discovered ein neues sub_label per
	 * Topic-Discovery (Phase 6b). Damit der User die Idee sieht und mit
	 * einem Klick aktivieren kann, materialisieren wir die Rule als
	 * disabled + created_by='ki'. Erscheint in der AutoSort-Liste mit
	 * KI-Badge im Add-in.
	 *
	 * Idempotent: existiert bereits eine Rule (egal welcher origin), wird
	 * NICHTS gemacht — das schützt vor:
	 *   (a) Re-Discovery durch denselben Score-Batch
	 *   (b) Überschreiben einer bereits user-aktivierten Rule
	 *
	 * @return bool true wenn neue KI-Rule angelegt wurde
	 */
	public function suggestKiRule(
		string $tenantId,
		string $userId,
		string $label,
		string $subLabel,
		string $folderName,
	): bool {
		if (!in_array($label, self::LABELS, true) || $subLabel === '') {
			return false;
		}
		$existing = $this->findExistingId($tenantId, $userId, $label, $subLabel);
		if ($existing !== null) {
			return false;
		}
		// Carry-Over DA-Impl 6b-3: leerer folder_name wird als NULL gespeichert,
		// damit folder_default.<label> beim Read/Aktivieren lazy resolved wird.
		$this->db->prepare('INSERT INTO auto_sort_rules
			(id, tenant_id, user_id, label, sub_label, enabled, folder_name, created_by)
			VALUES (:id, :t, :u, :l, :s, 0, :f, "ki")')
			->execute([
				':id' => Uuid::v4(),
				':t'  => $tenantId,
				':u'  => $userId,
				':l'  => $label,
				':s'  => $subLabel,
				':f'  => $folderName !== '' ? $folderName : null,
			]);
		return true;
	}

	/**
	 * Resolved den aktuellen Default-Pfad für eine (label, sub_label)-Rule.
	 * Wird genutzt wenn `folder_name` NULL ist (KI-Vorschlag mit lazy-resolve).
	 */
	private function resolveDefaultFolderName(string $label, ?string $subLabel): string
	{
		$base = $this->defaultFolder($label);
		return $subLabel !== null && $subLabel !== '' ? $base . '/' . $subLabel : $base;
	}
}
