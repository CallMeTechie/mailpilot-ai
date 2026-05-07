<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Kernel;
use MailPilot\Http\Request;
use MailPilot\Services\JwtService;

/**
 * Base class for controllers. Provides auth context and helpers.
 *
 * Errors are surfaced as HttpException so the Router can map them to JSON
 * cleanly. No more exit() in middleware-like code paths — that breaks tests.
 */
abstract class BaseController
{
	public function __construct(protected readonly Kernel $kernel)
	{
	}

	/**
	 * @return array{tenant_id:string, user_id:string, email:string, jti:string, exp:int}
	 */
	protected function requireAuth(): array
	{
		$token = Request::bearer();
		if ($token === null) {
			throw HttpException::unauthorized();
		}
		return $this->kernel->get(JwtService::class)->verify($token);
	}

	/**
	 * @return array{token:string, jti:string, exp:int}
	 */
	protected function issueJwt(string $tenantId, string $userId, string $email): array
	{
		return $this->kernel->get(JwtService::class)->issue($tenantId, $userId, $email);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	protected function requireField(array $body, string $key): mixed
	{
		if (!array_key_exists($key, $body) || $body[$key] === null || $body[$key] === '') {
			throw HttpException::badRequest('VALIDATION', "Feld '{$key}' fehlt");
		}
		return $body[$key];
	}
}
