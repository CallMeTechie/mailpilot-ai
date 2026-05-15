<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\SettingsRepository;
use MailPilot\Tests\TestCase;

/**
 * Pin-Test für den 30s-In-Memory-Cache-TTL von SettingsRepository.
 *
 * Hintergrund: SettingsRepository cached system_settings im PHP-Heap.
 * Vor dem TTL-Fix (commit c666d31) hielt der Worker-Prozess die Werte
 * tagelang — Marc's Admin-Panel-Edits griffen nicht ohne Container-
 * Restart. Dieser Test pinnt:
 *   1. Cache hat KEINE TTL-Race-Toleranz innerhalb 30 s (alter Wert).
 *   2. Cache invalidiert sich nach TTL (neuer Wert sichtbar).
 *
 * @group integration
 */
final class SettingsRepositoryTtlTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testCacheHitWithinTtl(): void
	{
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES ("ttl_test_key", "v1", "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute();

		$repo = new SettingsRepository($pdo);
		$this->assertSame('v1', $repo->getString('ttl_test_key'));

		// Direkt am Repo vorbei in die DB schreiben — simuliert was passiert
		// wenn ein zweiter Prozess (z.B. Admin-Save) das Setting ändert.
		$pdo->prepare('UPDATE system_settings SET `value` = "v2" WHERE `key` = "ttl_test_key"')->execute();

		// Cache-Hit: Repo gibt noch den ALTEN Wert zurück, weil loadedAt
		// frisch ist und TTL nicht abgelaufen ist.
		$this->assertSame('v1', $repo->getString('ttl_test_key'),
			'Cache-Hit innerhalb 30 s muss alten Wert liefern (verhindert DB-Hot-Path).');
	}

	public function testCacheInvalidatesAfterTtl(): void
	{
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES ("ttl_test_key", "v1", "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute();

		$repo = new SettingsRepository($pdo);
		$this->assertSame('v1', $repo->getString('ttl_test_key'));

		$pdo->prepare('UPDATE system_settings SET `value` = "v2" WHERE `key` = "ttl_test_key"')->execute();

		// Reflection: setze loadedAt 31 Sekunden zurück. Damit gilt der
		// Cache als abgelaufen und der nächste Read triggert reload().
		// (Echtes sleep(31) wäre korrekt, aber CI-langsam.)
		$ref = new \ReflectionClass($repo);
		$prop = $ref->getProperty('loadedAt');
		$prop->setValue($repo, time() - 31);

		$this->assertSame('v2', $repo->getString('ttl_test_key'),
			'Nach TTL muss der frische DB-Wert sichtbar werden — sonst greift Admin-Edit nie im Worker.');
	}

	public function testSetWritesThroughCacheImmediately(): void
	{
		// Der Per-Request-Pfad: wenn der gleiche Repo per set() schreibt,
		// erwartet der nachfolgende get() sofort den neuen Wert (kein
		// TTL-Warten). Sonst sieht ein POST /settings die eigene Änderung
		// nicht zurück.
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES ("ttl_test_key", "v1", "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute();

		$repo = new SettingsRepository($pdo);
		$this->assertSame('v1', $repo->getString('ttl_test_key'));

		$repo->set('ttl_test_key', 'v2');
		$this->assertSame('v2', $repo->getString('ttl_test_key'),
			'set() muss den Cache lokal aktualisieren, nicht erst nach TTL.');
	}
}
