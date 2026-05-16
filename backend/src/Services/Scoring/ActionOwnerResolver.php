<?php
declare(strict_types=1);

namespace MailPilot\Services\Scoring;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\BudgetService;
use MailPilot\Services\RedactionService;
use MailPilot\Util\MailRecipients;
use Psr\Log\LoggerInterface;

/**
 * Action-Owner-Resolution fuer Cache-Hit-Mails (Sprint 6a §5.1).
 *
 * Bei Cache-Hit kommt der Score ohne action_owner aus dem claude_cache;
 * dieser Resolver bestimmt das Feld nachtraeglich:
 *
 *   1. Pre-Filter: Mails ohne User-Signal + group-Prefix-From → Fallback
 *      direkt (spart Tokens fuer Newsletter-lastige Mailboxen ~30-40%)
 *   2. Mini-Call an Claude (eigene Prompt-Version P-SCORE-MINI) fuer
 *      ambiguose Mails — teilt sich Prompt-Cache-Prefix mit Score-Call
 *   3. Bei Mini-Call-Failure oder partial result → 3-Stufen-Fallback:
 *        - User-im-To, kein Name-Collision  → user/40
 *        - Verteiler-From oder ≥3 To-Andere → group/60
 *        - Sonst                            → unsure/0
 */
final class ActionOwnerResolver
{
	public function __construct(
		private readonly ClaudeProvider $claude,
		private readonly RedactionService $redactor,
		private readonly BudgetService $budget,
		private readonly PromptRepository $prompts,
		private readonly SettingsRepository $settings,
		private readonly ScoringPromptBuilder $promptBuilder,
		private readonly LoggerInterface $logger,
		private readonly int $schemaCodeRevision,
	) {
	}

	/**
	 * Eintrittspunkt fuer Cache-Hits aus MailScoringService::scoreBatch.
	 * Modifiziert $scored in-place mit action_owner/confidence/source.
	 *
	 * @param array<string,mixed> $userProfile
	 * @param list<array{mail:array<string,mixed>,score_index:int}> $cacheHits
	 * @param list<array<string,mixed>> $scored In-place modified
	 */
	public function resolveForCacheHits(array $userProfile, array $cacheHits, array &$scored): void
	{
		$userEmail = strtolower((string)($userProfile['email'] ?? ''));

		$miniHits = [];
		$fallbackHits = [];
		foreach ($cacheHits as $hit) {
			$mail = $hit['mail'];
			$rec  = MailRecipients::build($mail, $userEmail);
			$fromLocal = strtolower(strstr((string)($mail['from_email'] ?? ''), '@', true) ?: '');
			$groupFrom = in_array($fromLocal, ['info','support','no-reply','noreply','newsletter','team','service','notifications','kontakt'], true);
			$noUserSignal = $rec === [] || !array_filter($rec, static fn($r) => !empty($r['is_user']));
			if ($noUserSignal && $groupFrom) {
				$fallbackHits[] = $hit;
			} else {
				$miniHits[] = $hit;
			}
		}

		if ($fallbackHits !== []) {
			$this->applyFallback($userProfile, $fallbackHits, $scored);
			$this->logger->info('action_owner.prefilter_to_fallback', ['n' => count($fallbackHits)]);
		}

		if ($miniHits === []) {
			return;
		}

		$payload = [];
		foreach ($miniHits as $hit) {
			$mail = $hit['mail'];
			$body = (string)($mail['body_text'] ?? $mail['body_preview'] ?? '');
			$payload[] = [
				'mail_id'        => (string)$mail['id'],
				'recipients'     => MailRecipients::build($mail, $userEmail),
				'anrede_snippet' => substr($this->redactor->redact($body), 0, 200),
				'cached_label'   => $scored[$hit['score_index']]['label'] ?? 'auto',
			];
		}

		try {
			$results = $this->callMiniActionOwner($userProfile, $payload);
		} catch (\Throwable $e) {
			$this->logger->warning('action_owner.mini_call_failed', ['err' => $e->getMessage(), 'n' => count($payload)]);
			$this->applyFallback($userProfile, $miniHits, $scored);
			return;
		}

		$byMailId = [];
		foreach ($results as $r) {
			if (is_array($r) && isset($r['mail_id'])) {
				$byMailId[(string)$r['mail_id']] = $r;
			}
		}

		$missing = [];
		foreach ($miniHits as $hit) {
			$mailId = (string)$hit['mail']['id'];
			$r = $byMailId[$mailId] ?? null;
			if ($r === null) {
				$missing[] = $hit;
				continue;
			}
			$scored[$hit['score_index']]['action_owner']            = self::validate((string)($r['action_owner'] ?? 'unsure'));
			$scored[$hit['score_index']]['action_owner_confidence'] = max(0, min(100, (int)($r['confidence'] ?? 0)));
			$scored[$hit['score_index']]['action_owner_source']     = 'ki';
		}

		if ($missing !== []) {
			$this->logger->info('action_owner.partial_result', ['missing' => count($missing)]);
			$this->applyFallback($userProfile, $missing, $scored);
		}
	}

	/**
	 * 3-Stufen-Fallback fuer eine einzelne Mail (PRD §5.1). Wird auch vom
	 * Fresh-Path-Schema-Miss in MailScoringService::buildScoreFromClaude
	 * genutzt, wenn Claude action_owner nicht liefert.
	 *
	 *   1. User-im-To, kein Empfaenger mit kollidierendem Vornamen → user/40
	 *   2. Verteiler-From-Prefix ODER ≥3 weitere im To             → group/60
	 *   3. Alles andere                                            → unsure/0
	 *
	 * @param array<string,mixed> $mail
	 * @param array<string,mixed> $userProfile
	 * @return array{0:string,1:int}
	 */
	public function computeFallback(array $mail, array $userProfile): array
	{
		$userEmail = strtolower((string)($userProfile['email'] ?? ''));
		$aliases = array_map(
			static fn($a) => strtolower((string)$a),
			is_array($userProfile['aliases'] ?? null) ? $userProfile['aliases'] : [],
		);
		$groupPrefixes = ['info', 'support', 'no-reply', 'noreply', 'newsletter', 'team', 'service', 'notifications', 'kontakt'];

		$recipients = MailRecipients::build($mail, $userEmail);
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
	 * Erlaubte action_owner-Werte. KI-Output coerced zu 'unsure'.
	 */
	public static function validate(string $v): string
	{
		$allowed = ['user', 'other', 'group', 'unsure'];
		return in_array($v, $allowed, true) ? $v : 'unsure';
	}

	/**
	 * Schreibt fallback fuer eine Liste hits in $scored in-place.
	 *
	 * @param array<string,mixed> $userProfile
	 * @param list<array{mail:array<string,mixed>,score_index:int}> $hits
	 * @param list<array<string,mixed>> $scored
	 */
	private function applyFallback(array $userProfile, array $hits, array &$scored): void
	{
		foreach ($hits as $hit) {
			[$owner, $conf] = $this->computeFallback($hit['mail'], $userProfile);
			$scored[$hit['score_index']]['action_owner']            = $owner;
			$scored[$hit['score_index']]['action_owner_confidence'] = $conf;
			$scored[$hit['score_index']]['action_owner_source']     = 'fallback';
		}
	}

	/**
	 * @param array<string,mixed> $userProfile
	 * @param list<array<string,mixed>> $payload
	 * @return list<array<string,mixed>>
	 */
	private function callMiniActionOwner(array $userProfile, array $payload): array
	{
		$activeMini = $this->prompts->getActive('P-SCORE-MINI');
		$rules = "\n" . str_replace('\\n', "\n", $this->settings->getString(
			'prompt.action_owner_rules',
			'ACTION_OWNER_RULES: bei Anrede-Ambiguität immer action_owner=unsure.',
		)) . "\n";

		$systemSegments = $this->promptBuilder->buildSystemSegments(
			$activeMini['system_prompt'],
			$userProfile,
		);

		$user = str_replace(
			['{{action_owner_rules}}', '{{mails_json}}'],
			[$rules, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)],
			$activeMini['user_template'],
		);

		$tenantId  = (string)($userProfile['tenant_id'] ?? '');
		$userId    = (string)($userProfile['user_id']   ?? '') ?: null;
		$model     = $activeMini['model'];
		$promptTag = $this->prompts->cacheVersionTag(
			$activeMini['key_name'],
			$activeMini['version'] . '+code' . $this->schemaCodeRevision,
		);
		$maxTokens = max((int)$activeMini['max_tokens'], count($payload) * 40 + 200);

		$start = microtime(true);
		try {
			$response = $this->claude->messages([
				'model'      => $model,
				'max_tokens' => $maxTokens,
				'system'     => $systemSegments,
				'messages'   => [['role' => 'user', 'content' => $user]],
			]);
		} catch (\Throwable $e) {
			$this->recordCall($tenantId, $userId, [], (int)((microtime(true) - $start) * 1000), 'error', $e->getMessage(), $promptTag, $model);
			throw $e;
		}
		$this->recordCall($tenantId, $userId, $response['usage'] ?? [], (int)((microtime(true) - $start) * 1000), 'success', null, $promptTag, $model);

		$text = ScoringPromptBuilder::stripCodeFences(ClaudeClient::extractText($response));
		$json = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
		$out = $json['results'] ?? [];
		return is_array($out) ? $out : [];
	}

	/**
	 * @param array<string, mixed> $usage anthropic usage block
	 */
	private function recordCall(
		string $tenantId,
		?string $userId,
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
			'mailbox_id'             => null,
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
