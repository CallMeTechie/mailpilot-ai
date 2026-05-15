<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Sprint 6c — Pending-Action-Queue (PRD-Phase-6 §3, §3.1, §6c).
 *
 * Speichert Vorschläge aus dem suggest-Modus für die drei Toggle-Klassen
 * (move / create_topic / move_to_pending_topic / reply_draft), die der
 * User im Add-in approven oder rejecten kann.
 *
 * Wichtige Invarianten:
 *   - tenant_id + user_id sind PFLICHT (Multi-Tenant-Isolation aus CLAUDE.md §6).
 *   - created_under_mode wird zum Erstellungszeitpunkt eingefroren
 *     (DA-Finding 1). Age-Out und Auto-Approve liest DIESES Feld, nicht
 *     den aktuellen Setting-Wert — sonst stille Aktionen nach Toggle-Wechsel.
 *   - parent_pending_id koppelt move_to_pending_topic an seine create_topic-
 *     parent. ON DELETE CASCADE räumt verwaiste Children mit auf.
 *   - last_error + retry_count tracken Best-Effort-Approval (DA-Finding 2):
 *     fehlgeschlagene Moves nach create_topic-Approval bleiben pending mit
 *     last_error gesetzt.
 */
final class PendingActionRepository
{
	/** @var list<string> */
	public const KINDS    = ['move', 'create_topic', 'move_to_pending_topic', 'reply_draft', 'rule_suggestion'];
	/** @var list<string> */
	public const STATUSES = ['pending', 'approved', 'rejected', 'aged_out'];
	/** @var list<string> */
	public const MODES    = ['off', 'suggest', 'auto'];

	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * Legt eine neue Pending-Action an. Gibt die UUID zurück, damit der
	 * Caller direkt parent_pending_id für gekoppelte Children setzen kann.
	 *
	 * @param array<string,mixed> $payload  Frei-JSON; sollte mail_id/subject/
	 *                                       recipient_summary enthalten,
	 *                                       damit der UI-Tab sinnvoll rendert.
	 */
	public function create(
		string $tenantId,
		string $userId,
		string $kind,
		array $payload,
		string $createdUnderMode,
		?string $parentPendingId = null,
	): string {
		if (!in_array($kind, self::KINDS, true)) {
			throw new \InvalidArgumentException("Unknown kind: {$kind}");
		}
		if (!in_array($createdUnderMode, self::MODES, true)) {
			throw new \InvalidArgumentException("Unknown mode: {$createdUnderMode}");
		}
		$id = Uuid::v4();
		$this->db->prepare('INSERT INTO pending_actions
			(id, tenant_id, user_id, kind, payload, parent_pending_id, created_under_mode)
			VALUES (:id, :t, :u, :k, :p, :pp, :m)')
			->execute([
				':id' => $id, ':t' => $tenantId, ':u' => $userId,
				':k' => $kind, ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE),
				':pp' => $parentPendingId, ':m' => $createdUnderMode,
			]);
		return $id;
	}

	/**
	 * Cursor-paginierte Liste pending Aktionen für den Add-in-Tab
	 * (DA-Finding 4: kein 1000-zeilen-load). Filter by kind ist optional;
	 * wenn null, liefert alle Kinds. Sortierung created_at ASC, damit
	 * älteste zuerst sichtbar werden (treffen Age-Out zuerst).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listPendingForUser(string $tenantId, string $userId, ?string $kind, ?string $afterId, int $limit = 50): array
	{
		$sql = 'SELECT id, kind, payload, parent_pending_id, status, created_under_mode,
				last_error, retry_count, created_at, decided_at
			FROM pending_actions
			WHERE tenant_id = :t AND user_id = :u AND status = "pending"';
		$params = [':t' => $tenantId, ':u' => $userId];
		if ($kind !== null) {
			if (!in_array($kind, self::KINDS, true)) {
				return [];
			}
			$sql .= ' AND kind = :k';
			$params[':k'] = $kind;
		}
		if ($afterId !== null && $afterId !== '') {
			$sql .= ' AND id > :after';
			$params[':after'] = $afterId;
		}
		$sql .= ' ORDER BY id ASC LIMIT :lim';
		$stmt = $this->db->prepare($sql);
		foreach ($params as $k => $v) $stmt->bindValue($k, $v);
		$stmt->bindValue(':lim', max(1, min(200, $limit)), PDO::PARAM_INT);
		$stmt->execute();
		return array_map(static function (array $r): array {
			$payload = $r['payload'] !== null ? json_decode((string)$r['payload'], true) : null;
			return [
				'id'                 => (string)$r['id'],
				'kind'               => (string)$r['kind'],
				'payload'            => is_array($payload) ? $payload : [],
				'parent_pending_id'  => $r['parent_pending_id'] !== null ? (string)$r['parent_pending_id'] : null,
				'status'             => (string)$r['status'],
				'created_under_mode' => (string)$r['created_under_mode'],
				'last_error'         => $r['last_error'] !== null ? (string)$r['last_error'] : null,
				'retry_count'        => (int)$r['retry_count'],
				'created_at'         => (string)$r['created_at'],
				'decided_at'         => $r['decided_at'] !== null ? (string)$r['decided_at'] : null,
			];
		}, $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * Counts pro kind — UI rendert „Verschieben (3) / Topics (1) / Drafts (2)".
	 * Auch Banner-Total wird aus der Summe gebaut.
	 *
	 * @return array{move:int, create_topic:int, move_to_pending_topic:int, reply_draft:int, total:int}
	 */
	public function countByKind(string $tenantId, string $userId): array
	{
		$stmt = $this->db->prepare('SELECT kind, COUNT(*) AS n FROM pending_actions
			WHERE tenant_id = :t AND user_id = :u AND status = "pending"
			GROUP BY kind');
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		$out = ['move' => 0, 'create_topic' => 0, 'move_to_pending_topic' => 0, 'reply_draft' => 0, 'total' => 0];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$out[(string)$r['kind']] = (int)$r['n'];
			$out['total']           += (int)$r['n'];
		}
		return $out;
	}

	/**
	 * Findet eine einzelne Pending-Action — strict-tenant-scoped, sonst
	 * könnte ein User fremde Actions per ID approven.
	 *
	 * @return array<string,mixed>|null
	 */
	public function findById(string $tenantId, string $userId, string $id): ?array
	{
		$stmt = $this->db->prepare('SELECT id, kind, payload, parent_pending_id, status,
				created_under_mode, last_error, retry_count, created_at, decided_at
			FROM pending_actions
			WHERE tenant_id = :t AND user_id = :u AND id = :id LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':id' => $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row === false) return null;
		$payload = $row['payload'] !== null ? json_decode((string)$row['payload'], true) : null;
		$row['payload'] = is_array($payload) ? $payload : [];
		return $row;
	}

	/**
	 * Markiert Action als approved/rejected/aged_out. Caller ist
	 * verantwortlich für die tatsächliche Ausführung (Graph-Move etc.).
	 */
	public function setStatus(string $tenantId, string $userId, string $id, string $status): bool
	{
		if (!in_array($status, self::STATUSES, true)) {
			throw new \InvalidArgumentException("Unknown status: {$status}");
		}
		$stmt = $this->db->prepare('UPDATE pending_actions
			SET status = :s, decided_at = UTC_TIMESTAMP(3)
			WHERE id = :id AND tenant_id = :t AND user_id = :u AND status = "pending"');
		$stmt->execute([':id' => $id, ':t' => $tenantId, ':u' => $userId, ':s' => $status]);
		return $stmt->rowCount() > 0;
	}

	/**
	 * Best-Effort-Approval-Pfad (DA-Finding 2): Topic-Approval führt 50 Moves
	 * aus, 10 davon failed. rememberError schreibt den Fehler-Text +
	 * retry_count++, behält Status auf 'pending', damit der User „erneut
	 * versuchen" klicken kann.
	 */
	public function rememberError(string $tenantId, string $userId, string $id, string $error): void
	{
		$stmt = $this->db->prepare('UPDATE pending_actions
			SET last_error = :err, retry_count = retry_count + 1
			WHERE id = :id AND tenant_id = :t AND user_id = :u');
		$stmt->execute([':id' => $id, ':t' => $tenantId, ':u' => $userId, ':err' => substr($error, 0, 500)]);
	}

	/**
	 * Findet alle move_to_pending_topic-Children einer create_topic-Action.
	 * Wird beim Topic-Approval gebraucht, um den Bulk-Move auszuführen.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function findChildrenOfTopic(string $tenantId, string $userId, string $parentId): array
	{
		$stmt = $this->db->prepare('SELECT id, payload FROM pending_actions
			WHERE tenant_id = :t AND user_id = :u
			  AND parent_pending_id = :p AND kind = "move_to_pending_topic"
			  AND status = "pending"
			ORDER BY id ASC');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':p' => $parentId]);
		return array_map(static function (array $r): array {
			$p = $r['payload'] !== null ? json_decode((string)$r['payload'], true) : null;
			return ['id' => (string)$r['id'], 'payload' => is_array($p) ? $p : []];
		}, $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * Zählt die move_to_pending_topic-Children für eine Liste create_topic-
	 * Parent-IDs. UI nutzt das, um vor Topic-Approval ein Confirm-Modal mit
	 * Mail-Anzahl zu zeigen (PRD §3.1, DA-Impl-Finding 2).
	 *
	 * @param list<string> $parentIds
	 * @return array<string,int>  parent_id → count
	 */
	public function countChildrenForParents(string $tenantId, string $userId, array $parentIds): array
	{
		if ($parentIds === []) return [];
		$placeholders = implode(',', array_fill(0, count($parentIds), '?'));
		$stmt = $this->db->prepare("SELECT parent_pending_id, COUNT(*) AS n
			FROM pending_actions
			WHERE tenant_id = ? AND user_id = ?
			  AND kind = 'move_to_pending_topic' AND status = 'pending'
			  AND parent_pending_id IN ({$placeholders})
			GROUP BY parent_pending_id");
		$stmt->execute(array_merge([$tenantId, $userId], $parentIds));
		$out = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
			$out[(string)$r['parent_pending_id']] = (int)$r['n'];
		}
		return $out;
	}

	/**
	 * Findet die offene create_topic-Action für ein (label, sub_label)-Paar.
	 * Wird vom AutoSortService genutzt, um pending Moves an den passenden
	 * Topic-Vorschlag zu koppeln (PRD §3.1). Erste-Treffer-Suche; bei
	 * Mehrfach-Vorschlägen gewinnt der älteste.
	 */
	public function findPendingTopicId(string $tenantId, string $userId, string $label, string $subLabel): ?string
	{
		// DA-Impl-Finding 4: nutzt die Generated-Columns aus Migration 0019.
		// Composite-Index idx_pending_topic_lookup macht den Lookup O(log n)
		// statt vorheriger linearer Tabellen-Scans.
		$stmt = $this->db->prepare('SELECT id FROM pending_actions
			WHERE tenant_id = :t AND user_id = :u
			  AND kind = "create_topic" AND status = "pending"
			  AND payload_primary   = :l
			  AND payload_sub_label = :s
			ORDER BY created_at ASC LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':l' => $label, ':s' => $subLabel]);
		$id = $stmt->fetchColumn();
		return $id === false ? null : (string)$id;
	}

	/**
	 * Age-Out-Worker (PRD §6c). Setzt pending-Actions älter als
	 * retentionDays auf 'aged_out'. created_under_mode-Auswertung
	 * macht der Caller — wir markieren hier nur.
	 *
	 * @return int Anzahl umgemarkter Rows
	 */
	public function ageOut(int $retentionDays): int
	{
		$stmt = $this->db->prepare('UPDATE pending_actions
			SET status = "aged_out", decided_at = UTC_TIMESTAMP(3)
			WHERE status = "pending"
			  AND created_at < (UTC_TIMESTAMP(3) - INTERVAL :d DAY)');
		$stmt->bindValue(':d', max(1, $retentionDays), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}
}
