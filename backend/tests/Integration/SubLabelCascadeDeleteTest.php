<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Tests\TestCase;

/**
 * Pins the cascade-delete contract that SettingsController::deleteSubLabel
 * implements: removing a sub-label atomically drops every AutoSort
 * sub-rule that pointed at it. Stored as repository-level coverage
 * because the codebase has no controller-test stack.
 *
 * @group integration
 */
final class SubLabelCascadeDeleteTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testDeletingSubLabelRemovesDependentRulesAtomically(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$subs     = new SubLabelRepository($this->pdo());
		$autoSort = new AutoSortRepository($this->pdo());

		$subId = $subs->create($tenantId, $userId, 'auto', 'GitHub CI', null, null);
		$autoSort->upsert($tenantId, $userId, 'auto', null,        true, 'MailPilot/Auto');
		$autoSort->upsert($tenantId, $userId, 'auto', 'GitHub CI', true, 'MailPilot/Auto/CI');

		$this->assertSame(1, $autoSort->countBySubLabel($tenantId, $userId, 'auto', 'GitHub CI'));

		$pdo = $this->pdo();
		$pdo->beginTransaction();
		try {
			$row = $subs->findById($tenantId, $userId, $subId);
			$this->assertNotNull($row);
			$autoSort->delete($tenantId, $userId, $row['parent'], $row['name']);
			$subs->delete($tenantId, $userId, $subId);
			$pdo->commit();
		} catch (\Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}

		$this->assertSame([], $subs->listForUser($tenantId, $userId));
		$this->assertSame(0, $autoSort->countBySubLabel($tenantId, $userId, 'auto', 'GitHub CI'));
		// the catch-all rule that did not depend on the sub-label is preserved
		$catch = $autoSort->findRule($tenantId, $userId, 'auto', null);
		$this->assertNotNull($catch);
		$this->assertTrue($catch['enabled']);
		$this->assertSame('MailPilot/Auto', $catch['folder_name']);
	}

	public function testRollbackLeavesEverythingIntact(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$subs     = new SubLabelRepository($this->pdo());
		$autoSort = new AutoSortRepository($this->pdo());

		$subId = $subs->create($tenantId, $userId, 'auto', 'GitHub CI', null, null);
		$autoSort->upsert($tenantId, $userId, 'auto', 'GitHub CI', true, 'MailPilot/Auto/CI');

		$pdo = $this->pdo();
		$pdo->beginTransaction();
		try {
			$row = $subs->findById($tenantId, $userId, $subId);
			$autoSort->delete($tenantId, $userId, $row['parent'], $row['name']);
			throw new \RuntimeException('simulated mid-transaction failure');
		} catch (\Throwable) {
			$pdo->rollBack();
		}

		// Both rows survive — the partial DELETE of auto_sort_rules was rolled back.
		$this->assertCount(1, $subs->listForUser($tenantId, $userId));
		$this->assertSame(1, $autoSort->countBySubLabel($tenantId, $userId, 'auto', 'GitHub CI'));
	}
}
