<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Tests\TestCase;

/**
 * @group integration
 */
final class SubLabelRepositoryTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testListReturnsEmptyForNewUser(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());
		$this->assertSame([], $repo->listForUser($tenantId, $userId));
	}

	public function testCreateAndListRoundtrip(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());

		$id = $repo->create($tenantId, $userId, 'auto', 'GitHub CI', 'CI Pipeline Mails', '#ff8800');

		$items = $repo->listForUser($tenantId, $userId);
		$this->assertCount(1, $items);
		$this->assertSame($id, $items[0]['id']);
		$this->assertSame('auto', $items[0]['parent']);
		$this->assertSame('GitHub CI', $items[0]['name']);
		$this->assertSame('CI Pipeline Mails', $items[0]['description']);
		$this->assertSame('#ff8800', $items[0]['color']);
	}

	public function testCreateRejectsUnknownParent(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());

		$this->expectException(\InvalidArgumentException::class);
		$repo->create($tenantId, $userId, 'spam', 'Whatever', null, null);
	}

	public function testCreateRejectsEmptyName(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());

		$this->expectException(\InvalidArgumentException::class);
		$repo->create($tenantId, $userId, 'auto', '   ', null, null);
	}

	public function testCreateDedupesOnUniqueKey(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());

		$repo->create($tenantId, $userId, 'auto', 'GitHub CI', 'first',  '#111111');
		$repo->create($tenantId, $userId, 'auto', 'GitHub CI', 'second', '#222222');

		$items = $repo->listForUser($tenantId, $userId);
		$this->assertCount(1, $items, 'UNIQUE (tenant,user,parent,name) collapses duplicates');
		$this->assertSame('second',  $items[0]['description']);
		$this->assertSame('#222222', $items[0]['color']);
	}

	public function testSamePrimaryAllowsDifferentNames(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());

		$repo->create($tenantId, $userId, 'auto', 'GitHub CI', null, null);
		$repo->create($tenantId, $userId, 'auto', 'Bestellung', null, null);

		$this->assertCount(2, $repo->listForUser($tenantId, $userId));
	}

	public function testSameNameAllowedUnderDifferentPrimaries(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());

		$repo->create($tenantId, $userId, 'auto',   'Wichtig', null, null);
		$repo->create($tenantId, $userId, 'direct', 'Wichtig', null, null);

		$this->assertCount(2, $repo->listForUser($tenantId, $userId));
	}

	public function testUpdateChangesFieldsAndReturnsTrue(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());
		$id = $repo->create($tenantId, $userId, 'auto', 'CI', 'old desc', '#000000');

		$ok = $repo->update($tenantId, $userId, $id, 'CI Pipelines', 'new desc', '#ffffff');
		$this->assertTrue($ok);

		$items = $repo->listForUser($tenantId, $userId);
		$this->assertSame('CI Pipelines', $items[0]['name']);
		$this->assertSame('new desc',     $items[0]['description']);
		$this->assertSame('#ffffff',      $items[0]['color']);
	}

	public function testUpdateReturnsFalseForUnknownId(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());
		$this->assertFalse($repo->update($tenantId, $userId, $this->uuid(), 'X', null, null));
	}

	public function testUpdateRejectsEmptyName(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());
		$id = $repo->create($tenantId, $userId, 'auto', 'CI', null, null);

		$this->expectException(\InvalidArgumentException::class);
		$repo->update($tenantId, $userId, $id, '', null, null);
	}

	public function testDeleteRemovesRow(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());
		$id = $repo->create($tenantId, $userId, 'auto', 'CI', null, null);

		$this->assertTrue($repo->delete($tenantId, $userId, $id));
		$this->assertSame([], $repo->listForUser($tenantId, $userId));
		$this->assertFalse($repo->delete($tenantId, $userId, $id), 'second delete is a no-op');
	}

	public function testFindByIdRoundtripAndTenantScope(): void
	{
		[$tenantA, $userA] = $this->insertTenantAndUser('a@test.de');
		[$tenantB, $userB] = $this->insertTenantAndUser('b@test.de');
		$repo = new SubLabelRepository($this->pdo());

		$idA = $repo->create($tenantA, $userA, 'auto', 'GitHub CI', null, null);

		$found = $repo->findById($tenantA, $userA, $idA);
		$this->assertNotNull($found);
		$this->assertSame('auto', $found['parent']);
		$this->assertSame('GitHub CI', $found['name']);

		// cross-tenant lookup must miss
		$this->assertNull($repo->findById($tenantB, $userB, $idA));
		// unknown id misses too
		$this->assertNull($repo->findById($tenantA, $userA, $this->uuid()));
	}

	public function testListOrdersByParentEnumThenName(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new SubLabelRepository($this->pdo());

		$repo->create($tenantId, $userId, 'newsletter', 'Tech',       null, null);
		$repo->create($tenantId, $userId, 'auto',       'GitHub CI',  null, null);
		$repo->create($tenantId, $userId, 'auto',       'Bestellung', null, null);
		$repo->create($tenantId, $userId, 'direct',     'Wichtig',    null, null);

		$tuples = array_map(
			static fn(array $r): array => [$r['parent'], $r['name']],
			$repo->listForUser($tenantId, $userId),
		);

		// `parent` is an ENUM — MariaDB orders by declaration index,
		// not lexicographically. Declaration order is
		// (direct, action, cc, newsletter, auto, noise), which matches
		// the UI's importance-first sort that the rest of the app uses.
		$this->assertSame([
			['direct',     'Wichtig'],
			['newsletter', 'Tech'],
			['auto',       'Bestellung'],
			['auto',       'GitHub CI'],
		], $tuples);
	}
}
