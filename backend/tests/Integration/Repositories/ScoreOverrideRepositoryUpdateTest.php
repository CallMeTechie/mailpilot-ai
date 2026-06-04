<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Repositories;

use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

final class ScoreOverrideRepositoryUpdateTest extends TestCase
{
	public function testTwoCorrectionsSameSenderAndFieldYieldOneRule(): void
	{
		$this->truncateAll();
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());

		$repo->create($tenantId, $userId, [
			'match_sender_key' => 'sk:acme', 'set_priority' => 4,
			'source' => 'ki_inferred', 'origin_correction_id' => Uuid::v4(), 'enabled' => true,
		]);
		$slot = $repo->findUserDerivedSlot($tenantId, $userId, 'sk:acme', 'priority');
		self::assertNotNull($slot, 'erste Regel angelegt');

		$repo->updateFields($tenantId, (string)$slot['id'], ['set_priority' => 2]);
		$rows = $repo->listForUser($tenantId, $userId);
		$priorityRules = array_values(array_filter($rows, static fn(array $r): bool => $r['match_sender_key'] === 'sk:acme' && $r['set_priority'] !== null));
		self::assertCount(1, $priorityRules, 'genau eine priority-Regel pro (sender_key,Feld)');
		self::assertSame(2, (int)$priorityRules[0]['set_priority']);
	}

	public function testCountAndDisableLeastRecentlyUsed(): void
	{
		$this->truncateAll();
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());
		$pdo  = $this->pdo();

		// Drei user-derived Regeln anlegen (origin_correction_id != null, enabled=1).
		$corrId = Uuid::v4();
		$idA = $repo->create($tenantId, $userId, [
			'match_sender_key' => 'sk:a', 'set_priority' => 2,
			'source' => 'ki_inferred', 'origin_correction_id' => $corrId, 'enabled' => true,
		]);
		$idB = $repo->create($tenantId, $userId, [
			'match_sender_key' => 'sk:b', 'set_priority' => 3,
			'source' => 'ki_inferred', 'origin_correction_id' => $corrId, 'enabled' => true,
		]);
		$idC = $repo->create($tenantId, $userId, [
			'match_sender_key' => 'sk:c', 'set_priority' => 4,
			'source' => 'ki_inferred', 'origin_correction_id' => $corrId, 'enabled' => true,
		]);

		// last_applied_at explizit setzen damit die LRU-Reihenfolge deterministisch ist.
		// ORDER BY: last_applied_at IS NULL DESC, last_applied_at ASC
		// → NULL kommt ZUERST, also ist sk:a (NULL) der LRU-Kandidat;
		//   sk:b (aelter) < sk:c (neuer) folgen danach.
		// Wir wollen sk:a als eindeutigen LRU: setze sk:b und sk:c auf konkrete Timestamps,
		// sk:a bleibt NULL → wird als erstes von ORDER BY geliefert.
		$pdo->prepare('UPDATE score_override_rules SET last_applied_at = :ts WHERE id = :id')
			->execute([':ts' => '2026-01-01 10:00:00.000', ':id' => $idB]);
		$pdo->prepare('UPDATE score_override_rules SET last_applied_at = :ts WHERE id = :id')
			->execute([':ts' => '2026-01-02 10:00:00.000', ':id' => $idC]);
		// sk:a behaelt last_applied_at = NULL → LRU gemaess ORDER BY.

		self::assertSame(3, $repo->countUserDerived($tenantId, $userId), 'vor Disable: 3 aktive Regeln');

		$disabledId = $repo->disableLeastRecentlyUsed($tenantId, $userId);
		self::assertSame($idA, $disabledId, 'sk:a (NULL last_applied_at) ist der LRU');

		self::assertSame(2, $repo->countUserDerived($tenantId, $userId), 'nach Disable: noch 2 aktive Regeln');

		// Die deaktivierte Regel muss enabled=0 haben.
		$check = $pdo->prepare('SELECT enabled FROM score_override_rules WHERE id = :id');
		$check->execute([':id' => $idA]);
		self::assertSame('0', (string)$check->fetchColumn(), 'deaktivierte Regel hat enabled=0');

		// findUserDerivedSlot fuer unbekannten sender_key liefert null.
		self::assertNull(
			$repo->findUserDerivedSlot($tenantId, $userId, 'sk:unbekannt', 'priority'),
			'kein Slot fuer unbekannten sender_key'
		);
	}
}
