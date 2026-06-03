<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\CacheRepository;
use MailPilot\Tests\TestCase;

final class CorrectionInvalidatesCacheTest extends TestCase
{
	public function testPurgeByContentHashRemovesOnlyThatEntry(): void
	{
		$this->truncateAll();
		[$tenantId] = $this->insertTenantAndUser();
		$cache = new CacheRepository($this->pdo(), 30);
		$cache->put($tenantId, 'hash-A', 'P-SCORE@1.6+code1', 'm', ['label' => 'newsletter']);
		$cache->put($tenantId, 'hash-B', 'P-SCORE@1.6+code1', 'm', ['label' => 'direct']);

		$removed = $cache->purgeByContentHash($tenantId, 'hash-A');

		self::assertSame(1, $removed);
		self::assertNull($cache->get($tenantId, 'hash-A', 'P-SCORE@1.6+code1'));
		self::assertNotNull($cache->get($tenantId, 'hash-B', 'P-SCORE@1.6+code1'), 'andere Mail unberührt');
	}
}
