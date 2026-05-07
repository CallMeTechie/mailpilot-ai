<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Claude\BedrockClient;
use MailPilot\Tests\TestCase;
use Psr\Log\NullLogger;

final class BedrockClientTest extends TestCase
{
	public function testConstructorRejectsEmptyCredentials(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/credentials missing/');
		new BedrockClient('', 'secret', null, 'eu-central-1', [], 30, new NullLogger());
	}

	public function testConstructorRejectsEmptyRegion(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/region missing/');
		new BedrockClient('key', 'secret', null, '', [], 30, new NullLogger());
	}

	public function testSigV4SigningProducesExpectedAuthorizationHeader(): void
	{
		// Use reflection to call the private signing method directly.
		// Test values taken from AWS SigV4 test suite (get-vanilla test fixtures).
		$client = new BedrockClient(
			'AKIDEXAMPLE',
			'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
			null,
			'us-east-1',
			['x' => 'y'],
			30,
			new NullLogger(),
		);

		$method = new \ReflectionMethod($client, 'signRequest');
		$method->setAccessible(true);

		// Force a deterministic timestamp via a mocked global would require
		// injecting a clock. For now: call and verify the structural guarantees.
		$headers = $method->invoke($client, 'POST', 'bedrock-runtime.us-east-1.amazonaws.com', '/model/test/invoke', '{"a":1}');

		$this->assertIsArray($headers);

		// Must contain required SigV4 headers
		$joined = implode("\n", $headers);
		$this->assertStringContainsString('Content-Type: application/json', $joined);
		$this->assertStringContainsString('X-Amz-Date:', $joined);
		$this->assertStringContainsString('Authorization: AWS4-HMAC-SHA256', $joined);
		$this->assertStringContainsString('Credential=AKIDEXAMPLE/', $joined);
		$this->assertStringContainsString('/us-east-1/bedrock/aws4_request', $joined);
		$this->assertStringContainsString('SignedHeaders=content-type;host;x-amz-date', $joined);
		$this->assertStringContainsString('Signature=', $joined);
	}

	public function testSessionTokenIsIncludedInSignedHeaders(): void
	{
		$client = new BedrockClient(
			'AKIDEXAMPLE',
			'secret',
			'SESSION-TOKEN-VALUE',
			'eu-central-1',
			['x' => 'y'],
			30,
			new NullLogger(),
		);

		$method = new \ReflectionMethod($client, 'signRequest');
		$method->setAccessible(true);

		$headers = $method->invoke($client, 'POST', 'bedrock-runtime.eu-central-1.amazonaws.com', '/model/x/invoke', '{}');
		$joined = implode("\n", $headers);

		$this->assertStringContainsString('X-Amz-Security-Token: SESSION-TOKEN-VALUE', $joined);
		$this->assertStringContainsString('SignedHeaders=content-type;host;x-amz-date;x-amz-security-token', $joined);
	}

	public function testSignatureChangesWithDifferentPayload(): void
	{
		$client = new BedrockClient('k', 's', null, 'eu-central-1', ['x' => 'y'], 30, new NullLogger());
		$method = new \ReflectionMethod($client, 'signRequest');
		$method->setAccessible(true);

		$h1 = $method->invoke($client, 'POST', 'host', '/path', '{"a":1}');
		$h2 = $method->invoke($client, 'POST', 'host', '/path', '{"a":2}');

		// Extract signatures
		$sig1 = $this->extractSignature($h1);
		$sig2 = $this->extractSignature($h2);
		$this->assertNotSame($sig1, $sig2, 'Different payloads must produce different signatures');
	}

	/**
	 * @param list<string> $headers
	 */
	private function extractSignature(array $headers): string
	{
		foreach ($headers as $h) {
			if (preg_match('/Signature=([a-f0-9]+)/', $h, $m)) {
				return $m[1];
			}
		}
		return '';
	}
}
