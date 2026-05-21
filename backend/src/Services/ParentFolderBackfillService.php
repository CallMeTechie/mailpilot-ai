<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\MailboxRepository;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Phase 9n-Hotfix (Marc 2026-05-21) — Backfill fuer mails.parent_folder_id.
 *
 * Hintergrund: Mails die VOR Phase 9h.4 (Migration ~ 2026-05-20) gescort
 * wurden haben mails.parent_folder_id = NULL. BriefingController kann
 * deshalb nicht entscheiden ob die Mail noch in Inbox liegt oder schon
 * verschoben wurde. Die Pin-Query blendet diese Mails jetzt strikt aus
 * (9n-Hotfix), aber das verliert echte Inbox-Mails.
 *
 * Dieser Service holt periodisch fuer Mails mit NULL parent_folder_id
 * den aktuellen Stand via Graph fetchMessage:
 *   - Mail existiert → parent_folder_id setzen.
 *   - Mail 404 → mails.deleted_at stempeln (User hat die Mail manuell
 *     geloescht; sie ist eh keine Pin-Kandidatin).
 *
 * Priorisierung: Pin-eligible Mails (priority >= inbox_pin_priority_min)
 * werden zuerst verarbeitet, damit das Briefing schnell wieder Mails
 * zeigt. Run-Limit pro Aufruf: 20 Mails (~10 Sek bei avg Graph-Latenz).
 */
final class ParentFolderBackfillService
{
	private const BATCH_SIZE = 20;

	public function __construct(
		private readonly PDO $db,
		private readonly MailboxRepository $mailboxes,
		private readonly TokenService $tokens,
		private readonly GraphClient $graph,
		private readonly LoggerInterface $log,
	) {
	}

	/**
	 * Ein Backfill-Durchlauf. Verarbeitet bis zu BATCH_SIZE Mails.
	 *
	 * @return array{processed:int, updated:int, deleted:int, errors:int}
	 */
	public function tick(): array
	{
		$rows = $this->db->query(
			'SELECT m.id, m.tenant_id, m.mailbox_id, m.ms_message_id
			 FROM mails m
			 LEFT JOIN mail_scores s ON s.mail_id = m.id AND s.tenant_id = m.tenant_id
			 WHERE m.parent_folder_id IS NULL
			   AND m.ms_message_id IS NOT NULL
			   AND m.deleted_at IS NULL
			 ORDER BY (s.priority IS NULL) ASC, s.priority DESC, m.received_at DESC
			 LIMIT ' . self::BATCH_SIZE
		)->fetchAll(PDO::FETCH_ASSOC);

		$stats = ['processed' => 0, 'updated' => 0, 'deleted' => 0, 'errors' => 0];
		if ($rows === []) {
			return $stats;
		}

		$tokensByMailbox = [];
		$updateStmt = $this->db->prepare(
			'UPDATE mails SET parent_folder_id = :pf
			 WHERE id = :id AND tenant_id = :t'
		);
		$deleteStmt = $this->db->prepare(
			'UPDATE mails SET deleted_at = UTC_TIMESTAMP(3)
			 WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL'
		);

		foreach ($rows as $r) {
			$stats['processed']++;
			$mbId = (string)$r['mailbox_id'];
			if (!array_key_exists($mbId, $tokensByMailbox)) {
				$mb = $this->mailboxes->findById((string)$r['tenant_id'], $mbId);
				if ($mb === null) {
					$tokensByMailbox[$mbId] = null;
				} else {
					try {
						$tokensByMailbox[$mbId] = $this->tokens->ensureFreshAccessToken($mb);
					} catch (\Throwable $e) {
						$this->log->info('parent_folder_backfill.token_failed', [
							'mailbox_id' => $mbId, 'err' => $e->getMessage(),
						]);
						$tokensByMailbox[$mbId] = null;
					}
				}
			}
			$token = $tokensByMailbox[$mbId];
			if ($token === null) {
				$stats['errors']++;
				continue;
			}
			try {
				$msg = $this->graph->fetchMessage($token, (string)$r['ms_message_id']);
			} catch (\Throwable $e) {
				if (preg_match('/ErrorItemNotFound|MessageNotFound|\b404\b/i', $e->getMessage())) {
					$deleteStmt->execute([':id' => $r['id'], ':t' => $r['tenant_id']]);
					$stats['deleted']++;
					continue;
				}
				$this->log->info('parent_folder_backfill.fetch_failed', [
					'mail_id' => (string)$r['id'], 'err' => $e->getMessage(),
				]);
				$stats['errors']++;
				continue;
			}
			$parentId = (string)($msg['parentFolderId'] ?? '');
			if ($parentId === '') {
				$stats['errors']++;
				continue;
			}
			$updateStmt->execute([
				':pf' => $parentId,
				':id' => $r['id'],
				':t'  => $r['tenant_id'],
			]);
			$stats['updated']++;
		}

		$this->log->info('parent_folder_backfill.tick', $stats);
		return $stats;
	}
}
