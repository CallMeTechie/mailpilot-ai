<?php
declare(strict_types=1);

namespace MailPilot\Services;

/**
 * Thrown from any Claude-using service when BudgetService rejects a
 * call. Caught at the HTTP boundary (MailController) to return 429
 * with code=BUDGET_EXCEEDED so the add-in can show a specific toast.
 *
 * Field $scope is one of: 'global' | 'tenant' | 'user'. The add-in
 * surfaces it as part of the user-visible message.
 */
final class BudgetExceededException extends \RuntimeException
{
	public function __construct(public readonly string $scope, string $message = '')
	{
		parent::__construct($message !== '' ? $message : "Tageslimit ({$scope}) erreicht");
	}
}
