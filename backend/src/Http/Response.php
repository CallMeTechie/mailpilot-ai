<?php
declare(strict_types=1);

namespace MailPilot\Http;

final class Response
{
	public static function json(mixed $data, int $status = 200): void
	{
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function error(int $status, string $code, string $message, array $details = []): void
	{
		self::json([
			'error' => [
				'code'    => $code,
				'message' => $message,
				'details' => $details,
			],
		], $status);
	}

	public static function noContent(): void
	{
		http_response_code(204);
	}
}
