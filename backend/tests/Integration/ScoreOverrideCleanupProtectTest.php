<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\ScoreOverrideCleanupService;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

final class ScoreOverrideCleanupProtectTest extends TestCase
{
	public function testUserDerivedRuleSurvivesAutoclean(): void
	{
		$this->truncateAll();
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$pdo = $this->pdo();
		$id = (new ScoreOverrideRepository($pdo))->create($tenantId, $userId, [
			'match_sender_key' => 'sk:x', 'set_priority' => 4,
			'source' => 'ki_inferred', 'origin_correction_id' => Uuid::v4(), 'enabled' => false,
		]);
		$pdo->prepare("UPDATE score_override_rules SET created_at = (UTC_TIMESTAMP(3) - INTERVAL 30 DAY) WHERE id = :id")->execute([':id' => $id]);

		$set = new SettingsRepository($pdo);
		$set->set('score_rule_autoclean.enabled', '1');
		$set->set('score_rule_autoclean.delete_disabled', '1');
		$set->set('score_rule_autoclean.delete_unused_after_days', '7');

		(new ScoreOverrideCleanupService($pdo, $set, $this->logger()))->cleanup();

		$alive = $pdo->prepare('SELECT deleted_at FROM score_override_rules WHERE id = :id');
		$alive->execute([':id' => $id]);
		self::assertNull($alive->fetchColumn(), 'user-derived Regel darf NICHT gelöscht werden');
	}
}
