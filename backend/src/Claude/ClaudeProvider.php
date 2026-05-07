<?php
declare(strict_types=1);

namespace MailPilot\Claude;

/**
 * Abstract interface for anything that speaks the Claude Messages API.
 *
 * Implementations:
 *  - AnthropicClient — direct Anthropic API (default, USA hosting)
 *  - BedrockClient   — AWS Bedrock Runtime (EU-sovereign when using eu-central-1)
 *
 * The same payload shape works for both; the provider handles transport,
 * auth, and (for Bedrock) model ID translation.
 */
interface ClaudeProvider
{
	/**
	 * Send a messages request. Payload follows the Anthropic Messages API:
	 *   - model: string (logical model name, e.g. "claude-haiku-4-5-20251001")
	 *   - max_tokens: int
	 *   - temperature?: float
	 *   - system?: string
	 *   - messages: list<{role:string, content:string|array}>
	 *
	 * Returns the decoded JSON response with at minimum:
	 *   - content: list<{type:string, text?:string}>
	 *
	 * Implementations MUST:
	 *   - retry on 429/5xx with exponential backoff
	 *   - enforce a strict timeout
	 *   - NEVER log message bodies (PII!)
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function messages(array $payload): array;
}
