<?php
declare(strict_types=1);

namespace MailPilot\Claude;

use RuntimeException;

/**
 * Direct Anthropic API provider. Default for installations that don't need
 * EU data residency. Payloads go to api.anthropic.com (USA).
 */
final class AnthropicClient implements ClaudeProvider
{
	private const MAX_RETRIES = 3;

	public function __construct(
		private readonly string $apiKey,
		private readonly string $baseUrl,
		private readonly string $anthropicVersion,
		private readonly int $timeout,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
	}

	public function messages(array $payload): array
	{
		$url = rtrim($this->baseUrl, '/') . '/messages';
		$body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

		$attempt = 0;
		while (true) {
			$attempt++;
			$start = microtime(true);

			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_POST            => true,
				CURLOPT_POSTFIELDS      => $body,
				CURLOPT_RETURNTRANSFER  => true,
				CURLOPT_TIMEOUT         => $this->timeout,
				CURLOPT_CONNECTTIMEOUT  => 10,
				CURLOPT_HTTPHEADER      => [
					'Content-Type: application/json',
					'x-api-key: ' . $this->apiKey,
					'anthropic-version: ' . $this->anthropicVersion,
				],
			]);

			$response = curl_exec($ch);
			$status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$err      = curl_error($ch);
			curl_close($ch);

			$durMs = (int)((microtime(true) - $start) * 1000);

			$this->logger->info('claude.anthropic.call', [
				'attempt' => $attempt, 'status' => $status,
				'model' => $payload['model'] ?? '?', 'duration_ms' => $durMs,
			]);

			if ($status >= 200 && $status < 300 && is_string($response)) {
				return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
			}

			$retriable = ($status === 429 || $status >= 500) || $err !== '';
			if ($retriable && $attempt < self::MAX_RETRIES) {
				usleep((int)(2 ** $attempt * 500_000));
				continue;
			}

			throw new RuntimeException(sprintf(
				'Anthropic API failed: status=%d attempt=%d curlErr=%s',
				$status, $attempt, $err ?: 'none',
			));
		}
	}
}
