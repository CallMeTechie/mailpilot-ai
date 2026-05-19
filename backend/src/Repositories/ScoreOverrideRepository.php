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
			 enabled, source)
			VALUES (:id, :t, :u,
			 :msk, :msr, :mfl, :ml, :mpm,
			 :sp, :sar, :sl, :sfs,
			 :en, :src)');
		$stmt->execute([
			':id'  => $id,
			':t'   => $tenantId,
			':u'   => $userId,
			':msk' => $this->normSenderKey($data['match_sender_key'] ?? null),
			':msr' => $this->validRegex($data['match_subject_regex'] ?? null),
			':mfl' => $this->lowerOrNull($data['match_from_local'] ?? null, 120),
			':ml'  => $this->validLabelOrNull($data['match_label'] ?? null),
			':mpm' => $this->intOrNull($data['match_priority_min'] ?? null, 1, 5),
			':sp'  => $this->intOrNull($data['set_priority']        ?? null, 1, 5),
			':sar' => isset($data['set_action_required']) ? (int)(bool)$data['set_action_required'] : null,
			':sl'  => $this->validLabelOrNull($data['set_label'] ?? null),
			':sfs' => $this->validFolderSegmentsOrNull($data['set_folder_segments'] ?? null),
			':en'  => isset($data['enabled']) ? (int)(bool)$data['enabled'] : 1,
			':src' => (function() use ($data): string {
				$src = (string)($data['source'] ?? 'user_manual');
				return in_array($src, self::SOURCES, true) ? $src : 'user_manual';
			})(),
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
