<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\SenderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\Sender\FolderPathBuilder;
use MailPilot\Services\Sender\SenderResolver;
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
		/** @phpstan-ignore-next-line property.unused (Phase 9m: findRule-Pfad entfernt, Property bleibt fuer Backwards-Compat im Konstruktor) */
		private readonly AutoSortRepository $rules,
		private readonly PDO $db,
		private readonly \Psr\Log\LoggerInterface $logger,
		private readonly ?SettingsRepository $settings = null,
		// Sprint 6c: PendingActionRepository ist optional — alte Tests
		// rufen ohne; wenn nicht injiziert, falls AutoSortService durch
		// auf den klassischen auto-Pfad zurück (Default-Modus pre-6c).
		private readonly ?PendingActionRepository $pending = null,
		// Phase 7 (Marc 2026-05-19): Sender-zentrischer Move-Pfad. Wenn alle
		// drei gesetzt sind UND $score['folder_segments'] vorhanden → der
		// Move geht in /Sender/Topic statt /MailPilot/<Label>. Optional damit
		// Bestands-Tests + Backfill-Worker ohne diese Deps weiterlaufen.
		private readonly ?SenderResolver $senderResolver = null,
		private readonly ?SenderRepository $senders = null,
		private readonly ?FolderPathBuilder $pathBuilder = null,
		// Phase 9i (Marc 2026-05-20): MailboxRepository fuer Sent-Folder-ID-
		// Cache. Optional, damit aelteste Tests ohne weiter funktionieren.
		private readonly ?\MailPilot\Repositories\MailboxRepository $mailboxes = null,
		// Phase 9m (Marc 2026-05-21): deterministische Inbox-Schutz-Schicht.
		// Optional damit Bestands-Tests ohne Konstruktor-Update laufen.
		private readonly ?InboxProtectionResolver $inboxProtection = null,
	) {
	}

	/**
	 * Phase 9i — Skip-Check fuer Sent-Mails. Vergleicht parent_folder_id
	 * der Mail mit der Sent-Folder-ID der Mailbox (cached in mailboxes.
	 * sent_folder_id). Bei Cache-Miss resolved via Graph + persistiert.
	 *
	 * Rueckgabe: true wenn die Mail in „Gesendete Elemente" liegt und der
	 * Move daher geskippt werden soll.
	 */
	private function isInSentFolder(string $accessToken, array $mail): bool
	{
		if ($this->mailboxes === null) return false;
		$parent = (string)($mail['parent_folder_id'] ?? '');
		if ($parent === '') return false;
		$mbId   = (string)($mail['mailbox_id'] ?? '');
		$tenant = (string)($mail['tenant_id'] ?? '');
		if ($mbId === '' || $tenant === '') return false;
		$mb = $this->mailboxes->findById($tenant, $mbId);
		if ($mb === null) return false;
		$sentId = (string)($mb['sent_folder_id'] ?? '');
		if ($sentId === '') {
			// Self-healing: einmalig via Graph resolven + persistieren.
			try {
				$resolved = $this->graph->resolveWellKnownFolder($accessToken, 'sentitems');
				if ($resolved !== null && $resolved !== '') {
					$this->mailboxes->setSentFolderId($mbId, $resolved);
					$sentId = $resolved;
				}
			} catch (\Throwable $e) {
				$this->logger->info('autosort.sent_resolve_failed', ['err' => $e->getMessage()]);
				return false;
			}
		}
		return $sentId !== '' && $parent === $sentId;
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
		$label          = (string)($score['label'] ?? '');
		$subLabel       = isset($score['sub_label']) && $score['sub_label'] !== null && $score['sub_label'] !== ''
			? (string)$score['sub_label']
			: null;
		$priority       = (int)   ($score['priority']        ?? 0);
		$actionRequired = (bool)  ($score['action_required'] ?? false);
		$actionOwner    = (string)($score['action_owner']    ?? '');
		$inboxScore     = isset($score['inbox_score']) && is_numeric($score['inbox_score'])
			? max(0, min(100, (int)$score['inbox_score']))
			: null;
		$userCleared    = !empty($mail['user_cleared_at']);
		$forceMove      = !empty($score['force_move']);  // vom Done-Endpoint gesetzt

		// Phase 9i (Marc 2026-05-20): Sent-Mails niemals in normale Topic-
		// Folder verschieben. Marc: „Gesendete Emails duerfen nicht in die
		// Normalen Verzeichnisse verschoben werden". Greift auch fuer force_move
		// damit Done/Topic-Korrektur Sent-Mails nicht aus Sent rausholt.
		if ($this->isInSentFolder($accessToken, $mail)) {
			return ['moved' => false, 'reason' => 'sent_folder_protected'];
		}

		// Phase 9m (Marc 2026-05-21): Deterministische Inbox-Schutz-Schicht.
		// Ersetzt die alten priority>=4-Hardcodes. Schutz greift wenn
		// IRGENDEINER der 6 Marker zutrifft (priority, label=direct, action,
		// VIP, TO/CC-Match, Alias-Body-Match).
		if (!$forceMove && !$userCleared && $this->inboxProtection !== null) {
			$prot = $this->inboxProtection->evaluate($tenantId, $userId, $mail, $score);
			if ($prot['protected']) {
				return ['moved' => false, 'reason' => 'inbox_protected', 'protection_reason' => $prot['reason']];
			}
		}

		$msMessageId = (string)($mail['ms_message_id'] ?? '');
		if ($msMessageId === '') {
			return ['moved' => false, 'reason' => 'missing_message_id'];
		}

		$mode = $this->moveMode();
		if ($mode === 'off') {
			return ['moved' => false, 'reason' => 'mode_off'];
		}

		// Phase 9m: label=noise → direkt in Outlook /me/mailFolders/junkemail.
		// Kein eigener Folder, kein Pfad-Builder; nutzt Outlook's natives
		// Spam-Management. Marc-Regel: „Spam in Outlook eigenem Junk-E-Mail
		// Ordner".
		if ($label === 'noise') {
			return $this->moveToJunk($accessToken, $tenantId, $userId, $mail);
		}

		// Phase 9m: alle anderen Labels gehen ueber resolveSenderPath.
		// FolderPathBuilder dispatcht intern:
		//   - newsletter → Newsletter/{display}
		//   - mit folder_segments + Sender-Root → Sender/Topic
		//   - ohne folder_segments aber mit Sender.root_folder_name → Bucket-only
		// Wenn nichts greift, ist senderPath null → Mail bleibt in Inbox.
		// KEIN Legacy-findRule-Fallback mehr (Phase 9m: keine MailPilot/*-Folder).
		$senderPath = $this->resolveSenderPath($tenantId, $mail, $score);
		if ($senderPath === null) {
			return ['moved' => false, 'reason' => 'no_sort_config'];
		}

		if ($mode === 'suggest' && $this->pending !== null) {
			$pid = $this->pending->create($tenantId, $userId, 'move', [
				'mail_id'       => (string)$mail['id'],
				'ms_message_id' => $msMessageId,
				'subject'       => (string)($mail['subject'] ?? ''),
				'from'          => (string)($mail['from_email'] ?? ''),
				'label'         => $label,
				'sub_label'     => $subLabel,
				'target_folder' => $senderPath,
				'source'        => 'sender_path',
			], 'suggest');
			return ['moved' => false, 'reason' => 'pending', 'pending_id' => $pid, 'kind' => 'move'];
		}
		// auto-Mode → direkter Move
		return $this->applyManualMove($accessToken, $tenantId, $userId, $mail, $senderPath);
	}

	/**
	 * Phase 9m (Marc 2026-05-21) — Spam-Mails landen in Outlook's nativem
	 * Junk-E-Mail-Folder, nicht in einem MailPilot-eigenen "Noise"-Ordner.
	 * Folder-ID wird per resolveWellKnownFolder('junkemail') geholt und
	 * (zur Beschleunigung kuenftiger Calls) im Mailbox-Cache abgelegt
	 * wenn die Spalte existiert.
	 *
	 * @param array<string, mixed> $mail
	 * @return array{moved:bool, reason?:string, folder?:string, error?:string}
	 */
	private function moveToJunk(string $accessToken, string $tenantId, string $userId, array $mail): array
	{
		$msMessageId = (string)($mail['ms_message_id'] ?? '');
		if ($msMessageId === '') {
			return ['moved' => false, 'reason' => 'missing_message_id'];
		}
		try {
			$junkId = $this->graph->resolveWellKnownFolder($accessToken, 'junkemail');
			if ($junkId === null || $junkId === '') {
				return ['moved' => false, 'reason' => 'junk_folder_not_resolvable'];
			}
			// Mail ist schon im Junk-Folder → kein Move noetig.
			$currentFolderId = (string)($mail['parent_folder_id'] ?? '');
			if ($currentFolderId !== '' && $currentFolderId === $junkId) {
				$this->db->prepare('UPDATE mail_scores
					SET auto_sorted_at = UTC_TIMESTAMP(3),
					    cleared_at = UTC_TIMESTAMP(3)
					WHERE mail_id = :m AND tenant_id = :t')
					->execute([':m' => $mail['id'], ':t' => $tenantId]);
				return ['moved' => true, 'folder' => 'Junk-E-Mail', 'reason' => 'already_in_junk'];
			}
			$newMsId = $this->graph->moveToFolder($accessToken, $msMessageId, $junkId);
			if ($newMsId !== null && $newMsId !== $msMessageId) {
				try {
					$this->db->prepare('UPDATE mails
						SET ms_message_id = :new
						WHERE id = :id AND tenant_id = :t')
						->execute([':new' => $newMsId, ':id' => $mail['id'], ':t' => $tenantId]);
				} catch (\PDOException $e) {
					if ($e->getCode() === '23000') {
						$this->db->prepare('UPDATE mails
							SET deleted_at = UTC_TIMESTAMP(3)
							WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL')
							->execute([':id' => $mail['id'], ':t' => $tenantId]);
					} else {
						throw $e;
					}
				}
			}
			$this->db->prepare('UPDATE mail_scores
				SET auto_sorted_at = UTC_TIMESTAMP(3),
				    cleared_at = UTC_TIMESTAMP(3)
				WHERE mail_id = :m AND tenant_id = :t')
				->execute([':m' => $mail['id'], ':t' => $tenantId]);
			$this->logger->info('autosort.junk_moved', [
				'user' => $userId, 'mail_id' => (string)$mail['id'],
			]);
			return ['moved' => true, 'folder' => 'Junk-E-Mail'];
		} catch (\Throwable $e) {
			$this->logger->warning('autosort.junk_failed', [
				'user' => $userId, 'mail_id' => (string)$mail['id'],
				'err'  => $e->getMessage(),
			]);
			return ['moved' => false, 'reason' => 'graph_error', 'error' => $e->getMessage()];
		}
	}

	/**
	 * Phase 7 — leitet den Sender-zentrischen Folder-Pfad aus mail + score ab.
	 * Returnt null wenn:
	 *   - die drei Sender-Services nicht injiziert sind (Bestand-Tests),
	 *   - $score['folder_segments'] leer/fehlt (KI hat keinen Vorschlag),
	 *   - from_email fehlt oder PSL keine registrable Domain liefert,
	 *   - kein Sender-Bucket existiert,
	 *   - oder FolderPathBuilder null returnt (z.B. nur sender-segment ohne Topic).
	 */
	private function resolveSenderPath(string $tenantId, array $mail, array $score): ?string
	{
		if ($this->senderResolver === null || $this->senders === null || $this->pathBuilder === null) {
			return null;
		}
		$label = (string)($score['label'] ?? '');
		$from = (string)($mail['from_email'] ?? '');
		if ($from === '') return null;
		$at = strrpos($from, '@');
		if ($at === false) return null;
		$host = strtolower(substr($from, $at + 1));
		$regDomain = $this->senderResolver->registrableDomain($host);
		if ($regDomain === null) return null;
		$bucket = $this->senders->findByRegistrableDomain($tenantId, $regDomain);
		// Phase 9m: Newsletter und Bucket-Only-Mode brauchen kein folder_segments.
		// FolderPathBuilder dispatcht intern.
		$rawSegments = $score['folder_segments'] ?? null;
		$segments = is_array($rawSegments)
			? array_values(array_map('strval', $rawSegments))
			: null;
		return $this->pathBuilder->build($label, $bucket, $segments);
	}

	/**
	 * Phase 4 (Marc 2026-05-18) — manueller Move auf einen explizit
	 * uebergebenen Folder-Pfad. Wird vom Done-Endpoint aufgerufen,
	 * NACHDEM mails.user_cleared_at gesetzt wurde.
	 *
	 * Macht kein Rule-Lookup, kein Pin-Check — der Aufrufer entscheidet
	 * dass jetzt verschoben wird. Setzt auto_sorted_at + cleared_at,
	 * refresht ms_message_id wenn Graph eine neue Immutable-ID liefert.
	 *
	 * @param array<string,mixed> $mail (id, ms_message_id, tenant_id)
	 * @return array{moved:bool, folder?:string, reason?:string}
	 */
	public function applyManualMove(
		string $accessToken,
		string $tenantId,
		string $userId,
		array $mail,
		string $folderPath,
	): array {
		$msMessageId = (string)($mail['ms_message_id'] ?? '');
		if ($msMessageId === '') {
			return ['moved' => false, 'reason' => 'missing_message_id'];
		}
		$folderPath = trim($folderPath);
		if ($folderPath === '') {
			return ['moved' => false, 'reason' => 'empty_folder_path'];
		}

		// Phase 9i (Marc 2026-05-20): Sent-Mails niemals in normale Topic-
		// Folder verschieben. Gilt auch fuer den manuellen Done/Topic-Pfad
		// (User koennte irrtuemlich Sent-Mail korrigieren).
		if ($this->isInSentFolder($accessToken, $mail)) {
			return ['moved' => false, 'reason' => 'sent_folder_protected'];
		}

		try {
			$folderId = $this->graph->ensureFolderPath($accessToken, $folderPath);
			// Phase 9h.1 (Marc 2026-05-20): Skip moveToFolder wenn die Mail
			// schon im Ziel-Folder ist. Spart ~1-2s Graph-Roundtrip pro Korrektur,
			// wo der User den vorgeschlagenen Pfad uebernimmt.
			$currentFolderId = (string)($mail['parent_folder_id'] ?? '');
			if ($currentFolderId !== '' && $currentFolderId === $folderId) {
				$this->db->prepare('UPDATE mail_scores
					SET auto_sorted_at = UTC_TIMESTAMP(3),
					    cleared_at = UTC_TIMESTAMP(3)
					WHERE mail_id = :m AND tenant_id = :t')
					->execute([':m' => $mail['id'], ':t' => $tenantId]);
				return ['moved' => true, 'folder' => $folderPath, 'reason' => 'already_in_target'];
			}
			$newMsId  = $this->graph->moveToFolder($accessToken, $msMessageId, $folderId);

			// ms_message_id-Refresh (AQMk-IDs aendern sich nach Move).
			// Selbe Logik wie im applyToScoredMail-Pfad.
			if ($newMsId !== null && $newMsId !== $msMessageId) {
				try {
					$this->db->prepare('UPDATE mails
						SET ms_message_id = :new
						WHERE id = :id AND tenant_id = :t')
						->execute([':new' => $newMsId, ':id' => $mail['id'], ':t' => $tenantId]);
				} catch (\PDOException $e) {
					if ($e->getCode() === '23000') {
						$this->db->prepare('UPDATE mails
							SET deleted_at = UTC_TIMESTAMP(3)
							WHERE id = :id AND tenant_id = :t AND deleted_at IS NULL')
							->execute([':id' => $mail['id'], ':t' => $tenantId]);
					} else {
						throw $e;
					}
				}
			}

			$this->db->prepare('UPDATE mail_scores
				SET auto_sorted_at = UTC_TIMESTAMP(3),
				    cleared_at = UTC_TIMESTAMP(3)
				WHERE mail_id = :m AND tenant_id = :t')
				->execute([':m' => $mail['id'], ':t' => $tenantId]);

			$this->logger->info('autosort.manual_moved', [
				'user'    => $userId,
				'mail_id' => (string)$mail['id'],
				'folder'  => $folderPath,
			]);
			return ['moved' => true, 'folder' => $folderPath];
		} catch (\Throwable $e) {
			$this->logger->warning('autosort.manual_failed', [
				'user'    => $userId,
				'mail_id' => (string)$mail['id'],
				'folder'  => $folderPath,
				'err'     => $e->getMessage(),
			]);
			return ['moved' => false, 'reason' => 'graph_error', 'error' => $e->getMessage()];
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
