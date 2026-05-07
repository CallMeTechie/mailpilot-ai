<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Response;

/**
 * DSGVO endpoints: export (Art. 15) and delete (Art. 17).
 */
final class MeController extends BaseController
{
	public function export(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pdo = $this->kernel->get(\PDO::class);

		$fetch = function (string $sql, array $p) use ($pdo): array {
			$stmt = $pdo->prepare($sql);
			$stmt->execute($p);
			return $stmt->fetchAll(\PDO::FETCH_ASSOC);
		};

		$export = [
			'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'user'         => $fetch('SELECT id, email, display_name, language, timezone, briefing_hour, created_at
				FROM users WHERE id = :id', [':id' => $ctx['user_id']]),
			'mailboxes'    => $fetch('SELECT id, email, display_name, last_sync_at, created_at
				FROM mailboxes WHERE user_id = :u AND deleted_at IS NULL', [':u' => $ctx['user_id']]),
			'vip_senders'  => $fetch('SELECT email, display_name, created_at
				FROM vip_senders WHERE user_id = :u AND deleted_at IS NULL', [':u' => $ctx['user_id']]),
			'keywords'     => $fetch('SELECT keyword, created_at
				FROM project_keywords WHERE user_id = :u AND deleted_at IS NULL', [':u' => $ctx['user_id']]),
			'redaction'    => $fetch('SELECT pattern, description, enabled, created_at
				FROM redaction_rules WHERE user_id = :u AND deleted_at IS NULL', [':u' => $ctx['user_id']]),
			'mail_scores_last_30d' => $fetch('SELECT m.subject, m.from_email, m.received_at,
					s.label, s.action_required, s.priority, s.summary, s.scored_at
				FROM mail_scores s
				INNER JOIN mails m ON m.id = s.mail_id
				WHERE m.tenant_id = :t
				  AND s.scored_at >= (UTC_TIMESTAMP(3) - INTERVAL 30 DAY)
				ORDER BY s.scored_at DESC', [':t' => $ctx['tenant_id']]),
		];

		Response::json($export);
	}

	public function delete(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pdo = $this->kernel->get(\PDO::class);

		$pdo->beginTransaction();
		try {
			// Soft-delete user and all owned records
			$pdo->prepare('UPDATE users       SET deleted_at = UTC_TIMESTAMP(3) WHERE id = :id')
				->execute([':id' => $ctx['user_id']]);
			$pdo->prepare('UPDATE mailboxes   SET deleted_at = UTC_TIMESTAMP(3) WHERE user_id = :u')
				->execute([':u'  => $ctx['user_id']]);
			$pdo->prepare('UPDATE vip_senders SET deleted_at = UTC_TIMESTAMP(3) WHERE user_id = :u')
				->execute([':u'  => $ctx['user_id']]);
			$pdo->prepare('UPDATE project_keywords SET deleted_at = UTC_TIMESTAMP(3) WHERE user_id = :u')
				->execute([':u'  => $ctx['user_id']]);
			$pdo->prepare('UPDATE redaction_rules  SET deleted_at = UTC_TIMESTAMP(3) WHERE user_id = :u')
				->execute([':u'  => $ctx['user_id']]);

			$pdo->prepare('INSERT INTO audit_log (tenant_id, user_id, event, entity, entity_id, meta_json)
				VALUES (:t, :u, "user.delete_request", "user", :id, NULL)')
				->execute([':t' => $ctx['tenant_id'], ':u' => $ctx['user_id'], ':id' => $ctx['user_id']]);

			$pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}

		Response::noContent();
	}
}
