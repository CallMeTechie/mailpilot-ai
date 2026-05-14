<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\AutoSortCorrectionRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

/**
 * Sprint 6d — Move-Lern-Loop (PRD §4 Single-Correction-Schutz).
 *
 * Pinnt die DA-Pre-Impl-Findings:
 *   #1 Quiet-Window: stabilized_at startet NULL, wird vom Worker nach
 *      autosort.correction_quiet_window_minutes gesetzt.
 *   #1 Indecision-Reset: ein zweiter Move auf dieselbe Mail resettet
 *      stabilized_at (kein silent doppelter Lern-Eintrag).
 *
 * @group integration
 */
final class AutoSortCorrectionTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
		$this->pdo()->exec('DELETE FROM auto_sort_corrections');
	}

	private function seedUser(): array
	{
		$tenantId = Uuid::v4();
		$userId   = Uuid::v4();
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO tenants (id, name) VALUES (:id, "T")')->execute([':id' => $tenantId]);
		$pdo->prepare('INSERT INTO users (id, email, display_name) VALUES (:id, "marc@example.de", "Marc")')->execute([':id' => $userId]);
		$pdo->prepare('INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (:t, :u, "owner")')
			->execute([':t' => $tenantId, ':u' => $userId]);
		return [$tenantId, $userId];
	}

	public function testNewCorrectionIsCreatedWithoutStabilization(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new AutoSortCorrectionRepository($this->pdo());

		$id = $repo->create($tenantId, $userId, 'mail-1', 'MailPilot/Auto', 'MailPilot/Direct',
			'GitHub CI', 'Notifications');

		$row = $this->pdo()->query("SELECT stabilized_at FROM auto_sort_corrections WHERE id = "
			. $this->pdo()->quote($id))->fetch();
		$this->assertNull($row['stabilized_at'],
			'DA-Finding 1: neue Korrekturen sind initial nicht stabilisiert');
	}

	public function testPromoteStableSetsTimestampForOldRows(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new AutoSortCorrectionRepository($this->pdo());
		$id = $repo->create($tenantId, $userId, 'mail-old', 'A', 'B', 's1', 's2');
		$this->pdo()->prepare('UPDATE auto_sort_corrections SET created_at = (UTC_TIMESTAMP(3) - INTERVAL 90 MINUTE)
			WHERE id = :id')->execute([':id' => $id]);

		$affected = $repo->promoteStable(60);
		$this->assertSame(1, $affected);

		$row = $this->pdo()->query("SELECT stabilized_at FROM auto_sort_corrections WHERE id = "
			. $this->pdo()->quote($id))->fetch();
		$this->assertNotNull($row['stabilized_at']);
	}

	public function testThresholdCountsOnlyStabilizedDistinctMails(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new AutoSortCorrectionRepository($this->pdo());

		// 3 verschiedene Mails, gleiche (sub_label-Korrektur)
		foreach (['m1','m2','m3'] as $mid) {
			$repo->create($tenantId, $userId, $mid, 'A', 'B', 's1', 's2');
		}
		// Plus 5x dieselbe Mail (Indecision) — darf nicht extra zählen
		for ($i = 0; $i < 5; $i++) {
			$repo->create($tenantId, $userId, 'm1', 'A', 'B', 's1', 's2');
		}

		// Nichts stabilisiert → 0
		$this->assertSame(0, $repo->countSimilarStablePairsLastDays($tenantId, $userId, 's1', 's2', 30));

		// Backdate alle, dann promote
		$this->pdo()->prepare("UPDATE auto_sort_corrections
			SET created_at = (UTC_TIMESTAMP(3) - INTERVAL 2 HOUR)")->execute();
		$repo->promoteStable(60);

		// DISTINCT mail_id: 3 verschiedene Mails, nicht 8 (Indecision dedupliziert)
		$this->assertSame(3, $repo->countSimilarStablePairsLastDays($tenantId, $userId, 's1', 's2', 30),
			'Schwellwert zählt DISTINCT mail_id, nicht jede Hin-und-zurück-Bewegung');
	}

	public function testIndecisionDoesNotCreateDuplicateRows(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new AutoSortCorrectionRepository($this->pdo());

		$id1 = $repo->create($tenantId, $userId, 'mail-indecisive', 'A', 'B', 's1', 's2');
		$id2 = $repo->create($tenantId, $userId, 'mail-indecisive', 'A', 'C', 's1', 's3');

		$this->assertSame($id1, $id2,
			'Erneuter Move auf dieselbe Mail aktualisiert die bestehende Korrektur');
		$count = (int)$this->pdo()->query("SELECT COUNT(*) FROM auto_sort_corrections
			WHERE mail_id = 'mail-indecisive'")->fetchColumn();
		$this->assertSame(1, $count, 'Keine doppelte Row für hin-und-her-bewegte Mail');
	}

	public function testPurgeOlderThanRespectsSoftDelete(): void
	{
		[$tenantId, $userId] = $this->seedUser();
		$repo = new AutoSortCorrectionRepository($this->pdo());

		$keep   = $repo->create($tenantId, $userId, 'mk', 'A', 'B', 's1', 's2');
		$expire = $repo->create($tenantId, $userId, 'me', 'A', 'B', 's1', 's2');
		$this->pdo()->prepare('UPDATE auto_sort_corrections SET created_at = (UTC_TIMESTAMP(3) - INTERVAL 120 DAY)
			WHERE id = :id')->execute([':id' => $expire]);

		$repo->purgeOlderThan(90);

		$row = $this->pdo()->query("SELECT deleted_at FROM auto_sort_corrections WHERE id = "
			. $this->pdo()->quote($expire))->fetch();
		$this->assertNotNull($row['deleted_at']);
		$row = $this->pdo()->query("SELECT deleted_at FROM auto_sort_corrections WHERE id = "
			. $this->pdo()->quote($keep))->fetch();
		$this->assertNull($row['deleted_at']);
	}
}
