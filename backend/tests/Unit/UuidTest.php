<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

final class UuidTest extends TestCase
{
	public function testProducesValidUuidV4(): void
	{
		$id = Uuid::v4();
		$this->assertSame(36, strlen($id));
		$this->assertTrue(Uuid::isValid($id));
	}

	public function testIsUnique(): void
	{
		$set = [];
		for ($i = 0; $i < 1000; $i++) {
			$set[Uuid::v4()] = true;
		}
		$this->assertCount(1000, $set);
	}

	public function testIsValidRejectsBadInput(): void
	{
		$this->assertFalse(Uuid::isValid(''));
		$this->assertFalse(Uuid::isValid('not-a-uuid'));
		$this->assertFalse(Uuid::isValid('00000000-0000-3000-8000-000000000000')); // wrong version digit
		$this->assertFalse(Uuid::isValid('00000000-0000-4000-c000-000000000000')); // bad variant nibble
		$this->assertTrue(Uuid::isValid('00000000-0000-4000-8000-000000000000'));
	}
}
