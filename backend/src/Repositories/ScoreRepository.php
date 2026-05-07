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

		$sql = 'INSERT INTO mail_scores
			(id, tenant_id, mail_id, label, action_required, priority, summary, reasoning, prompt_version, model, cached, scored_at)
			VALUES (:id, :tenant_id, :mail_id, :label, :action_required, :priority, :summary, :reasoning, :pv, :model, :cached, UTC_TIMESTAMP(3))
			ON DUPLICATE KEY UPDATE
				label = VALUES(label),
				action_required = VALUES(action_required),
				priority = VALUES(priority),
				summary = VALUES(summary),
				reasoning = VALUES(reasoning),
				prompt_version = VALUES(prompt_version),
				model = VALUES(model),
				cached = VALUES(cached),
				scored_at = VALUES(scored_at)';

		$stmt = $this->db->prepare($sql);
		foreach ($scores as $s) {
			$stmt->execute([
				':id'              => $s['id'],
				':tenant_id'       => $s['tenant_id'],
				':mail_id'         => $s['mail_id'],
				':label'           => $s['label'],
				':action_required' => $s['action_required'],
				':priority'        => $s['priority'],
				':summary'         => $s['summary'],
				':reasoning'       => $s['reasoning'],
				':pv'              => $s['prompt_version'],
				':model'           => $s['model'],
				':cached'          => $s['cached'],
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
		$sql = 'SELECT m.id AS mail_id, m.from_email, m.from_name, m.subject, m.received_at,
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
