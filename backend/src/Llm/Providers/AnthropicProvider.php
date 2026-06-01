<?php
declare(strict_types=1);

namespace MailPilot\Llm\Providers;

use MailPilot\Claude\AnthropicClient;
use MailPilot\Claude\AnthropicOverloadedException;
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
 * Phase 9q-A (Marc 2026-05-22) — Anthropic-Provider als LlmProvider-Impl.
 *
 * Wrapt den existierenden AnthropicClient und uebersetzt NormalizedRequest/
 * Response in/aus dem Anthropic-Payload-Shape. API-Key wird aus llm_providers
 * gelesen und mit SecretBox entschluesselt; bei NULL Encrypted-Spalte wird
 * der env-Var-Fallback verwendet (Migrations-Pfad).
 *
 * In 9q-A laufen MailScoringService etc. WEITER ueber AnthropicClient direkt
 * — dieser Wrapper ist parallel verfuegbar fuer den LlmRouter ab 9q-B.
 */
final class AnthropicProvider implements LlmProvider
{
	private const ANTHROPIC_VERSION_DEFAULT = '2023-06-01';
	private const TIMEOUT_DEFAULT = 60;
	private const UNHEALTHY_COOLDOWN_S = 60;
	private const DISCOVERY_TIMEOUT_S = 10;
	private const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

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
		return 'anthropic';
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
		$row = $this->repo->findByKind('anthropic');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver Anthropic-Provider in llm_providers');
		}
		$apiKey = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://api.anthropic.com');
		// Phase 9q-Hotfix (Marc 2026-05-23): AnthropicClient haengt nur
		// '/messages' an base_url, erwartet daher dass '/v1' im base_url
		// enthalten ist. Wenn nicht (z.B. neu angelegter Provider per
		// Admin-UI), ergaenzen wir es defensiv. Damit ist die UI-Default-
		// Erwartung 'https://api.anthropic.com' kompatibel zum legacy-
		// config.php-Pattern '.../v1'.
		if (!preg_match('#/v\d+/?$#', $baseUrl)) {
			$baseUrl = rtrim($baseUrl, '/') . '/v1';
		}

		$client = new AnthropicClient(
			$apiKey,
			$baseUrl,
			self::ANTHROPIC_VERSION_DEFAULT,
			$this->timeout,
			$this->logger,
		);

		$payload = $this->buildPayload($request);
		try {
			$response = $client->messages($payload);
		} catch (AnthropicOverloadedException $e) {
			$this->unhealthyUntil = time() + self::UNHEALTHY_COOLDOWN_S;
			throw new LlmOverloadedException($e->getMessage(), $e->retryAfterSeconds);
		} catch (RuntimeException $e) {
			// Transport/curl-Fehler → unavailable. Auth/4xx-Fehler ebenfalls
			// hier — der LlmRouter sollte aber bei Auth-Fehlern NICHT failovern,
			// das markieren wir spaeter genauer. Fuer 9q-A reicht „unavailable".
			$this->unhealthyUntil = time() + self::UNHEALTHY_COOLDOWN_S;
			throw new LlmUnavailableException($e->getMessage(), 0, $e);
		}

		return $this->buildResponse($response, $request->modelHint);
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
			'Anthropic-Provider hat weder api_key_encrypted noch nutzbaren env-Fallback'
		);
	}

	/**
	 * @return list<ModelDescriptor>
	 */
	public function listModels(): array
	{
		$row = $this->repo->findByKind('anthropic');
		if ($row === null) {
			throw new LlmUnavailableException('Kein aktiver Anthropic-Provider in llm_providers');
		}
		$apiKey  = $this->resolveApiKey($row);
		$baseUrl = (string)($row['base_url'] ?? 'https://api.anthropic.com');
		if (!preg_match('#/v\d+/?$#', $baseUrl)) {
			$baseUrl = rtrim($baseUrl, '/') . '/v1';
		}

		$out     = [];
		$afterId = null;
		do {
			$url = rtrim($baseUrl, '/') . '/models?limit=1000'
				. ($afterId !== null ? '&after_id=' . rawurlencode($afterId) : '');
			$json = $this->httpGetJson($url, [
				'x-api-key: ' . $apiKey,
				'anthropic-version: ' . self::ANTHROPIC_VERSION_DEFAULT,
			]);
			foreach (self::parseModelsResponse($json) as $d) {
				$out[] = $d;
			}
			$afterId = ($json['has_more'] ?? false) === true ? ($json['last_id'] ?? null) : null;
		} while (is_string($afterId) && $afterId !== '');

		return $out;
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
			$effortCap = $m['capabilities']['effort'] ?? [];
			$levels = [];
			if (is_array($effortCap) && ($effortCap['supported'] ?? false) === true) {
				foreach (self::EFFORT_LEVELS as $lvl) {
					if (($effortCap[$lvl]['supported'] ?? false) === true) {
						$levels[] = $lvl;
					}
				}
			}
			$maxOut = (int)($m['max_tokens'] ?? 0);
			$maxCtx = (int)($m['max_input_tokens'] ?? 0);
			$out[] = new ModelDescriptor(
				modelId:          (string)$m['id'],
				displayName:      (string)($m['display_name'] ?? $m['id']),
				effortLevels:     $levels,
				maxOutputTokens:  $maxOut > 0 ? $maxOut : null,
				maxContextTokens: $maxCtx > 0 ? $maxCtx : null,
				releasedAt:       isset($m['created_at']) ? (string)$m['created_at'] : null,
			);
		}
		return $out;
	}

	/**
	 * GET → decoded JSON. Wirft LlmUnavailableException bei Transport-/HTTP-Fehler.
	 *
	 * @param  list<string> $headers
	 * @return array<string,mixed>
	 */
	private function httpGetJson(string $url, array $headers): array
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => self::DISCOVERY_TIMEOUT_S,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
		]);
		$resp   = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$err    = curl_error($ch);
		curl_close($ch);

		if ($err !== '' || $status < 200 || $status >= 300 || !is_string($resp)) {
			throw new LlmUnavailableException(sprintf(
				'Anthropic listModels failed: status=%d curlErr=%s', $status, $err ?: 'none',
			));
		}
		$decoded = json_decode($resp, true, 512, JSON_THROW_ON_ERROR);
		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildPayload(NormalizedRequest $request): array
	{
		$systemSegments = [[
			'type' => 'text',
			'text' => $request->systemPrompt,
		]];
		// Phase 9q-A: cacheSegments-Index 0 markiert das System-Prompt als
		// 1h-cached. Sobald wir mehr Segmente unterstuetzen, iterieren wir.
		if (in_array(0, $request->cacheSegments, true)) {
			$systemSegments[0]['cache_control'] = ['type' => 'ephemeral', 'ttl' => '1h'];
		}

		$messages = array_map(
			static fn(array $m): array => [
				'role'    => $m['role'],
				'content' => $m['content'],
			],
			$request->messages,
		);

		return [
			'model'       => $request->modelHint,
			'max_tokens'  => $request->maxTokens,
			'temperature' => $request->temperature,
			'system'      => $systemSegments,
			'messages'    => $messages,
		];
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function buildResponse(array $raw, string $modelHint): NormalizedResponse
	{
		$contentBlocks = $raw['content'] ?? [];
		$content = '';
		if (is_array($contentBlocks)) {
			foreach ($contentBlocks as $block) {
				if (is_array($block) && ($block['type'] ?? '') === 'text') {
					$content .= (string)($block['text'] ?? '');
				}
			}
		}

		$usage = $raw['usage'] ?? [];
		return new NormalizedResponse(
			content:      $content,
			usage:        [
				'inputTokens'  => (int)($usage['input_tokens']  ?? 0),
				'outputTokens' => (int)($usage['output_tokens'] ?? 0),
				'cachedTokens' => (int)($usage['cache_read_input_tokens'] ?? 0),
				'raw'          => is_array($usage) ? $usage : [],
			],
			finishReason: (string)($raw['stop_reason'] ?? 'unknown'),
			modelId:      (string)($raw['model'] ?? $modelHint),
			providerKind: 'anthropic',
		);
	}
}
