<?php
declare(strict_types=1);

namespace MailPilot\Http;

final class Request
{
	/**
	 * @return array<string, mixed>
	 */
	public static function jsonBody(): array
	{
		$raw = file_get_contents('php://input');
		if ($raw === false || $raw === '') {
			return [];
		}
		try {
			$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
			return is_array($decoded) ? $decoded : [];
		} catch (\JsonException) {
			return [];
		}
	}

	public static function query(string $name, ?string $default = null): ?string
	{
		$v = $_GET[$name] ?? $default;
		return is_string($v) ? $v : $default;
	}

	public static function bearer(): ?string
	{
		$h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
		if (is_string($h) && preg_match('/Bearer\s+(.+)/i', $h, $m)) {
			return trim($m[1]);
		}
		return null;
	}

	public static function ip(): string
	{
		return (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
	}
}
