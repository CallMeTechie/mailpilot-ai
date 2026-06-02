<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Repositories;

use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

/** @group integration */
final class LlmModelRepositoryPrimaryModelTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000e1';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		// truncateAll() lässt llm_models/llm_providers BEWUSST stehen (andere
		// Tests verlassen sich auf die Seed-Daten). Dieser Test braucht aber
		// eine global leere llm_models-Tabelle, damit primaryModelIdForRole(
		// 'summary') deterministisch null liefert (sonst gewinnen Seed-Modelle).
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
		$pdo->exec('TRUNCATE TABLE llm_models');
		$pdo->exec('TRUNCATE TABLE llm_providers');
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	}

	public function testPrimaryModelIdForRoleReturnsHighestPriorityEnabledModel(): void
	{
		$pdo = $this->pdo();
		$providerId = self::PROVIDER_ID;
		$pdo->prepare('INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (:id, "Anthropic", "anthropic", "https://api.anthropic.com", 0, 1, 10)')
			->execute([':id' => $providerId]);
		// Zwei Score-Modelle: priority 5 (niedriger=höher) gewinnt gegen 20.
		foreach ([['m-a', 20], ['m-b', 5]] as [$mid, $prio]) {
			$pdo->prepare('INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
				VALUES (:id, :p, :m, "score", 1, :prio)')
				->execute([':id' => Uuid::v4(), ':p' => $providerId, ':m' => $mid, ':prio' => $prio]);
		}

		$repo = new LlmModelRepository($pdo);
		self::assertSame('m-b', $repo->primaryModelIdForRole('score'));
		self::assertNull($repo->primaryModelIdForRole('summary'));
	}
}
