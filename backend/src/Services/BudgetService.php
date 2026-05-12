<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageRepository;

/**
 * Token budget gate + usage recorder.
 *
 * Three independent ceilings, all checked before a call goes out:
 *   - budget.global.daily_tokens   (system-wide output tokens / day)
 *   - budget.tenant.daily_tokens   (per tenant)
 *   - budget.user.daily_tokens     (per user)
 *
 * "Output tokens" because that is what Anthropic charges for and what
 * grows with the user's actual demand. After every call recordUsage()
 * persists the row so soft-rollout (mode = "log_only") still produces
 * telemetry.
 */
final class BudgetService
{
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly UsageRepository $usage,
		private readonly PricingRepository $pricing,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{ok:bool, reason:?string, scope:?string}
	 *   reason = 'budget_user' | 'budget_tenant' | 'budget_global' | null
	 */
	public function canSpend(string $tenantId, ?string $userId, int $estimatedOutputTokens): array
	{
		$mode = $this->settings->getString('budget.enforcement_mode', 'enforce');
		$enforce = $mode === 'enforce';

		$globalUsed = $this->usage->globalOutputTokensToday();
		$globalLim  = $this->settings->getInt('budget.global.daily_tokens', 5_000_000);
		if ($globalLim > 0 && $globalUsed + $estimatedOutputTokens > $globalLim) {
			return $this->decision('budget_global', 'global', $enforce);
		}

		$tenantUsed = $this->usage->outputTokensToday($tenantId, null);
		$tenantLim  = $this->settings->getInt('budget.tenant.daily_tokens', 2_000_000);
		if ($tenantLim > 0 && $tenantUsed + $estimatedOutputTokens > $tenantLim) {
			return $this->decision('budget_tenant', 'tenant', $enforce);
		}

		if ($userId !== null) {
			$userUsed = $this->usage->outputTokensToday($tenantId, $userId);
			$userLim  = $this->settings->getInt('budget.user.daily_tokens', 100_000);
			if ($userLim > 0 && $userUsed + $estimatedOutputTokens > $userLim) {
				return $this->decision('budget_user', 'user', $enforce);
			}
		}

		return ['ok' => true, 'reason' => null, 'scope' => null];
	}

	/**
	 * @param array{
	 *   tenant_id:string, user_id:?string, mailbox_id:?string, mail_id:?string,
	 *   prompt_version:string, model:string,
	 *   input_tokens:int, output_tokens:int,
	 *   cache_read_tokens:int, cache_creation_tokens:int,
	 *   duration_ms:int,
	 *   status:string, error_text:?string,
	 * } $call
	 */
	public function recordUsage(array $call): void
	{
		$cost = $this->pricing->costEur($call['model'], [
			'input'          => $call['input_tokens'],
			'output'         => $call['output_tokens'],
			'cache_read'     => $call['cache_read_tokens'],
			'cache_creation' => $call['cache_creation_tokens'],
		]);

		try {
			$this->usage->record($call + ['cost_eur' => $cost]);
		} catch (\Throwable $e) {
			$this->logger->warning('budget.record_failed', [
				'err'   => $e->getMessage(),
				'model' => $call['model'],
			]);
		}
	}

	/**
	 * @return array{ok:bool, reason:string, scope:string}
	 */
	private function decision(string $reason, string $scope, bool $enforce): array
	{
		$this->logger->info($enforce ? 'budget.block' : 'budget.would_block', [
			'reason' => $reason, 'scope' => $scope,
		]);
		return ['ok' => !$enforce, 'reason' => $reason, 'scope' => $scope];
	}
}
