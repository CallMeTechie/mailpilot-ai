<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\AutoSortRepository;
use PDO;

/**
 * Routes a freshly-scored mail into the user-configured Outlook
 * folder, if a matching rule is enabled. Safety net: mails labelled
 * "direct" or "action" with priority >= 4 are NEVER moved even if
 * the rule is on — the user must see them in the inbox.
 *
 * Folder resolution is lazy: on the first hit per (user, label)
 * we ensureFolderPath() against Graph (creates "MailPilot/<Label>"
 * if missing), then cache the id in auto_sort_rules.folder_id so
 * subsequent moves are a single API call.
 *
 * Per-call failures are logged + persisted as last_error on the
 * rule row; they never block the rest of the sync.
 */
final class AutoSortService
{
	public function __construct(
		private readonly GraphClient $graph,
		private readonly AutoSortRepository $rules,
		private readonly PDO $db,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $mail   row from mails (needs ms_message_id)
	 * @param array<string, mixed> $score  row from mail_scores (needs label + priority)
	 *
	 * @return array{moved:bool, reason?:string, folder?:string}
	 */
	public function applyToScoredMail(
		string $accessToken,
		string $tenantId,
		string $userId,
		array $mail,
		array $score,
	): array {
		$label    = (string)($score['label']    ?? '');
		$subLabel = isset($score['sub_label']) && $score['sub_label'] !== null && $score['sub_label'] !== ''
			? (string)$score['sub_label']
			: null;
		$priority = (int)   ($score['priority'] ?? 0);

		if (in_array($label, ['direct', 'action'], true) && $priority >= 4) {
			return ['moved' => false, 'reason' => 'high_priority_protected'];
		}

		$rule = $this->rules->findRule($tenantId, $userId, $label, $subLabel);
		if ($rule === null || !$rule['enabled']) {
			return ['moved' => false, 'reason' => 'rule_disabled'];
		}
		// findRule may have fallen back from the requested $subLabel to
		// the catch-all (sub_label = null). Persist hits against the
		// rule that actually matched so folder_id / last_error end up
		// on the right row.
		$matchedSub = $rule['sub_label'] ?? null;

		$msMessageId = (string)($mail['ms_message_id'] ?? '');
		if ($msMessageId === '') {
			return ['moved' => false, 'reason' => 'missing_message_id'];
		}

		// If we already moved this mail once, skip — avoids hammering
		// Graph with redundant move requests when ensureScored is
		// called on a mail the background sweep handled minutes ago.
		$stmt = $this->db->prepare('SELECT auto_sorted_at FROM mail_scores
			WHERE mail_id = :m AND tenant_id = :t LIMIT 1');
		$stmt->execute([':m' => $mail['id'], ':t' => $tenantId]);
		$existing = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($existing && $existing['auto_sorted_at'] !== null) {
			return ['moved' => false, 'reason' => 'already_sorted'];
		}

		try {
			$folderId = $rule['folder_id'];
			if ($folderId === null || $folderId === '') {
				$folderId = $this->graph->ensureFolderPath($accessToken, $rule['folder_name']);
				$this->rules->rememberFolderId($tenantId, $userId, $label, $matchedSub, $folderId);
			}
			$this->graph->moveToFolder($accessToken, $msMessageId, $folderId);
			// Mark so the background backfill query doesn't try to
			// move it again on the next sweep.
			$this->db->prepare('UPDATE mail_scores SET auto_sorted_at = UTC_TIMESTAMP(3)
				WHERE mail_id = :m AND tenant_id = :t')
				->execute([':m' => $mail['id'], ':t' => $tenantId]);
			$this->logger->info('autosort.moved', [
				'user'      => $userId,
				'label'     => $label,
				'sub_label' => $matchedSub,
				'folder'    => $rule['folder_name'],
			]);
			return ['moved' => true, 'folder' => $rule['folder_name']];
		} catch (\Throwable $e) {
			$this->logger->warning('autosort.failed', [
				'user'      => $userId,
				'label'     => $label,
				'sub_label' => $matchedSub,
				'err'       => $e->getMessage(),
			]);
			$this->rules->rememberError($tenantId, $userId, $label, $matchedSub, $e->getMessage());

			// Stale cached id (folder deleted by user) → drop it so the
			// next round re-resolves.
			if (preg_match('/\b(404|410)\b/', $e->getMessage())) {
				$this->rules->rememberFolderId($tenantId, $userId, $label, $matchedSub, '');
			}

			// Retry-Cap: jeden Fail zählen, bei 3 endgültig skippen
			// (auto_sorted_at = NOW() schließt die Mail aus der
			// applyAutoSortNow-Match-Query aus, identisch zu success).
			// Ohne diesen Cap hat die Frontend-Schleife dieselbe Mail
			// bei jedem Token-Fehler 40+ mal wiederversucht — siehe
			// das "2000 verarbeitet / 300 Fehler"-Symptom.
			$this->db->prepare('UPDATE mail_scores
				SET auto_sort_attempts = auto_sort_attempts + 1
				WHERE mail_id = :m AND tenant_id = :t')
				->execute([':m' => $mail['id'], ':t' => $tenantId]);
			$this->db->prepare('UPDATE mail_scores
				SET auto_sorted_at = UTC_TIMESTAMP(3)
				WHERE mail_id = :m AND tenant_id = :t
				  AND auto_sort_attempts >= 3
				  AND auto_sorted_at IS NULL')
				->execute([':m' => $mail['id'], ':t' => $tenantId]);

			return ['moved' => false, 'reason' => 'graph_error'];
		}
	}

	/**
	 * Back-fill pass: find scored mails that match an enabled rule
	 * but have not been moved yet (auto_sorted_at IS NULL), filtered
	 * by the same direct/action≥4 safety. Returns counters.
	 *
	 * Worker calls this in the background sweep so newly-enabled
	 * rules retroactively touch the existing inbox, no manual
	 * "Regeln jetzt anwenden" click required.
	 *
	 * @return array{processed:int, moved:int, errors:int}
	 */
	public function backfillForMailbox(string $accessToken, string $tenantId, string $userId, string $mailboxId, int $limit = 50): array
	{
		// EXISTS sub-query: pick up any mail whose (label, sub_label)
		// matches an enabled exact rule, OR whose label matches an
		// enabled catch-all rule (sub_label IS NULL). applyToScoredMail
		// resolves the precedence per row.
		$stmt = $this->db->prepare('SELECT m.id, m.ms_message_id, m.mailbox_id,
				s.label AS score_label, s.sub_label AS score_sub_label,
				s.priority AS score_priority, s.action_required AS score_ar
			FROM mails m
			INNER JOIN mail_scores s ON s.mail_id = m.id
			WHERE m.tenant_id    = :t
			  AND m.mailbox_id   = :mb
			  AND m.deleted_at IS NULL
			  AND s.auto_sorted_at IS NULL
			  AND NOT (s.label IN ("direct","action") AND s.priority >= 4)
			  AND EXISTS (
				SELECT 1 FROM auto_sort_rules r
				WHERE r.tenant_id = m.tenant_id
				  AND r.user_id   = :u
				  AND r.label     = s.label
				  AND r.enabled   = 1
				  AND (r.sub_label = s.sub_label OR r.sub_label IS NULL)
			  )
			ORDER BY m.received_at DESC
			LIMIT :lim');
		$stmt->bindValue(':t',  $tenantId);
		$stmt->bindValue(':u',  $userId);
		$stmt->bindValue(':mb', $mailboxId);
		$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$moved = 0; $errors = 0;
		foreach ($rows as $row) {
			$res = $this->applyToScoredMail($accessToken, $tenantId, $userId, $row, [
				'label'           => $row['score_label'],
				'sub_label'       => $row['score_sub_label'],
				'priority'        => $row['score_priority'],
				'action_required' => $row['score_ar'],
			]);
			if (!empty($res['moved']))                          $moved++;
			elseif (($res['reason'] ?? '') === 'graph_error')   $errors++;
		}
		return ['processed' => count($rows), 'moved' => $moved, 'errors' => $errors];
	}
}
