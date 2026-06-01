<?php
declare(strict_types=1);

namespace MailPilot\Llm\Providers;

use MailPilot\Llm\LlmOverloadedException;
use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmUnavailableException;
use MailPilot\Llm\ModelDescriptor;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Security\SecretBox;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Phase 9q-D (Marc 2026-05-23) — Universeller OpenAI-API-kompatibler
 * Provider. Deckt lokale Inferenz-Server ab, die alle das OpenAI-Chat-
 * Completions-Schema sprechen:
 *
 *   - Ollama     (http://host:11434/v1/chat/completions)
 *   - LM Studio  (http://host:1234/v1/chat/completions)
 *   - llama.cpp  (Server-Mode, /v1/chat/completions)
 *   - vLLM       (/v1/chat/completions)
 *   - Qwen via DashScope (api.dashscope.com/compatible-mode/v1/...)
 *
 * Unterschied zu OpenAiProvider:
 *   - API-Key OPTIONAL — viele lokale Server brauchen keinen Auth-Header.
 *   - Wenn api_key_encrypted oder api_key_env_fallback gesetzt → Bearer.
 *   - Sonst kein Auth-Header.
 *
 * Connect-Timeout reduziert (5s statt 10s) damit lokal-down-Failover
 * schnell triggert — kein Sinn 30s auf einen toten Ollama zu warten.
 */
final class OpenAiCompatibleProvider implements LlmProvider
{
	private const TIMEOUT_DEFAULT       = 120;  // lokal-CPU braucht oft 10-30s
	private const CONNECT_TIMEOUT       = 5;
	private const DISCOVERY_TIMEOUT_S   = 10;
	private const MAX_RETRIES           = 2;    // weniger Retries als Cloud — lokal-down ist sofort permanent
	private const UNHEALTHY_COOLDOWN_S  = 30;

	private ?int $unhealthyUntil = null;

	public function __construct(
		private readonly LlmProviderRepository $repo,
		private readonly SecretBox $secretBox,
		private readonly LoggerInterface $logger,
		private readonly int $timeout = self::TIMEOUT_DEFAULT,
	) {
	}

	public function kind(): string
	{
		return 'openai_compatible';
	}

	public function isHealthy(): bool
	{
		if ($this->unhealthyUntil === null) {
			return true;
		}
		if (time() >= $this->unhealthyUntil) {
			$this->unhealthyUntil = null;
			return true;
		}
		return false;
	}

	public function complete(NormalizedRequest $request): NormalizedResponse
	{
		$row = $this->repo->findByKind('openai_compatible');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver openai_compatible-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);   // kann '' sein (lokal ohne Auth)
		$baseUrl = (string)($row['base_url'] ?? '');
		if ($baseUrl === '') {
			throw new LlmUnavailableException('openai_compatible-Provider hat keinen base_url');
		}
		$url     = rtrim($baseUrl, '/') . '/v1/chat/completions';
		$payload = $this->buildPayload($request);
		$body    = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

		$headers = ['Content-Type: application/json'];
		if ($apiKey !== '') {
			$headers[] = 'Authorization: Bearer ' . $apiKey;
		}

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
				CURLOPT_CONNECTTIMEOUT  => self::CONNECT_TIMEOUT,
				CURLOPT_HTTPHEADER      => $headers,
			]);
			$response = curl_exec($ch);
			$status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$err      = curl_error($ch);
			curl_close($ch);

			$durMs = (int)((microtime(true) - $start) * 1000);
			$this->logger->info('llm.openai_compatible.call', [
				'attempt'     => $attempt,
				'status'      => $status,
				'base_url'    => $baseUrl,
				'model'       => $payload['model'],
				'duration_ms' => $durMs,
			]);

			if ($status >= 200 && $status < 300 && is_string($response) && $response !== '') {
				$decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
				return $this->buildResponse(is_array($decoded) ? $decoded : [], $request->modelHint);
			}

			$retriable = ($status === 429 || $status >= 500) || $err !== '';
			if ($retriable && $attempt < self::MAX_RETRIES) {
				usleep((int)(2 ** $attempt * 500_000));
				continue;
			}

			$snippet = is_string($response) ? substr($response, 0, 400) : '';
			$this->logger->error('llm.openai_compatible.error', [
				'status' => $status, 'curl_err' => $err, 'body' => $snippet,
			]);

			// Connect-Refused / DNS-Fail / Timeout → unavailable (Router
			// schaltet auf Cloud-Provider falls Privacy-Mode erlaubt).
			if ($err !== '') {
				$this->unhealthyUntil = time() + self::UNHEALTHY_COOLDOWN_S;
				throw new LlmUnavailableException(
					sprintf('Lokales Modell unter %s nicht erreichbar: %s', $baseUrl, $err)
				);
			}
			if ($status === 429 || $status >= 500) {
				$this->unhealthyUntil = time() + self::UNHEALTHY_COOLDOWN_S;
				throw new LlmOverloadedException(
					sprintf('Lokales Modell ueberlastet (status=%d, attempt=%d)', $status, $attempt),
					15,
				);
			}
			// 4xx → kein Failover (Config-Bug oder Model nicht im lokalen Server installiert).
			throw new RuntimeException(sprintf(
				'openai_compatible API failed: status=%d body=%s', $status, $snippet,
			));
		}
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function resolveApiKey(array $row): string
	{
		$enc = $row['api_key_encrypted'] ?? null;
		if (is_string($enc) && $enc !== '') {
			return $this->secretBox->decrypt($enc);
		}
		$envName = (string)($row['api_key_env_fallback'] ?? '');
		if ($envName !== '') {
			$envVal = getenv($envName);
			if (is_string($envVal) && $envVal !== '') {
				return $envVal;
			}
		}
		// Lokale Server brauchen oft keinen Key — leer ist OK.
		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildPayload(NormalizedRequest $request): array
	{
		$messages = [['role' => 'system', 'content' => $request->systemPrompt]];
		foreach ($request->messages as $m) {
			$messages[] = ['role' => $m['role'], 'content' => $m['content']];
		}

		$payload = [
			'model'       => $request->modelHint,
			'messages'    => $messages,
			'max_tokens'  => $request->maxTokens,
			'temperature' => $request->temperature,
		];
		// JSON-Mode wird von Ollama unterstuetzt, von LM Studio teils nicht.
		// Wenn Caller json_object angefragt hat, senden wir's; lokaler Server
		// kann es ignorieren und liefert dann eventuell free-text — das
		// haben wir bei der JSON-Validierung im Caller abzufangen.
		if ($request->responseFormat === 'json_object') {
			$payload['response_format'] = ['type' => 'json_object'];
		}
		return $payload;
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function buildResponse(array $raw, string $modelHint): NormalizedResponse
	{
		$choice  = $raw['choices'][0]['message']['content'] ?? '';
		$content = is_string($choice) ? $choice : '';

		$usage = $raw['usage'] ?? [];
		return new NormalizedResponse(
			content:      $content,
			usage:        [
				'inputTokens'  => (int)($usage['prompt_tokens']     ?? 0),
				'outputTokens' => (int)($usage['completion_tokens'] ?? 0),
				'cachedTokens' => 0,
				'raw'          => is_array($usage) ? $usage : [],
			],
			finishReason: (string)($raw['choices'][0]['finish_reason'] ?? 'unknown'),
			modelId:      (string)($raw['model'] ?? $modelHint),
			providerKind: 'openai_compatible',
		);
	}

	/**
	 * @return list<ModelDescriptor>
	 */
	public function listModels(): array
	{
		$row = $this->repo->findByKind('openai_compatible');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver openai_compatible-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? '');
		if ($baseUrl === '') {
			throw new LlmUnavailableException('openai_compatible-Provider hat keinen base_url');
		}
		$url = rtrim($baseUrl, '/') . '/v1/models';

		$headers = ['Content-Type: application/json'];
		if ($apiKey !== '') {
			$headers[] = 'Authorization: Bearer ' . $apiKey;
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::DISCOVERY_TIMEOUT_S,
			CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
			CURLOPT_HTTPHEADER     => $headers,
		]);
		$resp   = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($err !== '' || $status < 200 || $status >= 300 || !is_string($resp)) {
			throw new LlmUnavailableException(sprintf(
				'openai_compatible listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
			));
		}
		$decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
		return self::parseModelsResponse(is_array($decoded) ? $decoded : []);
	}

	/**
	 * @param  array<string,mixed> $json
	 * @return list<ModelDescriptor>
	 */
	public static function parseModelsResponse(array $json): array
	{
		$out = [];
		foreach (($json['data'] ?? []) as $m) {
			if (!is_array($m) || !isset($m['id'])) {
				continue;
			}
			$id = (string)$m['id'];
			$out[] = new ModelDescriptor(modelId: $id, displayName: $id, effortLevels: []);
		}
		return $out;
	}
}
