<?php
declare(strict_types=1);

namespace MailPilot\Llm;

use MailPilot\Repositories\LlmCallLogRepository;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Phase 9q-G (Marc 2026-05-23) — Logger zwischen LlmRouter und Audit-DB.
 *
 * Best-effort: alle DB-Inserts/-Updates in try/catch. Logging-Probleme
 * duerfen den Inferenz-Pfad NIE blockieren.
 *
 * Health-Update via EWMA (Exponentially Weighted Moving Average):
 *   avg = 0.2 * latency + 0.8 * old_avg
 * Smooth genug fuer Dashboard, reaktiv genug fuer Outage-Erkennung.
 */
final class LlmCallLogger
{
	private const EWMA_ALPHA = 0.2;

	public function __construct(
		private readonly LlmCallLogRepository $log,
		private readonly LlmModelRepository $models,
		private readonly LlmProviderRepository $providers,
		private readonly LoggerInterface $sysLog,
	) {
	}

	public function logSuccess(string $providerId, string $role, NormalizedResponse $resp, int $latencyMs): void
	{
		$cost = $this->computeCost(
			$providerId, $resp->modelId, $role,
			$resp->usage['inputTokens']  ?? 0,
			$resp->usage['outputTokens'] ?? 0,
		);

		try {
			$this->log->insert([
				'provider_id'   => $providerId,
				'model_id_str'  => $resp->modelId,
				'role'          => $role,
				'input_tokens'  => $resp->usage['inputTokens']  ?? 0,
				'output_tokens' => $resp->usage['outputTokens'] ?? 0,
				'cached_tokens' => $resp->usage['cachedTokens'] ?? 0,
				'latency_ms'    => $latencyMs,
				'status'        => 'ok',
				'error_msg'     => null,
				'cost_usd'      => $cost,
			]);
			$this->updateHealthOk($providerId, $latencyMs);
		} catch (Throwable $e) {
			$this->sysLog->warning('llm_call_logger.log_failed', [
				'provider_id' => $providerId, 'err' => $e->getMessage(),
			]);
		}
	}

	public function logFailure(
		string $providerId,
		string $role,
		string $modelHint,
		string $status,
		string $errorMsg,
		int $latencyMs,
	): void {
		try {
			$this->log->insert([
				'provider_id'   => $providerId,
				'model_id_str'  => $modelHint,
				'role'          => $role,
				'input_tokens'  => 0,
				'output_tokens' => 0,
				'cached_tokens' => 0,
				'latency_ms'    => $latencyMs,
				'status'        => $status,
				'error_msg'     => $errorMsg,
				'cost_usd'      => 0.0,
			]);
			$this->updateHealthError($providerId, $errorMsg);
		} catch (Throwable $e) {
			$this->sysLog->warning('llm_call_logger.log_failed', [
				'provider_id' => $providerId, 'err' => $e->getMessage(),
			]);
		}
	}

	private function computeCost(string $providerId, string $modelIdStr, string $role, int $inTokens, int $outTokens): float
	{
		// Lookup im llm_models — Cost-Felder koennen NULL sein (lokale Modelle).
		$row = $this->models->findForProviderAndRole($providerId, $role);
		if ($row === null || $row['cost_per_mtok_in'] === null || $row['cost_per_mtok_out'] === null) {
			return 0.0;
		}
		$cin  = (float)$row['cost_per_mtok_in'];
		$cout = (float)$row['cost_per_mtok_out'];
		return ($inTokens / 1_000_000.0) * $cin + ($outTokens / 1_000_000.0) * $cout;
	}

	private function updateHealthOk(string $providerId, int $latencyMs): void
	{
		$existing = $this->providers->findById($providerId);
		$oldAvg = 0;
		if ($existing !== null && $existing['health'] !== null) {
			$decoded = json_decode((string)$existing['health'], true);
			if (is_array($decoded) && isset($decoded['avg_latency_ms'])) {
				$oldAvg = (int)$decoded['avg_latency_ms'];
			}
		}
		$newAvg = $oldAvg === 0
			? $latencyMs
			: (int)round(self::EWMA_ALPHA * $latencyMs + (1 - self::EWMA_ALPHA) * $oldAvg);

		$this->providers->markHealth($providerId, [
			'last_ok_at'     => gmdate('Y-m-d\TH:i:s\Z'),
			'avg_latency_ms' => $newAvg,
			'last_error'     => null,
		]);
	}

	private function updateHealthError(string $providerId, string $errorMsg): void
	{
		$this->providers->markHealth($providerId, [
			'last_error_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'last_error'    => substr($errorMsg, 0, 200),
		]);
	}
}
