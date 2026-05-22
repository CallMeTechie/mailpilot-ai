<?php
declare(strict_types=1);

namespace MailPilot\Claude;

use RuntimeException;

/**
 * Phase 9p-Hotfix (Marc 2026-05-22) — markiert temporaere Anthropic-
 * Auslastung (HTTP 529 overloaded_error). Der Router fangt diese
 * Exception und mappt sie auf HTTP 503 + Retry-After-Header, damit das
 * Add-in einen Toast „KI-Anbieter ueberlastet" zeigen kann statt eines
 * generischen „Interner Fehler"-Bildes.
 */
final class AnthropicOverloadedException extends RuntimeException
{
	public function __construct(
		string $message = 'Anthropic API ueberlastet',
		public readonly int $retryAfterSeconds = 30,
	) {
		parent::__construct($message);
	}
}
