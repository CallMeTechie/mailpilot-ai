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

	/**
	 * Phase 9h.3 (Marc 2026-05-21) — parallele Variante via curl_multi.
	 *
	 * Sendet alle Payloads gleichzeitig (statt 3× ~2.5s sequenziell wird's
	 * ~2.5s gesamt). Returnt Array gleicher Reihenfolge: pro Payload entweder
	 * die decodierte Response ODER ein RuntimeException-Objekt (kein throw —
	 * Caller entscheidet pro Slot ob ein Fehler fatal ist).
	 *
	 * Wichtig: KEIN Retry hier — Caller-Code in correctScore behandelt
	 * Inferenz-Fehler ohnehin als best-effort. Wenn wir hier retrien wuerden,
	 * blockiert ein zaeh anlaufendes Anthropic den gesamten Batch.
	 *
	 * @param list<array<string, mixed>> $payloads
	 * @return list<array<string, mixed>|\RuntimeException>
	 */
	public function messagesBatch(array $payloads): array
	{
		if ($payloads === []) {
			return [];
		}
		$url     = rtrim($this->baseUrl, '/') . '/messages';
		$mh      = curl_multi_init();
		$handles = [];

		foreach ($payloads as $i => $payload) {
			$body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
			$ch   = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => $this->timeout,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_HTTPHEADER     => [
					'Content-Type: application/json',
					'x-api-key: ' . $this->apiKey,
					'anthropic-version: ' . $this->anthropicVersion,
					'anthropic-beta: prompt-caching-2024-07-31,extended-cache-ttl-2025-04-11',
				],
			]);
			curl_multi_add_handle($mh, $ch);
			$handles[$i] = $ch;
		}

		$start = microtime(true);
		$active = null;
		do {
			$status = curl_multi_exec($mh, $active);
			if ($active) {
				curl_multi_select($mh, 1.0);
			}
		} while ($active && $status === CURLM_OK);
		$durMs = (int)((microtime(true) - $start) * 1000);

		$results = [];
		foreach ($handles as $i => $ch) {
			$response   = curl_multi_getcontent($ch);
			$httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$err        = curl_error($ch);
			$model      = (string)($payloads[$i]['model'] ?? '?');

			$this->logger->info('claude.anthropic.call', [
				'attempt'     => 1,
				'status'      => $httpStatus,
				'model'       => $model,
				'duration_ms' => $durMs,
				'batch_slot'  => $i,
			]);

			if ($httpStatus >= 200 && $httpStatus < 300 && is_string($response) && $response !== '') {
				try {
					$results[$i] = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
				} catch (\JsonException $e) {
					$results[$i] = new RuntimeException(sprintf(
						'Anthropic batch decode failed slot=%d: %s', $i, $e->getMessage(),
					));
				}
			} else {
				$snippet = is_string($response) ? substr($response, 0, 200) : '';
				$this->logger->warning('claude.anthropic.batch_slot_error', [
					'slot'     => $i,
					'status'   => $httpStatus,
					'curl_err' => $err,
				]);
				$results[$i] = new RuntimeException(sprintf(
					'Anthropic batch failed slot=%d status=%d curlErr=%s body=%s',
					$i, $httpStatus, $err ?: 'none', $snippet,
				));
			}
			curl_multi_remove_handle($mh, $ch);
			curl_close($ch);
		}
		curl_multi_close($mh);

		ksort($results);
		return array_values($results);
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
					// Sprint 6a: Prompt-Caching + 1h-Extended-TTL. Header
					// schadet nicht, wenn der Payload keine cache_control-
					// Marker enthält; aktiviert aber das Feature wenn doch.
					'anthropic-beta: prompt-caching-2024-07-31,extended-cache-ttl-2025-04-11',
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

			$snippet = is_string($response) ? substr($response, 0, 400) : '';
			$this->logger->error('claude.anthropic.error', [
				'status'   => $status,
				'curl_err' => $err,
				'body'     => $snippet,
			]);
			throw new RuntimeException(sprintf(
				'Anthropic API failed: status=%d attempt=%d curlErr=%s body=%s',
				$status, $attempt, $err ?: 'none', $snippet,
			));
		}
	}
}
