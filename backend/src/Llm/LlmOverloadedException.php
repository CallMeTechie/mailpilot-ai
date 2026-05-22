<?php
declare(strict_types=1);

namespace MailPilot\Llm;

use RuntimeException;

/**
 * Phase 9q-A (Marc 2026-05-22) — Provider-agnostische „temporaer ueberlastet"-
 * Markierung. LlmRouter faengt das ab und probiert den naechsten Provider
 * in der Fallback-Chain.
 *
 * Loest die anthropic-spezifische AnthropicOverloadedException (9p-Hotfix)
 * langfristig ab — bleibt bestehen bis MailScoringService etc. komplett
 * auf LlmRouter umgestellt sind.
 */
final class LlmOverloadedException extends RuntimeException
{
	public function __construct(
		string $message = 'LLM-Provider ueberlastet',
		public readonly int $retryAfterSeconds = 30,
	) {
		parent::__construct($message);
	}
}
