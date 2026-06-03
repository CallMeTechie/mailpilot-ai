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
}
