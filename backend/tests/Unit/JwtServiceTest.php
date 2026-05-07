<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Services\JwtService;
use MailPilot\Tests\TestCase;

final class JwtServiceTest extends TestCase
{
	private function svc(?\PDO $pdo = null): JwtService
	{
		return new JwtService(
			'a-strong-test-secret',
			'mailpilot.ai.test',
			'mailpilot-test-audience',
			3600,
			$pdo,
		);
	}

	public function testIssueAndVerifyRoundTrip(): void
	{
		$svc = $this->svc();
		$out = $svc->issue('tenant-1', 'user-1', 'marc@test.de');

		$this->assertNotEmpty($out['token']);
		$this->assertNotEmpty($out['jti']);
		$this->assertGreaterThan(time(), $out['exp']);

		$decoded = $svc->verify($out['token']);
		$this->assertSame('tenant-1', $decoded['tenant_id']);
		$this->assertSame('user-1',   $decoded['user_id']);
		$this->assertSame('marc@test.de', $decoded['email']);
		$this->assertSame($out['jti'], $decoded['jti']);
	}

	public function testRejectsTamperedToken(): void
	{
		$svc = $this->svc();
		$out = $svc->issue('t', 'u', 'e@x.de');
		$tampered = substr($out['token'], 0, -2) . 'XX';

		$this->expectException(HttpException::class);
		$svc->verify($tampered);
	}

	public function testRejectsTokenFromDifferentIssuer(): void
	{
		$other = new JwtService('a-strong-test-secret', 'evil-issuer', 'mailpilot-test-audience', 3600);
		$out = $other->issue('t', 'u', 'e@x.de');

		$this->expectException(HttpException::class);
		$this->expectExceptionMessage('Issuer ungültig');
		$this->svc()->verify($out['token']);
	}

	public function testRejectsTokenForWrongAudience(): void
	{
		$other = new JwtService('a-strong-test-secret', 'mailpilot.ai.test', 'wrong-audience', 3600);
		$out = $other->issue('t', 'u', 'e@x.de');

		$this->expectException(HttpException::class);
		$this->expectExceptionMessage('Audience ungültig');
		$this->svc()->verify($out['token']);
	}

	public function testEmptySecretRejected(): void
	{
		$this->expectException(\RuntimeException::class);
		new JwtService('', 'iss', 'aud', 3600);
	}

	public function testIncludesIssAudJtiClaims(): void
	{
		$svc = $this->svc();
		$out = $svc->issue('t', 'u', 'e@x.de');
		[, $payload] = explode('.', $out['token']);
		$decoded = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

		$this->assertSame('mailpilot.ai.test', $decoded['iss']);
		$this->assertSame('mailpilot-test-audience', $decoded['aud']);
		$this->assertNotEmpty($decoded['jti']);
		$this->assertGreaterThan($decoded['iat'], $decoded['exp']);
	}
}
