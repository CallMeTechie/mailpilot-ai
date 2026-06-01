<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Llm\LlmAllProvidersDownException;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\SummaryRepository;

/**
 * Mail-Zusammenfassung via Claude Opus.
 *
 * Prompt + Model + max_tokens kommen seit Phase B aus prompt_versions
 * (admin-editierbar via /admin/prompts). Der Service rendert das
 * Template via str_replace mit den Platzhaltern aus Migration 0013.
 */
final class MailSummaryService
{
	private const PROMPT_KEY = 'P-SUMMARY';

	public function __construct(
		private readonly LlmRouter $router,
		private readonly MailRepository $mails,
		private readonly SummaryRepository $summaries,
		private readonly RedactionService $redactor,
		private readonly BudgetService $budget,
		private readonly PromptRepository $prompts,
		private readonly ?ClaudeProvider $claudeFallback = null,
	) {
	}

	public function summarize(string $tenantId, string $mailId, string $userEmail, string $language, ?string $userId = null): string
	{
		$existing = $this->summaries->findByMailId($tenantId, $mailId);
		if ($existing !== null) {
			return $existing['summary_text'];
		}

		$mail = $this->mails->findById($tenantId, $mailId);
		if ($mail === null) {
			throw new \RuntimeException('mail_not_found');
		}

		$body = (string)($mail['body_text'] ?? $mail['body_preview'] ?? '');
		$body = \MailPilot\Util\Utf8::sanitize($this->redactor->redact($body));

		// Aktiven Prompt aus DB laden
		$activePrompt = $this->prompts->getActive(self::PROMPT_KEY);
		$promptVersionTag = $this->prompts->cacheVersionTag(
			$activePrompt['key_name'],
			$activePrompt['version'],
		);
		$model     = $activePrompt['model'];
		$maxTokens = $activePrompt['max_tokens'];

		$system = str_replace(
			['{{user_email}}', '{{user_language}}'],
			[$userEmail, $language],
			$activePrompt['system_prompt'],
		);
		$user = str_replace(
			['{{from_name}}', '{{from_email}}', '{{subject}}', '{{body}}'],
			[(string)($mail['from_name'] ?? ''), (string)($mail['from_email'] ?? ''), (string)($mail['subject'] ?? ''), $body],
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
				$resp      = $this->router->complete($req, 'summary');
				$text      = $resp->content;
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
				$text      = ClaudeClient::extractText($legacy);
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

		$this->summaries->create($tenantId, $mailId, $text, $promptVersionTag, $usedModel);
		return $text;
	}
}
