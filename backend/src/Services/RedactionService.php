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

		// Phase-H6 — Prompt-Injection-Defense. Eine boesartige Mail koennte
		// versuchen Claude's System-Prompt zu ueberschreiben. Output-
		// Validation (validateLabel, sub_label-Whitelist, priority-Clamp)
		// faengt die meisten Wirkungen ab, aber redacten ist defense-in-
		// depth und macht Log-Forensik einfacher (geredactete Strings
		// sind auf einen Blick als Versuch erkennbar).
		//
		// Patterns auf Englisch + Deutsch. Multi-line (s-flag entfaellt,
		// weil wir auf Wort-Folge matchen, nicht ueber Zeilen). Case-
		// insensitive (i) + Unicode (u).
		['pattern' => '/ignore\s+(all\s+)?(previous|prior|preceding|above)\s+instructions?/iu',  'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_ignore'],
		['pattern' => '/(forget|disregard|override|nullify)\s+(all\s+)?(previous|prior|preceding|above)/iu', 'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_forget'],
		['pattern' => '/(system|developer)\s*[:>]?\s*(prompt|instructions?|message)/iu', 'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_system'],
		['pattern' => '/you\s+are\s+now\s+(a|an|the)?\s*[a-z]+/iu', 'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_role_swap'],
		['pattern' => '/\b(jailbreak|DAN\s+mode|developer\s+mode|admin\s+mode)\b/iu', 'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_jailbreak'],
		// Deutsch
		['pattern' => '/ignoriere\s+(alle\s+)?(vorherigen?|bisherigen?|obigen?)\s+(anweisungen?|instruktionen?)/iu', 'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_ignore_de'],
		['pattern' => '/vergiss\s+(alle\s+)?(vorherigen?|bisherigen?|obigen?)/iu', 'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_forget_de'],
		['pattern' => '/du\s+bist\s+(jetzt|nun)\s+(ein|eine)/iu', 'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_role_swap_de'],
		// XML/Token-Tags die manche LLMs als Steuersequenzen interpretieren
		['pattern' => '/<\|?(im_start|im_end|system|assistant|user|tool_use|tool_result)\|?>/i', 'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_tokens'],
		['pattern' => '/<\/?(instructions?|prompt|system)>/iu', 'replacement' => '[INJECTION-REDACTED]', 'label' => 'pi_xml_tags'],
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

	/**
	 * Sprint 6g (DA-R2 Finding 1) — Dedizierte Redaction für Korrektur-
	 * Begründungen. Freitext geht durch IBAN/CC/SSN + User-Patterns
	 * (wie redact()) PLUS eine Namensliste, die der Admin im System-
	 * Settings-Panel pflegt (`reasoning_pii_names`).
	 *
	 * Namensmatch ist case-insensitive und an Wortgrenzen (`\b`) gebunden,
	 * damit „Stephan Müller" nicht „stephan@example.com" trifft.
	 *
	 * @param list<string> $nameList Optional zusätzliche Eigennamen.
	 */
	public function redactReasoning(string $reasoning, array $nameList = []): string
	{
		$out = $this->redact($reasoning);
		foreach ($nameList as $name) {
			if (!is_string($name) || trim($name) === '') {
				continue;
			}
			$pattern = '#\b' . preg_quote(trim($name), '#') . '\b#iu';
			$result  = @preg_replace($pattern, '[NAME-REDACTED]', $out);
			if (is_string($result)) {
				$out = $result;
			}
		}
		return $out;
	}

	/**
	 * Reduziert eine E-Mail-Adresse auf `*@domain.tld`. Schützt
	 * Lokalanteile (Vorname.Nachname, Identifier) im `from`-Feld,
	 * das zu Claude wandert — für die Rule-Inference reicht die Domain.
	 *
	 * Liefert `[FROM-REDACTED]` wenn das Argument kein @ enthält oder
	 * leer ist.
	 */
	public function reduceFromToDomain(string $email): string
	{
		$email = trim($email);
		if ($email === '') {
			return '[FROM-REDACTED]';
		}
		$at = strrpos($email, '@');
		if ($at === false || $at === strlen($email) - 1) {
			return '[FROM-REDACTED]';
		}
		return '*@' . substr($email, $at + 1);
	}
}
