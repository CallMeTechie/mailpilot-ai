<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Util\Uuid;

/**
 * Classifies a batch of mails via Claude Haiku and persists scores.
 *
 * Pipeline per batch:
 *   1. Pre-filter obvious newsletters/auto (header-based) → auto-label, skip Claude.
 *   2. For remaining mails: check cache by content hash. Hit → reuse score.
 *   3. Miss → redact → build prompt → call Claude Haiku → parse JSON.
 *   4. Persist scores + cache the results.
 */
final class MailScoringService
{
	public const PROMPT_VERSION = 'P-SCORE@1.0';

	public function __construct(
		private readonly ClaudeProvider $claude,
		private readonly MailRepository $mails,
		private readonly ScoreRepository $scores,
		private readonly CacheRepository $cache,
		private readonly RedactionService $redactor,
		private readonly string $model,
		private readonly int $batchSize,
		private readonly int $maxBodyBytes,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $userProfile  email, language, vip_senders, project_keywords
	 * @param list<array<string, mixed>> $mails   mail rows as DTOs
	 * @return list<array<string, mixed>>         score rows
	 */
	public function scoreBatch(string $tenantId, array $userProfile, array $mails): array
	{
		$scored   = [];
		$toClaude = [];

		foreach ($mails as $mail) {
			// --- Step 1: pre-filter ---
			if ($this->isObviousNewsletter($mail, $userProfile)) {
				$scored[] = $this->buildPresetScore($tenantId, $mail, 'newsletter', 1, 'Automatischer Newsletter');
				continue;
			}

			// --- Step 2: cache lookup ---
			$hash = $this->contentHash($mail);
			$cached = $this->cache->get($tenantId, $hash, self::PROMPT_VERSION);
			if ($cached !== null) {
				$row = $this->buildScoreFromCache($tenantId, $mail, $cached);
				$scored[] = $row;
				continue;
			}

			// --- Step 3: queue for Claude ---
			$toClaude[] = ['mail' => $mail, 'hash' => $hash];
		}

		// --- Step 4: batches to Claude ---
		foreach (array_chunk($toClaude, $this->batchSize) as $chunk) {
			$results = $this->callClaude($userProfile, array_column($chunk, 'mail'));
			foreach ($chunk as $i => $item) {
				$claudeResult = $results[$i] ?? null;
				if ($claudeResult === null) {
					$this->logger->warning('scoring.missing_result', ['mail_id' => $item['mail']['id']]);
					continue;
				}
				$row = $this->buildScoreFromClaude($tenantId, $item['mail'], $claudeResult);
				$scored[] = $row;

				$this->cache->put($tenantId, $item['hash'], self::PROMPT_VERSION, $this->model, $claudeResult);
			}
		}

		$this->scores->upsertMany($scored);
		return $scored;
	}

	private function isObviousNewsletter(array $mail, array $userProfile): bool
	{
		if (!($mail['list_unsubscribe'] ?? false)) {
			return false;
		}
		$vipEmails = array_map('strtolower', $userProfile['vip_senders'] ?? []);
		$from = strtolower($mail['from_email'] ?? '');
		return !in_array($from, $vipEmails, true);
	}

	private function contentHash(array $mail): string
	{
		$body = (string)($mail['body_text'] ?? $mail['body_preview'] ?? '');
		$slice = substr($body, 0, $this->maxBodyBytes);
		return hash('sha256', implode('|', [
			strtolower((string)($mail['from_email'] ?? '')),
			trim((string)($mail['subject'] ?? '')),
			$slice,
		]));
	}

	/**
	 * @param list<array<string, mixed>> $mails
	 * @return list<array<string, mixed>>
	 */
	private function callClaude(array $userProfile, array $mails): array
	{
		$redacted = array_map(
			fn(array $m): array => $this->redactor->redactMail([
				'id'               => $m['id'],
				'from'             => $m['from_email'],
				'from_name'        => $m['from_name'] ?? '',
				'to'               => $m['to_json'] ?? [],
				'cc'               => $m['cc_json'] ?? [],
				'subject'          => $m['subject'] ?? '',
				'body_preview'     => substr((string)($m['body_text'] ?? $m['body_preview'] ?? ''), 0, $this->maxBodyBytes),
				'is_reply'         => (bool)($m['is_reply'] ?? false),
				'has_attachment'   => (bool)($m['has_attachment'] ?? false),
				'list_unsubscribe' => (bool)($m['list_unsubscribe'] ?? false),
				'received_at'      => $m['received_at'] ?? '',
			]),
			$mails,
		);

		$system = $this->buildSystemPrompt();
		$user   = $this->buildUserPrompt($userProfile, $redacted);

		$response = $this->claude->messages([
			'model'       => $this->model,
			'max_tokens'  => 2000,
			'temperature' => 0.1,
			'system'      => $system,
			'messages'    => [['role' => 'user', 'content' => $user]],
		]);

		$text = ClaudeClient::extractText($response);
		$text = $this->stripCodeFences($text);

		try {
			$json = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->error('scoring.invalid_json', ['excerpt' => substr($text, 0, 200)]);
			return [];
		}

		return $json['results'] ?? [];
	}

	private function stripCodeFences(string $text): string
	{
		$text = trim($text);
		if (str_starts_with($text, '```')) {
			$text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
		}
		return trim($text);
	}

	private function buildSystemPrompt(): string
	{
		return <<<TXT
Du bist MailPilot, ein präziser E-Mail-Triage-Assistent. Du klassifizierst
eingehende E-Mails aus Sicht eines bestimmten Nutzers. Du antwortest
AUSSCHLIESSLICH in gültigem JSON nach dem vorgegebenen Schema. Kein Prosa,
keine Markdown-Codefences, kein Kommentar.

Labels:
- direct: E-Mail ist persönlich an den Nutzer gerichtet, erwartet Wahrnehmung
- action: Absender erwartet konkret Antwort/Entscheidung/Handlung
- cc: Nutzer ist nur informativ im CC/BCC
- newsletter: Marketing, Abonnement (List-Unsubscribe gesetzt)
- auto: Automatisiert (CI, Monitoring, Rechnungen, Versandbestätigungen)
- noise: Spam-verdächtig / irrelevant

direct und cc schließen sich aus. action_required kann zusätzlich gesetzt sein.
Bei Newsletter/Auto/Noise ist action_required immer false.

Priorität 1-5: 5=sofort, 4=heute, 3=diese Woche, 2=wann passt, 1=ignorierbar.

Zusammenfassung max. 160 Zeichen, in user.language, keine Anführungszeichen, keine Emojis.
TXT;
	}

	private function buildUserPrompt(array $userProfile, array $mails): string
	{
		$vip = implode(', ', $userProfile['vip_senders'] ?? []);
		$kw  = implode(', ', $userProfile['project_keywords'] ?? []);
		$mailsJson = json_encode($mails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		return <<<TXT
USER_PROFILE:
- email: {$userProfile['email']}
- language: {$userProfile['language']}
- vip_senders: [{$vip}]
- project_keywords: [{$kw}]

MAILS_TO_CLASSIFY:
{$mailsJson}

Gib exakt ein JSON-Objekt zurück:
{"results":[{"id":"<mail.id>","label":"direct|action|cc|newsletter|auto|noise","action_required":true|false,"priority":1-5,"summary":"max 160 chars","reasoning":"max 80 chars"}]}

Anzahl results = Anzahl mails, in derselben Reihenfolge.
TXT;
	}

	private function buildPresetScore(string $tenantId, array $mail, string $label, int $priority, string $summary): array
	{
		return [
			'id'              => Uuid::v4(),
			'tenant_id'       => $tenantId,
			'mail_id'         => $mail['id'],
			'label'           => $label,
			'action_required' => 0,
			'priority'        => $priority,
			'summary'         => $summary,
			'reasoning'       => 'preset:pre-filter',
			'prompt_version' => self::PROMPT_VERSION,
			'model'           => 'preset',
			'cached'          => 0,
		];
	}

	private function buildScoreFromCache(string $tenantId, array $mail, array $cached): array
	{
		return [
			'id'              => Uuid::v4(),
			'tenant_id'       => $tenantId,
			'mail_id'         => $mail['id'],
			'label'           => (string)($cached['label'] ?? 'auto'),
			'action_required' => (int)($cached['action_required'] ?? 0),
			'priority'        => (int)($cached['priority'] ?? 2),
			'summary'         => $this->truncate((string)($cached['summary'] ?? ''), 200),
			'reasoning'       => $this->truncate((string)($cached['reasoning'] ?? ''), 200),
			'prompt_version' => self::PROMPT_VERSION,
			'model'           => $this->model,
			'cached'          => 1,
		];
	}

	private function buildScoreFromClaude(string $tenantId, array $mail, array $result): array
	{
		return [
			'id'              => Uuid::v4(),
			'tenant_id'       => $tenantId,
			'mail_id'         => $mail['id'],
			'label'           => $this->validateLabel((string)($result['label'] ?? 'auto')),
			'action_required' => (int)(bool)($result['action_required'] ?? false),
			'priority'        => max(1, min(5, (int)($result['priority'] ?? 2))),
			'summary'         => $this->truncate((string)($result['summary'] ?? ''), 200),
			'reasoning'       => $this->truncate((string)($result['reasoning'] ?? ''), 200),
			'prompt_version' => self::PROMPT_VERSION,
			'model'           => $this->model,
			'cached'          => 0,
		];
	}

	private function validateLabel(string $label): string
	{
		$allowed = ['direct', 'action', 'cc', 'newsletter', 'auto', 'noise'];
		return in_array($label, $allowed, true) ? $label : 'auto';
	}

	private function truncate(string $s, int $max): string
	{
		return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
	}

}
