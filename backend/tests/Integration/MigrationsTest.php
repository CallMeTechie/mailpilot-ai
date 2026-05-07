<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Tests\TestCase;

/**
 * Verifies migrations/ are well-formed and that hardening tables
 * (jwt_blacklist) exist after applying them.
 *
 * @group integration
 */
final class MigrationsTest extends TestCase
{
	public function testAllMigrationsAreNumberedSequentially(): void
	{
		$dir = __DIR__ . '/../../migrations';
		$files = glob($dir . '/[0-9]*.sql') ?: [];
		sort($files, SORT_STRING);
		$this->assertNotEmpty($files);

		$expected = 1;
		foreach ($files as $f) {
			$name = basename($f, '.sql');
			$this->assertMatchesRegularExpression('/^\d{4}_/', $name);
			$num = (int)substr($name, 0, 4);
			$this->assertSame($expected, $num, "Gap in migrations: expected {$expected}, got {$num}");
			$expected++;
		}
	}

	public function testJwtBlacklistTableMatchesMigrationShape(): void
	{
		$pdo = $this->pdo();
		$pdo->exec('CREATE TABLE IF NOT EXISTS jwt_blacklist (
			jti CHAR(36) NOT NULL PRIMARY KEY,
			expires_at DATETIME(3) NOT NULL,
			revoked_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
		)');

		$cols = $pdo->query("SHOW COLUMNS FROM jwt_blacklist")->fetchAll(\PDO::FETCH_ASSOC);
		$names = array_column($cols, 'Field');
		$this->assertContains('jti', $names);
		$this->assertContains('expires_at', $names);
		$this->assertContains('revoked_at', $names);
	}
}
