<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\SenderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Services\Sender\FolderPathBuilder;
use MailPilot\Services\Sender\SenderResolver;
use PDO;

final class BriefingController extends BaseController
{
	public function today(array $params, array $body): void
	{
		$ctx = $this->requireAuth();

		$mailboxes = $this->kernel->get(MailboxRepository::class)
			->findByUser($ctx['tenant_id'], $ctx['user_id']);

		if ($mailboxes === []) {
			throw HttpException::preconditionFailed('MAILBOX_NOT_CONNECTED', 'Kein Postfach verbunden');
		}

		// Phase 9n (Marc 2026-05-21): „Top Priorität"-Sektion entfernt.
		// Phase 9p (Marc 2026-05-22): Label-Counters (Direct/Action/CC/
		// Newsletter/Auto/Noise) entfernt. Mit der Sender-zentrierten
		// Folder-Architektur ab 9m zaehlten die Karten Mails die laengst
		// im Sender-Ordner liegen — Ghost-Counts in der Inbox-Ansicht.
		// Die Pin-Liste reicht als To-Do-Indikator; Counts ohne Inbox-
		// Filter sind irrefuehrend.

		// Budget + worker info so the add-in footer can show a live
		// "12k / 100k Tokens" badge and a worker-alive indicator. Both
		// are cheap reads from settings/usage_daily.
		$settings = $this->kernel->get(SettingsRepository::class);
		$usage    = $this->kernel->get(UsageRepository::class);

		$userLimit = $settings->getInt('budget.user.daily_tokens', 0);
		$userUsed  = $usage->outputTokensToday($ctx['tenant_id'], $ctx['user_id']);
		$pct       = $userLimit > 0 ? min(100, (int)round(($userUsed / $userLimit) * 100)) : 0;

		$lastSeen   = $settings->getString('worker.last_seen', '');
		$workerOk   = false;
		if ($lastSeen !== '') {
			$age = time() - strtotime($lastSeen);
			// Schwelle via Admin-Panel editierbar (Migration 0014):
			// worker.heartbeat_threshold_seconds. Default 300s deckt
			// einen Score-Batch mit Anthropic-Latenz ab.
			$threshold = max(60, $settings->getInt('worker.heartbeat_threshold_seconds', 300));
			$workerOk = $age >= 0 && $age < $threshold;
		}

		// Phase 5 (Marc 2026-05-18): Pin-Liste. Mails mit inbox_score ueber
		// Schwelle und ohne User-Done bleiben in der Inbox UND erscheinen
		// hier ganz oben — sortiert nach Score absteigend.
		$pinned = $this->buildPinnedList($ctx['tenant_id'], $ctx['user_id'], $settings);

		Response::json([
			'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'pinned'       => $pinned,
			'budget'       => [
				'user_used'  => $userUsed,
				'user_limit' => $userLimit,
				'percent'    => $pct,
				'enforcement_mode' => $settings->getString('budget.enforcement_mode', 'enforce'),
			],
			'worker'       => [
				'last_seen' => $lastSeen,
				'healthy'   => $workerOk,
			],
		]);
	}

	/**
	 * Phase 5 — Inbox-Pin-Liste fuer das Add-in.
	 *
	 * Liefert Mails wo inbox_score >= Schwelle UND user_cleared_at IS NULL
	 * UND mail_scores.cleared_at IS NULL (nicht schon verschoben). Sortiert
	 * nach inbox_score DESC, dann received_at DESC. Limit 50.
	 *
	 * Pro Mail Path-Preview gerendert via FolderPathBuilder — Add-in zeigt
	 * den Chip „→ Amazon/OTP" am Done-Button.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function buildPinnedList(string $tenantId, string $userId, SettingsRepository $settings): array
	{
		$pdo = $this->kernel->get(PDO::class);
		// Phase 9f (Marc 2026-05-19): Pin-Liste nach priority sortiert, statt
		// nach inbox_score. Schwelle aus dem neuen Setting inbox_pin_priority_min.
		$minPrio = max(1, min(5, $settings->getInt('inbox_pin_priority_min', 4)));

		// Phase 9i (Marc 2026-05-20): Sent-Mails aus der Pinned-Liste raus —
		// gesendete Mails sind kein „to-do", auch wenn ihre Prio hoch ist.
		// Phase 9n (Marc 2026-05-21): Mails die NICHT in der Inbox liegen
		// (User hat sie manuell verschoben) sind kein „to-do" mehr. Filter:
		// parent_folder_id = inbox_folder_id (wenn beide bekannt). Solange
		// inbox_folder_id noch NULL ist (vor Self-Healing), zeigt die Query
		// alle Mails — Backwards-Compat.
		$sql = "SELECT m.id, m.ms_message_id, m.subject, m.from_email, m.from_name, m.received_at,
				s.inbox_score, s.spoof_suspect, s.folder_segments, s.label, s.priority,
				s.summary, s.action_required, s.action_owner
			FROM mails m
			INNER JOIN mail_scores s ON s.mail_id = m.id AND s.tenant_id = m.tenant_id
			INNER JOIN mailboxes mb ON mb.id = m.mailbox_id
			WHERE m.tenant_id = :t
			  AND mb.user_id = :u
			  AND m.deleted_at IS NULL
			  AND m.user_cleared_at IS NULL
			  AND s.cleared_at IS NULL
			  AND s.auto_sorted_at IS NULL
			  AND s.priority IS NOT NULL
			  AND s.priority >= :prio
			  AND (mb.sent_folder_id IS NULL OR m.parent_folder_id IS NULL
			       OR m.parent_folder_id <> mb.sent_folder_id)
			  -- Phase 9n-Hotfix (Marc 2026-05-21): wenn inbox_folder_id
			  -- bekannt ist, MUSS parent_folder_id passen. Mails ohne
			  -- parent_folder_id (Pre-Phase-9h.4 Altdaten) verschwinden
			  -- aus der Pin-Liste — der Worker-Backfill (Phase 9o) holt
			  -- sie nach. Verhindert Geist-Mails im Briefing.
			  AND (mb.inbox_folder_id IS NULL
			       OR (m.parent_folder_id IS NOT NULL AND m.parent_folder_id = mb.inbox_folder_id))
			ORDER BY s.priority DESC, m.received_at DESC
			LIMIT 50";
		$stmt = $pdo->prepare($sql);
		$stmt->bindValue(':t', $tenantId);
		$stmt->bindValue(':u', $userId);
		$stmt->bindValue(':prio', $minPrio, PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$senders     = $this->kernel->get(SenderRepository::class);
		$resolver    = $this->kernel->get(SenderResolver::class);
		$pathBuilder = $this->kernel->get(FolderPathBuilder::class);

		$out = [];
		foreach ($rows as $r) {
			$segments = null;
			if ($r['folder_segments'] !== null) {
				$decoded = json_decode((string)$r['folder_segments'], true);
				if (is_array($decoded)) {
					$segments = array_values(array_map('strval', $decoded));
				}
			}
			// Host → registrable Domain via PSL, dann Bucket-Lookup.
			// Resolve-Pfad wuerde einen neuen Bucket anlegen — wir wollen hier
			// nur LESEN, daher den Lookup-Pfad direkt.
			$host = $this->domainOf((string)$r['from_email']);
			$regDomain = $host !== '' ? $resolver->registrableDomain($host) : null;
			$bucket = $regDomain !== null
				? $senders->findByRegistrableDomain($tenantId, $regDomain)
				: null;
			$preview = $pathBuilder->build((string)($r['label'] ?? ''), $bucket, $segments);

			$out[] = [
				'mail_id'             => (string)$r['id'],
				'ms_message_id'       => (string)($r['ms_message_id'] ?? ''),
				'subject'             => (string)($r['subject'] ?? ''),
				'from_email'          => (string)($r['from_email'] ?? ''),
				'from_name'           => $r['from_name'] !== null ? (string)$r['from_name'] : null,
				'received_at'         => (string)($r['received_at'] ?? ''),
				'inbox_score'         => (int)$r['inbox_score'],
				'spoof_suspect'       => (bool)(int)$r['spoof_suspect'],
				'label'               => (string)($r['label'] ?? 'auto'),
				'priority'            => (int)($r['priority'] ?? 2),
				'sender_display_name' => $bucket['display_name'] ?? null,
				'preview_path'        => $preview,    // null = bleibt in Inbox auch nach Done
				// Phase 9n (Marc 2026-05-21): KI-Empfehlung + Action-Hinweis fuer Briefing-Card.
				'summary'             => $r['summary'] !== null ? (string)$r['summary'] : null,
				'action_required'     => (bool)(int)($r['action_required'] ?? 0),
				'action_owner'        => $r['action_owner'] !== null ? (string)$r['action_owner'] : null,
			];
		}
		return $out;
	}

	/**
	 * Extrahiert den Host aus einer E-Mail-Adresse, oder leeren String.
	 * Wir koennten PSL nutzen, aber findByRegistrableDomain matched via
	 * exakter Schreibweise — die PSL-Aufloesung passiert im SenderResolver,
	 * hier reicht der naive Lookup auf bekannte Buckets.
	 */
	private function domainOf(string $email): string
	{
		$at = strrpos($email, '@');
		return $at === false ? '' : strtolower(substr($email, $at + 1));
	}
}
