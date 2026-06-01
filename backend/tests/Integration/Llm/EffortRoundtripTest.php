<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

/**
 * @group integration
 */
final class EffortRoundtripTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c5';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'TestRt', 'anthropic', 'http://rt.test', 0, 1, 10)")->execute([self::PROVIDER_ID]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, effort, enabled, priority)
			VALUES (?, ?, 'claude-opus-4-8', 'summary', NULL, 1, 10)")->execute([Uuid::v4(), self::PROVIDER_ID]);
	}

	public function testEffortColumnPersistsAndIsReadByModelRepo(): void
	{
		$pdo = $this->pdo();
		$pdo->prepare("UPDATE llm_models SET effort = 'medium' WHERE provider_id = :p AND role = 'summary'")
			->execute([':p' => self::PROVIDER_ID]);

		$row = (new LlmModelRepository($pdo))->findForProviderAndRole(self::PROVIDER_ID, 'summary');
		self::assertNotNull($row);
		self::assertSame('medium', $row['effort']);
	}
}
