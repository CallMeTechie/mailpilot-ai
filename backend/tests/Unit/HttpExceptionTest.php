<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Tests\TestCase;

final class HttpExceptionTest extends TestCase
{
	public function testFactoriesProduceCorrectStatus(): void
	{
		$this->assertSame(400, HttpException::badRequest('VAL', 'x')->status);
		$this->assertSame(401, HttpException::unauthorized()->status);
		$this->assertSame(403, HttpException::forbidden()->status);
		$this->assertSame(404, HttpException::notFound()->status);
		$this->assertSame(412, HttpException::preconditionFailed('CODE', 'msg')->status);
	}

	public function testCarriesErrorCodeAndMessage(): void
	{
		$e = HttpException::badRequest('OAUTH_STATE_EXPIRED', 'state abgelaufen');
		$this->assertSame('OAUTH_STATE_EXPIRED', $e->errorCode);
		$this->assertSame('state abgelaufen', $e->getMessage());
	}

	public function testIsThrowable(): void
	{
		$this->expectException(\RuntimeException::class);
		throw HttpException::unauthorized();
	}
}
