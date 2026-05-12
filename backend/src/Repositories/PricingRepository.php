<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use PDO;

/**
 * Per-model EUR pricing. Anthropic ships USD; admin maintains the EUR
 * column directly so we don't need an FX rate service.
 *
 * Costs are reported in EUR (DECIMAL(10,6)) so a 200-token Haiku call
 * costs ~€0.000920 — well below DECIMAL rounding range.
 */
final class PricingRepository
{
	/** @var array<string, array{input:float, output:float, cache_read:?float, cache_creation:?float}>|null */
	private ?array $cache = null;

	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * @param array{input:int, output:int, cache_read:int, cache_creation:int} $usage
	 */
	public function costEur(string $model, array $usage): float
	{
		$p = $this->priceFor($model);
		if ($p === null) return 0.0;

		$cost  = ($usage['input']  / 1_000_000) * $p['input'];
		$cost += ($usage['output'] / 1_000_000) * $p['output'];
		if ($p['cache_read'] !== null) {
			$cost += ($usage['cache_read']     / 1_000_000) * $p['cache_read'];
		}
		if ($p['cache_creation'] !== null) {
			$cost += ($usage['cache_creation'] / 1_000_000) * $p['cache_creation'];
		}
		return round($cost, 6);
	}

	/**
	 * @return list<array{model:string, input_eur_per_1m:float, output_eur_per_1m:float, cache_read_eur_per_1m:?float, cache_creation_eur_per_1m:?float, effective_from:string, updated_at:string}>
	 */
	public function all(): array
	{
		$rows = $this->db->query('SELECT model, input_eur_per_1m, output_eur_per_1m,
			cache_read_eur_per_1m, cache_creation_eur_per_1m, effective_from, updated_at
			FROM model_pricing ORDER BY model')->fetchAll(PDO::FETCH_ASSOC);
		return array_map(static fn(array $r): array => [
			'model'                     => (string)$r['model'],
			'input_eur_per_1m'          => (float)$r['input_eur_per_1m'],
			'output_eur_per_1m'         => (float)$r['output_eur_per_1m'],
			'cache_read_eur_per_1m'     => $r['cache_read_eur_per_1m']     !== null ? (float)$r['cache_read_eur_per_1m']     : null,
			'cache_creation_eur_per_1m' => $r['cache_creation_eur_per_1m'] !== null ? (float)$r['cache_creation_eur_per_1m'] : null,
			'effective_from'            => (string)$r['effective_from'],
			'updated_at'                => (string)$r['updated_at'],
		], $rows);
	}

	public function upsert(
		string $model,
		float $inputPer1m,
		float $outputPer1m,
		?float $cacheReadPer1m,
		?float $cacheCreationPer1m,
	): void {
		$stmt = $this->db->prepare('INSERT INTO model_pricing
			(model, input_eur_per_1m, output_eur_per_1m, cache_read_eur_per_1m, cache_creation_eur_per_1m)
			VALUES (:m, :i, :o, :cr, :cc)
			ON DUPLICATE KEY UPDATE
				input_eur_per_1m = VALUES(input_eur_per_1m),
				output_eur_per_1m = VALUES(output_eur_per_1m),
				cache_read_eur_per_1m = VALUES(cache_read_eur_per_1m),
				cache_creation_eur_per_1m = VALUES(cache_creation_eur_per_1m)');
		$stmt->execute([
			':m'  => $model,
			':i'  => $inputPer1m,
			':o'  => $outputPer1m,
			':cr' => $cacheReadPer1m,
			':cc' => $cacheCreationPer1m,
		]);
		$this->cache = null;
	}

	/**
	 * @return array{input:float, output:float, cache_read:?float, cache_creation:?float}|null
	 */
	private function priceFor(string $model): ?array
	{
		if ($this->cache === null) {
			$rows = $this->db->query('SELECT model, input_eur_per_1m, output_eur_per_1m,
				cache_read_eur_per_1m, cache_creation_eur_per_1m FROM model_pricing')->fetchAll(PDO::FETCH_ASSOC);
			$this->cache = [];
			foreach ($rows as $r) {
				$this->cache[(string)$r['model']] = [
					'input'          => (float)$r['input_eur_per_1m'],
					'output'         => (float)$r['output_eur_per_1m'],
					'cache_read'     => $r['cache_read_eur_per_1m']     !== null ? (float)$r['cache_read_eur_per_1m']     : null,
					'cache_creation' => $r['cache_creation_eur_per_1m'] !== null ? (float)$r['cache_creation_eur_per_1m'] : null,
				];
			}
		}
		return $this->cache[$model] ?? null;
	}
}
