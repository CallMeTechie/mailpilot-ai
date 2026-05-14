<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * User-driven score corrections. One row per (tenant, mail).
 * Re-correcting overwrites in place. The companion column
 * mail_scores.user_corrected_at locks the score against future
 * Claude overrides.
 */
final class CorrectionRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * Store/update a correction and stamp mail_scores so Claude
	 * never overwrites it. Returns the new correction id.
	 *
	 * @param array{label:string, priority:int, action_required:bool, reasoning:?string} $correction
	 * @param array{label:?string, priority:?int, action_required:?bool} $original
	 */
	public function record(string $tenantId, string $userId, string $mailId, array $correction, array $original): string
	{
		$id = Uuid::v4();
		$this->db->beginTransaction();
		try {
			$stmt = $this->db->prepare('INSERT INTO mail_score_corrections
				(id, tenant_id, user_id, mail_id,
				 original_label, original_priority, original_action,
				 corrected_label, corrected_priority, corrected_action,
				 reasoning)
				VALUES (:id, :t, :u, :m, :ol, :op, :oa, :cl, :cp, :ca, :r)
				ON DUPLICATE KEY UPDATE
					corrected_label    = VALUES(corrected_label),
					corrected_priority = VALUES(corrected_priority),
					corrected_action   = VALUES(corrected_action),
					reasoning          = VALUES(reasoning)');
			$stmt->execute([
				':id' => $id, ':t' => $tenantId, ':u' => $userId, ':m' => $mailId,
				':ol' => $original['label']           ?? null,
				':op' => $original['priority']        ?? null,
				':oa' => $original['action_required'] === null ? null : ($original['action_required'] ? 1 : 0),
				':cl' => $correction['label'],
				':cp' => $correction['priority'],
				':ca' => $correction['action_required'] ? 1 : 0,
				':r'  => $correction['reasoning'] !== null ? substr($correction['reasoning'], 0, 500) : null,
			]);

			$this->db->prepare('UPDATE mail_scores
					SET label           = :l,
						priority        = :p,
						action_required = :a,
						user_corrected_at = UTC_TIMESTAMP(3)
					WHERE mail_id = :m AND tenant_id = :t')
				->execute([
					':l' => $correction['label'],
					':p' => $correction['priority'],
					':a' => $correction['action_required'] ? 1 : 0,
					':m' => $mailId, ':t' => $tenantId,
				]);

			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) $this->db->rollBack();
			throw $e;
		}
		return $id;
	}

	/**
	 * Recent corrections for a user, used as few-shot context for new
	 * scoring runs. Joined onto mails so we can show sender/subject.
	 *
	 * @return list<array{from_email:string, subject:string, original_label:?string,
	 *                   corrected_label:string, corrected_priority:int, reasoning:?string}>
	 */
	public function recentForUser(string $tenantId, string $userId, int $limit = 10): array
	{
		$stmt = $this->db->prepare('SELECT m.from_email, m.subject,
				c.original_label, c.original_priority,
				c.corrected_label, c.corrected_priority, c.corrected_action, c.reasoning
			FROM mail_score_corrections c
			INNER JOIN mails m ON m.id = c.mail_id
			WHERE c.tenant_id = :t AND c.user_id = :u
			ORDER BY c.created_at DESC
			LIMIT :lim');
		$stmt->bindValue(':t', $tenantId);
		$stmt->bindValue(':u', $userId);
		$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		return array_map(static fn(array $r): array => [
			'from_email'         => (string)($r['from_email'] ?? ''),
			'subject'            => (string)($r['subject'] ?? ''),
			'original_label'     => $r['original_label'] !== null ? (string)$r['original_label'] : null,
			'original_priority'  => $r['original_priority'] !== null ? (int)$r['original_priority'] : null,
			'corrected_label'    => (string)$r['corrected_label'],
			'corrected_priority' => (int)$r['corrected_priority'],
			'corrected_action'   => (bool)$r['corrected_action'],
			'reasoning'          => $r['reasoning'] !== null ? (string)$r['reasoning'] : null,
		], $rows);
	}

	/**
	 * Sprint 6e DA-Finding 2: stable Few-Shot-Reihenfolge für Cache-
	 * Segment-Hash. ORDER BY ASC sortiert älteste first → neue Korrekturen
	 * landen am Ende, der Top-Block bleibt stabil → Anthropic-Cache-Read
	 * greift. 30d-Window verhindert, dass uralte Korrekturen ewig bleiben.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function forFewShotPrompt(string $tenantId, string $userId, int $limit, int $windowDays = 30): array
	{
		$stmt = $this->db->prepare('SELECT m.from_email, m.subject,
				c.original_label, c.original_priority,
				c.corrected_label, c.corrected_priority, c.corrected_action, c.reasoning
			FROM mail_score_corrections c
			INNER JOIN mails m ON m.id = c.mail_id
			WHERE c.tenant_id = :t AND c.user_id = :u
			  AND c.created_at >= (UTC_TIMESTAMP(3) - INTERVAL :w DAY)
			ORDER BY c.created_at ASC, c.id ASC
			LIMIT :lim');
		$stmt->bindValue(':t', $tenantId);
		$stmt->bindValue(':u', $userId);
		$stmt->bindValue(':w',   max(1, $windowDays), PDO::PARAM_INT);
		$stmt->bindValue(':lim', max(1, $limit),      PDO::PARAM_INT);
		$stmt->execute();
		return array_map(static fn(array $r): array => [
			'from_email'         => (string)($r['from_email'] ?? ''),
			'subject'            => (string)($r['subject'] ?? ''),
			'original_label'     => $r['original_label'] !== null ? (string)$r['original_label'] : null,
			'original_priority'  => $r['original_priority'] !== null ? (int)$r['original_priority'] : null,
			'corrected_label'    => (string)$r['corrected_label'],
			'corrected_priority' => (int)$r['corrected_priority'],
			'corrected_action'   => (bool)$r['corrected_action'],
			'reasoning'          => $r['reasoning'] !== null ? (string)$r['reasoning'] : null,
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}
}
