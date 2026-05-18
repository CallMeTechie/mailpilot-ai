<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\ScoreRepository;
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

		$scores = $this->kernel->get(ScoreRepository::class);

		// Last 7 days — covers the realistic "what's in my inbox right now"
		// window. A pure "today UTC" filter looked empty for users whose
		// initial sync brought in mostly older mail.
		$sinceUtc = gmdate('Y-m-d H:i:s.000', time() - 7 * 86400);

		$countersTotal = ['direct' => 0, 'action' => 0, 'cc' => 0, 'newsletter' => 0, 'auto' => 0, 'noise' => 0];
		$top = [];
		foreach ($mailboxes as $mb) {
			$c = $scores->countByLabelSince($ctx['tenant_id'], (string)$mb['id'], $sinceUtc);
			foreach ($c as $k => $v) {
				$countersTotal[$k] = ($countersTotal[$k] ?? 0) + $v;
			}
			$top = array_merge($top, $scores->topPrioritySince($ctx['tenant_id'], (string)$mb['id'], $sinceUtc, 5));
		}

		// Sort merged top-list
		usort($top, static function (array $a, array $b): int {
			return ($b['priority'] <=> $a['priority'])
				?: (strcmp((string)$b['received_at'], (string)$a['received_at']));
		});
		$top = array_slice($top, 0, 10);

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
			'counters'     => $countersTotal,
			'top_priority' => $top,
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
		$threshold = max(0, min(100, $settings->getInt('inbox_pin_threshold', 70)));

		$sql = "SELECT m.id, m.ms_message_id, m.subject, m.from_email, m.from_name, m.received_at,
				s.inbox_score, s.spoof_suspect, s.folder_segments, s.label, s.priority
			FROM mails m
			INNER JOIN mail_scores s ON s.mail_id = m.id AND s.tenant_id = m.tenant_id
			INNER JOIN mailboxes mb ON mb.id = m.mailbox_id
			WHERE m.tenant_id = :t
			  AND mb.user_id = :u
			  AND m.deleted_at IS NULL
			  AND m.user_cleared_at IS NULL
			  AND s.cleared_at IS NULL
			  AND s.auto_sorted_at IS NULL
			  AND s.inbox_score IS NOT NULL
			  AND s.inbox_score >= :thr
			ORDER BY s.inbox_score DESC, m.received_at DESC
			LIMIT 50";
		$stmt = $pdo->prepare($sql);
		$stmt->bindValue(':t', $tenantId);
		$stmt->bindValue(':u', $userId);
		$stmt->bindValue(':thr', $threshold, PDO::PARAM_INT);
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
			$preview = $pathBuilder->build($bucket, $segments);

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
