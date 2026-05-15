<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\DraftRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageCounterRepository;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Sprint 6f — Auto-Reply-Drafts Hintergrund-Job.
 *
 * Wird vom Worker alle 5 Min angestoßen. Sucht Mails mit hoher Priorität
 * für die der User antworten muss, prüft eine Reihe von Skip-Gates
 * (DA-Runden 1+2) und ruft pro Kandidat den ReplyDraftService.
 *
 * Skip-Gates (alle observable via usage_counters.kind='auto_reply_skip_<reason>'):
 *   - disabled:           autoreply_enabled = 0
 *   - before_enabled_at:  Mail vor Opt-In empfangen (Cold-Start-Schutz)
 *   - fyi_filter:         Subject matched Regex ODER Body < min_chars
 *   - no_conversation_id: keine conversation_id (defensive)
 *   - sent_match:         User hat bereits in Outlook geantwortet
 *   - quota_exceeded:     autoreply_max_per_day erreicht
 *   - budget_exceeded:    Token-Daily-Budget hat geblockt
 *   - error:              Graph-Call oder Draft-Erstellung gescheitert
 *
 * Kein automatisches Senden (Mandat) — Drafts liegen in reply_drafts
 * und werden im Add-in „Diese Mail"-Tab angezeigt.
 */
final class AutoReplyService
{
	private const BATCH_LIMIT = 20;

	public function __construct(
		private readonly PDO                    $db,
		private readonly GraphClient            $graph,
		private readonly TokenService           $tokens,
		private readonly SettingsRepository     $settings,
		private readonly UsageCounterRepository $usage,
		private readonly DraftRepository        $drafts,
		private readonly MailboxRepository      $mailboxes,
		private readonly ReplyDraftService      $replyDraftService,
		private readonly LoggerInterface        $logger,
	) {}

	/**
	 * @return array{generated:int, candidates:int, skipped:array<string,int>}
	 */
	public function tick(): array
	{
		if (!$this->settings->getBool('autoreply_enabled', false)) {
			return ['generated' => 0, 'candidates' => 0, 'skipped' => ['disabled' => 1]];
		}

		$cap       = max(1, $this->settings->getInt('autoreply_max_per_day', 15));
		$floor     = max(1, min(5, $this->settings->getInt('autoreply_priority_floor', 4)));
		$onlyUser  = $this->settings->getBool('autoreply_only_owner_user', true);
		$enabledAt = $this->settings->getString('autoreply_enabled_at', '');
		$subjectRe = $this->settings->getString('autoreply_skip_subject_regex', '');
		$bodyMin   = max(0, $this->settings->getInt('autoreply_skip_body_min_chars', 200));

		$candidates = $this->fetchCandidates($floor, $onlyUser, $enabledAt);
		$generated  = 0;
		$skipped    = ['fyi_filter' => 0, 'sent_match' => 0, 'quota_exceeded' => 0,
			'budget_exceeded' => 0, 'no_conversation_id' => 0, 'error' => 0];

		foreach ($candidates as $c) {
			// FYI-Pre-Filter: Subject-Regex + Body-Min-Length
			if ($subjectRe !== '' && @preg_match($subjectRe, (string)$c['subject']) === 1) {
				$this->bumpSkip($c, 'fyi_filter');
				$skipped['fyi_filter']++;
				continue;
			}
			if (strlen((string)$c['body_text']) < $bodyMin) {
				$this->bumpSkip($c, 'fyi_filter');
				$skipped['fyi_filter']++;
				continue;
			}

			$conversationId = (string)$c['conversation_id'];
			if ($conversationId === '') {
				$this->bumpSkip($c, 'no_conversation_id');
				$skipped['no_conversation_id']++;
				continue;
			}

			// Sent-Folder-Check via Graph (DA-R1 Finding 1 + R2 Finding 1)
			$accessToken = $this->resolveAccessToken((string)$c['mailbox_id'], (string)$c['tenant_id']);
			if ($accessToken === null) {
				$skipped['error']++;
				continue;
			}
			try {
				$last = $this->graph->getConversationLastMessage($accessToken, $conversationId);
			} catch (\Throwable $e) {
				$this->logger->warning('auto_reply.graph_check_failed', [
					'mail' => $c['mail_id'], 'err' => $e->getMessage(),
				]);
				$skipped['error']++;
				continue;
			}
			if ($this->userAlreadyReplied($last, $c)) {
				// Defensive: setze stale_at auf alle aktiven Drafts der Konversation.
				$this->drafts->markStaleByConversation((string)$c['tenant_id'], $conversationId);
				$this->bumpSkip($c, 'sent_match');
				$skipped['sent_match']++;
				continue;
			}

			// Quota — atomic increment, bei Cap stoppen für den ganzen Tick
			try {
				$this->usage->incrementOrFail(
					(string)$c['tenant_id'],
					(string)$c['user_id'],
					'auto_reply',
					$cap,
				);
			} catch (QuotaExceededException) {
				$this->bumpSkip($c, 'quota_exceeded');
				$skipped['quota_exceeded']++;
				break; // weitere Kandidaten kämen sowieso über Cap
			}

			// Draft generieren
			try {
				$this->replyDraftService->draft(
					(string)$c['tenant_id'],
					(string)$c['mail_id'],
					null,                              // kein user_instruction
					(string)$c['user_id'],
					'auto',                            // created_by
				);
				$generated++;
				$this->logger->info('auto_reply.generated', [
					'mail'            => $c['mail_id'],
					'subject_excerpt' => substr((string)$c['subject'], 0, 60),
				]);
			} catch (BudgetExceededException) {
				$this->bumpSkip($c, 'budget_exceeded');
				$skipped['budget_exceeded']++;
				break; // Budget geblockt → weitere Calls würden auch failen
			} catch (\Throwable $e) {
				$this->logger->warning('auto_reply.draft_failed', [
					'mail' => $c['mail_id'], 'err' => $e->getMessage(),
				]);
				$skipped['error']++;
			}
		}

		$this->logger->info('auto_reply.tick_done', [
			'candidates' => count($candidates),
			'generated'  => $generated,
			'skipped'    => $skipped,
		]);

		return ['generated' => $generated, 'candidates' => count($candidates), 'skipped' => $skipped];
	}

	/** @return list<array<string,mixed>> */
	private function fetchCandidates(int $floor, bool $onlyUser, string $enabledAt): array
	{
		$sql = 'SELECT m.id AS mail_id, m.tenant_id, m.mailbox_id, m.subject,
				m.from_email, m.body_text, m.received_at, m.conversation_id, mb.user_id
			FROM mails m
			INNER JOIN mailboxes mb ON mb.id = m.mailbox_id AND mb.deleted_at IS NULL
			INNER JOIN mail_scores s ON s.mail_id = m.id AND s.tenant_id = m.tenant_id
			LEFT JOIN reply_drafts rd ON rd.mail_id = m.id
				AND rd.dismissed_at IS NULL AND rd.stale_at IS NULL
			WHERE s.action_required = 1
			  AND s.priority >= :pf
			  AND s.cleared_at IS NULL
			  AND m.deleted_at IS NULL
			  AND rd.id IS NULL';
		if ($onlyUser) {
			$sql .= " AND s.action_owner = 'user'";
		}
		if ($enabledAt !== '') {
			$sql .= ' AND m.received_at >= :ea';
		}
		$sql .= ' ORDER BY s.priority DESC, m.received_at DESC LIMIT :lim';

		$stmt = $this->db->prepare($sql);
		$stmt->bindValue(':pf', $floor, PDO::PARAM_INT);
		if ($enabledAt !== '') {
			$stmt->bindValue(':ea', $enabledAt);
		}
		$stmt->bindValue(':lim', self::BATCH_LIMIT, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Hat der User selbst (eines seiner Identitäten) nach Original-Empfang
	 * geantwortet? DA-R2 Finding 1: Match gegen users.email ∪ mailboxes.email.
	 *
	 * @param array<string,mixed>|null $lastMessage
	 * @param array<string,mixed>      $candidate
	 */
	private function userAlreadyReplied(?array $lastMessage, array $candidate): bool
	{
		if ($lastMessage === null) {
			return false;
		}
		$lastTs = strtotime((string)($lastMessage['received_at'] ?? '')) ?: 0;
		$origTs = strtotime((string)$candidate['received_at']) ?: 0;
		// 30 s Toleranz für Clock-Drift zwischen Graph + DB.
		if ($lastTs <= $origTs + 30) {
			return false;
		}
		$lastFrom = strtolower((string)($lastMessage['from_email'] ?? ''));
		if ($lastFrom === '') {
			return false;
		}
		$identities = $this->identitySetForUser(
			(string)$candidate['tenant_id'],
			(string)$candidate['user_id'],
		);
		return in_array($lastFrom, $identities, true);
	}

	/** @return list<string> */
	private function identitySetForUser(string $tenantId, string $userId): array
	{
		// users.email (primary) plus alle Mailbox-Adressen, die der User
		// in diesem Tenant verknüpft hat. UNION-Distinct über email.
		// PDO native prepare erlaubt jeden Named-Param nur 1x — daher
		// :u1/:u2 statt :u zweimal.
		$stmt = $this->db->prepare('SELECT LOWER(email) AS email FROM users WHERE id = :u1
			UNION
			SELECT LOWER(email) AS email FROM mailboxes
			WHERE tenant_id = :t AND user_id = :u2 AND deleted_at IS NULL');
		$stmt->execute([':t' => $tenantId, ':u1' => $userId, ':u2' => $userId]);
		return array_values(array_filter(
			array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'email'),
			static fn($v): bool => is_string($v) && $v !== '',
		));
	}

	private function resolveAccessToken(string $mailboxId, string $tenantId): ?string
	{
		$mb = $this->mailboxes->findById($tenantId, $mailboxId);
		if ($mb === null) {
			return null;
		}
		try {
			return $this->tokens->ensureFreshAccessToken($mb);
		} catch (\Throwable $e) {
			$this->logger->warning('auto_reply.token_refresh_failed', [
				'mailbox' => $mailboxId, 'err' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Skip-Observability (DA-R2 Finding 2). Inkrementiert pro Mail einen
	 * Counter `auto_reply_skip_<reason>` mit Cap=PHP_INT_MAX, sodass das
	 * niemals als Quota-Throw zurückschlägt. Plus Log-Entry mit Grund.
	 *
	 * @param array<string,mixed> $candidate
	 */
	private function bumpSkip(array $candidate, string $reason): void
	{
		try {
			$this->usage->incrementOrFail(
				(string)$candidate['tenant_id'],
				(string)$candidate['user_id'],
				'auto_reply_skip_' . $reason,
				PHP_INT_MAX,
			);
		} catch (QuotaExceededException) {
			// Unreachable mit Cap=MAX, defensive.
		}
		$this->logger->info('auto_reply.skipped', [
			'mail'   => $candidate['mail_id'],
			'reason' => $reason,
		]);
	}
}
