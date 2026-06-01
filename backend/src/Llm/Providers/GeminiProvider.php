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
 * Phase 9q-E (Marc 2026-05-23) — Google Gemini (Vertex AI) Provider.
 *
 * Endpoint: POST {base_url}/v1beta/models/{model}:generateContent?key={api_key}
 * Default base_url: https://generativelanguage.googleapis.com
 *
 * Unterschiede zur OpenAI/Anthropic-API:
 *   - system-prompt liegt in einem separaten Top-Level-Feld
 *     `systemInstruction.parts[].text` (kein role=system im messages-Array)
 *   - messages-Array heisst `contents`, role 'assistant' heisst 'model'
 *   - JSON-Mode via `generationConfig.responseMimeType='application/json'`
 *     plus optional responseSchema fuer strict-JSON
 *   - API-Key als Query-Param ?key=..., nicht im Header
 *   - Token-Usage: promptTokenCount, candidatesTokenCount,
 *     cachedContentTokenCount (Context Caching mit eigenen Cache-IDs)
 *
 * Pricing-Hint (2026-Q2):
 *   - gemini-2.0-flash:  $0.075 in / $0.30 out per Mtok (deutlich
 *     guenstiger als OpenAI gpt-4o-mini)
 *   - gemini-2.5-pro:    $1.25  in / $10.00 out per Mtok
 */
final class GeminiProvider implements LlmProvider
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
		return 'gemini';
	}

	/**
	 * @return list<ModelDescriptor>
	 */
	public function listModels(): array
	{
		$row = $this->repo->findByKind('gemini');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver Gemini-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://generativelanguage.googleapis.com');
		$url     = sprintf('%s/v1beta/models?pageSize=1000&key=%s', rtrim($baseUrl, '/'), rawurlencode($apiKey));

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::DISCOVERY_TIMEOUT_S,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
		]);
		$resp   = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($err !== '' || $status < 200 || $status >= 300 || !is_string($resp)) {
			throw new LlmUnavailableException(sprintf(
				'Gemini listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
			));
		}
		$decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
		return self::parseModelsResponse(is_array($decoded) ? $decoded : []);
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
		$row = $this->repo->findByKind('gemini');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver Gemini-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://generativelanguage.googleapis.com');
		$url = sprintf(
			'%s/v1beta/models/%s:generateContent?key=%s',
			rtrim($baseUrl, '/'),
			rawurlencode($request->modelHint),
			rawurlencode($apiKey),
		);

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
				CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
			]);
			$response = curl_exec($ch);
			$status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			$err      = curl_error($ch);
			curl_close($ch);

			$durMs = (int)((microtime(true) - $start) * 1000);
			$this->logger->info('llm.gemini.call', [
				'attempt'     => $attempt,
				'status'      => $status,
				'model'       => $request->modelHint,
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
			$this->logger->error('llm.gemini.error', [
				'status' => $status, 'curl_err' => $err, 'body' => $snippet,
			]);

			if ($status === 429 || $status >= 500 || $err !== '') {
				$this->unhealthyUntil = time() + self::UNHEALTHY_COOLDOWN_S;
				throw new LlmOverloadedException(
					sprintf('Gemini API ueberlastet (status=%d, attempt=%d)', $status, $attempt),
					30,
				);
			}
			throw new RuntimeException(sprintf(
				'Gemini API failed: status=%d body=%s', $status, $snippet,
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
			'Gemini-Provider hat weder api_key_encrypted noch nutzbaren env-Fallback'
		);
	}

	/**
	 * @param  array<string,mixed> $json
	 * @return list<ModelDescriptor>
	 */
	public static function parseModelsResponse(array $json): array
	{
		$out = [];
		foreach (($json['models'] ?? []) as $m) {
			if (!is_array($m) || !isset($m['name'])) {
				continue;
			}
			$methods = $m['supportedGenerationMethods'] ?? [];
			if (!is_array($methods) || !in_array('generateContent', $methods, true)) {
				continue;
			}
			$id     = (string)preg_replace('#^models/#', '', (string)$m['name']);
			$maxOut = (int)($m['outputTokenLimit'] ?? 0);
			$maxCtx = (int)($m['inputTokenLimit'] ?? 0);
			$out[] = new ModelDescriptor(
				modelId:          $id,
				displayName:      (string)($m['displayName'] ?? $id),
				effortLevels:     [],
				maxOutputTokens:  $maxOut > 0 ? $maxOut : null,
				maxContextTokens: $maxCtx > 0 ? $maxCtx : null,
			);
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildPayload(NormalizedRequest $request): array
	{
		$contents = [];
		foreach ($request->messages as $m) {
			$role = $m['role'] === 'assistant' ? 'model' : $m['role'];
			$contents[] = [
				'role'  => $role,
				'parts' => [['text' => $m['content']]],
			];
		}

		$payload = [
			'systemInstruction' => [
				'parts' => [['text' => $request->systemPrompt]],
			],
			'contents'         => $contents,
			'generationConfig' => [
				'maxOutputTokens' => $request->maxTokens,
				'temperature'     => $request->temperature,
			],
		];
		if ($request->responseFormat === 'json_object') {
			$payload['generationConfig']['responseMimeType'] = 'application/json';
		}
		return $payload;
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function buildResponse(array $raw, string $modelHint): NormalizedResponse
	{
		// Gemini liefert candidates[].content.parts[].text — wir konkatieren
		// alle text-parts vom ersten candidate.
		$content = '';
		$parts   = $raw['candidates'][0]['content']['parts'] ?? [];
		if (is_array($parts)) {
			foreach ($parts as $p) {
				if (is_array($p) && isset($p['text'])) {
					$content .= (string)$p['text'];
				}
			}
		}

		$usage = $raw['usageMetadata'] ?? [];
		return new NormalizedResponse(
			content:      $content,
			usage:        [
				'inputTokens'  => (int)($usage['promptTokenCount']        ?? 0),
				'outputTokens' => (int)($usage['candidatesTokenCount']    ?? 0),
				'cachedTokens' => (int)($usage['cachedContentTokenCount'] ?? 0),
				'raw'          => is_array($usage) ? $usage : [],
			],
			finishReason: (string)($raw['candidates'][0]['finishReason'] ?? 'unknown'),
			modelId:      $modelHint,
			providerKind: 'gemini',
		);
	}
}
