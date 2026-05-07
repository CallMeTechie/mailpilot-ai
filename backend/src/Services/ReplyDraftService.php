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
		private readonly string $model,
	) {
	}

	public function draft(string $tenantId, string $mailId, ?string $instruction = null): string
	{
		$mail = $this->mails->findById($tenantId, $mailId);
		if ($mail === null) {
			throw new \RuntimeException('mail_not_found');
		}

		$body = $this->redactor->redact((string)($mail['body_text'] ?? ''));

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

		$resp = $this->claude->messages([
			'model'       => $this->model,
			'max_tokens'  => 800,
			'temperature' => 0.4,
			'system'      => $system,
			'messages'    => [['role' => 'user', 'content' => $user]],
		]);

		$draft = ClaudeClient::extractText($resp);
		$this->drafts->create($tenantId, $mailId, $draft, $instruction, self::PROMPT_VERSION, $this->model);
		return $draft;
	}
}
