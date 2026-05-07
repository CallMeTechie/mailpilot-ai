<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use PDO;

final class SummaryRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	public function findByMailId(string $tenantId, string $mailId): ?array
	{
		$stmt = $this->db->prepare('SELECT * FROM mail_summaries
			WHERE mail_id = :m AND tenant_id = :t LIMIT 1');
		$stmt->execute([':m' => $mailId, ':t' => $tenantId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	public function create(string $tenantId, string $mailId, string $text, string $promptVersion, string $model): void
	{
		$stmt = $this->db->prepare('INSERT INTO mail_summaries
			(id, tenant_id, mail_id, summary_text, prompt_version, model)
			VALUES (UUID(), :t, :m, :s, :pv, :mo)
			ON DUPLICATE KEY UPDATE
				summary_text = VALUES(summary_text),
				prompt_version = VALUES(prompt_version),
				model = VALUES(model),
				generated_at = UTC_TIMESTAMP(3)');
		$stmt->execute([
			':t'  => $tenantId,
			':m'  => $mailId,
			':s'  => $text,
			':pv' => $promptVersion,
			':mo' => $model,
		]);
	}
}
