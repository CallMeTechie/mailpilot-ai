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
 * Phase 9q-E (Marc 2026-05-23) — Mistral (EU-basiert, DSGVO-freundlich).
 *
 * Endpoint: POST {base_url}/v1/chat/completions (default api.mistral.ai).
 * API ist OpenAI-kompatibel — eigener Provider statt OpenAiCompatible weil:
 *   - Admin-UI zeigt „Mistral" als eigenen Vendor (kind=mistral)
 *   - Cost-Tracking pro Provider getrennt (Mistral hat anderes Pricing)
 *   - EU-Hosting-Hinweis in Description (relevant fuer Privacy-Mode-Wahl)
 *
 * Pricing-Hint (2026-Q2):
 *   - mistral-small-latest:  $0.20 in / $0.60 out per Mtok
 *   - mistral-large-latest:  $2.00 in / $6.00 out per Mtok
 */
final class MistralProvider implements LlmProvider
{
	private const TIMEOUT_DEFAULT      = 60;
	private const MAX_RETRIES          = 3;
	private const UNHEALTHY_COOLDOWN_S = 60;
	private const DISCOVERY_TIMEOUT_S  = 10;

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
		return 'mistral';
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
		$row = $this->repo->findByKind('mistral');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver Mistral-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://api.mistral.ai');
		$url     = rtrim($baseUrl, '/') . '/v1/chat/completions';
		$payload = $this->buildPayload($request);
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
			]);
			$response = curl_exec($ch);
			$status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$err      = curl_error($ch);
			curl_close($ch);

			$durMs = (int)((microtime(true) - $start) * 1000);
			$this->logger->info('llm.mistral.call', [
				'attempt'     => $attempt,
				'status'      => $status,
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
			$this->logger->error('llm.mistral.error', [
				'status' => $status, 'curl_err' => $err, 'body' => $snippet,
			]);

			if ($status === 429 || $status >= 500 || $err !== '') {
				$this->unhealthyUntil = time() + self::UNHEALTHY_COOLDOWN_S;
				throw new LlmOverloadedException(
					sprintf('Mistral API ueberlastet (status=%d, attempt=%d)', $status, $attempt),
					30,
				);
			}
			throw new RuntimeException(sprintf(
				'Mistral API failed: status=%d body=%s', $status, $snippet,
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
		throw new RuntimeException(
			'Mistral-Provider hat weder api_key_encrypted noch nutzbaren env-Fallback'
		);
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
			providerKind: 'mistral',
		);
	}

	/**
	 * @return list<ModelDescriptor>
	 */
	public function listModels(): array
	{
		$row = $this->repo->findByKind('mistral');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver Mistral-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://api.mistral.ai');
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
				'Mistral listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
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
			if (($m['capabilities']['completion_chat'] ?? false) !== true) {
				continue;
			}
			$id = (string)$m['id'];
			$out[] = new ModelDescriptor(
				modelId:      $id,
				displayName:  (string)($m['name'] ?? $id),
				effortLevels: [],
			);
		}
		return $out;
	}
}
