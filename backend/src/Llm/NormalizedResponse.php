<?php
declare(strict_types=1);

namespace MailPilot\Llm;

/**
 * Phase 9q-A (Marc 2026-05-22) — Provider-agnostische Antwort von Inferenz-
 * Calls. Provider uebersetzen ihre native Response (Anthropic content[],
 * OpenAI choices[].message.content, Gemini candidates[].content.parts[])
 * in dieses Format.
 *
 * usage-Felder pro Provider:
 *   - Anthropic: input_tokens, output_tokens, cache_creation_input_tokens,
 *     cache_read_input_tokens
 *   - OpenAI:    prompt_tokens (cached_tokens darin), completion_tokens
 *   - Gemini:    promptTokenCount, candidatesTokenCount, cachedContentTokenCount
 *
 * Wir mappen sie auf inputTokens / outputTokens / cachedTokens. Provider-
 * spezifische Felder (z.B. Anthropic cache_creation) gehen verloren — wer
 * sie braucht (UsageRepository fuer Cost-Tracking) liest sie aus dem raw-
 * Response der in usage['raw'] mitgegeben wird.
 */
final class NormalizedResponse
{
	/**
	 * @param array{
	 *   inputTokens: int,
	 *   outputTokens: int,
	 *   cachedTokens?: int,
	 *   raw?: array<string,mixed>,
	 * } $usage
	 */
	public function __construct(
		public readonly string $content,
		public readonly array  $usage,
		public readonly string $finishReason,
		public readonly string $modelId,
		public readonly string $providerKind,
	) {
	}
}
