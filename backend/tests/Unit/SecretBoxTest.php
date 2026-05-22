<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Security\SecretBox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 9q-A (Marc 2026-05-22) — SecretBox Authenticated-Encryption Tests.
 */
final class SecretBoxTest extends TestCase
{
	private function newBox(): SecretBox
	{
		// 32-Bytes synthetischer Key — niemals fuer Production-Crypto nutzen.
		$key = str_repeat("\x01", SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
		return new SecretBox($key);
	}

	public function testRoundtripReturnsOriginalPlaintext(): void
	{
		$box = $this->newBox();
		$plaintext = 'sk-ant-api03-fake-test-token-12345';
		$cipher = $box->encrypt($plaintext);
		self::assertNotSame($plaintext, $cipher, 'Cipher darf nicht == plaintext sein');
		self::assertSame($plaintext, $box->decrypt($cipher));
	}

	public function testEncryptSameInputTwiceProducesDifferentCiphers(): void
	{
		$box = $this->newBox();
		$a = $box->encrypt('hello');
		$b = $box->encrypt('hello');
		self::assertNotSame($a, $b, 'Nonce muss zufaellig sein → kein deterministischer Cipher');
		self::assertSame('hello', $box->decrypt($a));
		self::assertSame('hello', $box->decrypt($b));
	}

	public function testTamperedCipherThrows(): void
	{
		$box = $this->newBox();
		$cipher = $box->encrypt('important-secret');
		// Letztes Byte flippen — MAC schlaegt fehl
		$tampered = substr($cipher, 0, -1) . (substr($cipher, -1) === 'A' ? 'B' : 'A');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('SecretBox');
		$box->decrypt($tampered);
	}

	public function testWrongKeyThrows(): void
	{
		$alice = $this->newBox();
		$cipher = $alice->encrypt('alice secret');

		$bob = new SecretBox(str_repeat("\x02", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
		$this->expectException(RuntimeException::class);
		$bob->decrypt($cipher);
	}

	public function testInvalidKeyLengthThrows(): void
	{
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Master-Key muss exakt');
		new SecretBox('too-short');
	}

	public function testInvalidBase64Throws(): void
	{
		$box = $this->newBox();
		$this->expectException(RuntimeException::class);
		$box->decrypt('!!!not-base64!!!');
	}
}
