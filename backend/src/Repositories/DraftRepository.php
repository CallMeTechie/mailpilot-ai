<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

final class DraftRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	public function create(
		string $tenantId,
		string $mailId,
		string $text,
		?string $instruction,
		string $promptVersion,
		string $model,
	): string {
		$id = Uuid::v4();
		$stmt = $this->db->prepare('INSERT INTO reply_drafts
			(id, tenant_id, mail_id, draft_text, user_instruction, prompt_version, model)
			VALUES (:id, :t, :m, :d, :i, :pv, :mo)');
		$stmt->execute([
			':id' => $id,
			':t'  => $tenantId,
			':m'  => $mailId,
			':d'  => $text,
			':i'  => $instruction,
			':pv' => $promptVersion,
			':mo' => $model,
		]);
		return $id;
	}
}
