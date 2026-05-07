<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Services\TokenService;
use MailPilot\Tests\TestCase;
use Psr\Log\NullLogger;

final class TokenServiceTest extends TestCase
{
	private function makeService(?GraphClient $graph = null): TokenService
	{
		$graph = $graph ?? new GraphClient('cid','cs','https://r','common','Mail.Read', new NullLogger());
		$mailboxes = $this->createStub(MailboxRepository::class);
		return new TokenService(
			$graph,
			$mailboxes,
			str_repeat('a', 64),
		);
	}

	public function testEncryptDecryptRoundTrip(): void
	{
		$svc = $this->makeService();
		$plain = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.verylongrefreshtoken';
		$enc = $svc->encrypt($plain);
		$this->assertNotSame($plain, $enc);
		$this->assertSame($plain, $svc->decrypt($enc));
	}

	public function testEncryptProducesDifferentCiphertextEachTime(): void
	{
		$svc = $this->makeService();
		$plain = 'same_input';
		$a = $svc->encrypt($plain);
		$b = $svc->encrypt($plain);
		$this->assertNotSame($a, $b, 'Each encryption must use a fresh IV');
		$this->assertSame($plain, $svc->decrypt($a));
		$this->assertSame($plain, $svc->decrypt($b));
	}

	public function testInvalidKeyLengthIsRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		$mailboxes = $this->createStub(MailboxRepository::class);
		new TokenService(
			new GraphClient('cid','cs','https://r','common','Mail.Read', new NullLogger()),
			$mailboxes,
			'too-short',
		);
	}

	public function testDecryptFailsOnTampered(): void
	{
		$svc = $this->makeService();
		$enc = $svc->encrypt('secret');
		$tampered = substr($enc, 0, -1) . chr(ord(substr($enc, -1)) ^ 1);
		$this->expectException(\RuntimeException::class);
		$svc->decrypt($tampered);
	}
}
