<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use PDO;

/**
 * Key/value store backed by the system_settings table.
 *
 * Settings are read often (every Claude call needs budget limits) and
 * rarely written (admin form submits). Per-request in-memory cache;
 * wrap this class with Redis if reads ever get hot enough.
 */
final class SettingsRepository
{
	/** @var array<string, string> */
	private array $cache = [];
	private bool $cacheLoaded = false;

	public function __construct(private readonly PDO $db)
	{
	}

	public function getInt(string $key, int $default = 0): int
	{
		$v = $this->getRaw($key);
		return $v === null ? $default : (int)$v;
	}

	public function getString(string $key, string $default = ''): string
	{
		return $this->getRaw($key) ?? $default;
	}

	public function getBool(string $key, bool $default = false): bool
	{
		$v = $this->getRaw($key);
		if ($v === null) return $default;
		return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
	}

	/**
	 * @return list<array{key:string, value:string, type:string, description:?string, updated_at:string}>
	 */
	public function all(): array
	{
		$rows = $this->db->query('SELECT `key`, `value`, `type`, description, updated_at
			FROM system_settings ORDER BY `key`')->fetchAll(PDO::FETCH_ASSOC);
		return array_map(static fn(array $r): array => [
			'key'         => (string)$r['key'],
			'value'       => (string)$r['value'],
			'type'        => (string)$r['type'],
			'description' => $r['description'] !== null ? (string)$r['description'] : null,
			'updated_at'  => (string)$r['updated_at'],
		], $rows);
	}

	public function set(string $key, string $value): void
	{
		// UPSERT: bisher reines UPDATE → wenn die Row nicht existierte
		// (z.B. Setting wird zum ersten Mal vom Admin-Panel gespeichert),
		// schrieb UPDATE auf 0 Rows → der Wert "sprang zurück" beim
		// Reload. INSERT … ON DUPLICATE KEY UPDATE deckt beide Fälle.
		$stmt = $this->db->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES (:k, :v, "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
		$stmt->execute([':v' => $value, ':k' => $key]);
		$this->cache[$key] = $value;
	}

	private function getRaw(string $key): ?string
	{
		$this->load();
		return $this->cache[$key] ?? null;
	}

	private function load(): void
	{
		if ($this->cacheLoaded) return;
		$rows = $this->db->query('SELECT `key`, `value` FROM system_settings')->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $r) {
			$this->cache[(string)$r['key']] = (string)$r['value'];
		}
		$this->cacheLoaded = true;
	}
}
