<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use PDO;

final class ScoreRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @param list<array<string, mixed>> $scores
	 */
	public function upsertMany(array $scores): void
	{
		if ($scores === []) {
			return;
		}

		// Sprint 6e DA-Finding 3: Per-Feld-Sticky via user_corrected_fields.
		// FIND_IN_SET(field, user_corrected_fields) > 0 → User hat genau
		// dieses Feld korrigiert, keep it. Sonst Claude refresh ok.
		// summary/reasoning bleiben immer Claude-refreshbar (kein Sticky).
		// user_corrected_at-Pfad legacy für Score-Korrekturen aus Sprint 3e:
		// wenn user_corrected_fields NULL aber user_corrected_at gesetzt,
		// behandeln wir die klassische sticky-Semantik (alle vier Felder).
		$sql = 'INSERT INTO mail_scores
			(id, tenant_id, mail_id, label, sub_label, action_required,
			 action_owner, action_owner_confidence, action_owner_source,
			 priority, summary, reasoning, prompt_version, model, cached, spoof_suspect, scored_at)
			VALUES (:id, :tenant_id, :mail_id, :label, :sub_label, :action_required,
			 :action_owner, :action_owner_confidence, :action_owner_source,
			 :priority, :summary, :reasoning, :pv, :model, :cached, :spoof_suspect, UTC_TIMESTAMP(3))
			ON DUPLICATE KEY UPDATE
				label                   = IF((user_corrected_fields IS NOT NULL AND FIND_IN_SET("label", user_corrected_fields)) OR (user_corrected_fields IS NULL AND user_corrected_at IS NOT NULL),                label,                   VALUES(label)),
				sub_label               = IF((user_corrected_fields IS NOT NULL AND FIND_IN_SET("sub_label", user_corrected_fields)) OR (user_corrected_fields IS NULL AND user_corrected_at IS NOT NULL),            sub_label,               VALUES(sub_label)),
				action_required         = IF(user_corrected_fields IS NULL AND user_corrected_at IS NOT NULL,                                                                                                          action_required,         VALUES(action_required)),
				action_owner            = IF((user_corrected_fields IS NOT NULL AND FIND_IN_SET("action_owner", user_corrected_fields)),                                                                              action_owner,            VALUES(action_owner)),
				action_owner_confidence = IF((user_corrected_fields IS NOT NULL AND FIND_IN_SET("action_owner", user_corrected_fields)),                                                                              action_owner_confidence, VALUES(action_owner_confidence)),
				action_owner_source     = IF((user_corrected_fields IS NOT NULL AND FIND_IN_SET("action_owner", user_corrected_fields)),                                                                              action_owner_source,     VALUES(action_owner_source)),
				priority                = IF((user_corrected_fields IS NOT NULL AND FIND_IN_SET("priority", user_corrected_fields)) OR (user_corrected_fields IS NULL AND user_corrected_at IS NOT NULL),             priority,                VALUES(priority)),
				summary         = VALUES(summary),
				reasoning       = VALUES(reasoning),
				prompt_version  = VALUES(prompt_version),
				model           = VALUES(model),
				cached          = VALUES(cached),
				spoof_suspect   = VALUES(spoof_suspect),
				scored_at       = VALUES(scored_at)';

		$stmt = $this->db->prepare($sql);
		foreach ($scores as $s) {
			$stmt->execute([
				':id'              => $s['id'],
				':tenant_id'       => $s['tenant_id'],
				':mail_id'         => $s['mail_id'],
				':label'           => $s['label'],
				':sub_label'       => $s['sub_label'] ?? null,
				':action_required' => $s['action_required'],
				':action_owner'            => $s['action_owner']            ?? 'unsure',
				':action_owner_confidence' => $s['action_owner_confidence'] ?? null,
				':action_owner_source'     => $s['action_owner_source']     ?? null,
				':priority'        => $s['priority'],
				':summary'         => $s['summary'],
				':reasoning'       => $s['reasoning'],
				':pv'              => $s['prompt_version'],
				':model'           => $s['model'],
				':cached'          => $s['cached'],
				// Phase 3a: spoof_suspect default 0 fuer Aufrufer die das
				// Feld noch nicht setzen (Cache-Hits ohne LookalikeDetector-Lauf).
				':spoof_suspect'   => isset($s['spoof_suspect']) ? (int)(bool)$s['spoof_suspect'] : 0,
			]);
		}
	}

	/**
	 * @return array<string, int>
	 */
	public function countByLabelSince(string $tenantId, string $mailboxId, string $sinceUtc): array
	{
		$sql = 'SELECT s.label, COUNT(*) AS n
				FROM mail_scores s
				INNER JOIN mails m ON m.id = s.mail_id
				WHERE s.tenant_id = :t
				  AND m.mailbox_id = :mb
				  AND m.received_at >= :since
				  AND m.deleted_at IS NULL
				GROUP BY s.label';
		$stmt = $this->db->prepare($sql);
		$stmt->execute([':t' => $tenantId, ':mb' => $mailboxId, ':since' => $sinceUtc]);

		$out = ['direct' => 0, 'action' => 0, 'cc' => 0, 'newsletter' => 0, 'auto' => 0, 'noise' => 0];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$out[(string)$row['label']] = (int)$row['n'];
		}
		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function topPrioritySince(string $tenantId, string $mailboxId, string $sinceUtc, int $limit = 10): array
	{
		$sql = 'SELECT m.id AS mail_id, m.ms_message_id, m.from_email, m.from_name, m.subject, m.received_at,
					   s.label, s.priority, s.action_required, s.summary
				FROM mail_scores s
				INNER JOIN mails m ON m.id = s.mail_id
				WHERE s.tenant_id = :t
				  AND m.mailbox_id = :mb
				  AND m.received_at >= :since
				  AND s.label IN ("direct","action")
				  AND m.deleted_at IS NULL
				ORDER BY s.priority DESC, m.received_at DESC
				LIMIT :limit';
		$stmt = $this->db->prepare($sql);
		$stmt->bindValue(':t', $tenantId);
		$stmt->bindValue(':mb', $mailboxId);
		$stmt->bindValue(':since', $sinceUtc);
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
}
