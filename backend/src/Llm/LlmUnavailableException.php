<?php
declare(strict_types=1);

namespace MailPilot\Llm;

use RuntimeException;

/**
 * Phase 9q-A (Marc 2026-05-22) — Provider antwortet gar nicht (Connect-
 * Refused / DNS / Timeout / wiederholte 5xx ohne overloaded-Marker).
 * LlmRouter probiert den naechsten Provider in der Chain.
 */
final class LlmUnavailableException extends RuntimeException
{
}
