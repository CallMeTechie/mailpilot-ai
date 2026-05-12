<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Claude\ClaudeProvider;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\SummaryRepository;

final class MailSummaryService
{
	public const PROMPT_VERSION = 'P-SUMMARY@1.0';

	public function __construct(
		private readonly ClaudeProvider $claude,
		private readonly MailRepository $mails,
		private readonly SummaryRepository $summaries,
		private readonly RedactionService $redactor,
		private readonly BudgetService $budget,
		private readonly string $model,
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
		// Strip stray non-UTF-8 bytes before they reach the JSON encoder
		// inside AnthropicClient — otherwise the encoder returns false and
		// the API request goes out with an empty body.
		$body = \MailPilot\Util\Utf8::sanitize($this->redactor->redact($body));

		$system = <<<TXT
Du fasst eine E-Mail für {$userEmail} zusammen. Deine Zusammenfassung ersetzt
das Lesen der Mail. Struktur:

**Worum geht's:** Ein Satz.
**Was wird erwartet:** Ein Satz — was soll der Nutzer tun? Oder "Nichts, nur Information."
**Deadline:** Datum/Zeit falls genannt, sonst "keine".
**Kontext:** Ein Satz zum Thread-Kontext falls Reply, sonst weglassen.

Antworte auf {$language}. Kein Markdown außer den Labels, klare Sätze. Max 120 Wörter.
TXT;

		$user = "From: {$mail['from_name']} <{$mail['from_email']}>\n"
			. "Subject: {$mail['subject']}\n"
			. "---\n" . $body;

		$start = microtime(true);
		try {
			// Claude 4.x rejects "temperature" with HTTP 400.
			$resp = $this->claude->messages([
				'model'      => $this->model,
				'max_tokens' => 400,
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

		$text = ClaudeClient::extractText($resp);
		$this->summaries->create($tenantId, $mailId, $text, self::PROMPT_VERSION, $this->model);
		return $text;
	}
}
