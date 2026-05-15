<?php
declare(strict_types=1);

namespace MailPilot\Services;

/**
 * Thrown when UsageCounterRepository::incrementOrFail detects that the
 * caller has already used up their daily quota for a given action kind
 * (e.g. rule_inference, auto_reply). Caught at the controller boundary
 * to return 429 with code=QUOTA_EXCEEDED so the add-in can show a
 * specific toast.
 *
 * Distinct from BudgetExceededException — that one is for Claude token
 * costs (BudgetService.consumeTokens); this one is for per-user action
 * caps that don't track token spend.
 */
final class QuotaExceededException extends \RuntimeException
{
	public function __construct(
		public readonly string $kind,
		public readonly int    $cap,
		public readonly int    $current,
		string $message = '',
	) {
		parent::__construct(
			$message !== ''
				? $message
				: sprintf('Quota für %s erreicht (%d/%d)', $kind, $current, $cap)
		);
	}
}
