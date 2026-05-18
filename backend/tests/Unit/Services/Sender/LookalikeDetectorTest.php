<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Services\Sender;

use MailPilot\Services\Sender\LookalikeDetector;
use PHPUnit\Framework\TestCase;

/**
 * Pure heuristic tests fuer detectReason() — kein DB-Zugriff.
 *
 * Marc-Beispiele (2026-05-18):
 *   ebay-mails    vs ebay   → spoof (hyphen-pattern)
 *   amazon-email  vs amazon → spoof (hyphen-pattern)
 *   amaz0n        vs amazon → spoof (levenshtein 1 + prefix 4)
 *   etsy          vs ebay   → kein spoof (distanz 3 aber prefix 1)
 *   ebay          vs ebay   → kein spoof (identisch)
 */
final class LookalikeDetectorTest extends TestCase
{
	public function testHyphenPrefixIsSpoof(): void
	{
		$reason = LookalikeDetector::detectReason('amazon-email', 'amazon');
		$this->assertNotNull($reason);
		$this->assertStringContainsString('hyphen_token_of:amazon', $reason);
	}

	public function testHyphenSuffixIsSpoof(): void
	{
		$reason = LookalikeDetector::detectReason('login-amazon', 'amazon');
		$this->assertNotNull($reason);
		$this->assertStringContainsString('hyphen_token_of:amazon', $reason);
	}

	public function testEbayMailsIsSpoofOfEbay(): void
	{
		$reason = LookalikeDetector::detectReason('ebay-mails', 'ebay');
		$this->assertNotNull($reason, 'ebay-mails muss als Spoof von ebay erkannt werden (Marc-Beispiel)');
	}

	public function testLevenshteinTypoSpoof(): void
	{
		// amaz0n ↔ amazon: Distanz 1, common prefix 4 → match
		$reason = LookalikeDetector::detectReason('amaz0n', 'amazon');
		$this->assertNotNull($reason);
		$this->assertStringContainsString('levenshtein_1', $reason);
	}

	public function testDifferentBrandsAreNotSpoof(): void
	{
		// etsy ↔ ebay: Distanz 3 (innerhalb cap) ABER common prefix 1
		// → no match. Schuetzt vor false-positive auf kurze Stems.
		$this->assertNull(LookalikeDetector::detectReason('etsy', 'ebay'));
	}

	public function testIdenticalStemReturnsMatchOnHyphenOnly(): void
	{
		// Identische Stems werden upstream in check() rausgefiltert; hier
		// pruefen wir dass detectReason() bei selbem String keinen Spoof-
		// Marker liefert (Distanz 0 ist gemaess Code ausgeschlossen).
		$this->assertNull(LookalikeDetector::detectReason('amazon', 'amazon'));
	}

	public function testShortStemsNotFalsePositive(): void
	{
		// „ab" ↔ „ac": Distanz 1 aber unterhalb PREFIX_MATCH_MIN (4) →
		// kein Spoof. Schuetzt vor lawinenartigen False-Positives auf
		// 2-3-Buchstaben-Stems.
		$this->assertNull(LookalikeDetector::detectReason('ab', 'ac'));
	}

	public function testHyphenPatternUnderPrefixMinIsIgnored(): void
	{
		// Known-Stem zu kurz fuer Hyphen-Heuristik („ab" < 4 chars) — kein
		// Match, damit „ab-foo" nicht alles flagged.
		$this->assertNull(LookalikeDetector::detectReason('ab-foo', 'ab'));
	}

	public function testPaypalSecureIsSpoof(): void
	{
		// Klassisches Phishing-Beispiel
		$reason = LookalikeDetector::detectReason('paypal-secure', 'paypal');
		$this->assertNotNull($reason);
		$this->assertStringContainsString('hyphen_token_of:paypal', $reason);
	}
}
