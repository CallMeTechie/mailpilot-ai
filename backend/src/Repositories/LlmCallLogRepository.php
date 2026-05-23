<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Phase 9q-G (Marc 2026-05-23) — Audit-Log fuer LLM-Inferenz-Calls.
 *
 * Insert pro Call vom LlmCallLogger (best-effort, schluckt Errors damit
 * der eigentliche Inferenz-Pfad nicht durch Logging-Probleme bricht).
 * Aggregations-Methoden fuer das Admin-Dashboard (Phase 9q-G.4).
 */
final class LlmCallLogRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @param array{
	 *   provider_id: string,
	 *   model_id_str: string,
	 *   role: string,
	 *   input_tokens?: int,
	 *   output_tokens?: int,
	 *   cached_tokens?: int,
	 *   latency_ms?: int,
	 *   status: string,
	 *   error_msg?: ?string,
	 *   cost_usd?: float,
	 * } $row
	 */
	public function insert(array $row): void
	{
		$stmt = $this->db->prepare(
			'INSERT INTO llm_call_log
				(id, provider_id, model_id_str, role,
				 input_tokens, output_tokens, cached_tokens,
				 latency_ms, status, error_msg, cost_usd)
			 VALUES
				(:id, :p, :m, :r,
				 :it, :ot, :ct,
				 :lat, :st, :err, :cost)'
		);
		$stmt->execute([
			':id'   => Uuid::v4(),
			':p'    => $row['provider_id'],
			':m'    => substr((string)$row['model_id_str'], 0, 120),
			':r'    => $row['role'],
			':it'   => (int)($row['input_tokens']  ?? 0),
			':ot'   => (int)($row['output_tokens'] ?? 0),
			':ct'   => (int)($row['cached_tokens'] ?? 0),
			':lat'  => (int)($row['latency_ms']    ?? 0),
			':st'   => $row['status'],
			':err'  => $row['error_msg'] !== null ? substr((string)$row['error_msg'], 0, 500) : null,
			':cost' => (float)($row['cost_usd']    ?? 0.0),
		]);
	}

	/**
	 * Aggregation pro Provider fuer das Dashboard.
	 *
	 * @return list<array{
	 *   provider_id:string, calls:int, errors:int,
	 *   input_tokens:int, output_tokens:int, cached_tokens:int,
	 *   total_usd:float, avg_latency_ms:int
	 * }>
	 */
	public function aggregateByProvider(int $sinceDays = 30): array
	{
		$sql = 'SELECT provider_id,
				COUNT(*)                                         AS calls,
				SUM(CASE WHEN status <> "ok" THEN 1 ELSE 0 END)  AS errors,
				SUM(input_tokens)                                AS input_tokens,
				SUM(output_tokens)                               AS output_tokens,
				SUM(cached_tokens)                               AS cached_tokens,
				SUM(cost_usd)                                    AS total_usd,
				AVG(latency_ms)                                  AS avg_latency_ms
			FROM llm_call_log
			WHERE created_at >= (UTC_TIMESTAMP(3) - INTERVAL :d DAY)
			GROUP BY provider_id';
		$stmt = $this->db->prepare($sql);
		$stmt->bindValue(':d', max(1, $sinceDays), PDO::PARAM_INT);
		$stmt->execute();
		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		return array_map(static fn(array $r): array => [
			'provider_id'    => (string)$r['provider_id'],
			'calls'          => (int)$r['calls'],
			'errors'         => (int)$r['errors'],
			'input_tokens'   => (int)$r['input_tokens'],
			'output_tokens'  => (int)$r['output_tokens'],
			'cached_tokens'  => (int)$r['cached_tokens'],
			'total_usd'      => (float)$r['total_usd'],
			'avg_latency_ms' => (int)round((float)$r['avg_latency_ms']),
		], $rows);
	}

	/**
	 * Aggregation pro Provider+Model+Role — fuer Cost-Drilldown.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function aggregateByProviderModel(int $sinceDays = 30): array
	{
		$sql = 'SELECT provider_id, model_id_str, role,
				COUNT(*) AS calls,
				SUM(input_tokens)  AS input_tokens,
				SUM(output_tokens) AS output_tokens,
				SUM(cost_usd)      AS total_usd
			FROM llm_call_log
			WHERE created_at >= (UTC_TIMESTAMP(3) - INTERVAL :d DAY)
			GROUP BY provider_id, model_id_str, role
			ORDER BY total_usd DESC, calls DESC';
		$stmt = $this->db->prepare($sql);
		$stmt->bindValue(':d', max(1, $sinceDays), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
}
