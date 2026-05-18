<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\Scoring\ActionOwnerResolver;
use MailPilot\Services\Scoring\ScoringPromptBuilder;
use MailPilot\Services\Scoring\SubLabelDiscoverer;
use MailPilot\Services\Sender\LookalikeDetector;
use MailPilot\Services\Sender\SenderResolver;
use MailPilot\Util\Uuid;

/**
 * Classifies a batch of mails via Claude Haiku and persists scores.
 *
 * Pipeline per batch:
 *   1. Pre-filter / cache lookup by content hash. Hit → reuse score.
 *   2. Miss → redact → build prompt → call Claude Haiku → parse JSON.
 *   3. Persist scores + cache results.
 *   4. Cache-Hits: separate Mini-Call (via ActionOwnerResolver) fuer
 *      action_owner (PRD §5.1, nicht cacheable).
 *
 * Sub-Logik in Scoring/* extrahiert, dieser Service orchestriert:
 *   - ScoringPromptBuilder  → User-Template + System-Segmente
 *   - ActionOwnerResolver   → action_owner (Mini-Call + Fallback)
 *   - SubLabelDiscoverer    → Topic-Discovery + Fuzzy-Merge
 */
final class MailScoringService
{
	private const SCHEMA_CODE_REVISION = 1;
	private const PROMPT_KEY = 'P-SCORE';

	private readonly ActionOwnerResolver $actionOwner;
	private readonly SubLabelDiscoverer $subLabelDiscoverer;
	private readonly ScoringPromptBuilder $promptBuilder;

	public function __construct(
		private readonly ClaudeProvider $claude,
		private readonly MailRepository $mails,
		private readonly ScoreRepository $scores,
		private readonly CacheRepository $cache,
		private readonly RedactionService $redactor,
		private readonly BudgetService $budget,
		\MailPilot\Repositories\CorrectionRepository $corrections,
		\MailPilot\Repositories\SubLabelRepository $subLabels,
		\MailPilot\Repositories\AutoSortRepository $autoSortRules,
		private readonly PromptRepository $prompts,
		// Nach Phase-1-Split wandern alle SettingsRepository-Nutzungen in
		// ScoringPromptBuilder / SubLabelDiscoverer / ActionOwnerResolver.
		// Hier nur noch durchreichen, keine Property-Speicherung (PHPStan
		// L5: "never read, only written").
		SettingsRepository $settings,
		private readonly int $batchSize,
		private readonly int $maxBodyBytes,
		private readonly \Psr\Log\LoggerInterface $logger,
		?\MailPilot\Repositories\PendingActionRepository $pendingActions = null,
		?\MailPilot\Repositories\AutoSortCorrectionRepository $autoSortCorrections = null,
		// Phase 3a (2026-05-18): Sender-Layer optional, damit aelteren Tests
		// die ohne Resolver konstruieren weiterhin gruen sind. In Prod sind
		// beide via Kernel verdrahtet — siehe Kernel::get(MailScoringService).
		private readonly ?SenderResolver $senderResolver = null,
		private readonly ?LookalikeDetector $lookalikeDetector = null,
	) {
		$this->promptBuilder = new ScoringPromptBuilder($settings, $corrections, $autoSortCorrections);
		$this->actionOwner = new ActionOwnerResolver(
			$claude, $redactor, $budget, $prompts, $settings,
			$this->promptBuilder, $logger, self::SCHEMA_CODE_REVISION,
		);
		$this->subLabelDiscoverer = new SubLabelDiscoverer(
			$settings, $subLabels, $autoSortRules, $logger, $pendingActions,
		);
	}

	/**
	 * @param array<string, mixed> $userProfile
	 * @param list<array<string, mixed>> $mails
	 * @param callable(int):void|null $onChunkDone
	 * @return list<array<string, mixed>>
	 */
	public function scoreBatch(string $tenantId, array $userProfile, array $mails, ?callable $onChunkDone = null): array
	{
		$scored   = [];
		$toClaude = [];

		$activePrompt = $this->prompts->getActive(self::PROMPT_KEY);
		$promptVersionTag = $this->prompts->cacheVersionTag(
			$activePrompt['key_name'],
			$activePrompt['version'] . '+code' . self::SCHEMA_CODE_REVISION,
		);

		$userId = (string)($userProfile['user_id'] ?? '');
		$subLabelMap = $userId !== ''
			? $this->subLabelDiscoverer->loadMap($tenantId, $userId)
			: [];

		// Mails per Cache klassifiziert brauchen separaten Mini-Call fuer
		// action_owner (PRD §5.1). Sammeln, nach Hauptloop in EINEM Batch.
		$cacheHits = [];

		foreach ($mails as $mail) {
			$hash = $this->contentHash($mail);
			$cached = $this->cache->get($tenantId, $hash, $promptVersionTag);
			if ($cached !== null) {
				$row = $this->buildScoreFromCache($tenantId, $userId, $mail, $cached, $subLabelMap, $promptVersionTag, $activePrompt['model']);
				$scored[] = $row;
				$cacheHits[] = ['mail' => $mail, 'score_index' => count($scored) - 1];
				if ($onChunkDone) { $onChunkDone(count($scored)); }
				continue;
			}

			$toClaude[] = ['mail' => $mail, 'hash' => $hash];
		}

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

				// claude_cache speichert NUR kontext-unabhaengige Felder.
				// action_owner/confidence per PRD §5.1 NICHT cacheable.
				$cacheable = $claudeResult;
				unset($cacheable['action_owner'], $cacheable['action_owner_confidence']);
				$this->cache->put($tenantId, $item['hash'], $promptVersionTag, $activePrompt['model'], $cacheable);
			}
			if ($onChunkDone) { $onChunkDone(count($scored)); }
		}

		if ($cacheHits !== []) {
			$this->actionOwner->resolveForCacheHits($userProfile, $cacheHits, $scored);
		}

		// Phase 3a: Sender-Bucket + Spoof-Erkennung. Mutiert $scored in-place
		// (haengt spoof_suspect-Flag an jede Row, wenn Resolver verfuegbar).
		$this->enrichScoresWithSender($tenantId, $mails, $scored);

		$this->scores->upsertMany($scored);
		return $scored;
	}

	/**
	 * Phase 3a — pro gescorter Mail den SenderResolver aufrufen (legt
	 * Bucket bei Bedarf an) und neue Sender durch den LookalikeDetector
	 * laufen lassen. Schreibt spoof_suspect zurueck in die Score-Row.
	 *
	 * Tolerant gegenueber nicht-injizierten Services (alte Tests) — dann
	 * No-op, spoof_suspect bleibt 0. Fehler werden geloggt und schlucken
	 * den Score-Pfad NICHT ab — Scoring ist wichtiger als Bucket-Hygiene.
	 *
	 * @param list<array<string,mixed>> $mails
	 * @param list<array<string,mixed>> $scored mutiert in-place
	 */
	private function enrichScoresWithSender(string $tenantId, array $mails, array &$scored): void
	{
		if ($this->senderResolver === null) {
			return;
		}
		// Index mails by id fuer schnellen Lookup pro Score-Row.
		$mailById = [];
		foreach ($mails as $m) {
			if (isset($m['id'])) {
				$mailById[(string)$m['id']] = $m;
			}
		}

		foreach ($scored as &$row) {
			$mid = (string)($row['mail_id'] ?? '');
			$mail = $mailById[$mid] ?? null;
			if ($mail === null) continue;
			$from = (string)($mail['from_email'] ?? '');
			if ($from === '') continue;

			try {
				$bucket = $this->senderResolver->resolve($tenantId, $from);
				if ($bucket === null) {
					continue;
				}
				// Wenn bucket frisch unknown ist, durch den LookalikeDetector
				// schicken — der flippt trust_status ggf. auf suspected_spoof.
				if ($bucket['trust_status'] === 'unknown' && $this->lookalikeDetector !== null) {
					$result = $this->lookalikeDetector->check(
						$tenantId,
						(string)$bucket['id'],
						(string)$bucket['sender_key'],
					);
					if ($result['spoof'] ?? false) {
						$row['spoof_suspect'] = 1;
						continue;
					}
				}
				$row['spoof_suspect'] = $bucket['trust_status'] === 'suspected_spoof' ? 1 : 0;
			} catch (\Throwable $e) {
				$this->logger->warning('scoring.sender_enrich_failed', [
					'mail_id' => $mid,
					'from'    => $from,
					'err'     => $e->getMessage(),
				]);
			}
		}
		unset($row);
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
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 * @param array{system_prompt:string, user_template:string, model:string, max_tokens:int, key_name:string, version:string, temperature:float} $activePrompt
	 * @return list<array<string, mixed>>
	 */
	private function callClaude(array $userProfile, array $mails, array $subLabelMap, array $activePrompt): array
	{
		$userEmail = strtolower((string)($userProfile['email'] ?? ''));
		$redacted = array_map(
			fn(array $m): array => $this->redactor->redactMail([
				'id'               => $m['id'],
				'from'             => $m['from_email'],
				'from_name'        => $m['from_name'] ?? '',
				'recipients'       => \MailPilot\Util\MailRecipients::build($m, $userEmail),
				'subject'          => $m['subject'] ?? '',
				'body_preview'     => substr((string)($m['body_text'] ?? $m['body_preview'] ?? ''), 0, $this->maxBodyBytes),
				'is_reply'         => (bool)($m['is_reply'] ?? false),
				'has_attachment'   => (bool)($m['has_attachment'] ?? false),
				'list_unsubscribe' => (bool)($m['list_unsubscribe'] ?? false),
				'received_at'      => $m['received_at'] ?? '',
			]),
			$mails,
		);

		$systemSegments = $this->promptBuilder->buildSystemSegments($activePrompt['system_prompt'], $userProfile, $subLabelMap);
		$user   = $this->promptBuilder->renderUserTemplate($activePrompt['user_template'], $userProfile, $redacted, $subLabelMap);
		$model  = $activePrompt['model'];

		// Output scales linearly: ~140 tokens per mail + 400 slack fuer
		// Opening/Closing JSON (sonst hartes Token-Cap mid-Object → unparseable).
		$maxTokens = max($activePrompt['max_tokens'], count($mails) * 160 + 400);

		$tenantId  = (string)($userProfile['tenant_id'] ?? '');
		$userId    = (string)($userProfile['user_id'] ?? '') ?: null;
		$mailboxId = (string)($mails[0]['mailbox_id'] ?? '') ?: null;
		$promptVersionTag = $this->prompts->cacheVersionTag(
			$activePrompt['key_name'],
			$activePrompt['version'] . '+code' . self::SCHEMA_CODE_REVISION,
		);

		// Pre-flight: refuse if we'd blow the daily budget.
		if ($tenantId !== '') {
			$gate = $this->budget->canSpend($tenantId, $userId, $maxTokens);
			if (!$gate['ok']) {
				$this->recordCall($tenantId, $userId, $mailboxId, [], 0, 'blocked', $gate['reason'], $promptVersionTag, $model);
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
			$this->recordCall($tenantId, $userId, $mailboxId, [], (int)((microtime(true) - $start) * 1000), 'error', $e->getMessage(), $promptVersionTag, $model);
			throw $e;
		}
		$this->recordCall($tenantId, $userId, $mailboxId, $response['usage'] ?? [], (int)((microtime(true) - $start) * 1000), 'success', null, $promptVersionTag, $model);

		$text = ScoringPromptBuilder::stripCodeFences(ClaudeClient::extractText($response));
		try {
			$json = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->error('scoring.invalid_json', ['excerpt' => substr($text, 0, 200)]);
			return [];
		}

		return $json['results'] ?? [];
	}

	/**
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 */
	private function buildScoreFromCache(string $tenantId, string $userId, array $mail, array $cached, array &$subLabelMap, string $promptVersionTag, string $model): array
	{
		$label = (string)($cached['label'] ?? 'auto');
		// action_owner ist per PRD §5.1 NICHT cacheable. Default 'unsure'
		// raus; ActionOwnerResolver::resolveForCacheHits ueberschreibt batch-weise.
		return [
			'id'              => Uuid::v4(),
			'tenant_id'       => $tenantId,
			'mail_id'         => $mail['id'],
			'label'           => $label,
			'sub_label'       => $this->subLabelDiscoverer->resolveOrDiscover(
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
	 * @param array<string,mixed> $userProfile Fuer Schema-Miss-Fallback noetig.
	 */
	private function buildScoreFromClaude(string $tenantId, string $userId, array $mail, array $result, array &$subLabelMap, string $promptVersionTag, string $model, array $userProfile): array
	{
		$label = $this->validateLabel((string)($result['label'] ?? 'auto'));
		$isNew = (bool)($result['sub_label_is_new'] ?? false);

		// action_owner-Source-Tracking: Claude liefert → source='ki';
		// fehlt → Single-Mail-Fallback mit source='fallback'.
		if (array_key_exists('action_owner', $result)) {
			$ao  = ActionOwnerResolver::validate((string)$result['action_owner']);
			$aoc = max(0, min(100, (int)($result['action_owner_confidence'] ?? 0)));
			$aos = 'ki';
		} else {
			[$ao, $aoc] = $this->actionOwner->computeFallback($mail, $userProfile);
			$aos = 'fallback';
		}

		return [
			'id'              => Uuid::v4(),
			'tenant_id'       => $tenantId,
			'mail_id'         => $mail['id'],
			'label'           => $label,
			'sub_label'       => $this->subLabelDiscoverer->resolveOrDiscover(
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

	private function validateLabel(string $label): string
	{
		$allowed = ['direct', 'action', 'cc', 'newsletter', 'auto', 'noise'];
		return in_array($label, $allowed, true) ? $label : 'auto';
	}

	private function truncate(string $s, int $max): string
	{
		return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
	}

	/**
	 * @param array<string, mixed> $usage anthropic usage block
	 */
	private function recordCall(
		string $tenantId,
		?string $userId,
		?string $mailboxId,
		array $usage,
		int $durationMs,
		string $status,
		?string $errorText,
		string $promptVersionTag,
		string $model,
	): void {
		if ($tenantId === '') return;
		$this->budget->recordUsage([
			'tenant_id'              => $tenantId,
			'user_id'                => $userId,
			'mailbox_id'             => $mailboxId,
			'mail_id'                => null,
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
