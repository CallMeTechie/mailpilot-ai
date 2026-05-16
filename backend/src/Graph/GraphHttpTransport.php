<?php
declare(strict_types=1);

namespace MailPilot\Graph;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Low-level HTTP transport fuer Microsoft Graph API.
 *
 * Kapselt curl-Setup, 429-Retry mit Retry-After-Honor, und JSON-Parsing.
 * Wird von GraphMailClient + GraphFolderClient als shared Dependency
 * konsumiert. OAuth nutzt einen separaten Token-Endpoint (siehe
 * GraphOAuthClient), deshalb nicht hier.
 *
 * Ausgegliedert aus GraphClient (Phase-3 split).
 */
final class GraphHttpTransport
{
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * GET mit 429-Retry-Loop (max 2 Versuche, max 5s sleep). Bei 2x429 →
	 * GraphThrottledException (Caller kann User-Loop abbrechen).
	 *
	 * @return array<string, mixed>
	 */
	public function get(string $accessToken, string $url): array
	{
		for ($attempt = 1; $attempt <= 2; $attempt++) {
			$retryAfter = 0;
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_HTTPHEADER     => [
					'Authorization: Bearer ' . $accessToken,
				],
				CURLOPT_HEADERFUNCTION => function ($_, string $header) use (&$retryAfter): int {
					if (preg_match('/^Retry-After:\s*(\d+)/i', $header, $m)) {
						$retryAfter = (int)$m[1];
					}
					return strlen($header);
				},
			]);
			$body = curl_exec($ch);
			$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			curl_close($ch);

			if ($status >= 200 && $status < 300 && is_string($body)) {
				return json_decode($body, true, 32, JSON_THROW_ON_ERROR);
			}

			if ($status === 429 && $attempt === 1) {
				$sleepSecs = max(1, min(5, $retryAfter ?: 1));
				sleep($sleepSecs);
				continue;
			}
			if ($status === 429) {
				throw new GraphThrottledException($url, max(1, $retryAfter ?: 60));
			}
			throw new RuntimeException("Graph GET failed: {$status}");
		}
		throw new RuntimeException("Graph GET failed: unreachable");
	}

	public function patch(string $accessToken, string $url, array $payload): void
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => 'PATCH',
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $accessToken,
				'Content-Type: application/json',
			],
		]);
		curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($status < 200 || $status >= 300) {
			throw new RuntimeException("Graph PATCH failed: {$status}");
		}
	}

	/**
	 * @return array<string,mixed>|null Decoded body, null bei 204/empty.
	 *         Caller muss error.code aus RuntimeException-Message parsen.
	 */
	public function postJson(string $accessToken, string $url, array $payload): ?array
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $accessToken,
				'Content-Type: application/json',
			],
		]);
		$body   = (string)curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($status < 200 || $status >= 300) {
			$code = '';
			try {
				$decoded = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
				if (is_array($decoded) && isset($decoded['error']['code'])) {
					$code = ' (' . (string)$decoded['error']['code'] . ')';
				}
			} catch (\JsonException) { /* keine strukturierte Antwort */ }
			throw new RuntimeException("Graph POST failed: {$status}{$code}");
		}

		if ($body === '') return null;
		try {
			$decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		return is_array($decoded) ? $decoded : null;
	}

	public function delete(string $accessToken, string $url): void
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_CUSTOMREQUEST  => 'DELETE',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $accessToken,
			],
		]);
		curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		curl_close($ch);

		if ($status < 200 || $status >= 300) {
			throw new RuntimeException("Graph DELETE failed: {$status}");
		}
	}
}
