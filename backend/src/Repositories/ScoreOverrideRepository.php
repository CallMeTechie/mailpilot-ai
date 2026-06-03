<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Sort-Refactor Phase 9a — Repository fuer score_override_rules.
 *
 * Regel-Validation: mindestens EIN Match-Feld muss gesetzt sein, sonst
 * wuerde die Regel jede Mail uebersteuern. Diese Pruefung lebt hier,
 * nicht in der Migration (DB-CHECK-Constraints sind in MariaDB pre-10.2.1
 * stumm; Validierung im PHP-Layer ist deterministisch).
 */
final class ScoreOverrideRepository
{
	/** @var list<string> */
	public const LABELS = ['direct', 'action', 'cc', 'newsletter', 'auto', 'noise'];
	/** @var list<string> */
	public const SOURCES = ['user_manual', 'ki_inferred'];
	/** @var list<string> */
	private const MATCH_FIELDS = [
		'match_sender_key', 'match_subject_regex', 'match_from_local',
		'match_label', 'match_priority_min',
	];

	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function create(string $tenantId, string $userId, array $data): string
	{
		$this->assertAtLeastOneMatch($data);
		$id = Uuid::v4();
		$stmt = $this->db->prepare('INSERT INTO score_override_rules
			(id, tenant_id, user_id,
			 match_sender_key, match_subject_regex, match_from_local, match_label, match_priority_min,
			 set_priority, set_action_required, set_label, set_folder_segments,
			 enabled, source, origin_correction_id)
			VALUES (:id, :t, :u,
			 :msk, :msr, :mfl, :ml, :mpm,
			 :sp, :sar, :sl, :sfs,
			 :en, :src, :ocid)');
		$stmt->execute([
			':id'   => $id,
			':t'    => $tenantId,
			':u'    => $userId,
			':msk'  => $this->normSenderKey($data['match_sender_key'] ?? null),
			':msr'  => $this->validRegex($data['match_subject_regex'] ?? null),
			':mfl'  => $this->lowerOrNull($data['match_from_local'] ?? null, 120),
			':ml'   => $this->validLabelOrNull($data['match_label'] ?? null),
			':mpm'  => $this->intOrNull($data['match_priority_min'] ?? null, 1, 5),
			':sp'   => $this->intOrNull($data['set_priority']        ?? null, 1, 5),
			':sar'  => isset($data['set_action_required']) ? (int)(bool)$data['set_action_required'] : null,
			':sl'   => $this->validLabelOrNull($data['set_label'] ?? null),
			':sfs'  => $this->validFolderSegmentsOrNull($data['set_folder_segments'] ?? null),
			':en'   => isset($data['enabled']) ? (int)(bool)$data['enabled'] : 1,
			':src'  => (function() use ($data): string {
				$src = (string)($data['source'] ?? 'user_manual');
				return in_array($src, self::SOURCES, true) ? $src : 'user_manual';
			})(),
			':ocid' => $data['origin_correction_id'] ?? null,
		]);
		return $id;
	}

	/**
	 * Liefert alle enabled Regeln eines Users in deterministischer Reihenfolge
	 * (created_at ASC, dann id ASC) — die ERSTE matchende Regel gewinnt im
	 * ScoreOverrideService, das macht das Verhalten reproduzierbar.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listEnabledForMatching(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT id, match_sender_key, match_subject_regex, match_from_local,
				match_label, match_priority_min,
				set_priority, set_action_required, set_label, set_folder_segments
			FROM score_override_rules
			WHERE tenant_id = :t AND user_id = :u AND enabled = 1 AND deleted_at IS NULL
			ORDER BY created_at ASC, id ASC');
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		return array_map(fn(array $r): array => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * UI-Listing inkl. disabled + Audit-Felder.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listForUser(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT id, match_sender_key, match_subject_regex, match_from_local,
				match_label, match_priority_min,
				set_priority, set_action_required, set_label, set_folder_segments,
				enabled, source, applies_count, last_applied_at, created_at, updated_at
			FROM score_override_rules
			WHERE tenant_id = :t AND user_id = :u AND deleted_at IS NULL
			ORDER BY created_at DESC');
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		return array_map(fn(array $r): array => $this->hydrate($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * Loggt einen Apply-Hit: zaehlt applies_count hoch + setzt last_applied_at.
	 * Best-effort; Fehler hier sollen den Score-Pfad nicht killen.
	 */
	public function recordApply(string $tenantId, string $ruleId): void
	{
		try {
			$this->db->prepare('UPDATE score_override_rules
				SET applies_count = applies_count + 1, last_applied_at = UTC_TIMESTAMP(3)
				WHERE id = :id AND tenant_id = :t')
				->execute([':id' => $ruleId, ':t' => $tenantId]);
		} catch (\Throwable) { /* swallow */ }
	}

	/**
	 * Phase 9k (Marc 2026-05-20) — Konflikt-Detection. Zwei Regeln sind
	 * konkurrierend wenn:
	 *   - beide enabled + nicht-deleted
	 *   - gleicher match_sender_key (nicht null)
	 *   - mindestens ein gemeinsam gesetztes Set-Feld mit unterschiedlichem
	 *     Wert (priority, action_required, label, folder_segments)
	 *
	 * Subject-Regex-Ueberlapp ist mathematisch nicht entscheidbar (regex
	 * subset = unsolvable), daher nicht beruecksichtigt — match_sender_key
	 * als pragmatischer Konflikt-Indikator.
	 *
	 * Self-join wo b.id > a.id, damit jedes Paar nur einmal kommt.
	 *
	 * @return list<array{rule_a:array<string,mixed>, rule_b:array<string,mixed>, conflicting_fields:list<string>}>
	 */
	public function findConflicts(string $tenantId, string $userId): array
	{
		$sql = 'SELECT
				a.id AS a_id, a.match_sender_key AS a_sk, a.match_subject_regex AS a_sr,
				a.match_from_local AS a_fl, a.match_label AS a_ml, a.match_priority_min AS a_pm,
				a.set_priority AS a_sp, a.set_action_required AS a_sar, a.set_label AS a_sl,
				a.set_folder_segments AS a_sfs, a.source AS a_src, a.created_at AS a_ca,
				b.id AS b_id, b.match_sender_key AS b_sk, b.match_subject_regex AS b_sr,
				b.match_from_local AS b_fl, b.match_label AS b_ml, b.match_priority_min AS b_pm,
				b.set_priority AS b_sp, b.set_action_required AS b_sar, b.set_label AS b_sl,
				b.set_folder_segments AS b_sfs, b.source AS b_src, b.created_at AS b_ca
			FROM score_override_rules a
			INNER JOIN score_override_rules b
				ON  b.tenant_id = a.tenant_id
				AND b.user_id = a.user_id
				AND b.id > a.id
				AND b.match_sender_key = a.match_sender_key
				AND b.deleted_at IS NULL
				AND b.enabled = 1
			WHERE a.tenant_id = :t AND a.user_id = :u
				AND a.deleted_at IS NULL
				AND a.enabled = 1
				AND a.match_sender_key IS NOT NULL
				AND (
					(a.set_priority IS NOT NULL AND b.set_priority IS NOT NULL AND a.set_priority <> b.set_priority)
					OR (a.set_action_required IS NOT NULL AND b.set_action_required IS NOT NULL AND a.set_action_required <> b.set_action_required)
					OR (a.set_label IS NOT NULL AND b.set_label IS NOT NULL AND a.set_label <> b.set_label)
					OR (a.set_folder_segments IS NOT NULL AND b.set_folder_segments IS NOT NULL AND a.set_folder_segments <> b.set_folder_segments)
				)
			ORDER BY a.created_at ASC, b.created_at ASC';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		$out = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$conflicting = [];
			if ($row['a_sp']  !== null && $row['b_sp']  !== null && $row['a_sp']  !== $row['b_sp'])  $conflicting[] = 'priority';
			if ($row['a_sar'] !== null && $row['b_sar'] !== null && $row['a_sar'] !== $row['b_sar']) $conflicting[] = 'action_required';
			if ($row['a_sl']  !== null && $row['b_sl']  !== null && $row['a_sl']  !== $row['b_sl'])  $conflicting[] = 'label';
			if ($row['a_sfs'] !== null && $row['b_sfs'] !== null && $row['a_sfs'] !== $row['b_sfs']) $conflicting[] = 'folder_segments';
			$out[] = [
				'rule_a'             => $this->rowToConflictRule($row, 'a_'),
				'rule_b'             => $this->rowToConflictRule($row, 'b_'),
				'conflicting_fields' => $conflicting,
			];
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function rowToConflictRule(array $row, string $prefix): array
	{
		$fs = $row[$prefix . 'sfs'] ?? null;
		return [
			'id'                  => (string)$row[$prefix . 'id'],
			'match_sender_key'    => $row[$prefix . 'sk']  !== null ? (string)$row[$prefix . 'sk']  : null,
			'match_subject_regex' => $row[$prefix . 'sr']  !== null ? (string)$row[$prefix . 'sr']  : null,
			'match_from_local'    => $row[$prefix . 'fl']  !== null ? (string)$row[$prefix . 'fl']  : null,
			'match_label'         => $row[$prefix . 'ml']  !== null ? (string)$row[$prefix . 'ml']  : null,
			'match_priority_min'  => $row[$prefix . 'pm']  !== null ? (int)$row[$prefix . 'pm']     : null,
			'set_priority'        => $row[$prefix . 'sp']  !== null ? (int)$row[$prefix . 'sp']     : null,
			'set_action_required' => $row[$prefix . 'sar'] !== null ? (int)(bool)$row[$prefix . 'sar'] : null,
			'set_label'           => $row[$prefix . 'sl']  !== null ? (string)$row[$prefix . 'sl']  : null,
			'set_folder_segments' => $fs !== null ? json_decode((string)$fs, true) : null,
			'source'              => (string)($row[$prefix . 'src'] ?? 'user_manual'),
			'created_at'          => (string)($row[$prefix . 'ca'] ?? ''),
		];
	}

	/**
	 * Holt eine Regel scoped auf (tenant,user) — null wenn nicht vorhanden/fremd.
	 * Wird im Feedback-Pfad zur Ownership-Prüfung eingesetzt, bevor eine Regel
	 * mutiert wird (Task 8 Security-Hardening).
	 *
	 * @return array<string,mixed>|null
	 */
	public function findByIdForUser(string $tenantId, string $userId, string $ruleId): ?array
	{
		$stmt = $this->db->prepare(
			'SELECT * FROM score_override_rules
			 WHERE id = :id AND tenant_id = :t AND user_id = :u AND deleted_at IS NULL LIMIT 1'
		);
		$stmt->execute([':id' => $ruleId, ':t' => $tenantId, ':u' => $userId]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * Findet die EINE user-derived Regel für (sender_key, Set-Feld), falls vorhanden.
	 * @return array<string,mixed>|null
	 */
	public function findUserDerivedSlot(string $tenantId, string $userId, string $senderKey, string $field): ?array
	{
		$col = match ($field) {
			'priority'        => 'set_priority',
			'action_required' => 'set_action_required',
			'label'           => 'set_label',
			'folder_segments' => 'set_folder_segments',
			default           => throw new \InvalidArgumentException("unknown field $field"),
		};
		$stmt = $this->db->prepare(
			"SELECT * FROM score_override_rules
			 WHERE tenant_id = :t AND user_id = :u AND match_sender_key = :sk
			   AND origin_correction_id IS NOT NULL AND $col IS NOT NULL AND deleted_at IS NULL
			 ORDER BY created_at ASC LIMIT 1"
		);
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':sk' => $senderKey]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * Aktualisiert Set-/Match-Felder einer bestehenden Regel in-place.
	 * Jedes Feld wird durch denselben Validator/Normalizer verarbeitet wie in create().
	 * @param array<string,mixed> $fields
	 */
	public function updateFields(string $tenantId, string $ruleId, array $fields): void
	{
		$allowed = ['set_priority', 'set_action_required', 'set_label', 'set_folder_segments',
			'match_subject_regex', 'match_from_local', 'enabled'];
		$sets = [];
		$params = [':id' => $ruleId, ':t' => $tenantId];
		foreach ($fields as $k => $v) {
			if (!in_array($k, $allowed, true)) { continue; }
			$normalized = match ($k) {
				'set_priority'        => $this->intOrNull($v, 1, 5),
				'set_action_required' => $v !== null ? (int)(bool)$v : null,
				'set_label'           => $this->validLabelOrNull($v),
				'set_folder_segments' => $this->validFolderSegmentsOrNull($v),
				'match_subject_regex' => $this->validRegex($v),
				'match_from_local'    => $this->lowerOrNull($v, 120),
				'enabled'             => (int)(bool)$v,
			};
			$sets[] = "`$k` = :$k";
			$params[":$k"] = $normalized;
		}
		if ($sets === []) { return; }
		$this->db->prepare(
			'UPDATE score_override_rules SET ' . implode(', ', $sets)
			. ', updated_at = UTC_TIMESTAMP(3) WHERE id = :id AND tenant_id = :t'
		)->execute($params);
	}

	/** Anzahl aktiver user-derived Regeln (für Soft-Cap). */
	public function countUserDerived(string $tenantId, string $userId): int
	{
		$stmt = $this->db->prepare(
			'SELECT COUNT(*) FROM score_override_rules
			 WHERE tenant_id = :t AND user_id = :u
			   AND origin_correction_id IS NOT NULL AND enabled = 1 AND deleted_at IS NULL'
		);
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		return (int)$stmt->fetchColumn();
	}

	/** Deaktiviert (nicht löscht) die am längsten nicht angewandte user-derived Regel. */
	public function disableLeastRecentlyUsed(string $tenantId, string $userId): ?string
	{
		$stmt = $this->db->prepare(
			'SELECT id FROM score_override_rules
			 WHERE tenant_id = :t AND user_id = :u
			   AND origin_correction_id IS NOT NULL AND enabled = 1 AND deleted_at IS NULL
			 ORDER BY last_applied_at IS NULL DESC, last_applied_at ASC, created_at ASC LIMIT 1'
		);
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		$id = $stmt->fetchColumn();
		if ($id === false) { return null; }
		$this->db->prepare('UPDATE score_override_rules SET enabled = 0, updated_at = UTC_TIMESTAMP(3) WHERE id = :id AND tenant_id = :t AND user_id = :u')
			->execute([':id' => $id, ':t' => $tenantId, ':u' => $userId]);
		return (string)$id;
	}

	public function softDelete(string $tenantId, string $userId, string $id): bool
	{
		$stmt = $this->db->prepare('UPDATE score_override_rules
			SET deleted_at = UTC_TIMESTAMP(3)
			WHERE id = :id AND tenant_id = :t AND user_id = :u AND deleted_at IS NULL');
		$stmt->execute([':id' => $id, ':t' => $tenantId, ':u' => $userId]);
		return $stmt->rowCount() > 0;
	}

	// ----- Helpers --------------------------------------------------------

	/**
	 * @param array<string,mixed> $data
	 */
	private function assertAtLeastOneMatch(array $data): void
	{
		foreach (self::MATCH_FIELDS as $f) {
			if (isset($data[$f]) && $data[$f] !== null && $data[$f] !== '') {
				return;
			}
		}
		throw new \InvalidArgumentException(
			'score_override_rule: mindestens ein match_*-Feld muss gesetzt sein'
		);
	}

	private function normSenderKey(mixed $v): ?string
	{
		if ($v === null || $v === '') return null;
		$s = strtolower(trim((string)$v));
		return $s === '' ? null : substr($s, 0, 64);
	}

	private function lowerOrNull(mixed $v, int $maxLen): ?string
	{
		if ($v === null || $v === '') return null;
		$s = strtolower(trim((string)$v));
		return $s === '' ? null : substr($s, 0, $maxLen);
	}

	private function validLabelOrNull(mixed $v): ?string
	{
		if ($v === null || $v === '') return null;
		$s = strtolower((string)$v);
		return in_array($s, self::LABELS, true) ? $s : null;
	}

	private function intOrNull(mixed $v, int $min, int $max): ?int
	{
		if ($v === null || $v === '') return null;
		if (!is_numeric($v)) return null;
		return max($min, min($max, (int)$v));
	}

	/**
	 * Pattern wird beim Insert validiert: muss als preg_match aufrufbar sein
	 * (sonst preg_match liefert false statt 0/1 und wir wollen das nicht
	 * jedes Mal in apply() merken). Max-Length-Cap 255 entspricht Spalte.
	 */
	/**
	 * @return list<string>|null
	 */
	private function decodeSegments(mixed $v): ?array
	{
		if ($v === null || $v === '') return null;
		$arr = is_string($v) ? json_decode($v, true) : $v;
		if (!is_array($arr) || $arr === []) return null;
		return array_values(array_map('strval', $arr));
	}

	/**
	 * Phase 9e (Marc 2026-05-19) — JSON-Array bis 3 Strings (FolderPathBuilder
	 * MAX_DEPTH), je max 64 Zeichen, ohne Pfad-Separatoren. Akzeptiert sowohl
	 * arrays als auch JSON-codierte Strings (KI-Inferenz liefert oft schon
	 * decoded array, REST-Calls oft als string).
	 */
	private function validFolderSegmentsOrNull(mixed $v): ?string
	{
		if ($v === null || $v === '' || $v === []) return null;
		$arr = is_string($v) ? json_decode($v, true) : $v;
		if (!is_array($arr) || $arr === []) {
			throw new \InvalidArgumentException('set_folder_segments muss ein JSON-Array sein');
		}
		if (count($arr) > 3) {
			throw new \InvalidArgumentException('set_folder_segments: max 3 Segmente erlaubt');
		}
		$clean = [];
		foreach ($arr as $seg) {
			if (!is_string($seg) || $seg === '') {
				throw new \InvalidArgumentException('set_folder_segments: jedes Segment muss ein nicht-leerer String sein');
			}
			$s = trim($seg);
			if (mb_strlen($s) > 64) {
				throw new \InvalidArgumentException('set_folder_segments: Segment max 64 Zeichen');
			}
			if (str_contains($s, '/') || str_contains($s, '\\')) {
				throw new \InvalidArgumentException('set_folder_segments: kein "/" oder "\\" im Segment-Namen');
			}
			$clean[] = $s;
		}
		return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	}

	private function validRegex(mixed $v): ?string
	{
		if ($v === null || $v === '') return null;
		$p = (string)$v;
		if (strlen($p) > 255) {
			throw new \InvalidArgumentException('match_subject_regex max 255 Zeichen');
		}
		// Test-Match ohne den User-Subject — wirft ErrorException wenn ungueltig.
		set_error_handler(static fn(int $no, string $msg) => throw new \InvalidArgumentException(
			"match_subject_regex ungueltig: {$msg}"
		));
		try {
			if (@preg_match($p, '') === false) {
				throw new \InvalidArgumentException('match_subject_regex: preg_match returned false');
			}
		} finally {
			restore_error_handler();
		}
		return $p;
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private function hydrate(array $r): array
	{
		return [
			'id'                   => (string)$r['id'],
			'match_sender_key'     => $r['match_sender_key']    !== null ? (string)$r['match_sender_key']    : null,
			'match_subject_regex'  => $r['match_subject_regex'] !== null ? (string)$r['match_subject_regex'] : null,
			'match_from_local'     => $r['match_from_local']    !== null ? (string)$r['match_from_local']    : null,
			'match_label'          => $r['match_label']         !== null ? (string)$r['match_label']         : null,
			'match_priority_min'   => $r['match_priority_min']  !== null ? (int)$r['match_priority_min']     : null,
			'set_priority'         => $r['set_priority']        !== null ? (int)$r['set_priority']           : null,
			'set_action_required'  => $r['set_action_required'] !== null ? (int)(bool)$r['set_action_required'] : null,
			'set_label'            => $r['set_label']           !== null ? (string)$r['set_label']           : null,
			'set_folder_segments'  => $this->decodeSegments($r['set_folder_segments'] ?? null),
			'enabled'              => isset($r['enabled'])      ? (bool)(int)$r['enabled']                   : true,
			'source'               => $r['source']              ?? 'user_manual',
			'applies_count'        => isset($r['applies_count'])    ? (int)$r['applies_count']               : 0,
			'last_applied_at'      => isset($r['last_applied_at']) && $r['last_applied_at'] !== null
				? (string)$r['last_applied_at'] : null,
			'created_at'           => (string)($r['created_at'] ?? ''),
			'updated_at'           => (string)($r['updated_at'] ?? ''),
		];
	}
}
