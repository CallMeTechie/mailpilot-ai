<?php
declare(strict_types=1);

namespace MailPilot\Graph;

/**
 * Carry-Over DA-Impl 6d Finding 3: Graph 429 mit Retry-After-Header.
 *
 * Wird vom GraphClient::get() geworfen, wenn ein 429 auch nach einem
 * Retry-Versuch persistiert. ReconciliationService fängt das speziell,
 * bricht die User-Iteration ab und macht beim nächsten Tag weiter —
 * statt mit einem generischen Throwable den ganzen Worker-Tick zu killen.
 */
final class GraphThrottledException extends \RuntimeException
{
	public function __construct(
		string $url,
		public readonly int $retryAfterSeconds,
		?\Throwable $previous = null,
	) {
		parent::__construct(
			"Graph throttled on {$url}, retry-after {$retryAfterSeconds}s",
			429,
			$previous,
		);
	}
}
