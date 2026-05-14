<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Sprint 6d — Move-Korrekturen (PRD-Phase-6 §4).
 *
 * Eine Korrektur ist die Beobachtung „User hat eine Mail anders einsortiert
 * als die KI vorgesehen hatte". Sie wird im pending-Zustand (stabilized_at
 * IS NULL) geschrieben; ein Worker promoviert sie zu „stabilisiert", wenn
 * die Mail nach Quiet-Window (default 60min) nicht weiter verschoben wurde
 * — Schutz vor Indecision-Loops und Hin-und-zurück (DA-Pre-Impl Finding 1).
 *
 * Erst stabilisierte Korrekturen zählen für den 3-in-30d-Schwellwert
 * (PRD §4 Single-Correction-Schutz).
 */
final class AutoSortCorrectionRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * Legt eine neue Korrektur an. Idempotent über (mail_id) — bei
	 * Mehrfach-Detection wird die Zeile aktualisiert, damit ein
	 * Indecision-Move sie nicht doppelt zählt.
	 *
	 * Indecision-Reset ist Absicht (DA-Impl Finding 4): jeder Re-Move
	 * resettet stabilized_at=NULL → das Quiet-Window startet neu. Bei
	 * stark indecisiven Usern entsteht so KEIN Lern-Signal, was korrekt
	 * ist: Rauschen ist kein Signal. Beobachtung im UI später via
	 * COUNT(*) WHERE stabilized_at IS NULL AND created_at < NOW()-30d.
	 */
	public function create(
		string $tenantId,
		string $userId,
		?string $mailId,
		string $originalPath,
		string $correctedPath,
		?string $originalSubLabel,
		?string $suggestedSubLabel,
		?string $userReason = null,
	): string {
		// Bei demselben (user, mail) wird die Zeile aktualisiert, statt
		// einen zweiten Eintrag zu erzeugen — verhindert Indecision-
		// Doppelzählung.
		$existing = null;
		if ($mailId !== null) {
			$stmt = $this->db->prepare('SELECT id FROM auto_sort_corrections
				WHERE tenant_id = :t AND user_id = :u AND mail_id = :m
				  AND deleted_at IS NULL LIMIT 1');
			$stmt->execute([':t' => $tenantId, ':u' => $userId, ':m' => $mailId]);
			$id = $stmt->fetchColumn();
			$existing = $id === false ? null : (string)$id;
		}

		if ($existing !== null) {
			// Update korrigiert die Ziel-Pfade + setzt stabilized_at zurück,
			// weil die Mail erneut bewegt wurde — Quiet-Window startet neu.
			$this->db->prepare('UPDATE auto_sort_corrections
				SET original_folder_path = :op, corrected_folder_path = :cp,
				    original_sub_label = :os, suggested_sub_label = :ss,
				    user_reason = COALESCE(:r, user_reason),
				    stabilized_at = NULL,
				    created_at = UTC_TIMESTAMP(3)
				WHERE id = :id')
				->execute([
					':id' => $existing, ':op' => $originalPath, ':cp' => $correctedPath,
					':os' => $originalSubLabel, ':ss' => $suggestedSubLabel, ':r' => $userReason,
				]);
			return $existing;
		}

		$id = Uuid::v4();
		$this->db->prepare('INSERT INTO auto_sort_corrections
			(id, tenant_id, user_id, mail_id,
			 original_folder_path, corrected_folder_path,
			 original_sub_label, suggested_sub_label, user_reason)
			VALUES (:id, :t, :u, :m, :op, :cp, :os, :ss, :r)')
			->execute([
				':id' => $id, ':t' => $tenantId, ':u' => $userId, ':m' => $mailId,
				':op' => $originalPath, ':cp' => $correctedPath,
				':os' => $originalSubLabel, ':ss' => $suggestedSubLabel, ':r' => $userReason,
			]);
		return $id;
	}

	/**
	 * Promotion-Job (Worker daily): pending Korrekturen, deren letzter
	 * Move länger als $quietMinutes her ist, werden stabilisiert.
	 *
	 * @return int Anzahl promoted
	 */
	public function promoteStable(int $quietMinutes): int
	{
		$stmt = $this->db->prepare('UPDATE auto_sort_corrections
			SET stabilized_at = UTC_TIMESTAMP(3)
			WHERE stabilized_at IS NULL
			  AND deleted_at IS NULL
			  AND created_at < (UTC_TIMESTAMP(3) - INTERVAL :m MINUTE)');
		$stmt->bindValue(':m', max(1, $quietMinutes), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Single-Correction-Schutz (PRD §4): wieviele STABILISIERTE Korrekturen
	 * gibt es im Pair (original_sub_label → suggested_sub_label) in den
	 * letzten N Tagen?
	 */
	public function countSimilarStablePairsLastDays(
		string $tenantId,
		string $userId,
		?string $originalSubLabel,
		?string $suggestedSubLabel,
		int $days,
	): int {
		// COUNT(DISTINCT mail_id) statt COUNT(*) — wenn dieselbe Mail
		// dreimal hin-und-her wandert, ist es trotzdem 1 Korrektur.
		$sql = 'SELECT COUNT(DISTINCT mail_id) FROM auto_sort_corrections
			WHERE tenant_id = :t AND user_id = :u
			  AND stabilized_at IS NOT NULL
			  AND deleted_at IS NULL
			  AND created_at >= (UTC_TIMESTAMP(3) - INTERVAL :d DAY)';
		$params = [':t' => $tenantId, ':u' => $userId, ':d' => max(1, $days)];
		if ($originalSubLabel === null) {
			$sql .= ' AND original_sub_label IS NULL';
		} else {
			$sql .= ' AND original_sub_label = :os';
			$params[':os'] = $originalSubLabel;
		}
		if ($suggestedSubLabel === null) {
			$sql .= ' AND suggested_sub_label IS NULL';
		} else {
			$sql .= ' AND suggested_sub_label = :ss';
			$params[':ss'] = $suggestedSubLabel;
		}
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
		return (int)$stmt->fetchColumn();
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listForUser(string $tenantId, string $userId, int $limit = 100): array
	{
		$stmt = $this->db->prepare('SELECT id, mail_id, original_folder_path, corrected_folder_path,
				original_sub_label, suggested_sub_label, user_reason, stabilized_at, created_at
			FROM auto_sort_corrections
			WHERE tenant_id = :t AND user_id = :u AND deleted_at IS NULL
			ORDER BY created_at DESC LIMIT :lim');
		$stmt->bindValue(':t', $tenantId);
		$stmt->bindValue(':u', $userId);
		$stmt->bindValue(':lim', max(1, min(500, $limit)), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Updates user_reason für eine bestehende Korrektur (Add-in-Banner).
	 */
	public function setUserReason(string $tenantId, string $userId, string $id, string $reason): bool
	{
		$stmt = $this->db->prepare('UPDATE auto_sort_corrections
			SET user_reason = :r WHERE id = :id AND tenant_id = :t AND user_id = :u');
		$stmt->execute([':id' => $id, ':t' => $tenantId, ':u' => $userId, ':r' => substr($reason, 0, 500)]);
		return $stmt->rowCount() > 0;
	}

	/**
	 * Sprint 6e DA-Finding 2: stable Few-Shot-Reihenfolge für Cache.
	 * Nur stabilisierte Korrekturen, ORDER BY stabilized_at ASC →
	 * älteste first, neue hängen hinten an, Segment-Hash bleibt stabil.
	 *
	 * @return list<array{original_sub_label:?string, suggested_sub_label:?string,
	 *                   user_reason:?string, original_folder_path:string,
	 *                   corrected_folder_path:string}>
	 */
	public function forFewShotPrompt(string $tenantId, string $userId, int $limit, int $windowDays = 30): array
	{
		$stmt = $this->db->prepare('SELECT original_sub_label, suggested_sub_label,
				user_reason, original_folder_path, corrected_folder_path
			FROM auto_sort_corrections
			WHERE tenant_id = :t AND user_id = :u
			  AND stabilized_at IS NOT NULL
			  AND deleted_at IS NULL
			  AND created_at >= (UTC_TIMESTAMP(3) - INTERVAL :w DAY)
			ORDER BY stabilized_at ASC, id ASC
			LIMIT :lim');
		$stmt->bindValue(':t', $tenantId);
		$stmt->bindValue(':u', $userId);
		$stmt->bindValue(':w',   max(1, $windowDays), PDO::PARAM_INT);
		$stmt->bindValue(':lim', max(1, $limit),      PDO::PARAM_INT);
		$stmt->execute();
		return array_map(static fn(array $r): array => [
			'original_sub_label'    => $r['original_sub_label']    !== null ? (string)$r['original_sub_label']    : null,
			'suggested_sub_label'   => $r['suggested_sub_label']   !== null ? (string)$r['suggested_sub_label']   : null,
			'user_reason'           => $r['user_reason']           !== null ? (string)$r['user_reason']           : null,
			'original_folder_path'  => (string)$r['original_folder_path'],
			'corrected_folder_path' => (string)$r['corrected_folder_path'],
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * Soft-Delete-Purge älter als $days Tage (PRD §6c: 90d-Retention).
	 *
	 * @return int Anzahl gelöschter Rows
	 */
	public function purgeOlderThan(int $days): int
	{
		$stmt = $this->db->prepare('UPDATE auto_sort_corrections
			SET deleted_at = UTC_TIMESTAMP(3)
			WHERE deleted_at IS NULL
			  AND created_at < (UTC_TIMESTAMP(3) - INTERVAL :d DAY)');
		$stmt->bindValue(':d', max(1, $days), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}
}
