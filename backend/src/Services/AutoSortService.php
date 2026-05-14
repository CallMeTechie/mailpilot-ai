<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\SettingsRepository;
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
		private readonly ?SettingsRepository $settings = null,
		// Sprint 6c: PendingActionRepository ist optional — alte Tests
		// rufen ohne; wenn nicht injiziert, falls AutoSortService durch
		// auf den klassischen auto-Pfad zurück (Default-Modus pre-6c).
		private readonly ?PendingActionRepository $pending = null,
	) {
	}

	/**
	 * Liest den autosort_move_mode aus den System-Settings. Default
	 * 'auto' für maximale Backwards-Compat in Tests, wenn weder Settings-
	 * Repo noch der Seed greift.
	 */
	private function moveMode(): string
	{
		if ($this->settings === null) return 'auto';
		$v = $this->settings->getString('autosort_move_mode', 'suggest');
		return in_array($v, ['off', 'suggest', 'auto'], true) ? $v : 'suggest';
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

		// Sprint 6c Modus-Schalter (PRD §3 Toggle 1). 'off' blockiert
		// jeden Move; 'suggest' legt eine pending_action an und überlässt
		// dem User die Entscheidung; 'auto' fällt durch zum bestehenden
		// Pfad. created_under_mode wird eingefroren (DA-Finding 1), damit
		// Toggle-Wechsel suggest→auto keine bestehenden Pending mit-flippt.
		$mode = $this->moveMode();
		if ($mode === 'off') {
			return ['moved' => false, 'reason' => 'mode_off'];
		}
		if ($mode === 'suggest') {
			// DA-Impl-Finding 1: fail-closed. Wenn kein PendingRepo injiziert
			// ist (Test-Bestand vor 6c), würde der Fall-Through silent einen
			// echten Move ausführen statt einer Pending-Anlage. Lieber laut
			// throw — Test muss das Repo übergeben (auch wenn null in
			// auto-Mode-Tests gewünscht ist, dann Mode explizit setzen).
			if ($this->pending === null) {
				throw new \RuntimeException('AutoSortService: suggest-mode benötigt PendingActionRepository');
			}
			// PRD §3.1: wenn die Rule disabled ist UND es eine offene
			// create_topic-pending für (label, sub_label) gibt, koppeln
			// wir den Move via parent_pending_id. Topic-Approval triggert
			// dann den Bulk-Move-Confirm. Bei aktiver Rule ist die Kopplung
			// nicht nötig (User hat schon zugestimmt).
			$parentPid = null;
			$kind = 'move';
			if (!$rule['enabled'] && $matchedSub !== null) {
				$parentPid = $this->pending->findPendingTopicId($tenantId, $userId, $label, $matchedSub);
				if ($parentPid !== null) {
					$kind = 'move_to_pending_topic';
				}
			}
			$pid = $this->pending->create($tenantId, $userId, $kind, [
				'mail_id'       => (string)$mail['id'],
				'ms_message_id' => $msMessageId,
				'subject'       => (string)($mail['subject'] ?? ''),
				'from'          => (string)($mail['from_email'] ?? ''),
				'label'         => $label,
				'sub_label'     => $matchedSub,
				'target_folder' => (string)$rule['folder_name'],
			], 'suggest', $parentPid);
			$this->logger->info('autosort.pending', [
				'user' => $userId, 'label' => $label, 'kind' => $kind,
				'pid' => $pid, 'parent_pid' => $parentPid,
			]);
			return ['moved' => false, 'reason' => 'pending', 'pending_id' => $pid, 'kind' => $kind];
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
			$newMsId = $this->graph->moveToFolder($accessToken, $msMessageId, $folderId);
			// AQMk-IDs ändern sich nach Move (im Gegensatz zu echten
			// Immutable-IDs). Ohne diesen Update lieferte
			// displayMessageFormAsync im Add-in danach ErrorItemNotFound
			// — der „Öffnen"-Button im Heute-Tab wäre für jede gesortete
			// Mail tot.
			if ($newMsId !== null && $newMsId !== $msMessageId) {
				try {
					$this->db->prepare('UPDATE mails
						SET ms_message_id = :new
						WHERE id = :id AND tenant_id = :t')
						->execute([':new' => $newMsId, ':id' => $mail['id'], ':t' => $tenantId]);
				} catch (\PDOException $e) {
					// SQLSTATE 23000 / 1062 = uq_mail-Verstoss: ein paralleler
					// Sync hat die Mail mit der neuen ID bereits als eigene
					// Row geupserted. Die NEUE Row ist korrekt; wir markieren
					// die alte Row als gelöscht, damit nichts doppelt im UI
					// auftaucht. Move in Outlook ist trotzdem schon passiert.
					if ($e->getCode() === '23000') {
						$this->db->prepare('UPDATE mails
							SET deleted_at = UTC_TIMESTAMP(3)
							WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL')
							->execute([':id' => $mail['id'], ':t' => $tenantId]);
						$this->logger->info('autosort.id_refresh_conflict_resolved', [
							'mail_id' => $mail['id'],
							'old_ms_id' => substr($msMessageId, 0, 20) . '...',
							'new_ms_id' => substr($newMsId, 0, 20) . '...',
						]);
					} else {
						throw $e;
					}
				}
			}
			// Mark so the background backfill query doesn't try to
			// move it again on the next sweep. cleared_at (Sprint 6e
			// DA-Finding 1) markiert die Mail als „weg aus der Inbox"
			// für die TodayController-Done-Sektion — egal ob auto-
			// sorted oder später user-moved.
			$this->db->prepare('UPDATE mail_scores
				SET auto_sorted_at = UTC_TIMESTAMP(3),
				    cleared_at = UTC_TIMESTAMP(3)
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
			$msg = $e->getMessage();
			$this->logger->warning('autosort.failed', [
				'user'      => $userId,
				'label'     => $label,
				'sub_label' => $matchedSub,
				'err'       => $msg,
			]);

			// Discriminate 404 by Graph error code (postJson packt das in
			// die Exception-Message — siehe GraphClient::postJson).
			$isItemMissing   = (bool)preg_match('/ErrorItemNotFound|ErrorMessageNotFound|MailboxItemNotFoundException/i', $msg);
			$isFolderMissing = (bool)preg_match('/ErrorFolderNotFound|ErrorParentFolderNotFound|\b410\b/i', $msg);

			// rememberError schreibt in auto_sort_rules.last_error — das ist
			// die Rule-Diagnose im Add-in. Bei ErrorItemNotFound ist die
			// Rule aber nicht das Problem, nur die einzelne Mail-ID war
			// stale → den Rule-Error NICHT anrühren.
			if (!$isItemMissing) {
				$this->rules->rememberError($tenantId, $userId, $label, $matchedSub, $msg);
			}

			if ($isItemMissing) {
				// Mail existiert in Outlook nicht mehr (User hat sie manuell
				// gelöscht/verschoben, REST-ID ist stale). Mail als deleted
				// markieren — fällt aus der Match-Query raus, kein Retry-
				// Loop. Score bleibt als Audit erhalten.
				$this->db->prepare('UPDATE mails SET deleted_at = UTC_TIMESTAMP(3)
					WHERE id = :m AND tenant_id = :t AND deleted_at IS NULL')
					->execute([':m' => $mail['id'], ':t' => $tenantId]);
				$this->db->prepare('UPDATE mail_scores SET auto_sorted_at = UTC_TIMESTAMP(3)
					WHERE mail_id = :m AND tenant_id = :t AND auto_sorted_at IS NULL')
					->execute([':m' => $mail['id'], ':t' => $tenantId]);
				return ['moved' => false, 'reason' => 'mail_gone'];
			}

			if ($isFolderMissing || preg_match('/\b404\b/', $msg)) {
				// Folder weg ODER unspezifischer 404 → folder_id droppen
				// damit der nächste Versuch ensureFolderPath neu läuft.
				$this->rules->rememberFolderId($tenantId, $userId, $label, $matchedSub, '');
			}

			// Retry-Cap (Sprint 0.2): jeden Fail zählen, bei 3 endgültig
			// skippen. Ohne Cap hat die Frontend-Schleife dieselbe Mail
			// 40+ mal wiederversucht — siehe „2000 verarbeitet / 300
			// Fehler"-Symptom.
			// Retry-Cap aus Migration 0014, default 3.
			$retryCap = $this->settings !== null
				? max(1, $this->settings->getInt('autosort.retry_cap', 3))
				: 3;
			$this->db->prepare('UPDATE mail_scores
				SET auto_sort_attempts = auto_sort_attempts + 1
				WHERE mail_id = :m AND tenant_id = :t')
				->execute([':m' => $mail['id'], ':t' => $tenantId]);
			$this->db->prepare('UPDATE mail_scores
				SET auto_sorted_at = UTC_TIMESTAMP(3)
				WHERE mail_id = :m AND tenant_id = :t
				  AND auto_sort_attempts >= :cap
				  AND auto_sorted_at IS NULL')
				->execute([':m' => $mail['id'], ':t' => $tenantId, ':cap' => $retryCap]);

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
