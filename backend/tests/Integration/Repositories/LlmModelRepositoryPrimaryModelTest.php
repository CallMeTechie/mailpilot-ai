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

	/**
	 * Stellt die Migrations-Seed-Daten fuer llm_providers und llm_models wieder
	 * her (Test-Isolation: setUp() trunciert diese Tabellen komplett, was nach-
	 * folgende Tests — insbesondere MigrationLearningLoopTest — bricht, weil
	 * truncateAll() diese Tabellen bewusst nicht leert).
	 *
	 * Nur die Anthropic-Provider-Zeile (0050) und das match-Modell (0065) werden
	 * zwingend benoetigt; score/inference/summary/draft werden ebenfalls restauriert,
	 * damit andere Tests die Seed-Reihen vorfinden.
	 */
	protected function tearDown(): void
	{
		parent::tearDown();

		$pdo = $this->pdo();

		// 0050 — Anthropic-Provider (Haupt-Provider, fuer alle seeded Modelle benoetigt).
		$pdo->exec("INSERT IGNORE INTO llm_providers
			(id, name, kind, base_url, api_key_env_fallback, is_local, enabled, priority)
			VALUES (
				'00000000-0000-4000-8000-000000000050',
				'Anthropic', 'anthropic', 'https://api.anthropic.com',
				'ANTHROPIC_API_KEY', 0, 1, 10
			)");

		// 0052 + 0062 — Anthropic-Modelle. summary/draft direkt mit claude-opus-4-8
		// und effort=medium (finaler Zustand nach Migration 0062).
		$pdo->exec("INSERT IGNORE INTO llm_models
			(id, provider_id, model_id, role, cost_per_mtok_in, cost_per_mtok_out,
			 supports_caching, max_context, enabled, priority)
			VALUES
				('00000000-0000-4000-8001-000000000050',
				 '00000000-0000-4000-8000-000000000050',
				 'claude-haiku-4-5-20251001', 'score', 0.80, 4.00, 1, 200000, 1, 10),
				('00000000-0000-4000-8001-000000000051',
				 '00000000-0000-4000-8000-000000000050',
				 'claude-haiku-4-5-20251001', 'inference', 0.80, 4.00, 1, 200000, 1, 10),
				('00000000-0000-4000-8001-000000000052',
				 '00000000-0000-4000-8000-000000000050',
				 'claude-opus-4-8', 'summary', 5.00, 25.00, 1, 200000, 1, 10),
				('00000000-0000-4000-8001-000000000053',
				 '00000000-0000-4000-8000-000000000050',
				 'claude-opus-4-8', 'draft', 5.00, 25.00, 1, 200000, 1, 10)");

		$pdo->exec("UPDATE llm_models SET effort = 'medium'
			WHERE role IN ('summary', 'draft')
			  AND provider_id = '00000000-0000-4000-8000-000000000050'
			  AND deleted_at IS NULL");

		// 0065 — match-Modell (Haiku, role=match, Spec-2-Seed).
		$pdo->exec("INSERT IGNORE INTO llm_models
			(id, provider_id, model_id, role, cost_per_mtok_in, cost_per_mtok_out,
			 supports_caching, max_context, enabled, priority)
			VALUES (
				'00000000-0000-4000-8001-000000000054',
				'00000000-0000-4000-8000-000000000050',
				'claude-haiku-4-5-20251001', 'match', 0.80, 4.00, 1, 200000, 1, 10
			)");
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
