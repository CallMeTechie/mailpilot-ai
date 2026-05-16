<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Util\MailRecipients;
use PHPUnit\Framework\TestCase;

/**
 * Pure-Function-Tests fuer den Recipient-Helper. Frueher als private
 * MailScoringService::buildRecipients — nach dem Phase-1-Split einzeln
 * testbar.
 */
final class MailRecipientsTest extends TestCase
{
	public function testEmptyMailReturnsEmptyList(): void
	{
		$this->assertSame([], MailRecipients::build([], 'marc@test.de'));
	}

	public function testJsonStringToRecipientsAreParsed(): void
	{
		$mail = [
			'to_json' => '[{"address":"marc@test.de","name":"Marc"}]',
			'cc_json' => '[]',
		];
		$out = MailRecipients::build($mail, 'marc@test.de');
		$this->assertCount(1, $out);
		$this->assertSame('marc@test.de', $out[0]['email']);
		$this->assertSame('Marc', $out[0]['name']);
		$this->assertSame('to', $out[0]['role']);
		$this->assertTrue($out[0]['is_user']);
	}

	public function testAlreadyDecodedArrayWorks(): void
	{
		$mail = [
			'to_json' => [['address' => 'a@x.de', 'name' => 'A'], ['email' => 'b@x.de']],
		];
		$out = MailRecipients::build($mail, 'a@x.de');
		$this->assertCount(2, $out);
		$this->assertSame('a@x.de', $out[0]['email']);
		$this->assertTrue($out[0]['is_user']);
		$this->assertSame('b@x.de', $out[1]['email']);
		$this->assertFalse($out[1]['is_user']);
	}

	public function testCcIsTaggedAsCc(): void
	{
		$mail = ['cc_json' => '[{"address":"chef@x.de"}]'];
		$out = MailRecipients::build($mail, 'marc@test.de');
		$this->assertCount(1, $out);
		$this->assertSame('cc', $out[0]['role']);
		$this->assertFalse($out[0]['is_user']);
	}

	public function testUserEmailIsCaseInsensitive(): void
	{
		$mail = ['to_json' => '[{"address":"MARC@test.de"}]'];
		$out = MailRecipients::build($mail, 'marc@test.de');
		$this->assertTrue($out[0]['is_user']);
		$this->assertSame('marc@test.de', $out[0]['email'], 'email muss lowercase sein');
	}

	public function testInvalidJsonGetsSkippedSilently(): void
	{
		$mail = ['to_json' => 'not-a-json-string', 'cc_json' => '[{"address":"ok@x.de"}]'];
		$out = MailRecipients::build($mail, 'a@x.de');
		// Invalid JSON wird zu [] (json_decode==false → || [] greift), cc_json normal.
		$this->assertCount(1, $out);
		$this->assertSame('ok@x.de', $out[0]['email']);
	}

	public function testEntryWithoutEmailIsSkipped(): void
	{
		$mail = ['to_json' => '[{"name":"Anonymous"},{"address":"x@y.de"}]'];
		$out = MailRecipients::build($mail, 'a@x.de');
		$this->assertCount(1, $out);
		$this->assertSame('x@y.de', $out[0]['email']);
	}

	public function testDisplayNameFallbackIsHonored(): void
	{
		$mail = ['to_json' => '[{"address":"x@y.de","display_name":"X Y"}]'];
		$out = MailRecipients::build($mail, 'a@x.de');
		$this->assertSame('X Y', $out[0]['name']);
	}

	public function testMultipleRecipientsOrderIsPreserved(): void
	{
		$mail = [
			'to_json' => '[{"address":"a@x.de"},{"address":"b@x.de"},{"address":"c@x.de"}]',
			'cc_json' => '[{"address":"d@x.de"}]',
		];
		$out = MailRecipients::build($mail, 'b@x.de');
		$this->assertCount(4, $out);
		$this->assertSame(['a@x.de','b@x.de','c@x.de','d@x.de'], array_column($out, 'email'));
		$this->assertSame(['to','to','to','cc'], array_column($out, 'role'));
		$this->assertSame([false, true, false, false], array_column($out, 'is_user'));
	}
}
