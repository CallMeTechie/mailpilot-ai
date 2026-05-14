<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\SettingsRepository;
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
	// Schema-Version: erhöhen, wenn die Code-Logik so geändert wird,
	// dass alte Cache-Einträge nicht mehr kompatibel sind (z.B.
	// neue Felder im Output-Schema). Wird mit der DB-Prompt-Version
	// in den Cache-Key gehängt: "P-SCORE@<db-version>+code<N>".
	private const SCHEMA_CODE_REVISION = 1;
	private const PROMPT_KEY = 'P-SCORE';

	public function __construct(
		private readonly ClaudeProvider $claude,
		private readonly MailRepository $mails,
		private readonly ScoreRepository $scores,
		private readonly CacheRepository $cache,
		private readonly RedactionService $redactor,
		private readonly BudgetService $budget,
		private readonly \MailPilot\Repositories\CorrectionRepository $corrections,
		private readonly SubLabelRepository $subLabels,
		private readonly AutoSortRepository $autoSortRules,
		private readonly PromptRepository $prompts,
		private readonly SettingsRepository $settings,
		private readonly int $batchSize,
		private readonly int $maxBodyBytes,
		private readonly \Psr\Log\LoggerInterface $logger,
		// Sprint 6c: optional, damit Tests vor Sprint 6c den Service ohne
		// Pending-Wiring bauen können. Wenn null, fällt der suggest-Pfad
		// auf den enabled=0-Rule-only-Pfad zurück (kein pending_action-
		// Eintrag im Tab, aber Rule erscheint im AutoSort-Tab mit Badge).
		private readonly ?\MailPilot\Repositories\PendingActionRepository $pendingActions = null,
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

		// Aktive Prompt-Version aus DB laden — sobald der Admin im
		// Panel eine neue Version aktiviert, greift sie ab dem
		// nächsten Score-Batch ohne Code-Deploy. Cache-Versions-Tag
		// kombiniert key_name+version+code-revision, sodass alte
		// Cache-Einträge automatisch ungültig werden, wenn entweder
		// der Prompt oder die Code-Logik sich geändert hat.
		$activePrompt    = $this->prompts->getActive(self::PROMPT_KEY);
		$promptVersionTag = $this->prompts->cacheVersionTag(
			$activePrompt['key_name'],
			$activePrompt['version'] . '+code' . self::SCHEMA_CODE_REVISION,
		);

		// Sub-Labels: per-user free-form names grouped by primary. Fed
		// into the Claude prompt as context; Claude either picks one
		// or returns null. The lookup map is the whitelist used by
		// the cache- and Claude-pathway alike.
		$userId       = (string)($userProfile['user_id'] ?? '');
		$subLabelMap  = $userId !== ''
			? $this->loadSubLabelMap($tenantId, $userId)
			: [];

		// Mails, die per Cache klassifiziert werden, brauchen einen
		// separaten Mini-Call für action_owner (PRD-Phase-6 §5.1) —
		// hier sammeln wir die Mail-Refs mit Index in $scored, um sie
		// nach dem Hauptloop in EINEM Batch zu Claude zu schicken.
		// Format: list<array{mail: array, score_index: int}>
		$cacheHits = [];

		foreach ($mails as $mail) {
			// --- Cache lookup ---
			$hash = $this->contentHash($mail);
			$cached = $this->cache->get($tenantId, $hash, $promptVersionTag);
			if ($cached !== null) {
				$row = $this->buildScoreFromCache($tenantId, $userId, $mail, $cached, $subLabelMap, $promptVersionTag, $activePrompt['model']);
				$scored[] = $row;
				$cacheHits[] = ['mail' => $mail, 'score_index' => count($scored) - 1];
				if ($onChunkDone) { $onChunkDone(count($scored)); }
				continue;
			}

			// --- Step 3: queue for Claude ---
			$toClaude[] = ['mail' => $mail, 'hash' => $hash];
		}

		// --- Step 4: batches to Claude ---
		foreach (array_chunk($toClaude, $this->batchSize) as $chunk) {
			$results = $this->callClaude($userProfile, array_column($chunk, 'mail'), $subLabelMap, $activePrompt);
			foreach ($chunk as $i => $item) {
				$claudeResult = $results[$i] ?? null;
				if ($claudeResult === null) {
					$this->logger->warning('scoring.missing_result', ['mail_id' => $item['mail']['id']]);
					continue;
				}
				$row = $this->buildScoreFromClaude($tenantId, $userId, $item['mail'], $claudeResult, $subLabelMap, $promptVersionTag, $activePrompt['model'], $userProfile);
				$scored[] = $row;

				// claude_cache speichert NUR die kontext-unabhängigen
				// Felder. action_owner/confidence kommen aus dem aktuellen
				// Empfänger-Kontext und sind per PRD §5.1 NICHT cacheable —
				// hier explizit entfernen. CacheRepository::put serialisiert
				// das übergebene Array 1:1 als JSON, also muss der Filter
				// vor dem Put passieren. CacheRepositoryTest pinnt das.
				$cacheable = $claudeResult;
				unset($cacheable['action_owner'], $cacheable['action_owner_confidence']);
				$this->cache->put($tenantId, $item['hash'], $promptVersionTag, $activePrompt['model'], $cacheable);
			}
			if ($onChunkDone) { $onChunkDone(count($scored)); }
		}

		// --- Step 5: Mini-Call für action_owner der Cache-Hits ---
		// EIN Call pro ensureScored-Aufruf, profitiert vom selben
		// Prompt-Cache wie der Score-Call (System + USER_IDENTITY).
		// Bei Failure → 3-Stufen-Fallback (Sprint 6a #5), nicht hier.
		if ($cacheHits !== []) {
			$this->resolveActionOwnersForCacheHits($userProfile, $cacheHits, $scored);
		}

		$this->scores->upsertMany($scored);
		return $scored;
	}

	/**
	 * Mini-Call: bekommt für jede Cache-Hit-Mail nur (recipients, Anrede-
	 * Snippet, cached label) und liefert {action_owner, confidence}. Die
	 * Ergebnisse werden direkt in die $scored-Rows zurück gemerged.
	 *
	 * Bei Failure (Throwable, Invalid-JSON, fehlende Mail-IDs) springt
	 * der 3-Stufen-Fallback an (Sprint 6a #5 — applyActionOwnerFallback).
	 *
	 * @param array<string,mixed> $userProfile
	 * @param list<array{mail:array<string,mixed>,score_index:int}> $cacheHits
	 * @param list<array<string,mixed>> $scored In-place modified
	 */
	private function resolveActionOwnersForCacheHits(array $userProfile, array $cacheHits, array &$scored): void
	{
		$userEmail = strtolower((string)($userProfile['email'] ?? ''));
		$payload = [];
		foreach ($cacheHits as $hit) {
			$mail = $hit['mail'];
			$body = (string)($mail['body_text'] ?? $mail['body_preview'] ?? '');
			$payload[] = [
				'mail_id'      => (string)$mail['id'],
				'recipients'   => $this->buildRecipients($mail, $userEmail),
				'anrede_snippet' => substr($this->redactor->redact($body), 0, 200),
				'cached_label' => $scored[$hit['score_index']]['label'] ?? 'auto',
			];
		}

		try {
			$results = $this->callMiniActionOwner($userProfile, $payload);
		} catch (\Throwable $e) {
			$this->logger->warning('action_owner.mini_call_failed', ['err' => $e->getMessage(), 'n' => count($payload)]);
			$this->applyActionOwnerFallback($userProfile, $cacheHits, $scored);
			return;
		}

		// Map nach mail_id für deterministischen Lookup (Index ist nicht
		// garantiert, falls Claude die Reihenfolge bricht).
		$byMailId = [];
		foreach ($results as $r) {
			if (is_array($r) && isset($r['mail_id'])) {
				$byMailId[(string)$r['mail_id']] = $r;
			}
		}

		$missing = [];
		foreach ($cacheHits as $hit) {
			$mailId = (string)$hit['mail']['id'];
			$r = $byMailId[$mailId] ?? null;
			if ($r === null) {
				$missing[] = $hit;
				continue;
			}
			$scored[$hit['score_index']]['action_owner']            = $this->validateActionOwner((string)($r['action_owner'] ?? 'unsure'));
			$scored[$hit['score_index']]['action_owner_confidence'] = max(0, min(100, (int)($r['confidence'] ?? 0)));
			$scored[$hit['score_index']]['action_owner_source']     = 'ki';
		}

		// Partial Result → für die fehlenden Mails Fallback fahren.
		if ($missing !== []) {
			$this->logger->info('action_owner.partial_result', ['missing' => count($missing)]);
			$this->applyActionOwnerFallback($userProfile, $missing, $scored);
		}
	}

	/**
	 * Sendet den Mini-Call an Claude. Output ist JSON-Array von
	 * {mail_id, action_owner, confidence}.
	 *
	 * @param array<string,mixed> $userProfile
	 * @param list<array<string,mixed>> $payload
	 * @return list<array<string,mixed>>
	 */
	private function callMiniActionOwner(array $userProfile, array $payload): array
	{
		$rules = "\n" . str_replace('\\n', "\n", $this->settings->getString(
			'prompt.action_owner_rules',
			'ACTION_OWNER_RULES: bei Anrede-Ambiguität immer action_owner=unsure.',
		)) . "\n";

		// Der System-Block ist hier kompakter als der Score-System-Prompt,
		// teilt sich aber das USER_IDENTITY-Segment via buildCachedSystem-
		// Segments (PRD §5.1: „Mini-Call enthält USER_IDENTITY-Segment und
		// profitiert vom Prompt-Cache").
		$systemSegments = $this->buildCachedSystemSegments(
			'Du bist MailPilot, action_owner-Reasoner. Du antwortest AUSSCHLIESSLICH in gültigem JSON nach dem vorgegebenen Schema. Kein Prosa, keine Markdown-Codefences.',
			$userProfile,
		);

		$user = "{$rules}\nMAILS:\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\nGib exakt ein JSON-Objekt zurück:\n{\"results\":[{\"mail_id\":\"<id>\",\"action_owner\":\"user|other|group|unsure\",\"confidence\":0-100}]}\n\nAnzahl results = Anzahl MAILS, in derselben Reihenfolge.";

		$tenantId = (string)($userProfile['tenant_id'] ?? '');
		$userId   = (string)($userProfile['user_id']   ?? '') ?: null;
		$model    = 'claude-haiku-4-5-20251001';
		$promptTag = 'P-SCORE@mini-action-owner+code' . self::SCHEMA_CODE_REVISION;

		$start = microtime(true);
		try {
			$response = $this->claude->messages([
				'model'      => $model,
				'max_tokens' => max(200, count($payload) * 40 + 200),
				'system'     => $systemSegments,
				'messages'   => [['role' => 'user', 'content' => $user]],
			]);
		} catch (\Throwable $e) {
			// Budget-Tracking auch im Error-Pfad, damit gescheiterte Mini-
			// Calls in usage_daily auftauchen (Latenz + Status).
			$this->recordCall($tenantId, $userId, null, null, [], (int)((microtime(true) - $start) * 1000), 'error', $e->getMessage(), $promptTag, $model);
			throw $e;
		}
		$this->recordCall($tenantId, $userId, null, null, $response['usage'] ?? [], (int)((microtime(true) - $start) * 1000), 'success', null, $promptTag, $model);

		$text = $this->stripCodeFences(ClaudeClient::extractText($response));
		$json = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
		$out = $json['results'] ?? [];
		return is_array($out) ? $out : [];
	}

	/**
	 * Deterministischer 3-Stufen-Fallback (Sprint 6a §5.1).
	 *   1. User-im-To, einzig mit dem Vornamen → user/40
	 *   2. BCC oder Verteiler-Adresse              → group/60
	 *   3. Sonst                                   → unsure/0
	 * Schreibt direkt in $scored, action_owner_source='fallback'.
	 *
	 * @param array<string,mixed> $userProfile
	 * @param list<array{mail:array<string,mixed>,score_index:int}> $hits
	 * @param list<array<string,mixed>> $scored
	 */
	private function applyActionOwnerFallback(array $userProfile, array $hits, array &$scored): void
	{
		foreach ($hits as $hit) {
			[$owner, $conf] = $this->computeFallbackOwner($hit['mail'], $userProfile);
			$scored[$hit['score_index']]['action_owner']            = $owner;
			$scored[$hit['score_index']]['action_owner_confidence'] = $conf;
			$scored[$hit['score_index']]['action_owner_source']     = 'fallback';
		}
	}

	/**
	 * 3-Stufen-Fallback für eine einzelne Mail (PRD §5.1). Wird von zwei
	 * Pfaden genutzt: Mini-Call-Failure (Cache-Hit-Mails) und Fresh-Path-
	 * Schema-Miss (Claude lieferte action_owner nicht).
	 *
	 *   1. User-im-To, kein Empfänger mit kollidierendem Vornamen → user/40
	 *   2. Verteiler-From-Prefix ODER ≥3 weitere im To             → group/60
	 *   3. Alles andere                                            → unsure/0
	 *
	 * @param array<string,mixed> $mail
	 * @param array<string,mixed> $userProfile
	 * @return array{0:string,1:int}
	 */
	private function computeFallbackOwner(array $mail, array $userProfile): array
	{
		$userEmail = strtolower((string)($userProfile['email'] ?? ''));
		$aliases = array_map(
			static fn($a) => strtolower((string)$a),
			is_array($userProfile['aliases'] ?? null) ? $userProfile['aliases'] : [],
		);
		$groupPrefixes = ['info', 'support', 'no-reply', 'noreply', 'newsletter', 'team', 'service', 'notifications', 'kontakt'];

		$recipients = $this->buildRecipients($mail, $userEmail);
		$userInTo = false;
		$nameCollision = false;
		$othersInTo = [];
		foreach ($recipients as $r) {
			if ($r['role'] !== 'to') continue;
			if ($r['is_user']) {
				$userInTo = true;
			} else {
				$othersInTo[] = $r;
				$firstName = strtolower(trim(explode(' ', $r['name'])[0] ?? ''));
				if ($firstName !== '' && in_array($firstName, $aliases, true)) {
					$nameCollision = true;
				}
			}
		}

		if ($userInTo && !$nameCollision) {
			return ['user', 40];
		}

		$fromLocal = strtolower(strstr(($mail['from_email'] ?? ''), '@', true) ?: '');
		if (in_array($fromLocal, $groupPrefixes, true) || count($othersInTo) >= 3) {
			return ['group', 60];
		}

		return ['unsure', 0];
	}

	/**
	 * Per parent label a list of {name, description}. The description
	 * is what the user typed in Settings — it goes into the prompt so
	 * Claude knows WHY a topic exists ("GitHub CI" → "Mails von
	 * notifications@github.com"), not just the name.
	 *
	 * @return array<string, list<array{name:string, description:?string}>>
	 */
	private function loadSubLabelMap(string $tenantId, string $userId): array
	{
		$map = [];
		foreach ($this->subLabels->listForUser($tenantId, $userId) as $row) {
			$map[$row['parent']][] = [
				'name'        => $row['name'],
				'description' => $row['description'] ?? null,
			];
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
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 * @return list<array<string, mixed>>
	 */
	/**
	 * @param array{system_prompt:string, user_template:string, model:string, max_tokens:int, key_name:string, version:string, temperature:float} $activePrompt
	 */
	private function callClaude(array $userProfile, array $mails, array $subLabelMap, array $activePrompt): array
	{
		$userEmail = strtolower((string)($userProfile['email'] ?? ''));
		$redacted = array_map(
			fn(array $m): array => $this->redactor->redactMail([
				'id'               => $m['id'],
				'from'             => $m['from_email'],
				'from_name'        => $m['from_name'] ?? '',
				// recipients-Array für action_owner-Disambiguierung (Sprint 6a §2.1).
				// is_user-Marker entlastet Claude vom Email-Vergleich und macht
				// Test-Pinning für Ambiguitäts-Fälle deterministisch.
				'recipients'       => $this->buildRecipients($m, $userEmail),
				'subject'          => $m['subject'] ?? '',
				'body_preview'     => substr((string)($m['body_text'] ?? $m['body_preview'] ?? ''), 0, $this->maxBodyBytes),
				'is_reply'         => (bool)($m['is_reply'] ?? false),
				'has_attachment'   => (bool)($m['has_attachment'] ?? false),
				'list_unsubscribe' => (bool)($m['list_unsubscribe'] ?? false),
				'received_at'      => $m['received_at'] ?? '',
			]),
			$mails,
		);

		// Sprint 6a/6b: System-Prompt segmentiert mit cache_control. Drei
		// Blöcke mit TTL=1h: (1) System-Text (admin-editierbar), (2)
		// USER_IDENTITY (Aliases + Name), (3) USER_TOPICS (sub-label-pool).
		// Bei sub-label-CRUD ändert sich Segment 3 — Anthropic invalidiert
		// es automatisch über den Inhalt-Hash, kein expliziter Wisch nötig.
		$systemSegments = $this->buildCachedSystemSegments($activePrompt['system_prompt'], $userProfile, $subLabelMap);
		$user   = $this->renderUserTemplate($activePrompt['user_template'], $userProfile, $redacted, $subLabelMap);
		$model  = $activePrompt['model'];

		// Output sizes scale linearly with batch — empirically ~140 tokens
		// per mail for the JSON result line. Add 400 tokens of slack for
		// the opening/closing JSON scaffold so the response never hits the
		// hard cap mid-object (which would yield unparseable JSON and lose
		// the whole batch).
		$maxTokens = max($activePrompt['max_tokens'], count($mails) * 160 + 400);

		$tenantId  = (string)($userProfile['tenant_id'] ?? '');
		$userId    = (string)($userProfile['user_id'] ?? '') ?: null;
		$mailboxId = (string)($mails[0]['mailbox_id'] ?? '') ?: null;
		$promptVersionTag = $this->prompts->cacheVersionTag(
			$activePrompt['key_name'],
			$activePrompt['version'] . '+code' . self::SCHEMA_CODE_REVISION,
		);

		// Pre-flight: refuse if we'd blow the daily budget. estimate =
		// the same maxTokens we'd pass to Anthropic (worst-case spend).
		if ($tenantId !== '') {
			$gate = $this->budget->canSpend($tenantId, $userId, $maxTokens);
			if (!$gate['ok']) {
				$this->recordCall($tenantId, $userId, $mailboxId, null, [], 0, 'blocked', $gate['reason'], $promptVersionTag, $model);
				throw new BudgetExceededException((string)$gate['scope']);
			}
		}

		$start = microtime(true);
		try {
			$response = $this->claude->messages([
				'model'      => $model,
				'max_tokens' => $maxTokens,
				'system'     => $systemSegments,
				'messages'   => [['role' => 'user', 'content' => $user]],
			]);
		} catch (\Throwable $e) {
			$this->recordCall($tenantId, $userId, $mailboxId, null, [], (int)((microtime(true) - $start) * 1000), 'error', $e->getMessage(), $promptVersionTag, $model);
			throw $e;
		}
		$this->recordCall($tenantId, $userId, $mailboxId, null, $response['usage'] ?? [], (int)((microtime(true) - $start) * 1000), 'success', null, $promptVersionTag, $model);

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

	/**
	 * Rendert das DB-User-Template (Admin-Panel-editierbar) mit den
	 * dynamischen Platzhaltern. Die Werte werden hier zur Laufzeit
	 * generiert (Corrections, Sub-Label-Pool, Discovery-Note,
	 * Output-Schema-Snippet). Das Template selbst sagt nur, WO sie
	 * platziert werden — der Operator kann Wortlaut und Layout im
	 * Admin-Panel anpassen, ohne Code-Deploy.
	 *
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 */
	private function renderUserTemplate(string $template, array $userProfile, array $mails, array $subLabelMap): string
	{
		$vip = implode(', ', $userProfile['vip_senders'] ?? []);
		$kw  = implode(', ', $userProfile['project_keywords'] ?? []);
		$mailsJson = json_encode(
			$mails,
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
		);

		// Few-Shot-Korrekturen (leer wenn keine)
		$correctionsBlock = '';
		$tenantId = (string)($userProfile['tenant_id'] ?? '');
		$userId   = (string)($userProfile['user_id']   ?? '');
		if ($tenantId !== '' && $userId !== '') {
			$recent = $this->corrections->recentForUser($tenantId, $userId, 10);
			if ($recent !== []) {
				$lines = ['', $this->settings->getString('prompt.corrections_header', 'PRIOR_USER_CORRECTIONS:')];
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
				$correctionsBlock = implode("\n", $lines) . "\n";
			}
		}

		// USER_SUBLABELS-Block: ab Sprint 6b im gecachten System-Segment 3
		// (siehe buildCachedSystemSegments). Im User-Template bleibt der
		// Platzhalter leer, damit nichts dupliziert wird. Schema-Snippet
		// hängt weiter vom Pool-State ab — bleibt im User-Template, weil
		// es die JSON-Output-Form pro Call steuert (eines pro Score-Batch,
		// nicht stabil cacheable).
		$subLabelsBlock = '';
		$schemaSubLabel = $subLabelMap !== []
			? $this->settings->getString('prompt.schema_sublabel_with_pool',
				'"sub_label":"<a topic name OR null>","sub_label_is_new":true|false')
			: $this->settings->getString('prompt.schema_sublabel_empty_pool',
				'"sub_label":null,"sub_label_is_new":false');

		// Topic-Discovery-Note (Phase 6b) — admin-editierbar; \n-Tokens
		// im DB-Wert werden hier zu echten Zeilenumbrüchen aufgelöst,
		// da SQL-Editor keine echten Newlines im String-Literal hat.
		$discoveryNote = "\n" . str_replace('\\n', "\n", $this->settings->getString(
			'prompt.topic_discovery_note',
			'TOPIC_DISCOVERY: propose new topics if no existing bucket fits.',
		)) . "\n";

		// USER_IDENTITY-Block (Sprint 6a §2). Liefert Display-Name + Aliase
		// an Claude, damit „Hallo Marc" auf den richtigen User mappt. Wenn
		// keine Aliase gepflegt → nur Display-Name (Alias-Pflege ist optional).
		$aliases = is_array($userProfile['aliases'] ?? null) ? $userProfile['aliases'] : [];
		$displayName = (string)($userProfile['display_name'] ?? '');
		$identityHeader = $this->settings->getString(
			'prompt.user_identity_header',
			'USER_IDENTITY:',
		);
		$identityLines = [''];
		$identityLines[] = $identityHeader;
		if ($displayName !== '') {
			$identityLines[] = '- name: ' . $displayName;
		}
		if ($aliases !== []) {
			$identityLines[] = '- aliases: [' . implode(', ', array_map(static fn($a) => (string)$a, $aliases)) . ']';
		}
		// Newline am Ende für sauberen Abstand zum nächsten Block
		$identityBlock = implode("\n", $identityLines) . "\n";

		// ACTION_OWNER_RULES-Block (Sprint 6a §2.1). Wortlaut admin-editierbar
		// in system_settings.prompt.action_owner_rules, \n-Tokens → echte
		// Zeilenumbrüche (wie bei topic_discovery_note).
		$actionOwnerRules = "\n" . str_replace('\\n', "\n", $this->settings->getString(
			'prompt.action_owner_rules',
			'ACTION_OWNER_RULES: bei Anrede-Ambiguität immer action_owner=unsure.',
		)) . "\n";

		return str_replace([
			'{{user_email}}',
			'{{user_language}}',
			'{{vip_senders_csv}}',
			'{{project_keywords_csv}}',
			'{{user_identity_block}}',
			'{{action_owner_rules_block}}',
			'{{corrections_block}}',
			'{{user_sublabels_block}}',
			'{{topic_discovery_note}}',
			'{{mails_json}}',
			'{{output_schema_sub_label}}',
		], [
			(string)($userProfile['email'] ?? ''),
			(string)($userProfile['language'] ?? 'de'),
			$vip,
			$kw,
			$identityBlock,
			$actionOwnerRules,
			$correctionsBlock,
			$subLabelsBlock,
			$discoveryNote,
			(string)$mailsJson,
			$schemaSubLabel,
		], $template);
	}

	/**
	 * Baut das recipients-Array für eine Mail aus to_json/cc_json.
	 * is_user wird via Email-Vergleich (lowercase) gesetzt. Wenn die
	 * User-Email nicht in den Recipients steht (z.B. BCC), enthält das
	 * Array sie nicht — der Mini-Call-Fallback (#5) erkennt das später
	 * über die Original-to_json/cc_json.
	 *
	 * @param array<string,mixed> $mail
	 * @return list<array{email:string,name:string,role:string,is_user:bool}>
	 */
	private function buildRecipients(array $mail, string $userEmail): array
	{
		$out = [];
		foreach (['to_json' => 'to', 'cc_json' => 'cc'] as $field => $role) {
			$raw = $mail[$field] ?? null;
			if (is_string($raw)) {
				$raw = json_decode($raw, true) ?: [];
			}
			if (!is_array($raw)) {
				continue;
			}
			foreach ($raw as $entry) {
				if (!is_array($entry)) continue;
				$email = strtolower((string)($entry['address'] ?? $entry['email'] ?? ''));
				if ($email === '') continue;
				$out[] = [
					'email'   => $email,
					'name'    => (string)($entry['name'] ?? $entry['display_name'] ?? ''),
					'role'    => $role,
					'is_user' => $email === $userEmail,
				];
			}
		}
		return $out;
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
			'prompt_version'  => self::PROMPT_KEY . '@preset',
			'model'           => 'preset',
			'cached'          => 0,
		];
	}

	/**
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 */
	private function buildScoreFromCache(string $tenantId, string $userId, array $mail, array $cached, array &$subLabelMap, string $promptVersionTag, string $model): array
	{
		$label = (string)($cached['label'] ?? 'auto');
		// action_owner ist per PRD-Phase-6 §5.1 NICHT cacheable. Bei Cache-
		// Hit gehen die Felder hier mit Default-'unsure'/null/null raus;
		// der Mini-Call in MailScoringService::resolveActionOwnersForCacheHits
		// (Sprint 6a #4) überschreibt sie batch-weise vor der Persistierung.
		// Test CacheRepositoryTest::testActionOwnerFieldsAreNotCached pinnt,
		// dass diese drei Felder NIE in claude_cache.response_json landen.
		return [
			'id'              => Uuid::v4(),
			'tenant_id'       => $tenantId,
			'mail_id'         => $mail['id'],
			'label'           => $label,
			'sub_label'       => $this->resolveOrDiscoverSubLabel(
				$tenantId, $userId, $label, $cached['sub_label'] ?? null, false, $subLabelMap,
			),
			'action_required'         => (int)($cached['action_required'] ?? 0),
			'action_owner'            => 'unsure',
			'action_owner_confidence' => null,
			'action_owner_source'     => null,
			'priority'        => (int)($cached['priority'] ?? 2),
			'summary'         => $this->truncate((string)($cached['summary'] ?? ''), 200),
			'reasoning'       => $this->truncate((string)($cached['reasoning'] ?? ''), 200),
			'prompt_version'  => $promptVersionTag,
			'model'           => $model,
			'cached'          => 1,
		];
	}

	/**
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 * @param array<string,mixed> $userProfile  Für Schema-Miss-Fallback nötig (DA-Finding 1).
	 */
	private function buildScoreFromClaude(string $tenantId, string $userId, array $mail, array $result, array &$subLabelMap, string $promptVersionTag, string $model, array $userProfile): array
	{
		$label = $this->validateLabel((string)($result['label'] ?? 'auto'));
		$isNew = (bool)($result['sub_label_is_new'] ?? false);

		// action_owner-Source-Tracking (DA-Finding 1): wenn Claude das
		// Feld im Result LIEFERT → source='ki'. Wenn es FEHLT (Schema-
		// Drift, abgeschnittenes JSON, OPUS-Variante ohne Sprint-6a-Schema)
		// → deterministischer Single-Mail-Fallback mit source='fallback',
		// damit Observability-Queries (`WHERE source='ki'`) kein
		// "AI confidently said unsure" sehen das gar nicht von der KI kam.
		if (array_key_exists('action_owner', $result)) {
			$ao  = $this->validateActionOwner((string)$result['action_owner']);
			$aoc = max(0, min(100, (int)($result['action_owner_confidence'] ?? 0)));
			$aos = 'ki';
		} else {
			[$ao, $aoc] = $this->computeFallbackOwner($mail, $userProfile);
			$aos = 'fallback';
		}

		return [
			'id'              => Uuid::v4(),
			'tenant_id'       => $tenantId,
			'mail_id'         => $mail['id'],
			'label'           => $label,
			'sub_label'       => $this->resolveOrDiscoverSubLabel(
				$tenantId, $userId, $label, $result['sub_label'] ?? null, $isNew, $subLabelMap,
			),
			'action_required'         => (int)(bool)($result['action_required'] ?? false),
			'action_owner'            => $ao,
			'action_owner_confidence' => $aoc,
			'action_owner_source'     => $aos,
			'priority'        => max(1, min(5, (int)($result['priority'] ?? 2))),
			'summary'         => $this->truncate((string)($result['summary'] ?? ''), 200),
			'reasoning'       => $this->truncate((string)($result['reasoning'] ?? ''), 200),
			'prompt_version'  => $promptVersionTag,
			'model'           => $model,
			'cached'          => 0,
		];
	}

	private function validateActionOwner(string $v): string
	{
		$allowed = ['user', 'other', 'group', 'unsure'];
		return in_array($v, $allowed, true) ? $v : 'unsure';
	}

	/**
	 * Baut die system-message für den Score-Call als Array von text-Blöcken
	 * mit cache_control (1h-Extended-TTL). Anthropic erkennt das ab Beta
	 * `extended-cache-ttl-2025-04-11`; ohne Beta-Header degradiert es
	 * silently auf 5min-Ephemeral.
	 *
	 * Bis zu drei Segmente:
	 *   1. admin-editierbarer System-Prompt
	 *   2. USER_IDENTITY (Aliases + Display-Name)
	 *   3. USER_TOPICS (sub-label-pool mit Description) — nur wenn nicht leer
	 *
	 * Mini-Call lässt $subLabelMap=[] weg (er braucht USER_TOPICS nicht),
	 * dadurch teilt er sich Segment 1+2 als Cache-Prefix mit dem Score-Call.
	 *
	 * @param array<string,mixed> $userProfile
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 * @return list<array<string,mixed>>
	 */
	private function buildCachedSystemSegments(string $systemPrompt, array $userProfile, array $subLabelMap = []): array
	{
		$aliases = is_array($userProfile['aliases'] ?? null) ? $userProfile['aliases'] : [];
		$displayName = (string)($userProfile['display_name'] ?? '');

		$identity = "USER_IDENTITY:\n- name: " . ($displayName !== '' ? $displayName : '(unbekannt)');
		if ($aliases !== []) {
			$identity .= "\n- aliases: [" . implode(', ', array_map(static fn($a) => (string)$a, $aliases)) . ']';
		}

		$segments = [
			[
				'type' => 'text',
				'text' => $systemPrompt,
				'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
			],
			[
				'type' => 'text',
				'text' => $identity,
				'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
			],
		];

		// Segment 3 — USER_TOPICS (Sprint 6b). Inhalt-Hash ändert sich bei
		// sub-label-CRUD ODER bei KI-Discovery; Anthropic invalidiert den
		// Cache-Eintrag dann automatisch. Nur einbauen wenn nicht leer,
		// sonst zahlt jeder erste Call cache_creation für einen leeren
		// Block ohne Spar-Effekt.
		//
		// DA-Finding 1: Reihenfolge stabilisieren. Ohne ksort/usort hat
		// die Discovery-In-Batch-Mutation einen anderen Hash als der
		// nächste DB-Reload (ORDER BY name) → cache_creation jedes Mal.
		if ($subLabelMap !== []) {
			ksort($subLabelMap, SORT_STRING);
			$header = $this->settings->getString('prompt.sublabels_header', 'USER_SUBLABELS:');
			$lines = [$header];
			foreach ($subLabelMap as $parent => $entries) {
				usort($entries, static fn(array $a, array $b): int => strcmp((string)$a['name'], (string)$b['name']));
				foreach ($entries as $entry) {
					$line = '- ' . $parent . ' / ' . $entry['name'];
					if (!empty($entry['description'])) {
						$line .= ' — ' . substr((string)$entry['description'], 0, 200);
					}
					$lines[] = $line;
				}
			}
			$segments[] = [
				'type' => 'text',
				'text' => implode("\n", $lines),
				'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
			];
		}

		return $segments;
	}

	private function validateLabel(string $label): string
	{
		$allowed = ['direct', 'action', 'cc', 'newsletter', 'auto', 'noise'];
		return in_array($label, $allowed, true) ? $label : 'auto';
	}

	/**
	 * Whitelist + Topic-Discovery (Phase 6b).
	 *
	 *   - In existing pool? → use as-is.
	 *   - sub_label_is_new=true AND not in pool: Fuzzy-Merge gegen Pool
	 *     (Levenshtein ≤ 3) — bei Match: existing name; sonst neuen
	 *     Topic in user_sublabels (created_by='ki') + auto_sort_rules
	 *     (enabled=true, default folder) anlegen.
	 *   - Andernfalls (KI hat halluziniert): NULL.
	 *
	 * Die Map wird by-ref geupdatet, damit weitere Mails im selben
	 * Batch den frisch entdeckten Topic ohne neuen DB-Round-Trip sehen.
	 *
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 */
	private function resolveOrDiscoverSubLabel(
		string $tenantId,
		string $userId,
		string $primary,
		mixed $candidate,
		bool $isNew,
		array &$subLabelMap,
	): ?string {
		if (!is_string($candidate)) return null;
		$candidate = trim($candidate);
		if ($candidate === '') return null;

		$pool  = $subLabelMap[$primary] ?? [];
		$names = array_column($pool, 'name');

		// 1) Exact match — Claude hat existing topic gewählt
		if (in_array($candidate, $names, true)) {
			return $candidate;
		}

		// 2) Discovery-Pfad
		if ($isNew && $userId !== '') {
			// Sprint 6c: Discovery wird durch autosort_create_topic_mode
			// gegated. 'off' = keine KI-Discovery, 'suggest'/'auto'
			// erlauben sie. created_under_mode wird in pending_actions
			// festgenagelt (DA-Finding 1).
			$createMode = $this->settings->getString('autosort_create_topic_mode', 'suggest');
			if (!in_array($createMode, ['suggest', 'auto'], true)) {
				$this->logger->info('topic.discovery_blocked_by_mode', [
					'primary' => $primary, 'mode' => $createMode,
				]);
				return null;
			}

			// 2a) Format-Sanity: max 30 chars, only letters/digits/-/_/space/slash
			if (mb_strlen($candidate) > 30 || !preg_match('/^[\p{L}\p{N}\s\-_\/]+$/u', $candidate)) {
				$this->logger->info('topic.discovery_rejected', [
					'name'    => $candidate,
					'primary' => $primary,
					'reason'  => 'format',
				]);
				return null;
			}

			// 2b) Fuzzy-Merge gegen existing names (lowercase) — vermeidet
			// "GitHub CI" + "GitHub Actions" + "CI Pipeline" Drift.
			// Schwelle aus Migration 0014, admin-editierbar.
			$mergeMax = max(0, $this->settings->getInt('topics.fuzzy_merge_levenshtein_max', 3));
			foreach ($names as $existing) {
				if (levenshtein(strtolower($candidate), strtolower($existing)) <= $mergeMax) {
					$this->logger->info('topic.merged_to_existing', [
						'proposed' => $candidate,
						'matched'  => $existing,
						'primary'  => $primary,
					]);
					return $existing;
				}
			}

			// 2c) Anlegen: user_sublabels (created_by='ki') + AutoSort-Rule.
			// Sprint 6c: bei autosort_create_topic_mode='auto' wird die
			// Rule sofort enabled; bei 'suggest' bleibt sie disabled (KI-
			// Badge im AutoSort-Tab) UND wir legen zusätzlich eine
			// pending_action(create_topic) an, damit das Pending-Tab sie
			// als „Neuer Topic vorgeschlagen" zeigt. Die Bulk-Move-
			// Bestätigung (PRD §3.1) passiert dann beim Approve im UI.
			try {
				$this->subLabels->create($tenantId, $userId, $primary, $candidate, null, null, 'ki');
				$folderDefault = $this->settings->getString('folder_default.' . $primary, 'MailPilot/' . ucfirst($primary));
				$folderPath    = $folderDefault . '/' . $candidate;

				if ($createMode === 'auto') {
					// Auto-Modus: AutoSort-Rule wird direkt enabled angelegt.
					// AutoSortService.applyToScoredMail moved dann ab dem
					// nächsten Score-Pass automatisch.
					$this->autoSortRules->upsert($tenantId, $userId, $primary, $candidate, true, $folderPath);
				} else {
					// Suggest-Modus: KI-Rule disabled + pending_action(create_topic)
					// damit der User im Pending-Tab UND im Auto-Sort-Tab den
					// Vorschlag sieht. DA-Impl-Finding 1: fail-closed wenn
					// kein PendingRepo injiziert (Sprint-6c-Vertrag).
					if ($this->pendingActions === null) {
						throw new \RuntimeException('MailScoringService: suggest-mode benötigt PendingActionRepository');
					}
					$this->autoSortRules->suggestKiRule($tenantId, $userId, $primary, $candidate, $folderPath);
					$this->pendingActions->create($tenantId, $userId, 'create_topic', [
						'primary'     => $primary,
						'sub_label'   => $candidate,
						'folder_path' => $folderPath,
						'reason'      => 'auto-discovery',
					], 'suggest');
				}

				$this->logger->info('topic.discovered', [
					'name'    => $candidate,
					'primary' => $primary,
					'mode'    => $createMode,
				]);
				// Map lokal updaten, damit Folge-Mails im selben Batch
				// den neuen Topic als "existing" sehen
				$subLabelMap[$primary][] = ['name' => $candidate, 'description' => null];
				return $candidate;
			} catch (\Throwable $e) {
				// DA-Finding 2: Race-Loser. Bei parallelem Discover desselben
				// Topics (Worker + manueller Rescore) wirft create() ggf.
				// auch wenn ON-DUPLICATE-KEY existiert — z.B. wenn dem
				// AutoSortRule-Insert ein anderer UNIQUE-Constraint im Weg
				// steht. Statt NULL zu liefern (verlorene Erst-Klassifizierung)
				// pruefen wir, ob das Topic JETZT in der DB existiert: wenn
				// ja, war's nur ein Race, $candidate gewinnt trotzdem.
				$this->logger->warning('topic.discovery_race_or_failed', [
					'name'    => $candidate,
					'primary' => $primary,
					'err'     => $e->getMessage(),
				]);
				foreach ($this->subLabels->listForUser($tenantId, $userId) as $row) {
					if ($row['parent'] === $primary
						&& strtolower($row['name']) === strtolower($candidate)) {
						$subLabelMap[$primary][] = ['name' => $candidate, 'description' => null];
						return $candidate;
					}
				}
				return null;
			}
		}

		// 3) KI hat halluziniert (Name nicht im Pool, isNew=false)
		return null;
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
		string $promptVersionTag,
		string $model,
	): void {
		if ($tenantId === '') return; // not enough context to attribute the call
		$this->budget->recordUsage([
			'tenant_id'              => $tenantId,
			'user_id'                => $userId,
			'mailbox_id'             => $mailboxId,
			'mail_id'                => $mailId,
			'prompt_version'         => $promptVersionTag,
			'model'                  => $model,
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
