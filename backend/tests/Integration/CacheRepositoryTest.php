<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\CacheRepository;
use MailPilot\Tests\TestCase;

/**
 * @group integration
 */
final class CacheRepositoryTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testPutAndGetRoundTrip(): void
	{
		[$tenantId] = $this->insertTenantAndUser();
		$repo = new CacheRepository($this->pdo(), 30);
		$hash = str_repeat('a', 64);
		$repo->put($tenantId, $hash, 'P-SCORE@1.0', 'haiku', ['label' => 'direct', 'priority' => 4]);

		$got = $repo->get($tenantId, $hash, 'P-SCORE@1.0');
		$this->assertNotNull($got);
		$this->assertSame('direct', $got['label']);
	}

	public function testGetMissReturnsNull(): void
	{
		[$tenantId] = $this->insertTenantAndUser();
		$repo = new CacheRepository($this->pdo(), 30);
		$this->assertNull($repo->get($tenantId, str_repeat('b', 64), 'P-SCORE@1.0'));
	}

	public function testHitsAreIncrementedOnRead(): void
	{
		[$tenantId] = $this->insertTenantAndUser();
		$repo = new CacheRepository($this->pdo(), 30);
		$hash = str_repeat('c', 64);
		$repo->put($tenantId, $hash, 'P-SCORE@1.0', 'haiku', ['label' => 'direct']);

		$repo->get($tenantId, $hash, 'P-SCORE@1.0');
		$repo->get($tenantId, $hash, 'P-SCORE@1.0');
		$repo->get($tenantId, $hash, 'P-SCORE@1.0');

		$stmt = $this->pdo()->prepare('SELECT hits FROM claude_cache WHERE content_hash = :h');
		$stmt->execute([':h' => $hash]);
		$hits = (int)$stmt->fetch()['hits'];

		// Started at 1 via INSERT, +3 reads = 4
		$this->assertSame(4, $hits);
	}

	public function testDifferentPromptVersionMissesCache(): void
	{
		[$tenantId] = $this->insertTenantAndUser();
		$repo = new CacheRepository($this->pdo(), 30);
		$hash = str_repeat('d', 64);
		$repo->put($tenantId, $hash, 'P-SCORE@1.0', 'haiku', ['label' => 'direct']);

		$this->assertNotNull($repo->get($tenantId, $hash, 'P-SCORE@1.0'));
		$this->assertNull($repo->get($tenantId, $hash, 'P-SCORE@2.0'), 'Version change must invalidate');
	}

	public function testExpiredEntriesNotReturned(): void
	{
		[$tenantId] = $this->insertTenantAndUser();
		$repo = new CacheRepository($this->pdo(), 30);
		$hash = str_repeat('e', 64);

		// Insert directly with past timestamp
		$this->pdo()->prepare('INSERT INTO claude_cache
			(content_hash, tenant_id, result_json, prompt_version, model, created_at)
			VALUES (:h, :t, :r, :pv, :m, (UTC_TIMESTAMP(3) - INTERVAL 60 DAY))')
			->execute([
				':h' => $hash, ':t' => $tenantId,
				':r' => json_encode(['label' => 'direct']),
				':pv' => 'P-SCORE@1.0', ':m' => 'haiku',
			]);

		$this->assertNull($repo->get($tenantId, $hash, 'P-SCORE@1.0'));
	}
}
