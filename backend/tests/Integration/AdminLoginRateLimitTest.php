<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Admin\Security\AdminLoginAttemptRepository;
use MailPilot\Tests\TestCase;

/**
 * Phase-H5 — Brute-Force-Schutz fuer Admin-Login.
 *
 * Test gegen die echte admin_login_attempts-Tabelle (Migration 0030).
 */
final class AdminLoginRateLimitTest extends TestCase
{
	protected function setUp(): void
	{
		$this->pdo()->exec('TRUNCATE TABLE admin_login_attempts');
	}

	private function repo(): AdminLoginAttemptRepository
	{
		return new AdminLoginAttemptRepository($this->pdo());
	}

	public function testNewIpHasNoFailures(): void
	{
		$r = $this->repo();
		$this->assertSame(0, $r->countRecentFailures('1.2.3.4'));
		$this->assertFalse($r->isLocked('1.2.3.4'));
		$this->assertSame(0, $r->secondsUntilUnlock('1.2.3.4'));
	}

	public function testFailedAttemptsAreCounted(): void
	{
		$r = $this->repo();
		for ($i = 0; $i < 3; $i++) {
			$r->record('10.0.0.1', 'admin', false);
		}
		$this->assertSame(3, $r->countRecentFailures('10.0.0.1'));
		$this->assertFalse($r->isLocked('10.0.0.1'), '3 fails < 5 threshold');
	}

	public function testFiveFailsTriggersLock(): void
	{
		$r = $this->repo();
		for ($i = 0; $i < 5; $i++) {
			$r->record('10.0.0.2', 'admin', false);
		}
		$this->assertTrue($r->isLocked('10.0.0.2'), 'Nach 5 fails muss IP gelockt sein');
		$this->assertGreaterThan(0, $r->secondsUntilUnlock('10.0.0.2'),
			'secondsUntilUnlock muss positiv sein');
	}

	public function testSuccessfulLoginClearsFailures(): void
	{
		$r = $this->repo();
		// 4× falsch
		for ($i = 0; $i < 4; $i++) {
			$r->record('10.0.0.3', 'admin', false);
		}
		$this->assertSame(4, $r->countRecentFailures('10.0.0.3'));

		// 5×: richtig → resettet
		$r->record('10.0.0.3', 'admin', true);
		$this->assertSame(0, $r->countRecentFailures('10.0.0.3'),
			'Erfolgreicher Login muss historische failures der IP loeschen');
		$this->assertFalse($r->isLocked('10.0.0.3'));
	}

	public function testOtherIpsAreNotAffected(): void
	{
		$r = $this->repo();
		for ($i = 0; $i < 5; $i++) {
			$r->record('192.168.1.100', 'admin', false);
		}
		$this->assertTrue($r->isLocked('192.168.1.100'));
		$this->assertFalse($r->isLocked('192.168.1.101'), 'Andere IP darf nicht mitlocken');
	}

	public function testCleanupRemovesOldEntries(): void
	{
		$r = $this->repo();
		$pdo = $this->pdo();

		// Alter Eintrag (40 Tage alt)
		$pdo->prepare('INSERT INTO admin_login_attempts (ip, username, success, attempted_at)
			VALUES (:ip, :u, 0, UTC_TIMESTAMP(3) - INTERVAL 40 DAY)')
			->execute([':ip' => '10.0.0.4', ':u' => 'old']);

		// Frischer Eintrag
		$r->record('10.0.0.5', 'fresh', false);

		$deleted = $r->cleanup();
		$this->assertSame(1, $deleted, 'Nur der 40-Tage-Eintrag muss weg sein');
		$this->assertSame(0, $r->countRecentFailures('10.0.0.4'));
		$this->assertSame(1, $r->countRecentFailures('10.0.0.5'));
	}

	public function testUsernameIsNullForEmptyLogin(): void
	{
		$r = $this->repo();
		$r->record('10.0.0.6', '', false);
		$row = $this->pdo()->query("SELECT username FROM admin_login_attempts WHERE ip='10.0.0.6'")->fetch();
		$this->assertNull($row['username'], 'Leerer Username muss als NULL in DB landen');
	}
}
