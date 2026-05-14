<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Controllers\MeController;
use MailPilot\Tests\TestCase;
use PDO;

/**
 * DSGVO-Coverage-Test (PRD-PHASE-6 §10.2).
 *
 * Scannt INFORMATION_SCHEMA nach allen Tabellen mit user_id-Spalte und
 * vergleicht gegen die drei Listen in MeController. Sobald jemand eine
 * neue user_id-Tabelle anlegt, MUSS er sie in exportTableList(),
 * deleteTableList() ODER deliberateNonUserTables() registrieren —
 * sonst schlägt der Test rot und der DSGVO-Leak ist abgefangen, bevor
 * der Commit auf main landet.
 *
 * @group integration
 */
final class MeControllerCoverageTest extends TestCase
{
	/**
	 * @return list<string>
	 */
	private function tablesWithUserId(): array
	{
		$stmt = $this->pdo()->prepare("
			SELECT DISTINCT TABLE_NAME
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'user_id'
			ORDER BY TABLE_NAME
		");
		$stmt->execute();
		return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	public function testEveryUserScopedTableIsInExport(): void
	{
		$known = array_merge(
			MeController::exportTableList(),
			MeController::deliberateNonUserTables(),
		);
		// users hat keine user_id-Spalte (id ist der PK) → manuell raus,
		// damit der Set-Vergleich nicht stolpert.
		$known = array_diff($known, ['users']);

		$unregistered = array_diff($this->tablesWithUserId(), $known);

		$this->assertSame(
			[],
			array_values($unregistered),
			"Diese Tabellen haben user_id, fehlen aber in MeController::exportTableList()"
			. " UND deliberateNonUserTables() — DSGVO-Lücke: " . implode(', ', $unregistered)
		);
	}

	public function testEveryUserScopedTableIsInDeleteOrDeliberate(): void
	{
		$known = array_merge(
			MeController::deleteTableList(),
			MeController::deliberateNonUserTables(),
		);
		$known = array_diff($known, ['users']);

		$unregistered = array_diff($this->tablesWithUserId(), $known);

		$this->assertSame(
			[],
			array_values($unregistered),
			"Diese Tabellen haben user_id, fehlen aber in MeController::deleteTableList()"
			. " UND deliberateNonUserTables(): " . implode(', ', $unregistered)
		);
	}

	public function testExportAndDeleteListsAreDisjointFromWhitelist(): void
	{
		// Sanity: eine Tabelle darf nicht GLEICHZEITIG exportiert UND
		// als „deliberate non-user" deklariert sein — das wäre ein
		// stiller Self-Bypass des Coverage-Tests.
		$overlap = array_intersect(
			MeController::exportTableList(),
			MeController::deliberateNonUserTables(),
		);
		$this->assertSame([], array_values($overlap),
			'Konflikt: in exportTableList UND deliberateNonUserTables: ' . implode(', ', $overlap));

		$overlap = array_intersect(
			MeController::deleteTableList(),
			MeController::deliberateNonUserTables(),
		);
		$this->assertSame([], array_values($overlap),
			'Konflikt: in deleteTableList UND deliberateNonUserTables: ' . implode(', ', $overlap));
	}
}
