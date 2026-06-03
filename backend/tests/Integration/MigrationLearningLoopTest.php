<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\SettingsRepository;
use MailPilot\Tests\TestCase;

final class MigrationLearningLoopTest extends TestCase
{
	public function testSchemaAndSettingsSeeded(): void
	{
		$pdo = $this->pdo();
		$col = $pdo->query("SHOW COLUMNS FROM score_override_rules LIKE 'origin_correction_id'")->fetch();
		self::assertNotFalse($col, 'origin_correction_id fehlt');
		self::assertStringContainsString('char(36)', strtolower((string)$col['Type']), 'origin_correction_id muss CHAR(36) sein');
		self::assertSame('YES', $col['Null'], 'origin_correction_id muss nullable sein');
		$idxRows = $pdo->query("SHOW INDEX FROM score_override_rules WHERE Key_name = 'idx_sor_origin'")->fetchAll();
		self::assertNotEmpty($idxRows, 'idx_sor_origin Index fehlt');
		$role = $pdo->query("SHOW COLUMNS FROM llm_models LIKE 'role'")->fetch();
		self::assertStringContainsString("'match'", (string)$role['Type']);
		$logRole = $pdo->query("SHOW COLUMNS FROM llm_call_log LIKE 'role'")->fetch();
		self::assertStringContainsString("'match'", (string)$logRole['Type'], 'llm_call_log.role muss match kennen');
		$kind = $pdo->query("SHOW COLUMNS FROM pending_actions LIKE 'kind'")->fetch();
		self::assertStringContainsString("'score_suggestion'", (string)$kind['Type'], 'pending_actions.kind muss score_suggestion kennen');
		$set = new SettingsRepository($pdo);
		self::assertSame('deterministic', $set->getString('learning.match_mode', ''));
		self::assertSame('80', $set->getString('learning.match_auto_threshold', ''));
		self::assertSame('50', $set->getString('learning.match_suggest_threshold', ''));
		self::assertSame('5', $set->getString('learning.match_per_batch_budget', ''));
		self::assertSame('200', $set->getString('learning.score_rules_soft_cap', ''));
		self::assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM llm_models WHERE role='match'")->fetchColumn());
	}
}
