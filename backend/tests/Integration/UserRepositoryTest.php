<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\UserRepository;
use MailPilot\Tests\TestCase;

/**
 * @group integration
 */
final class UserRepositoryTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testUpsertCreatesNewTenantAndUser(): void
	{
		$repo = new UserRepository($this->pdo());
		[$tenantId, $userId] = $repo->upsertTenantAndUser('new@test.de', 'New User');
		$this->assertNotEmpty($tenantId);
		$this->assertNotEmpty($userId);

		$user = $this->pdo()->query("SELECT * FROM users WHERE id = " . $this->pdo()->quote($userId))->fetch();
		$this->assertSame('new@test.de', $user['email']);
		$this->assertSame('New User', $user['display_name']);
	}

	public function testUpsertIsIdempotentForExistingUser(): void
	{
		$repo = new UserRepository($this->pdo());
		[$t1, $u1] = $repo->upsertTenantAndUser('marc@test.de', 'Marc');
		[$t2, $u2] = $repo->upsertTenantAndUser('marc@test.de', 'Marc');
		$this->assertSame($t1, $t2);
		$this->assertSame($u1, $u2);
	}

	public function testReplaceKeywordsRemovesOldAddsNew(): void
	{
		$repo = new UserRepository($this->pdo());
		[$tenantId, $userId] = $repo->upsertTenantAndUser('marc@test.de', 'Marc');

		$repo->replaceKeywords($tenantId, $userId, ['Ori:Dev', 'SocialPilot']);
		$repo->replaceKeywords($tenantId, $userId, ['MailPilot', 'FieldLink']);

		$stmt = $this->pdo()->prepare('SELECT keyword FROM project_keywords
			WHERE user_id = :u AND deleted_at IS NULL ORDER BY keyword');
		$stmt->execute([':u' => $userId]);
		$kws = array_column($stmt->fetchAll(), 'keyword');

		$this->assertSame(['FieldLink', 'MailPilot'], $kws);
	}

	public function testUpdatePreferencesPartial(): void
	{
		$repo = new UserRepository($this->pdo());
		[, $userId] = $repo->upsertTenantAndUser('marc@test.de', 'Marc');
		$repo->updatePreferences($userId, 'en', null, null);

		$row = $this->pdo()->query("SELECT language, timezone, briefing_hour FROM users WHERE id = " . $this->pdo()->quote($userId))->fetch();
		$this->assertSame('en', $row['language']);
		$this->assertSame('Europe/Berlin', $row['timezone'], 'unchanged');
		$this->assertSame(7, (int)$row['briefing_hour'], 'unchanged');
	}
}
