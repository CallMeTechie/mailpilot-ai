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
		private readonly string $model,
	) {
	}

	public function summarize(string $tenantId, string $mailId, string $userEmail, string $language): string
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
		$body = $this->redactor->redact($body);

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

		$resp = $this->claude->messages([
			'model'       => $this->model,
			'max_tokens'  => 400,
			'temperature' => 0.2,
			'system'      => $system,
			'messages'    => [['role' => 'user', 'content' => $user]],
		]);

		$text = ClaudeClient::extractText($resp);
		$this->summaries->create($tenantId, $mailId, $text, self::PROMPT_VERSION, $this->model);
		return $text;
	}
}
