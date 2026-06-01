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
 * Phase 9q-B (Marc 2026-05-22) — OpenAI als Fallback-Provider.
 *
 * Endpoint: POST {base_url}/v1/chat/completions (default api.openai.com).
 * JSON-Mode: response_format = {type: "json_object"} — schwaecher als
 * Anthropic-„antworte nur JSON" weil OpenAI erlaubt nicht-konformes JSON
 * wenn das Prompt nicht klar genug ist. Caller MUSS „JSON only"-Hinweis
 * im System-Prompt halten (P-SCORE@1.6 hat das).
 *
 * Rate-Limit-/Overload-Detection:
 *   - 429 Too Many Requests       → LlmOverloadedException
 *   - 503 Service Unavailable     → LlmOverloadedException
 *   - 5xx mit retry-After-Header  → LlmOverloadedException
 *   - 401/403/400/404             → RuntimeException (kein Failover)
 *
 * Pricing-Hint (Stand 2026-Q2):
 *   - gpt-4o-mini: $0.15 in / $0.60 out per Mtok
 *   - Anthropic-Haiku-4.5:  $0.80 in / $4 out per Mtok
 * → OpenAI ist als Failover deutlich guenstiger als Anthropic-Production-Mode.
 */
final class OpenAiProvider implements LlmProvider
{
	private const TIMEOUT_DEFAULT      = 60;
	private const MAX_RETRIES          = 3;
	private const UNHEALTHY_COOLDOWN_S = 60;
	private const DISCOVERY_TIMEOUT_S  = 10;
	// reasoning_effort-Vertrag von OpenAI. Wartbare API-Heuristik, keine
	// versions-spezifische Modell-Hardcodierung.
	private const CHAT_PREFIXES        = ['gpt-', 'o1', 'o3', 'o4', 'chatgpt-'];
	private const NON_CHAT_SUBSTR      = ['embedding', 'whisper', 'tts', 'transcribe', 'realtime', 'search', 'dall-e', 'moderation', 'audio', 'image', 'davinci', 'babbage'];

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
		return 'openai';
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
		$row = $this->repo->findByKind('openai');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver OpenAI-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://api.openai.com');
		$url     = rtrim($baseUrl, '/') . '/v1/chat/completions';
		$payload = self::buildPayload($request);
		$body    = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

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
					'Authorization: Bearer ' . $apiKey,
				],
				CURLOPT_HEADER          => true,
			]);
			$rawResponse = curl_exec($ch);
			$status      = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$headerSize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			$err         = curl_error($ch);
			curl_close($ch);

			$durMs = (int)((microtime(true) - $start) * 1000);
			$responseHeaders = is_string($rawResponse) ? substr($rawResponse, 0, $headerSize) : '';
			$responseBody    = is_string($rawResponse) ? substr($rawResponse, $headerSize)   : '';

			$this->logger->info('llm.openai.call', [
				'attempt'     => $attempt,
				'status'      => $status,
				'model'       => $payload['model'],
				'duration_ms' => $durMs,
			]);

			if ($status >= 200 && $status < 300 && $responseBody !== '') {
				$decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
				return $this->buildResponse(is_array($decoded) ? $decoded : [], $request->modelHint);
			}

			$retriable = ($status === 429 || $status >= 500) || $err !== '';
			if ($retriable && $attempt < self::MAX_RETRIES) {
				usleep((int)(2 ** $attempt * 500_000));
				continue;
			}

			$snippet = substr($responseBody, 0, 400);
			$this->logger->error('llm.openai.error', [
				'status'   => $status,
				'curl_err' => $err,
				'body'     => $snippet,
			]);

			// 429 = Rate Limit, 503 = Service Unavailable → Overload-Pattern.
			// 5xx generell als Overload behandeln — Caller (LlmRouter) wird
			// dann den naechsten Provider in der Chain probieren.
			if ($status === 429 || $status >= 500 || $err !== '') {
				$this->unhealthyUntil = time() + self::UNHEALTHY_COOLDOWN_S;
				$retryAfter = $this->parseRetryAfter($responseHeaders) ?? 30;
				throw new LlmOverloadedException(
					sprintf('OpenAI API ueberlastet (status=%d, attempt=%d)', $status, $attempt),
					$retryAfter,
				);
			}

			// 4xx (Auth, Bad Request, Quota) — kein Failover, das ist unser Bug.
			// Bei diesem Pfad ist $err per Logik oben immer '' (Overload-Branch
			// behandelt $err !== '' bereits).
			throw new RuntimeException(sprintf(
				'OpenAI API failed: status=%d attempt=%d body=%s',
				$status, $attempt, $snippet,
			));
		}
	}

	/**
	 * @return list<ModelDescriptor>
	 */
	public function listModels(): array
	{
		$row = $this->repo->findByKind('openai');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver OpenAI-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://api.openai.com');
		$url     = rtrim($baseUrl, '/') . '/v1/models';

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::DISCOVERY_TIMEOUT_S,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
		]);
		$resp   = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($err !== '' || $status < 200 || $status >= 300 || !is_string($resp)) {
			throw new LlmUnavailableException(sprintf(
				'OpenAI listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
			));
		}
		$decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
		return self::parseModelsResponse(is_array($decoded) ? $decoded : []);
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
		throw new RuntimeException(
			'OpenAI-Provider hat weder api_key_encrypted noch nutzbaren env-Fallback'
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function buildPayload(NormalizedRequest $request): array
	{
		// OpenAI braucht system inline als role=system (kein system-Top-Level
		// wie Anthropic). Cache-Hints werden ignoriert (OpenAI hat implicit
		// caching ab 1024 prompt-tokens, kein control noetig).
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
		if ($request->responseFormat === 'json_object') {
			$payload['response_format'] = ['type' => 'json_object'];
		}
		if ($request->effort !== null) {
			$payload['reasoning_effort'] = $request->effort;
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

		$usage  = $raw['usage'] ?? [];
		$cached = (int)($usage['prompt_tokens_details']['cached_tokens'] ?? 0);

		return new NormalizedResponse(
			content:      $content,
			usage:        [
				'inputTokens'  => (int)($usage['prompt_tokens']     ?? 0),
				'outputTokens' => (int)($usage['completion_tokens'] ?? 0),
				'cachedTokens' => $cached,
				'raw'          => is_array($usage) ? $usage : [],
			],
			finishReason: (string)($raw['choices'][0]['finish_reason'] ?? 'unknown'),
			modelId:      (string)($raw['model'] ?? $modelHint),
			providerKind: 'openai',
		);
	}

	private function parseRetryAfter(string $headers): ?int
	{
		if (preg_match('/^retry-after:\s*(\d+)/im', $headers, $m) === 1) {
			return (int)$m[1];
		}
		return null;
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
			if (!self::isChatModel($id)) {
				continue;
			}
			$out[] = new ModelDescriptor(
				modelId:      $id,
				displayName:  $id,
				effortLevels: ['low', 'medium', 'high'],
			);
		}
		return $out;
	}

	private static function isChatModel(string $id): bool
	{
		$lower = strtolower($id);
		foreach (self::NON_CHAT_SUBSTR as $bad) {
			if (str_contains($lower, $bad)) {
				return false;
			}
		}
		foreach (self::CHAT_PREFIXES as $p) {
			if (str_starts_with($lower, $p)) {
				return true;
			}
		}
		return false;
	}
}
