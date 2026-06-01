<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Llm\LlmAllProvidersDownException;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Repositories\DraftRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\RedactionRepository;

/**
 * Reply-Draft via Claude Opus. Prompt + Model + max_tokens kommen
 * seit Phase B aus prompt_versions (admin-editierbar via
 * /admin/prompts).
 */
final class ReplyDraftService
{
	private const PROMPT_KEY = 'P-REPLY';

	public function __construct(
		private readonly LlmRouter $router,
		private readonly MailRepository $mails,
		private readonly DraftRepository $drafts,
		private readonly RedactionService $redactor,
		private readonly BudgetService $budget,
		private readonly PromptRepository $prompts,
		// Sprint 6f DA-R2 Finding 3: optional, für user-spezifische redaction_rules.
		private readonly ?RedactionRepository $redactionRules = null,
		// B7: Safety-Net wenn die Router-Chain leer/kaputt ist → Legacy-Direct-Anthropic.
		private readonly ?ClaudeProvider $claudeFallback = null,
	) {
	}

	public function draft(
		string  $tenantId,
		string  $mailId,
		?string $instruction = null,
		?string $userId = null,
		string  $createdBy = 'user',
	): string {
		$mail = $this->mails->findById($tenantId, $mailId);
		if ($mail === null) {
			throw new \RuntimeException('mail_not_found');
		}

		// Sprint 6f DA-R2 Finding 3: scope Redactor pro Call mit den
		// user-spezifischen Patterns aus redaction_rules. Fallback auf
		// den global-injected Redactor wenn keine User-Patterns greifbar.
		$scopedRedactor = $this->redactor;
		if ($this->redactionRules !== null && $userId !== null) {
			$userPatterns = $this->redactionRules->enabledPatterns($tenantId, $userId);
			if ($userPatterns !== []) {
				$scopedRedactor = new RedactionService($userPatterns);
			}
		}

		$body = \MailPilot\Util\Utf8::sanitize(
			$scopedRedactor->redact((string)($mail['body_text'] ?? '')),
		);

		$activePrompt = $this->prompts->getActive(self::PROMPT_KEY);
		$promptVersionTag = $this->prompts->cacheVersionTag(
			$activePrompt['key_name'],
			$activePrompt['version'],
		);
		$model     = $activePrompt['model'];
		$maxTokens = $activePrompt['max_tokens'];

		$instructionBlock = $instruction !== null && $instruction !== ''
			? "\n\nUSER_INSTRUCTION: " . $instruction
			: '';

		$system = $activePrompt['system_prompt'];
		$user = str_replace(
			['{{from_name}}', '{{from_email}}', '{{subject}}', '{{body}}', '{{instruction_block}}'],
			[
				(string)($mail['from_name'] ?? ''),
				(string)($mail['from_email'] ?? ''),
				(string)($mail['subject'] ?? ''),
				$body,
				$instructionBlock,
			],
			$activePrompt['user_template'],
		);

		$mailboxId = (string)($mail['mailbox_id'] ?? '') ?: null;
		$gate = $this->budget->canSpend($tenantId, $userId, $maxTokens);
		if (!$gate['ok']) {
			$this->budget->recordUsage([
				'tenant_id' => $tenantId, 'user_id' => $userId,
				'mailbox_id' => $mailboxId, 'mail_id' => $mailId,
				'prompt_version' => $promptVersionTag, 'model' => $model,
				'input_tokens' => 0, 'output_tokens' => 0,
				'cache_read_tokens' => 0, 'cache_creation_tokens' => 0,
				'duration_ms' => 0, 'status' => 'blocked',
				'error_text' => (string)$gate['reason'],
			]);
			throw new BudgetExceededException((string)$gate['scope']);
		}

		$start = microtime(true);
		try {
			try {
				$req = new NormalizedRequest(
					systemPrompt:   $system,
					messages:       [['role' => 'user', 'content' => $user]],
					maxTokens:      $maxTokens,
					temperature:    0.3,
					modelHint:      '',     // Router resolved Modell+Effort pro Rolle
					responseFormat: null,
					cacheSegments:  [],     // wie bisher: KEIN Caching
				);
				$resp      = $this->router->complete($req, 'draft');
				$rawText   = $resp->content;
				$usageRaw  = $resp->usage['raw'] ?? [];
				$usedModel = $resp->modelId;
			} catch (LlmAllProvidersDownException $e) {
				// Safety-Net: leere/kaputte Chain → Legacy-Direct-Anthropic.
				if ($this->claudeFallback === null) {
					throw $e;
				}
				$legacy    = $this->claudeFallback->messages([
					'model' => $model, 'max_tokens' => $maxTokens,
					'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]],
				]);
				$rawText   = ClaudeClient::extractText($legacy);
				$usageRaw  = $legacy['usage'] ?? [];
				$usedModel = $model;
			}
		} catch (\Throwable $e) {
			$this->budget->recordUsage([
				'tenant_id' => $tenantId, 'user_id' => $userId,
				'mailbox_id' => $mailboxId, 'mail_id' => $mailId,
				'prompt_version' => $promptVersionTag, 'model' => $model,
				'input_tokens' => 0, 'output_tokens' => 0,
				'cache_read_tokens' => 0, 'cache_creation_tokens' => 0,
				'duration_ms' => (int)((microtime(true) - $start) * 1000),
				'status' => 'error', 'error_text' => substr($e->getMessage(), 0, 500),
			]);
			throw $e;
		}
		$u = $usageRaw;
		$this->budget->recordUsage([
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'mailbox_id' => $mailboxId, 'mail_id' => $mailId,
			'prompt_version' => $promptVersionTag, 'model' => $usedModel,
			'input_tokens'          => (int)($u['input_tokens']                ?? 0),
			'output_tokens'         => (int)($u['output_tokens']               ?? 0),
			'cache_read_tokens'     => (int)($u['cache_read_input_tokens']     ?? 0),
			'cache_creation_tokens' => (int)($u['cache_creation_input_tokens'] ?? 0),
			'duration_ms' => (int)((microtime(true) - $start) * 1000),
			'status' => 'success', 'error_text' => null,
		]);

		// Sprint 6f DA-R1 Finding 3: Output-PII filtern. Opus halluziniert
		// gelegentlich IBANs/CC im Antwort-Text („wie besprochen, meine
		// IBAN ist DE89..."). Vor der DB-Persistierung redacten, sonst
		// landet das in /me/export. Trade-off: legitime Self-IBANs werden
		// auch verschluckt — User muss von Hand reinschreiben.
		$draft = $scopedRedactor->redact($rawText);
		$this->drafts->create(
			$tenantId, $mailId, $draft, $instruction, $promptVersionTag, $usedModel,
			$userId,
			$mail['conversation_id'] !== null ? (string)$mail['conversation_id'] : null,
			$createdBy,
		);
		return $draft;
	}
}
