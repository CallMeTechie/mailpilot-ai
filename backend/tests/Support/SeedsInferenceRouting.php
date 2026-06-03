<?php
declare(strict_types=1);

namespace MailPilot\Tests\Support;

use MailPilot\Util\Uuid;

/**
 * Task 7 (2026-06-02) — Parallel zu SeedsScoreRouting, aber fuer die Rolle
 * 'inference': seedet eine llm_providers-Row + eine llm_models-'inference'-Row +
 * die system_settings-Keys, die der LlmRouter braucht, um den gescripteten
 * Provider fuer Regel-Extraktions-Calls auszuwaehlen.
 *
 * Jede Test-Klasse deklariert ihre eigene PROVIDER_ID-Konstante (pro Klasse
 * eindeutig) und ruft $this->seedInferenceRouting(self::PROVIDER_ID, '<Name>').
 */
trait SeedsInferenceRouting
{
	private function seedInferenceRouting(string $providerId, string $name = 'TestInference'): void
	{
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . $providerId . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . $providerId . "'");
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, ?, 'anthropic', 'http://x', 0, 1, 10)")->execute([$providerId, $name]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (?, ?, 'claude-haiku-4-5-20251001', 'inference', 1, 10)")->execute([Uuid::v4(), $providerId]);
		foreach ([
			['llm.routing_mode', 'router'],
			['llm.privacy_mode', 'cloud_allowed'],
			['llm.inference.fallback_chain', '["' . $providerId . '"]'],
		] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`) VALUES (?, ?, "string")
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
		}
	}
}
