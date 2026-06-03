<?php
declare(strict_types=1);

namespace MailPilot\Tests\Support;

use MailPilot\Util\Uuid;

/**
 * Shared test helper — seeds one llm_providers row + one llm_models 'score'
 * row + the three system_settings keys required for the LlmRouter to select
 * the scripted provider during integration tests.
 *
 * Each test class must declare its own PROVIDER_ID constant (unique per class)
 * and call $this->seedScoreRouting(self::PROVIDER_ID, '<DescriptiveName>').
 */
trait SeedsScoreRouting
{
	private function seedScoreRouting(string $providerId, string $name = 'TestScore'): void
	{
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . $providerId . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . $providerId . "'");
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, ?, 'anthropic', 'http://x', 0, 1, 10)")->execute([$providerId, $name]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (?, ?, 'claude-haiku-4-5-20251001', 'score', 1, 10)")->execute([Uuid::v4(), $providerId]);
		foreach ([
			['llm.routing_mode', 'router'],
			['llm.privacy_mode', 'cloud_allowed'],
			['llm.score.fallback_chain', '["' . $providerId . '"]'],
		] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`) VALUES (?, ?, "string")
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
		}
	}
}
