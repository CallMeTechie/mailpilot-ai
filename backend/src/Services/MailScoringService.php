<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\SubLabelRepository;
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
	// @1.1: Sub-Labels axis. Claude now also picks one of the user's
	// free-form sub labels (or returns null). Bump invalidates the
	// content-hash cache so already-scored mails get re-scored once.
	public const PROMPT_VERSION = 'P-SCORE@1.1';

	public function __construct(
		private readonly ClaudeProvider $claude,
		private readonly MailRepository $mails,
		private readonly ScoreRepository $scores,
		private readonly CacheRepository $cache,
		private readonly RedactionService $redactor,
		private readonly BudgetService $budget,
		private readonly \MailPilot\Repositories\CorrectionRepository $corrections,
		private readonly SubLabelRepository $subLabels,
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
	/**
	 * @param callable(int):void|null $onChunkDone  Called after every
	 *   scoring chunk and after each cache/preset hit with the current
	 *   number of scored mails. Lets the worker / UI surface real
	 *   progress instead of jumping to 100 % at the end.
	 */
	public function scoreBatch(string $tenantId, array $userProfile, array $mails, ?callable $onChunkDone = null): array
	{
		$scored   = [];
		$toClaude = [];

		// Sub-Labels: per-user free-form names grouped by primary. Fed
		// into the Claude prompt as context; Claude either picks one
		// or returns null. The lookup map is the whitelist used by
		// the cache- and Claude-pathway alike.
		$userId       = (string)($userProfile['user_id'] ?? '');
		$subLabelMap  = $userId !== ''
			? $this->loadSubLabelMap($tenantId, $userId)
			: [];

		foreach ($mails as $mail) {
			// Pre-filter previously short-circuited every mail with a
			// List-Unsubscribe header to a hard-coded "Automatischer
			// Newsletter" preset. That header is mandatory under DSGVO
			// even for transactional senders (law firms, banks,
			// DocuSign, government portals), so the heuristic mislabelled
			// important mails as marketing and never asked Claude.
			// Now every mail runs through the cache → Claude path; the
			// budget gate provides the cost cap instead.

			// --- Cache lookup ---
			$hash = $this->contentHash($mail);
			$cached = $this->cache->get($tenantId, $hash, self::PROMPT_VERSION);
			if ($cached !== null) {
				$row = $this->buildScoreFromCache($tenantId, $mail, $cached, $subLabelMap);
				$scored[] = $row;
				if ($onChunkDone) { $onChunkDone(count($scored)); }
				continue;
			}

			// --- Step 3: queue for Claude ---
			$toClaude[] = ['mail' => $mail, 'hash' => $hash];
		}

		// --- Step 4: batches to Claude ---
		foreach (array_chunk($toClaude, $this->batchSize) as $chunk) {
			$results = $this->callClaude($userProfile, array_column($chunk, 'mail'), $subLabelMap);
			foreach ($chunk as $i => $item) {
				$claudeResult = $results[$i] ?? null;
				if ($claudeResult === null) {
					$this->logger->warning('scoring.missing_result', ['mail_id' => $item['mail']['id']]);
					continue;
				}
				$row = $this->buildScoreFromClaude($tenantId, $item['mail'], $claudeResult, $subLabelMap);
				$scored[] = $row;

				$this->cache->put($tenantId, $item['hash'], self::PROMPT_VERSION, $this->model, $claudeResult);
			}
			if ($onChunkDone) { $onChunkDone(count($scored)); }
		}

		$this->scores->upsertMany($scored);
		return $scored;
	}

	/**
	 * @return array<string, list<string>>  parent label → sorted list of sub-label names
	 */
	private function loadSubLabelMap(string $tenantId, string $userId): array
	{
		$map = [];
		foreach ($this->subLabels->listForUser($tenantId, $userId) as $row) {
			$map[$row['parent']][] = $row['name'];
		}
		return $map;
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
	 * @param list<array<string, mixed>>     $mails
	 * @param array<string, list<string>>    $subLabelMap  parent → names
	 * @return list<array<string, mixed>>
	 */
	private function callClaude(array $userProfile, array $mails, array $subLabelMap = []): array
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
		$user   = $this->buildUserPrompt($userProfile, $redacted, $subLabelMap);

		// Output sizes scale linearly with batch — empirically ~140 tokens
		// per mail for the JSON result line. Add 400 tokens of slack for
		// the opening/closing JSON scaffold so the response never hits the
		// hard cap mid-object (which would yield unparseable JSON and lose
		// the whole batch).
		$maxTokens = max(2000, count($mails) * 160 + 400);

		$tenantId  = (string)($userProfile['tenant_id'] ?? '');
		$userId    = (string)($userProfile['user_id'] ?? '') ?: null;
		$mailboxId = (string)($mails[0]['mailbox_id'] ?? '') ?: null;

		// Pre-flight: refuse if we'd blow the daily budget. estimate =
		// the same maxTokens we'd pass to Anthropic (worst-case spend).
		if ($tenantId !== '') {
			$gate = $this->budget->canSpend($tenantId, $userId, $maxTokens);
			if (!$gate['ok']) {
				$this->recordCall($tenantId, $userId, $mailboxId, null, [], 0, 'blocked', $gate['reason']);
				throw new BudgetExceededException((string)$gate['scope']);
			}
		}

		$start = microtime(true);
		try {
			// temperature was 0.1 — Claude 4.x rejects the parameter as
			// deprecated and returns HTTP 400.
			$response = $this->claude->messages([
				'model'      => $this->model,
				'max_tokens' => $maxTokens,
				'system'     => $system,
				'messages'   => [['role' => 'user', 'content' => $user]],
			]);
		} catch (\Throwable $e) {
			$this->recordCall($tenantId, $userId, $mailboxId, null, [], (int)((microtime(true) - $start) * 1000), 'error', $e->getMessage());
			throw $e;
		}
		$this->recordCall($tenantId, $userId, $mailboxId, null, $response['usage'] ?? [], (int)((microtime(true) - $start) * 1000), 'success', null);

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

	/**
	 * @param array<string, list<string>> $subLabelMap parent → names (already loaded)
	 */
	private function buildUserPrompt(array $userProfile, array $mails, array $subLabelMap = []): string
	{
		$vip = implode(', ', $userProfile['vip_senders'] ?? []);
		$kw  = implode(', ', $userProfile['project_keywords'] ?? []);
		// Repository layer already sanitises, but defend against any stray
		// non-UTF-8 byte from cached rows or hand-imported data.
		$mailsJson = json_encode(
			$mails,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
		);

		// Feed the user's recent corrections back as few-shot examples
		// so Claude learns the per-user calibration. Pulled from the
		// CorrectionRepository — empty section if the user never
		// corrected anything.
		$corrections = '';
		$tenantId = (string)($userProfile['tenant_id'] ?? '');
		$userId   = (string)($userProfile['user_id']   ?? '');
		if ($tenantId !== '' && $userId !== '') {
			$recent = $this->corrections->recentForUser($tenantId, $userId, 10);
			if ($recent !== []) {
				$lines = ['', 'PRIOR_USER_CORRECTIONS (the human overruled the model — apply the same reasoning):'];
				foreach ($recent as $c) {
					$from = substr($c['from_email'], 0, 60);
					$subj = substr($c['subject'], 0, 60);
					$ki = ($c['original_label'] ?? '?') . '/' . ($c['original_priority'] ?? '?');
					$human = $c['corrected_label'] . '/' . $c['corrected_priority']
						. ($c['corrected_action'] ? ' (action)' : '');
					$reason = $c['reasoning'] !== null && $c['reasoning'] !== ''
						? ' — Grund: ' . substr($c['reasoning'], 0, 200)
						: '';
					$lines[] = "- From: {$from} | Subject: {$subj} | KI: {$ki} → Human: {$human}{$reason}";
				}
				$corrections = implode("\n", $lines) . "\n";
			}
		}

		// USER_SUBLABELS block: only emitted when the user actually
		// defined sub-labels. Empty pool ⇒ block omitted ⇒ Claude is
		// instructed to always return sub_label = null below.
		$subLabelsBlock = '';
		$schemaSubLabel = '"sub_label":null';
		if ($subLabelMap !== []) {
			$lines = ['', 'USER_SUBLABELS (the user\'s own finer buckets under each primary; pick exactly one name if a mail clearly fits, else null):'];
			foreach ($subLabelMap as $parent => $names) {
				$lines[] = '- ' . $parent . ': ' . implode(', ', $names);
			}
			$subLabelsBlock = implode("\n", $lines) . "\n";
			$schemaSubLabel = '"sub_label":"<one name from USER_SUBLABELS under the chosen label, or null>"';
		}

		return <<<TXT
USER_PROFILE:
- email: {$userProfile['email']}
- language: {$userProfile['language']}
- vip_senders: [{$vip}]
- project_keywords: [{$kw}]
{$corrections}{$subLabelsBlock}
MAILS_TO_CLASSIFY:
{$mailsJson}

Gib exakt ein JSON-Objekt zurück:
{"results":[{"id":"<mail.id>","label":"direct|action|cc|newsletter|auto|noise",{$schemaSubLabel},"action_required":true|false,"priority":1-5,"summary":"max 160 chars","reasoning":"max 80 chars"}]}

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

	/**
	 * @param array<string, list<string>> $subLabelMap
	 */
	private function buildScoreFromCache(string $tenantId, array $mail, array $cached, array $subLabelMap = []): array
	{
		$label = (string)($cached['label'] ?? 'auto');
		return [
			'id'              => Uuid::v4(),
			'tenant_id'       => $tenantId,
			'mail_id'         => $mail['id'],
			'label'           => $label,
			'sub_label'       => $this->validateSubLabel($label, $cached['sub_label'] ?? null, $subLabelMap),
			'action_required' => (int)($cached['action_required'] ?? 0),
			'priority'        => (int)($cached['priority'] ?? 2),
			'summary'         => $this->truncate((string)($cached['summary'] ?? ''), 200),
			'reasoning'       => $this->truncate((string)($cached['reasoning'] ?? ''), 200),
			'prompt_version' => self::PROMPT_VERSION,
			'model'           => $this->model,
			'cached'          => 1,
		];
	}

	/**
	 * @param array<string, list<string>> $subLabelMap
	 */
	private function buildScoreFromClaude(string $tenantId, array $mail, array $result, array $subLabelMap = []): array
	{
		$label = $this->validateLabel((string)($result['label'] ?? 'auto'));
		return [
			'id'              => Uuid::v4(),
			'tenant_id'       => $tenantId,
			'mail_id'         => $mail['id'],
			'label'           => $label,
			'sub_label'       => $this->validateSubLabel($label, $result['sub_label'] ?? null, $subLabelMap),
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

	/**
	 * Whitelist a Claude-/cache-supplied sub_label against the user's
	 * own pool under the chosen primary. Anything outside the pool
	 * (typos, hallucinations, stale cache from a deleted sub-label)
	 * collapses to NULL — the catch-all bucket in AutoSort.
	 *
	 * @param array<string, list<string>> $subLabelMap
	 */
	private function validateSubLabel(string $primary, mixed $candidate, array $subLabelMap): ?string
	{
		if (!is_string($candidate)) {
			return null;
		}
		$candidate = trim($candidate);
		if ($candidate === '') {
			return null;
		}
		$pool = $subLabelMap[$primary] ?? [];
		return in_array($candidate, $pool, true) ? $candidate : null;
	}

	private function truncate(string $s, int $max): string
	{
		return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
	}

	/**
	 * @param array<string, mixed> $usage anthropic usage block from /v1/messages
	 */
	private function recordCall(
		string $tenantId,
		?string $userId,
		?string $mailboxId,
		?string $mailId,
		array $usage,
		int $durationMs,
		string $status,
		?string $errorText,
	): void {
		if ($tenantId === '') return; // not enough context to attribute the call
		$this->budget->recordUsage([
			'tenant_id'              => $tenantId,
			'user_id'                => $userId,
			'mailbox_id'             => $mailboxId,
			'mail_id'                => $mailId,
			'prompt_version'         => self::PROMPT_VERSION,
			'model'                  => $this->model,
			'input_tokens'           => (int)($usage['input_tokens']                 ?? 0),
			'output_tokens'          => (int)($usage['output_tokens']                ?? 0),
			'cache_read_tokens'      => (int)($usage['cache_read_input_tokens']      ?? 0),
			'cache_creation_tokens'  => (int)($usage['cache_creation_input_tokens']  ?? 0),
			'duration_ms'            => $durationMs,
			'status'                 => $status,
			'error_text'             => $errorText !== null ? substr($errorText, 0, 500) : null,
		]);
	}
}
