<?php
declare(strict_types=1);

namespace MailPilot\Http\Exceptions;

use RuntimeException;

/**
 * Base for HTTP-mapped exceptions thrown by controllers.
 * Router catches them and maps to a JSON error response.
 */
class HttpException extends RuntimeException
{
	public function __construct(
		public readonly int $status,
		public readonly string $errorCode,
		string $message,
	) {
		parent::__construct($message);
	}

	public static function badRequest(string $code, string $message): self
	{
		return new self(400, $code, $message);
	}

	public static function unauthorized(string $code = 'AUTH_REQUIRED', string $message = 'Keine Authentifizierung'): self
	{
		return new self(401, $code, $message);
	}

	public static function forbidden(string $code = 'FORBIDDEN', string $message = 'Zugriff verweigert'): self
	{
		return new self(403, $code, $message);
	}

	public static function notFound(string $code = 'NOT_FOUND', string $message = 'Nicht gefunden'): self
	{
		return new self(404, $code, $message);
	}

	public static function preconditionFailed(string $code, string $message): self
	{
		return new self(412, $code, $message);
	}

	public static function tooManyRequests(string $code, string $message): self
	{
		return new self(429, $code, $message);
	}
}
