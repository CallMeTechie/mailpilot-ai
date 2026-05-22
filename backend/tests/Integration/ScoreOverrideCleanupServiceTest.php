<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\ScoreOverrideCleanupService;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Monolog\Logger;
use Monolog\Handler\NullHandler;

/**
 * Phase 9p (Marc 2026-05-22) — Auto-Cleanup fuer Score-Override-Regeln.
 *
 * Pinnt die Heuristik:
 *   - enabled=0 → wird geloescht, wenn delete_disabled=true
 *   - applies_count=0 AND created_at < UTC - INTERVAL N DAY → loeschen
 *   - bereits soft-deleted bleibt unangetastet
 *   - enabled=1 mit applies_count>0 bleibt unangetastet
 *
 * @group integration
 */
final class ScoreOverrideCleanupServiceTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
		// truncateAll laesst system_settings stehen — andere Tests koennen die
		// score_rule_autoclean.*-Defaults verbiegen. Wir resetten sie hier auf
		// die Migration-0049-Werte, damit Test-Order keine Rolle spielt.
		$s = new SettingsRepository($this->pdo());
		$s->set('score_rule_autoclean.enabled', '1');
		$s->set('score_rule_autoclean.delete_disabled', '1');
		$s->set('score_rule_autoclean.delete_unused_after_days', '7');
	}

	private function makeService(): ScoreOverrideCleanupService
	{
		$logger = new Logger('test');
		$logger->pushHandler(new NullHandler());
		return new ScoreOverrideCleanupService(
			$this->pdo(),
			new SettingsRepository($this->pdo()),
			$logger,
		);
	}

	private function seedRule(string $tenantId, string $userId, array $overrides = []): string
	{
		$id = Uuid::v4();
		$base = [
			'match_sender_key' => 'example.de',
			'enabled'          => 1,
			'applies_count'    => 0,
			'created_at'       => gmdate('Y-m-d H:i:s.000'),
		];
		$row = array_merge($base, $overrides, ['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId]);
		$cols = implode(', ', array_keys($row));
		$vals = implode(', ', array_map(fn($k) => ':' . $k, array_keys($row)));
		$stmt = $this->pdo()->prepare("INSERT INTO score_override_rules ($cols) VALUES ($vals)");
		$stmt->execute(array_combine(
			array_map(fn($k) => ':' . $k, array_keys($row)),
			array_values($row),
		));
		return $id;
	}

	public function testDeletesDisabledRulesWhenFlagSet(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$disabledId = $this->seedRule($tenantId, $userId, ['enabled' => 0]);
		$enabledId  = $this->seedRule($tenantId, $userId, ['enabled' => 1, 'applies_count' => 3]);

		$svc = $this->makeService();
		$deleted = $svc->cleanup();
		self::assertSame(1, $deleted);

		$rows = $this->pdo()->query('SELECT id, deleted_at FROM score_override_rules ORDER BY id')
			->fetchAll(\PDO::FETCH_ASSOC);
		$byId = [];
		foreach ($rows as $r) $byId[$r['id']] = $r['deleted_at'];

		self::assertNotNull($byId[$disabledId], 'Deaktivierte Regel ist soft-deleted');
		self::assertNull($byId[$enabledId], 'Aktive Regel mit Applies bleibt erhalten');
	}

	public function testKeepsDisabledRulesWhenDeleteDisabledFlagOff(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$this->seedRule($tenantId, $userId, ['enabled' => 0]);

		(new SettingsRepository($this->pdo()))->set('score_rule_autoclean.delete_disabled', '0');
		// Default fuer days bleibt 7 → unsere frische Regel ist nicht alt genug,
		// also wird sie auch nicht ueber die applies_count-Bedingung gefangen.

		$deleted = $this->makeService()->cleanup();
		self::assertSame(0, $deleted, 'Mit delete_disabled=false bleibt die Regel');
	}

	public function testDeletesUnusedOldRules(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$oldUnused = $this->seedRule($tenantId, $userId, [
			'enabled'      => 1,
			'applies_count' => 0,
			'created_at'   => gmdate('Y-m-d H:i:s.000', time() - 10 * 86400),
		]);
		$oldButUsed = $this->seedRule($tenantId, $userId, [
			'enabled'      => 1,
			'applies_count' => 5,
			'created_at'   => gmdate('Y-m-d H:i:s.000', time() - 10 * 86400),
		]);
		$freshUnused = $this->seedRule($tenantId, $userId, [
			'enabled'      => 1,
			'applies_count' => 0,
			'created_at'   => gmdate('Y-m-d H:i:s.000', time() - 2 * 86400),
		]);

		$deleted = $this->makeService()->cleanup();
		self::assertSame(1, $deleted);

		$rows = $this->pdo()->query('SELECT id, deleted_at FROM score_override_rules')
			->fetchAll(\PDO::FETCH_ASSOC);
		$byId = [];
		foreach ($rows as $r) $byId[$r['id']] = $r['deleted_at'];

		self::assertNotNull($byId[$oldUnused],   'Alte ungenutzte Regel wird aufgeraeumt');
		self::assertNull($byId[$oldButUsed],     'Alte aber benutzte Regel bleibt');
		self::assertNull($byId[$freshUnused],    'Frische Regel (2 Tage) bleibt');
	}

	public function testIgnoresAlreadySoftDeleted(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$this->seedRule($tenantId, $userId, ['enabled' => 0]);
		$this->pdo()->exec('UPDATE score_override_rules SET deleted_at = UTC_TIMESTAMP(3)');

		$deleted = $this->makeService()->cleanup();
		self::assertSame(0, $deleted, 'Soft-deleted Regeln werden nicht doppelt geloescht');
	}

	public function testTickRespectsEnabledFlag(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$this->seedRule($tenantId, $userId, ['enabled' => 0]);

		(new SettingsRepository($this->pdo()))->set('score_rule_autoclean.enabled', '0');
		$res = $this->makeService()->tick();

		self::assertFalse($res['ran'],   'tick laeuft nicht wenn enabled=false');
		self::assertSame(0, $res['deleted']);
	}

	public function testReadAndWriteConfigRoundtrip(): void
	{
		$svc = $this->makeService();
		$svc->writeConfig([
			'enabled' => false,
			'delete_disabled' => false,
			'delete_unused_after_days' => 14,
		]);
		$cfg = $svc->readConfig();

		self::assertFalse($cfg['enabled']);
		self::assertFalse($cfg['delete_disabled']);
		self::assertSame(14, $cfg['delete_unused_after_days']);
	}

	public function testWriteConfigClampsDays(): void
	{
		$svc = $this->makeService();
		$svc->writeConfig(['delete_unused_after_days' => 9999]);
		self::assertSame(365, $svc->readConfig()['delete_unused_after_days'], 'Clamp auf 365');

		$svc->writeConfig(['delete_unused_after_days' => 0]);
		self::assertSame(1, $svc->readConfig()['delete_unused_after_days'], 'Clamp auf 1');
	}
}
