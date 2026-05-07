<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Controllers\AuthController;
use MailPilot\Tests\TestCase;

/**
 * Verifies that the id_token parser in AuthController correctly extracts
 * Azure AD claims (tid, oid, preferred_username, name) without signature
 * verification (we trust the back-channel TLS).
 */
final class IdTokenParserTest extends TestCase
{
	public function testParsesValidJwtPayload(): void
	{
		$payload = [
			'tid' => '11111111-2222-3333-4444-555555555555',
			'oid' => '99999999-8888-7777-6666-555555555555',
			'preferred_username' => 'user@example.com',
			'name' => 'Test User',
			'iss' => 'https://login.microsoftonline.com/.../v2.0',
			'aud' => 'app-id',
			'exp' => time() + 3600,
		];
		$jwt = $this->makeJwt($payload);

		$claims = $this->invokeParse($jwt);
		$this->assertSame($payload['tid'], $claims['tid']);
		$this->assertSame($payload['oid'], $claims['oid']);
		$this->assertSame($payload['preferred_username'], $claims['preferred_username']);
		$this->assertSame($payload['name'], $claims['name']);
	}

	public function testReturnsEmptyOnMalformedToken(): void
	{
		$this->assertSame([], $this->invokeParse('not-a-jwt'));
		$this->assertSame([], $this->invokeParse(''));
		$this->assertSame([], $this->invokeParse('only.two'));
	}

	public function testReturnsEmptyOnInvalidJsonPayload(): void
	{
		$header  = $this->b64('{"alg":"RS256","typ":"JWT"}');
		$payload = $this->b64('not-json');
		$sig     = $this->b64('sig');
		$this->assertSame([], $this->invokeParse("{$header}.{$payload}.{$sig}"));
	}

	private function invokeParse(string $jwt): array
	{
		$method = new \ReflectionMethod(AuthController::class, 'parseJwtClaims');
		$method->setAccessible(true);
		/** @var array<string,mixed> $result */
		$result = $method->invoke(null, $jwt);
		return $result;
	}

	private function makeJwt(array $payload): string
	{
		return $this->b64('{"alg":"RS256","typ":"JWT"}')
			. '.' . $this->b64(json_encode($payload, JSON_UNESCAPED_UNICODE))
			. '.' . $this->b64('signature-not-verified');
	}

	private function b64(string $s): string
	{
		return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
	}
}
