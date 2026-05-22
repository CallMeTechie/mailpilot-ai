<?php
declare(strict_types=1);

namespace MailPilot\Llm;

use RuntimeException;
use Throwable;

/**
 * Phase 9q-B (Marc 2026-05-22) — der LlmRouter hat die gesamte Fallback-
 * Chain durchprobiert und JEDER Provider hat Overloaded/Unavailable
 * geworfen. Letzte Exception ist als $previous mitgegeben.
 *
 * Top-Level-Exception-Handler (Router.php in 9q-B.4) sollte das auf HTTP
 * 503 + Retry-After mappen — der User sieht denselben Toast wie bei
 * AnthropicOverloadedException (9p-Hotfix).
 */
final class LlmAllProvidersDownException extends RuntimeException
{
	public function __construct(string $message, ?Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
