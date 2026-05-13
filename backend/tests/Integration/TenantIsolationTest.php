<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Repositories\VipRepository;
use MailPilot\Tests\TestCase;

/**
 * Critical: verify no tenant can see another tenant's data.
 *
 * @group integration
 * @group security
 */
final class TenantIsolationTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testMailsAreTenantIsolated(): void
	{
		[$tenantA, $userA] = $this->insertTenantAndUser('a@test.de');
		[$tenantB, $userB] = $this->insertTenantAndUser('b@test.de');

		$mbA = $this->insertMailbox($tenantA, $userA);
		$mbB = $this->insertMailbox($tenantB, $userB);

		$mailA = $this->insertMail($tenantA, $mbA, ['subject' => 'Tenant A secret']);
		$mailB = $this->insertMail($tenantB, $mbB, ['subject' => 'Tenant B secret']);

		$repo = new MailRepository($this->pdo());

		// A kann A's Mail sehen
		$this->assertNotNull($repo->findById($tenantA, $mailA));
		// A kann B's Mail NICHT sehen
		$this->assertNull($repo->findById($tenantA, $mailB));
		// B kann A's Mail NICHT sehen
		$this->assertNull($repo->findById($tenantB, $mailA));
	}

	public function testVipSendersAreTenantIsolated(): void
	{
		[$tenantA, $userA] = $this->insertTenantAndUser('a@test.de');
		[$tenantB, $userB] = $this->insertTenantAndUser('b@test.de');

		$repo = new VipRepository($this->pdo());
		$repo->add($tenantA, $userA, 'bossA@a.de', 'Boss A');
		$repo->add($tenantB, $userB, 'bossB@b.de', 'Boss B');

		$vipsA = $repo->listForUser($tenantA, $userA);
		$vipsB = $repo->listForUser($tenantB, $userB);

		$this->assertCount(1, $vipsA);
		$this->assertCount(1, $vipsB);
		$this->assertSame('bossa@a.de', $vipsA[0]['email']);
		$this->assertSame('bossb@b.de', $vipsB[0]['email']);
	}

	public function testUnscoredMailQueryIsTenantIsolated(): void
	{
		[$tenantA, $userA] = $this->insertTenantAndUser('a@test.de');
		[$tenantB, $userB] = $this->insertTenantAndUser('b@test.de');

		$mbA = $this->insertMailbox($tenantA, $userA);
		$mbB = $this->insertMailbox($tenantB, $userB);
		$this->insertMail($tenantA, $mbA);
		$this->insertMail($tenantA, $mbA);
		$this->insertMail($tenantB, $mbB);

		$repo = new MailRepository($this->pdo());
		$this->assertCount(2, $repo->findUnscoredForMailbox($tenantA, $mbA));
		$this->assertCount(1, $repo->findUnscoredForMailbox($tenantB, $mbB));
		// Cross-tenant query must return empty
		$this->assertCount(0, $repo->findUnscoredForMailbox($tenantA, $mbB));
	}

	public function testSubLabelsAreTenantIsolated(): void
	{
		[$tenantA, $userA] = $this->insertTenantAndUser('a@test.de');
		[$tenantB, $userB] = $this->insertTenantAndUser('b@test.de');

		$repo = new SubLabelRepository($this->pdo());
		$idA = $repo->create($tenantA, $userA, 'auto', 'GitHub CI',  null, null);
		$idB = $repo->create($tenantB, $userB, 'auto', 'Bestellung', null, null);

		$listA = $repo->listForUser($tenantA, $userA);
		$listB = $repo->listForUser($tenantB, $userB);

		$this->assertCount(1, $listA);
		$this->assertCount(1, $listB);
		$this->assertSame('GitHub CI',  $listA[0]['name']);
		$this->assertSame('Bestellung', $listB[0]['name']);

		// Cross-tenant update + delete must be no-ops, even with the right id.
		$this->assertFalse($repo->update($tenantA, $userA, $idB, 'Hijack', null, null));
		$this->assertFalse($repo->delete($tenantA, $userA, $idB));

		// Tenant B's sub-label is still there, untouched.
		$stillB = $repo->listForUser($tenantB, $userB);
		$this->assertCount(1, $stillB);
		$this->assertSame('Bestellung', $stillB[0]['name']);
	}
}
