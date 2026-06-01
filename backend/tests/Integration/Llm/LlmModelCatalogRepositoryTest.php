<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Llm\ModelDescriptor;
use MailPilot\Repositories\LlmModelCatalogRepository;
use MailPilot\Tests\TestCase;

/**
 * @group integration
 */
final class LlmModelCatalogRepositoryTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c1';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_model_catalog WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			 VALUES (?, 'TestCatalog', 'anthropic', 'http://catalog.test', 0, 1, 10)"
		)->execute([self::PROVIDER_ID]);
	}

	public function testUpsertMarkStaleAndDatetime(): void
	{
		$repo = new LlmModelCatalogRepository($this->pdo());

		$repo->upsert(self::PROVIDER_ID, new ModelDescriptor(
			'claude-opus-4-8', 'Opus 4.8', ['low', 'high'], 128000, 1000000, '2026-05-01T00:00:00Z',
		));
		$repo->upsert(self::PROVIDER_ID, new ModelDescriptor('claude-haiku-4-5-20251001', 'Haiku 4.5', []));

		self::assertCount(2, $repo->listByProvider(self::PROVIDER_ID));

		$opus = $repo->find(self::PROVIDER_ID, 'claude-opus-4-8');
		self::assertNotNull($opus);
		self::assertSame('2026-05-01 00:00:00', (string)$opus['released_at']);
		self::assertSame(['low', 'high'], json_decode((string)$opus['effort_levels'], true));

		$repo->markStale(self::PROVIDER_ID, ['claude-opus-4-8']);
		$available = $repo->listByProvider(self::PROVIDER_ID, availableOnly: true);
		self::assertCount(1, $available);
		self::assertSame('claude-opus-4-8', $available[0]['model_id']);
	}
}
