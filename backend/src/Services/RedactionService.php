<?php
declare(strict_types=1);

namespace MailPilot\Services;

/**
 * Redacts PII from mail content before it is sent to external AI APIs.
 *
 * Ordering matters: built-in high-confidence patterns first (IBAN, credit card),
 * then tenant/user-specific patterns.
 */
final class RedactionService
{
	/** @var list<array{pattern:string, replacement:string, label:string}> */
	private const BUILTIN = [
		['pattern' => '/\bDE\d{2}[ ]?(?:\d{4}[ ]?){4}\d{2}\b/',         'replacement' => '[IBAN-REDACTED]',  'label' => 'iban_de'],
		['pattern' => '/\b[A-Z]{2}\d{2}[A-Z0-9 ]{11,30}\b/',             'replacement' => '[IBAN-REDACTED]',  'label' => 'iban_intl'],
		['pattern' => '/\b(?:\d[ -]?){13,19}\b/',                        'replacement' => '[CC-REDACTED]',    'label' => 'cc'],
		['pattern' => '/\b\d{3}-\d{2}-\d{4}\b/',                         'replacement' => '[SSN-REDACTED]',   'label' => 'ssn'],
	];

	/**
	 * @param list<array{pattern:string, description?:string}> $userPatterns
	 */
	public function __construct(
		private readonly array $userPatterns = [],
	) {
	}

	public function redact(string $text): string
	{
		$out = $text;
		foreach (self::BUILTIN as $rule) {
			$result = @preg_replace($rule['pattern'], $rule['replacement'], $out);
			if (is_string($result)) {
				$out = $result;
			}
		}
		foreach ($this->userPatterns as $rule) {
			// User patterns are stored WITHOUT delimiters; we wrap them safely.
			$p = '#' . str_replace('#', '\#', $rule['pattern']) . '#u';
			$result = @preg_replace($p, '[REDACTED]', $out);
			if (is_string($result)) {
				$out = $result;
			}
		}
		return $out;
	}

	/**
	 * Apply redaction to all string fields of an array shallowly.
	 * Useful for redacting a mail dict before passing to Claude.
	 *
	 * @param array<string, mixed> $mail
	 * @return array<string, mixed>
	 */
	public function redactMail(array $mail): array
	{
		foreach (['subject', 'body_preview', 'body_text'] as $field) {
			if (isset($mail[$field]) && is_string($mail[$field])) {
				$mail[$field] = $this->redact($mail[$field]);
			}
		}
		return $mail;
	}
}
