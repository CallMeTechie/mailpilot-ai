<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\DraftRepository;

final class ReplyDraftService
{
	public const PROMPT_VERSION = 'P-REPLY@1.0';

	public function __construct(
		private readonly ClaudeProvider $claude,
		private readonly MailRepository $mails,
		private readonly DraftRepository $drafts,
		private readonly RedactionService $redactor,
		private readonly BudgetService $budget,
		private readonly string $model,
	) {
	}

	public function draft(string $tenantId, string $mailId, ?string $instruction = null, ?string $userId = null): string
	{
		$mail = $this->mails->findById($tenantId, $mailId);
		if ($mail === null) {
			throw new \RuntimeException('mail_not_found');
		}

		// Sanitise before inlining into the prompt — invalid UTF-8 inside
		// $body propagates to AnthropicClient → json_encode → false →
		// empty HTTP payload → Claude returns nothing → draft = "" and the
		// add-in then renders the literal string "undefined".
		$body = \MailPilot\Util\Utf8::sanitize(
			$this->redactor->redact((string)($mail['body_text'] ?? '')),
		);

		$system = <<<TXT
Du entwirfst eine Antwort auf eine E-Mail. Der Nutzer reviewt und sendet selbst.

Regeln:
- Ton aus dem Thread ableiten (Du/Sie, formal/locker)
- Gleiche Sprache wie die eingehende Mail
- Keine erfundenen Zusagen, Termine oder Zahlen
- Wenn Entscheidung ansteht, die DER NUTZER treffen muss: Platzhalter [ENTSCHEIDUNG]
- Grußformel passend zum Thread
- Keine KI-Floskeln
- Max 150 Wörter

Output: Nur der Mail-Body. Keine Subject-Zeile, kein Markdown, keine Erklärung.
TXT;

		$instr = $instruction !== null ? "\n\nUSER_INSTRUCTION: {$instruction}" : '';
		$user = "ORIGINAL_MAIL:\nFrom: {$mail['from_name']} <{$mail['from_email']}>\n"
			. "Subject: {$mail['subject']}\n---\n{$body}{$instr}\n\nEntwirf die Antwort.";

		$mailboxId = (string)($mail['mailbox_id'] ?? '') ?: null;
		$gate = $this->budget->canSpend($tenantId, $userId, 800);
		if (!$gate['ok']) {
			$this->budget->recordUsage([
				'tenant_id' => $tenantId, 'user_id' => $userId,
				'mailbox_id' => $mailboxId, 'mail_id' => $mailId,
				'prompt_version' => self::PROMPT_VERSION, 'model' => $this->model,
				'input_tokens' => 0, 'output_tokens' => 0,
				'cache_read_tokens' => 0, 'cache_creation_tokens' => 0,
				'duration_ms' => 0, 'status' => 'blocked',
				'error_text' => (string)$gate['reason'],
			]);
			throw new BudgetExceededException((string)$gate['scope']);
		}

		$start = microtime(true);
		try {
			// Claude 4.x rejects "temperature" — let the model default.
			$resp = $this->claude->messages([
				'model'      => $this->model,
				'max_tokens' => 800,
				'system'     => $system,
				'messages'   => [['role' => 'user', 'content' => $user]],
			]);
		} catch (\Throwable $e) {
			$this->budget->recordUsage([
				'tenant_id' => $tenantId, 'user_id' => $userId,
				'mailbox_id' => (string)($mail['mailbox_id'] ?? '') ?: null, 'mail_id' => $mailId,
				'prompt_version' => self::PROMPT_VERSION, 'model' => $this->model,
				'input_tokens' => 0, 'output_tokens' => 0,
				'cache_read_tokens' => 0, 'cache_creation_tokens' => 0,
				'duration_ms' => (int)((microtime(true) - $start) * 1000),
				'status' => 'error', 'error_text' => substr($e->getMessage(), 0, 500),
			]);
			throw $e;
		}
		$u = $resp['usage'] ?? [];
		$this->budget->recordUsage([
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'mailbox_id' => (string)($mail['mailbox_id'] ?? '') ?: null, 'mail_id' => $mailId,
			'prompt_version' => self::PROMPT_VERSION, 'model' => $this->model,
			'input_tokens'          => (int)($u['input_tokens']                ?? 0),
			'output_tokens'         => (int)($u['output_tokens']               ?? 0),
			'cache_read_tokens'     => (int)($u['cache_read_input_tokens']     ?? 0),
			'cache_creation_tokens' => (int)($u['cache_creation_input_tokens'] ?? 0),
			'duration_ms' => (int)((microtime(true) - $start) * 1000),
			'status' => 'success', 'error_text' => null,
		]);

		$draft = ClaudeClient::extractText($resp);
		$this->drafts->create($tenantId, $mailId, $draft, $instruction, self::PROMPT_VERSION, $this->model);
		return $draft;
	}
}
