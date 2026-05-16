<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Scoring;

use MailPilot\Services\Scoring\ActionOwnerResolver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit-Tests fuer ActionOwnerResolver::computeFallback (3-Stufen) und
 * ::validate (Static-Whitelist).
 *
 * computeFallback ist pure — keine DB, keine Claude-Calls. Wir umgehen
 * den Konstruktor via Reflection, da der pure Pfad keine injected
 * Dependencies nutzt. Der volle Mini-Call-Pfad bleibt im
 * Integration-Test MailScoringServiceTest gepinnt.
 */
final class ActionOwnerResolverFallbackTest extends TestCase
{
	private function makeResolver(): ActionOwnerResolver
	{
		return (new ReflectionClass(ActionOwnerResolver::class))->newInstanceWithoutConstructor();
	}

	public function testUserInToReturnsUser40(): void
	{
		$mail = ['to_json' => '[{"address":"marc@test.de","name":"Marc"}]'];
		$profile = ['email' => 'marc@test.de', 'aliases' => []];

		[$owner, $conf] = $this->makeResolver()->computeFallback($mail, $profile);

		$this->assertSame('user', $owner);
		$this->assertSame(40, $conf);
	}

	public function testGroupPrefixFromReturnsGroup60(): void
	{
		$mail = [
			'from_email' => 'no-reply@stripe.com',
			'to_json'    => '[{"address":"other@x.de"}]',
		];
		$profile = ['email' => 'marc@test.de', 'aliases' => []];

		[$owner, $conf] = $this->makeResolver()->computeFallback($mail, $profile);

		$this->assertSame('group', $owner);
		$this->assertSame(60, $conf);
	}

	public function testThreeOrMoreOthersInToTriggersGroup(): void
	{
		$mail = [
			'from_email' => 'human@example.com',
			'to_json' => '[{"address":"a@x.de"},{"address":"b@x.de"},{"address":"c@x.de"}]',
		];
		$profile = ['email' => 'marc@test.de', 'aliases' => []];

		[$owner, $conf] = $this->makeResolver()->computeFallback($mail, $profile);

		$this->assertSame('group', $owner);
		$this->assertSame(60, $conf);
	}

	public function testUnknownOwnerFallsThroughToUnsure(): void
	{
		$mail = [
			'from_email' => 'human@example.com',
			'to_json' => '[{"address":"other@x.de"}]',
		];
		$profile = ['email' => 'marc@test.de', 'aliases' => []];

		[$owner, $conf] = $this->makeResolver()->computeFallback($mail, $profile);

		$this->assertSame('unsure', $owner);
		$this->assertSame(0, $conf);
	}

	public function testAliasCollisionSuppressesUserOwner(): void
	{
		// User im To, aber anderer Empfaenger heisst "Marc" → ambig → unsure.
		$mail = [
			'from_email' => 'chef@example.com',
			'to_json' => '[{"address":"marc@test.de"},{"address":"marc.berlin@partner.de","name":"Marc Berlin"}]',
		];
		$profile = ['email' => 'marc@test.de', 'aliases' => ['marc']];

		[$owner, $conf] = $this->makeResolver()->computeFallback($mail, $profile);

		$this->assertSame('unsure', $owner);
		$this->assertSame(0, $conf);
	}

	public function testCcRecipientsAreIgnoredForCount(): void
	{
		// 3 CC + 0 To-Andere → KEIN group (nur To-Andere zaehlen)
		$mail = [
			'from_email' => 'chef@example.com',
			'to_json' => '[{"address":"marc@test.de"}]',
			'cc_json' => '[{"address":"a@x.de"},{"address":"b@x.de"},{"address":"c@x.de"}]',
		];
		$profile = ['email' => 'marc@test.de', 'aliases' => []];

		[$owner, $conf] = $this->makeResolver()->computeFallback($mail, $profile);

		$this->assertSame('user', $owner);
		$this->assertSame(40, $conf);
	}

	public function testValidateAcceptsAllowedValues(): void
	{
		$this->assertSame('user',   ActionOwnerResolver::validate('user'));
		$this->assertSame('other',  ActionOwnerResolver::validate('other'));
		$this->assertSame('group',  ActionOwnerResolver::validate('group'));
		$this->assertSame('unsure', ActionOwnerResolver::validate('unsure'));
	}

	public function testValidateCoercesUnknownToUnsure(): void
	{
		$this->assertSame('unsure', ActionOwnerResolver::validate('foo'));
		$this->assertSame('unsure', ActionOwnerResolver::validate(''));
		$this->assertSame('unsure', ActionOwnerResolver::validate('USER'));
	}
}
