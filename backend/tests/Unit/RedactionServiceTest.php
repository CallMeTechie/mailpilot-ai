<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Services\RedactionService;
use MailPilot\Tests\TestCase;

final class RedactionServiceTest extends TestCase
{
	public function testIbanGermanIsRedacted(): void
	{
		$r = new RedactionService();
		$result = $r->redact('Bitte überweise auf DE89 3704 0044 0532 0130 00');
		$this->assertStringContainsString('[IBAN-REDACTED]', $result);
		$this->assertStringNotContainsString('3704', $result);
	}

	public function testIbanInternationalIsRedacted(): void
	{
		$r = new RedactionService();
		$result = $r->redact('IBAN: AT611904300234573201');
		$this->assertStringContainsString('[IBAN-REDACTED]', $result);
	}

	public function testCreditCardIsRedacted(): void
	{
		$r = new RedactionService();
		$result = $r->redact('Karte: 4111 1111 1111 1111');
		$this->assertStringContainsString('[CC-REDACTED]', $result);
		$this->assertStringNotContainsString('4111', $result);
	}

	public function testSsnIsRedacted(): void
	{
		$r = new RedactionService();
		$result = $r->redact('SSN 123-45-6789');
		$this->assertStringContainsString('[SSN-REDACTED]', $result);
	}

	public function testNormalTextUntouched(): void
	{
		$r = new RedactionService();
		$input = 'Hallo Marc, kannst du morgen um 10 Uhr?';
		$this->assertSame($input, $r->redact($input));
	}

	public function testUserPatternApplied(): void
	{
		$r = new RedactionService([
			['pattern' => 'CUST-\d{5}', 'description' => 'Customer ID'],
		]);
		$result = $r->redact('Kunde CUST-12345 hat Bestellung');
		$this->assertStringContainsString('[REDACTED]', $result);
		$this->assertStringNotContainsString('12345', $result);
	}

	public function testRedactMailAppliesToMultipleFields(): void
	{
		$r = new RedactionService();
		$mail = [
			'subject'      => 'Rechnung DE89 3704 0044 0532 0130 00',
			'body_preview' => 'Karte 4111 1111 1111 1111 gespeichert',
			'body_text'    => 'Beides: DE89 3704 0044 0532 0130 00 und 4111 1111 1111 1111',
			'from_email'   => 'alice@example.com',
		];
		$result = $r->redactMail($mail);
		$this->assertStringContainsString('[IBAN-REDACTED]', $result['subject']);
		$this->assertStringContainsString('[CC-REDACTED]', $result['body_preview']);
		$this->assertStringContainsString('[IBAN-REDACTED]', $result['body_text']);
		$this->assertStringContainsString('[CC-REDACTED]', $result['body_text']);
		$this->assertSame('alice@example.com', $result['from_email'], 'from_email must not be redacted');
	}

	public function testInvalidUserPatternIsSkippedSafely(): void
	{
		$r = new RedactionService([
			['pattern' => '(invalid[unclosed', 'description' => 'Broken'],
		]);
		$result = $r->redact('Some text here');
		$this->assertSame('Some text here', $result);
	}

	// Sprint 6g — DA-R2 Finding 1: Reasoning-Redaction mit Namensliste.

	public function testRedactReasoningRunsBuiltinPatterns(): void
	{
		$r = new RedactionService();
		$out = $r->redactReasoning('Bei IBAN DE89 3704 0044 0532 0130 00 muss verschoben werden');
		$this->assertStringContainsString('[IBAN-REDACTED]', $out);
	}

	public function testRedactReasoningReplacesNamesFromList(): void
	{
		$r = new RedactionService();
		$out = $r->redactReasoning(
			'Stephan Müller hat das mit Acme abgestimmt',
			['Stephan Müller', 'Acme'],
		);
		$this->assertStringContainsString('[NAME-REDACTED]', $out);
		$this->assertStringNotContainsString('Stephan Müller', $out);
		$this->assertStringNotContainsString(' Acme ', $out);
	}

	public function testRedactReasoningEmptyNameListDoesNothingExtra(): void
	{
		$r = new RedactionService();
		$plain = 'kein PII, keine Namen, nur normaler Text';
		$this->assertSame($plain, $r->redactReasoning($plain, []));
	}

	public function testReduceFromToDomain(): void
	{
		$r = new RedactionService();
		$this->assertSame('*@example.com',     $r->reduceFromToDomain('alice@example.com'));
		$this->assertSame('*@sub.example.com', $r->reduceFromToDomain('Bob.Vorname+filter@sub.example.com'));
		$this->assertSame('[FROM-REDACTED]',   $r->reduceFromToDomain(''));
		$this->assertSame('[FROM-REDACTED]',   $r->reduceFromToDomain('not-an-email'));
		$this->assertSame('[FROM-REDACTED]',   $r->reduceFromToDomain('trailing@'));
	}
}
