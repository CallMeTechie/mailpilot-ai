<?php
declare(strict_types=1);

namespace MailPilot\Llm\Providers;

use MailPilot\Claude\AnthropicClient;
use MailPilot\Claude\AnthropicOverloadedException;
use MailPilot\Llm\LlmOverloadedException;
use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmUnavailableException;
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
