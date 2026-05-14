<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Services\BudgetService;
use MailPilot\Services\MailScoringService;
use MailPilot\Services\RedactionService;
use MailPilot\Tests\Fixtures\FakeClaudeClient;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use PDO;

/**
 * Sprint 6b — pinnt Autonome Topic-Discovery:
 *   - KI discovered Sub-Label → AutoSortRule disabled + created_by='ki'
 *   - USER_TOPICS landet als drittes cache_control-Segment im System
 *   - Empty Pool: kein USER_TOPICS-Segment (kein wasted cache_creation)
 *
 * @group integration
 */
final class TopicDiscoveryTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	private function makeService(FakeClaudeClient $claude): MailScoringService
	{
		$pdo = $this->pdo();
		$budget = new BudgetService(
			new SettingsRepository($pdo),
			new UsageRepository($pdo),
			new PricingRepository($pdo),
			$this->logger(),
		);
		return new MailScoringService(
			$claude,
			new MailRepository($pdo),
			new ScoreRepository($pdo),
			new CacheRepository($pdo, 30),
			new RedactionService(),
			$budget,
			new CorrectionRepository($pdo),
			new SubLabelRepository($pdo),
			new AutoSortRepository($pdo, new SettingsRepository($pdo)),
			new PromptRepository($pdo),
			new SettingsRepository($pdo),
			20,
			2048,
			$this->logger(),
		);
	}

	private function seedTenantAndMailbox(): array
	{
		[$tenantId, $userId] = [Uuid::v4(), Uuid::v4()];
		$mailboxId = Uuid::v4();
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO tenants (id, name) VALUES (:id, "T")')->execute([':id' => $tenantId]);
		$pdo->prepare('INSERT INTO users (id, email, display_name) VALUES (:id, "marc@example.de", "Marc")')->execute([':id' => $userId]);
		$pdo->prepare('INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (:t, :u, "owner")')->execute([':t' => $tenantId, ':u' => $userId]);
		$pdo->prepare('INSERT INTO mailboxes (id, tenant_id, user_id, ms_user_id, ms_tenant_id, email,
			refresh_token_enc, scopes) VALUES (:id, :t, :u, "msu", "mst", "marc@example.de", "x", "Mail.Read")')
			->execute([':id' => $mailboxId, ':t' => $tenantId, ':u' => $userId]);
		return [$tenantId, $userId, $mailboxId];
	}

	private function seedMail(string $tenantId, string $mailboxId): string
	{
		$id = Uuid::v4();
		$this->pdo()->prepare('INSERT INTO mails
			(id, tenant_id, mailbox_id, ms_message_id, conversation_id, internet_msg_id,
			 from_email, from_name, to_json, cc_json, subject, body_preview, body_text,
			 has_attachment, list_unsubscribe, received_at)
			VALUES (:id, :t, :mb, :ms, "conv", "imid", "ci@github.com", "GitHub",
			 :to, "[]", "Build passed", "body", "body", 0, 0, UTC_TIMESTAMP(3))')
			->execute([
				':id' => $id, ':t' => $tenantId, ':mb' => $mailboxId,
				':ms' => 'msg-' . substr($id, 0, 8),
				':to' => json_encode([['address' => 'marc@example.de', 'name' => 'Marc']]),
			]);
		return $id;
	}

	public function testKiDiscoveryCreatesDisabledAutoSortRule(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();
		$claude = new FakeClaudeClient();
		$mailId = $this->seedMail($tenantId, $mailboxId);

		$claude->scriptJson(['results' => [[
			'id' => $mailId, 'label' => 'auto',
			'sub_label' => 'GitHub CI', 'sub_label_is_new' => true,
			'action_required' => false,
			'action_owner' => 'group', 'action_owner_confidence' => 50,
			'priority' => 2, 'summary' => 'CI passed', 'reasoning' => 'auto',
		]]]);

		$this->makeService($claude)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$this->pdo()->query("SELECT * FROM mails WHERE id = " . $this->pdo()->quote($mailId))->fetch()]);

		$rule = $this->pdo()->query("SELECT enabled, created_by, folder_name
			FROM auto_sort_rules WHERE sub_label = 'GitHub CI' LIMIT 1")->fetch();
		$this->assertIsArray($rule, 'KI-Discovery muss eine AutoSortRule erzeugen');
		$this->assertSame(0,     (int)$rule['enabled'],     'Rule muss disabled sein (User-Approve nötig)');
		$this->assertSame('ki',  $rule['created_by'],       'Rule muss als KI-Vorschlag markiert sein');
		$this->assertStringContainsString('GitHub CI', $rule['folder_name'],
			'folder_name sollte den Topic-Namen enthalten');
	}

	public function testEmptySubLabelPoolOmitsUserTopicsSegment(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();
		$claude = new FakeClaudeClient();
		$mailId = $this->seedMail($tenantId, $mailboxId);
		$claude->scriptJson(['results' => [[
			'id' => $mailId, 'label' => 'auto',
			'sub_label_is_new' => false,
			'action_required' => false,
			'action_owner' => 'group', 'action_owner_confidence' => 30,
			'priority' => 2, 'summary' => 'x', 'reasoning' => 'y',
		]]]);

		$this->makeService($claude)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$this->pdo()->query("SELECT * FROM mails WHERE id = " . $this->pdo()->quote($mailId))->fetch()]);

		$call = $claude->lastCall();
		$this->assertIsArray($call['system'] ?? null);
		// Leerer Pool → genau 2 Segmente: System + USER_IDENTITY.
		// Kein USER_TOPICS, damit kein cache_creation für leeren Block.
		$this->assertCount(2, $call['system'],
			'Leerer Sub-Label-Pool darf kein USER_TOPICS-Segment erzeugen');
	}

	public function testPopulatedPoolPutsUserTopicsAsThirdCachedSegment(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO user_sublabels (id, tenant_id, user_id, parent, name, description, created_by)
			VALUES (:id, :t, :u, "auto", "Bestellung", "Versand", "user")')
			->execute([':id' => Uuid::v4(), ':t' => $tenantId, ':u' => $userId]);

		$claude = new FakeClaudeClient();
		$mailId = $this->seedMail($tenantId, $mailboxId);
		$claude->scriptJson(['results' => [[
			'id' => $mailId, 'label' => 'auto',
			'sub_label' => 'Bestellung', 'sub_label_is_new' => false,
			'action_required' => false,
			'action_owner' => 'group', 'action_owner_confidence' => 50,
			'priority' => 2, 'summary' => 'x', 'reasoning' => 'y',
		]]]);

		$this->makeService($claude)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$pdo->query("SELECT * FROM mails WHERE id = " . $pdo->quote($mailId))->fetch()]);

		$call = $claude->lastCall();
		$this->assertIsArray($call['system'] ?? null);
		$this->assertCount(3, $call['system'],
			'Mit gefülltem Pool muss USER_TOPICS als drittes Segment kommen');
		$this->assertSame('ephemeral', $call['system'][2]['cache_control']['type'] ?? null);
		$this->assertSame('1h',        $call['system'][2]['cache_control']['ttl']  ?? null);
		$this->assertStringContainsString('Bestellung', $call['system'][2]['text'] ?? '',
			'USER_TOPICS-Segment muss die Sub-Label-Namen enthalten');
	}
}
