<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Util\Uuid;
use PDO;

/**
 * Writes per-call api_usage rows and upserts the matching usage_daily
 * aggregate in a single transaction so the dashboard stays consistent
 * even if the worker is killed mid-call.
 *
 * api_usage retention (~30d) is enforced by the worker housekeeping
 * loop via purgeOlderThan(), not here.
 */
final class UsageRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @param array{
	 *   tenant_id:string, user_id:?string, mailbox_id:?string, mail_id:?string,
	 *   prompt_version:string, model:string,
	 *   input_tokens:int, output_tokens:int,
	 *   cache_read_tokens:int, cache_creation_tokens:int,
	 *   cost_eur:float, duration_ms:int,
	 *   status:string, error_text:?string,
	 * } $u
	 */
	public function record(array $u): void
	{
		$id = Uuid::v4();
		$this->db->beginTransaction();
		try {
			$stmt = $this->db->prepare('INSERT INTO api_usage
				(id, tenant_id, user_id, mailbox_id, mail_id, prompt_version, model,
				 input_tokens, output_tokens, cache_read_tokens, cache_creation_tokens,
				 cost_eur, duration_ms, status, error_text)
				VALUES
				(:id, :t, :u, :mb, :mi, :pv, :m,
				 :it, :ot, :crt, :cct,
				 :c, :d, :s, :e)');
			$stmt->execute([
				':id'  => $id,
				':t'   => $u['tenant_id'],
				':u'   => $u['user_id'],
				':mb'  => $u['mailbox_id'],
				':mi'  => $u['mail_id'],
				':pv'  => $u['prompt_version'],
				':m'   => $u['model'],
				':it'  => $u['input_tokens'],
				':ot'  => $u['output_tokens'],
				':crt' => $u['cache_read_tokens'],
				':cct' => $u['cache_creation_tokens'],
				':c'   => $u['cost_eur'],
				':d'   => $u['duration_ms'],
				':s'   => $u['status'],
				':e'   => $u['error_text'],
			]);

			$blocked = $u['status'] === 'blocked' ? 1 : 0;
			$agg = $this->db->prepare('INSERT INTO usage_daily
				(`date`, tenant_id, user_id, prompt_version, model,
				 calls, input_tokens, output_tokens, cache_read_tokens, cache_creation_tokens,
				 cost_eur, blocked_count)
				VALUES (UTC_DATE(), :t, :u, :pv, :m, 1, :it, :ot, :crt, :cct, :c, :b)
				ON DUPLICATE KEY UPDATE
					calls = calls + 1,
					input_tokens = input_tokens + VALUES(input_tokens),
					output_tokens = output_tokens + VALUES(output_tokens),
					cache_read_tokens = cache_read_tokens + VALUES(cache_read_tokens),
					cache_creation_tokens = cache_creation_tokens + VALUES(cache_creation_tokens),
					cost_eur = cost_eur + VALUES(cost_eur),
					blocked_count = blocked_count + VALUES(blocked_count)');
			$agg->execute([
				':t'   => $u['tenant_id'],
				':u'   => $u['user_id'] ?? '',
				':pv'  => $u['prompt_version'],
				':m'   => $u['model'],
				':it'  => $u['input_tokens'],
				':ot'  => $u['output_tokens'],
				':crt' => $u['cache_read_tokens'],
				':cct' => $u['cache_creation_tokens'],
				':c'   => $u['cost_eur'],
				':b'   => $blocked,
			]);

			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	public function outputTokensToday(string $tenantId, ?string $userId): int
	{
		if ($userId === null) {
			$stmt = $this->db->prepare('SELECT COALESCE(SUM(output_tokens),0) FROM usage_daily
				WHERE `date` = UTC_DATE() AND tenant_id = :t');
			$stmt->execute([':t' => $tenantId]);
		} else {
			$stmt = $this->db->prepare('SELECT COALESCE(SUM(output_tokens),0) FROM usage_daily
				WHERE `date` = UTC_DATE() AND tenant_id = :t AND user_id = :u');
			$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		}
		return (int)$stmt->fetchColumn();
	}

	public function globalOutputTokensToday(): int
	{
		return (int)$this->db->query('SELECT COALESCE(SUM(output_tokens),0)
			FROM usage_daily WHERE `date` = UTC_DATE()')->fetchColumn();
	}

	/**
	 * @return array{calls:int, input_tokens:int, output_tokens:int, cost_eur:float, blocked:int}
	 */
	public function totalsSince(string $sinceDateUtc): array
	{
		$stmt = $this->db->prepare('SELECT
				COALESCE(SUM(calls),0)          AS calls,
				COALESCE(SUM(input_tokens),0)   AS input_tokens,
				COALESCE(SUM(output_tokens),0)  AS output_tokens,
				COALESCE(SUM(cost_eur),0)       AS cost_eur,
				COALESCE(SUM(blocked_count),0)  AS blocked
			FROM usage_daily WHERE `date` >= :since');
		$stmt->execute([':since' => $sinceDateUtc]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
		return [
			'calls'         => (int)($row['calls']         ?? 0),
			'input_tokens'  => (int)($row['input_tokens']  ?? 0),
			'output_tokens' => (int)($row['output_tokens'] ?? 0),
			'cost_eur'      => (float)($row['cost_eur']    ?? 0),
			'blocked'       => (int)($row['blocked']       ?? 0),
		];
	}

	/**
	 * @return list<array{date:string, calls:int, input_tokens:int, output_tokens:int, cost_eur:float}>
	 */
	public function dailyTrend(string $sinceDateUtc): array
	{
		$stmt = $this->db->prepare('SELECT `date`,
				SUM(calls) AS calls,
				SUM(input_tokens) AS input_tokens,
				SUM(output_tokens) AS output_tokens,
				SUM(cost_eur) AS cost_eur
			FROM usage_daily WHERE `date` >= :since
			GROUP BY `date` ORDER BY `date`');
		$stmt->execute([':since' => $sinceDateUtc]);
		return array_map(static fn(array $r): array => [
			'date'          => (string)$r['date'],
			'calls'         => (int)$r['calls'],
			'input_tokens'  => (int)$r['input_tokens'],
			'output_tokens' => (int)$r['output_tokens'],
			'cost_eur'      => (float)$r['cost_eur'],
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * @return list<array{prompt_version:string, calls:int, output_tokens:int, cost_eur:float}>
	 */
	public function breakdownByPromptSince(string $sinceDateUtc): array
	{
		$stmt = $this->db->prepare('SELECT prompt_version,
				SUM(calls) AS calls,
				SUM(output_tokens) AS output_tokens,
				SUM(cost_eur) AS cost_eur
			FROM usage_daily WHERE `date` >= :since
			GROUP BY prompt_version ORDER BY cost_eur DESC');
		$stmt->execute([':since' => $sinceDateUtc]);
		return array_map(static fn(array $r): array => [
			'prompt_version' => (string)$r['prompt_version'],
			'calls'          => (int)$r['calls'],
			'output_tokens'  => (int)$r['output_tokens'],
			'cost_eur'       => (float)$r['cost_eur'],
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * @return list<array{user_id:string, calls:int, output_tokens:int, cost_eur:float}>
	 */
	public function topUsersSince(string $sinceDateUtc, int $limit = 20): array
	{
		$stmt = $this->db->prepare('SELECT user_id,
				SUM(calls) AS calls,
				SUM(output_tokens) AS output_tokens,
				SUM(cost_eur) AS cost_eur
			FROM usage_daily WHERE `date` >= :since AND user_id != ""
			GROUP BY user_id ORDER BY cost_eur DESC LIMIT :lim');
		$stmt->bindValue(':since', $sinceDateUtc);
		$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
		$stmt->execute();
		return array_map(static fn(array $r): array => [
			'user_id'       => (string)$r['user_id'],
			'calls'         => (int)$r['calls'],
			'output_tokens' => (int)$r['output_tokens'],
			'cost_eur'      => (float)$r['cost_eur'],
		], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	public function purgeOlderThan(int $days): int
	{
		$stmt = $this->db->prepare('DELETE FROM api_usage
			WHERE created_at < (UTC_TIMESTAMP() - INTERVAL :d DAY)');
		$stmt->execute([':d' => $days]);
		return $stmt->rowCount();
	}
}
