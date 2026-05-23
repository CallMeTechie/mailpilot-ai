<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Phase 9q-H (Marc 2026-05-23) — Persistenz fuer das Golden-Set + Run-
 * Historie + per-Mail Detail.
 */
final class LlmGoldenRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listSet(bool $includeDisabled = false): array
	{
		$sql = 'SELECT * FROM llm_golden_set WHERE 1=1';
		if (!$includeDisabled) {
			$sql .= ' AND enabled = 1';
		}
		$sql .= ' ORDER BY created_at ASC';
		return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
	}

	public function insertRun(string $providerId, string $modelIdStr, string $role): string
	{
		$id = Uuid::v4();
		$this->db->prepare(
			'INSERT INTO llm_golden_run
				(id, provider_id, model_id_str, role, status)
			 VALUES (:id, :p, :m, :r, "running")'
		)->execute([
			':id' => $id, ':p' => $providerId,
			':m'  => substr($modelIdStr, 0, 120),
			':r'  => $role,
		]);
		return $id;
	}

	/**
	 * @param array{
	 *   total_mails:int,
	 *   correct_label:int,
	 *   correct_priority:int,
	 *   avg_latency_ms:int,
	 *   total_cost_usd:float,
	 *   status:string,
	 *   error_msg?:?string
	 * } $totals
	 */
	public function updateRunDone(string $runId, array $totals): void
	{
		$this->db->prepare(
			'UPDATE llm_golden_run
			 SET finished_at      = UTC_TIMESTAMP(3),
			     total_mails      = :t,
			     correct_label    = :cl,
			     correct_priority = :cp,
			     avg_latency_ms   = :lat,
			     total_cost_usd   = :cost,
			     status           = :st,
			     error_msg        = :err
			 WHERE id = :id'
		)->execute([
			':t'    => (int)$totals['total_mails'],
			':cl'   => (int)$totals['correct_label'],
			':cp'   => (int)$totals['correct_priority'],
			':lat'  => (int)$totals['avg_latency_ms'],
			':cost' => (float)$totals['total_cost_usd'],
			':st'   => $totals['status'],
			':err'  => isset($totals['error_msg']) && $totals['error_msg'] !== null
				? substr((string)$totals['error_msg'], 0, 500) : null,
			':id'   => $runId,
		]);
	}

	public function insertDetail(
		string $runId,
		string $goldenId,
		?string $predictedLabel,
		?int $predictedPriority,
		int $latencyMs,
		bool $labelOk,
		bool $priorityOk,
		?string $errorMsg = null,
	): void {
		$this->db->prepare(
			'INSERT INTO llm_golden_run_detail
				(id, run_id, golden_id, predicted_label, predicted_priority,
				 latency_ms, label_ok, priority_ok, error_msg)
			 VALUES (:id, :r, :g, :pl, :pp, :lat, :lok, :pok, :err)'
		)->execute([
			':id'  => Uuid::v4(),
			':r'   => $runId,
			':g'   => $goldenId,
			':pl'  => $predictedLabel !== null ? substr($predictedLabel, 0, 20) : null,
			':pp'  => $predictedPriority,
			':lat' => $latencyMs,
			':lok' => $labelOk ? 1 : 0,
			':pok' => $priorityOk ? 1 : 0,
			':err' => $errorMsg !== null ? substr($errorMsg, 0, 500) : null,
		]);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function listRecentRuns(int $limit = 50): array
	{
		$stmt = $this->db->prepare(
			'SELECT r.*, p.name AS provider_name, p.kind AS provider_kind
			 FROM llm_golden_run r
			 LEFT JOIN llm_providers p ON p.id = r.provider_id
			 ORDER BY r.started_at DESC
			 LIMIT :lim'
		);
		$stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Phase 9q B2 (Marc 2026-05-23): einzelnen Run löschen. CASCADE
	 * räumt llm_golden_run_detail mit.
	 */
	public function deleteRun(string $runId): bool
	{
		$stmt = $this->db->prepare('DELETE FROM llm_golden_run WHERE id = :id');
		$stmt->execute([':id' => $runId]);
		return $stmt->rowCount() > 0;
	}

	/**
	 * Bulk-Cleanup: alle Runs älter als N Tage löschen.
	 * Returnt Anzahl gelöschter Rows.
	 */
	public function deleteRunsOlderThan(int $days): int
	{
		$stmt = $this->db->prepare(
			'DELETE FROM llm_golden_run
			 WHERE started_at < (UTC_TIMESTAMP(3) - INTERVAL :d DAY)'
		);
		$stmt->bindValue(':d', max(1, $days), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function runDetails(string $runId): array
	{
		$stmt = $this->db->prepare(
			'SELECT d.*, g.subject, g.expected_label, g.expected_priority, g.notes
			 FROM llm_golden_run_detail d
			 INNER JOIN llm_golden_set g ON g.id = d.golden_id
			 WHERE d.run_id = :r
			 ORDER BY g.subject ASC'
		);
		$stmt->execute([':r' => $runId]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
}
