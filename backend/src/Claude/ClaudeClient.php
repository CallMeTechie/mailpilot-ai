<?php
declare(strict_types=1);

namespace MailPilot\Claude;

/**
 * Compatibility wrapper. Delegates to AnthropicClient for direct API usage.
 *
 * New code should type-hint on ClaudeProvider and use ProviderFactory to
 * construct the right backend. This class remains for:
 *   - Existing Services that still reference ClaudeClient
 *   - The static extractText() helper used everywhere
 */
class ClaudeClient implements ClaudeProvider
{
	private readonly AnthropicClient $inner;

	public function __construct(
		string $apiKey,
		string $baseUrl,
		string $anthropicVersion,
		int $timeout,
		\Psr\Log\LoggerInterface $logger,
	) {
		$this->inner = new AnthropicClient($apiKey, $baseUrl, $anthropicVersion, $timeout, $logger);
	}

	public function messages(array $payload): array
	{
		return $this->inner->messages($payload);
	}

	/**
	 * Phase 9h.3 (Marc 2026-05-21) — Batch-Variante via curl_multi.
	 * Delegiert an AnthropicClient::messagesBatch.
	 *
	 * @param list<array<string, mixed>> $payloads
	 * @return list<array<string, mixed>|\RuntimeException>
	 */
	public function messagesBatch(array $payloads): array
	{
		return $this->inner->messagesBatch($payloads);
	}

	/**
	 * Extract concatenated text from a Claude response (any provider).
	 */
	public static function extractText(array $response): string
	{
		$out = '';
		foreach ($response['content'] ?? [] as $block) {
			if (($block['type'] ?? '') === 'text') {
				$out .= $block['text'] . "\n";
			}
		}
		return trim($out);
	}
}
