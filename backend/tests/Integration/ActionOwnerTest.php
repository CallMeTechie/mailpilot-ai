<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PendingActionRepository;
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
 * Sprint 6a — pinnt PRD-§5.1-Invarianten (action_owner NICHT im Cache),
 * Recipients-Payload-Format und Anthropic-Cache-Control. Diese Tests
 * fangen Regressionen, die das KI-Butler-Verhalten subtil brechen
 * würden (z.B. Cache vererbt action_owner über Mails hinweg).
 *
 * @group integration
 */
final class ActionOwnerTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
		// Sprint 6c: Test geht von Auto-Discovery aus (kein suggest-Pending).
		$this->pdo()->prepare("UPDATE system_settings SET `value`='auto'
			WHERE `key`='autosort_create_topic_mode'")->execute();
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
			new AutoSortRepository($pdo),
			new PromptRepository($pdo),
			new SettingsRepository($pdo),
			20,
			2048,
			$this->logger(),
			new PendingActionRepository($pdo),
		);
	}

	private function seedTenantAndMailbox(): array
	{
		[$tenantId, $userId] = [Uuid::v4(), Uuid::v4()];
		$mailboxId = Uuid::v4();
		$this->pdo()->prepare('INSERT INTO tenants (id, name) VALUES (:id, "T")')
			->execute([':id' => $tenantId]);
		$this->pdo()->prepare('INSERT INTO users (id, email, display_name) VALUES (:id, :e, "Marc")')
			->execute([':id' => $userId, ':e' => 'marc@example.de']);
		$this->pdo()->prepare('INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (:t, :u, "owner")')
			->execute([':t' => $tenantId, ':u' => $userId]);
		// mailboxes.refresh_token_enc / .scopes sind NOT NULL ohne Default —
		// für Tests reichen Dummy-Bytes bzw. ein leerer Scope-String.
		$this->pdo()->prepare('INSERT INTO mailboxes
			(id, tenant_id, user_id, ms_user_id, ms_tenant_id, email,
			 refresh_token_enc, scopes)
			VALUES (:id, :t, :u, "msuser", "mstenant", "marc@example.de",
			 "fake-enc", "Mail.Read")')
			->execute([':id' => $mailboxId, ':t' => $tenantId, ':u' => $userId]);
		return [$tenantId, $userId, $mailboxId];
	}

	private function seedMail(string $tenantId, string $mailboxId, string $body, array $to = []): string
	{
		$id = Uuid::v4();
		$this->pdo()->prepare('INSERT INTO mails
			(id, tenant_id, mailbox_id, ms_message_id, conversation_id, internet_msg_id,
			 from_email, from_name, to_json, cc_json, subject, body_preview, body_text,
			 has_attachment, list_unsubscribe, received_at)
			VALUES (:id, :t, :mb, :ms, "conv", "imid", "ext@kunde.de", "Ext", :to, "[]",
			        "Subj", :bp, :bt, 0, 0, UTC_TIMESTAMP(3))')
			->execute([
				':id' => $id, ':t' => $tenantId, ':mb' => $mailboxId,
				':ms' => 'msg-' . substr($id, 0, 8),
				':to' => json_encode($to, JSON_UNESCAPED_UNICODE),
				':bp' => substr($body, 0, 500), ':bt' => $body,
			]);
		return $id;
	}

	private function fetchMail(string $mailId): array
	{
		$row = $this->pdo()->query("SELECT * FROM mails WHERE id = " . $this->pdo()->quote($mailId))->fetch();
		$this->assertIsArray($row);
		return $row;
	}

	public function testActionOwnerFieldsAreNotCached(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();

		$claude = new FakeClaudeClient();
		$mailId = $this->seedMail($tenantId, $mailboxId, 'Hallo Marc, kannst du das prüfen?',
			[['address' => 'marc@example.de', 'name' => 'Marc']]);

		$claude->scriptJson(['results' => [[
			'id' => $mailId, 'label' => 'action', 'sub_label' => null, 'sub_label_is_new' => false,
			'action_required' => true, 'action_owner' => 'user', 'action_owner_confidence' => 90,
			'priority' => 4, 'summary' => 'Prüfung gewünscht', 'reasoning' => 'Anrede + Frage',
		]]]);

		$this->makeService($claude)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
			'vip_senders' => [], 'project_keywords' => [],
		], [$this->fetchMail($mailId)]);

		$row = $this->pdo()->query('SELECT result_json FROM claude_cache LIMIT 1')->fetch();
		$this->assertIsArray($row, 'cache row should exist after fresh score');
		$cached = json_decode((string)$row['result_json'], true);
		$this->assertArrayNotHasKey('action_owner', $cached,
			'PRD §5.1: action_owner darf NIE im claude_cache landen');
		$this->assertArrayNotHasKey('action_owner_confidence', $cached,
			'PRD §5.1: action_owner_confidence darf NIE im claude_cache landen');
	}

	public function testFreshScoreStoresActionOwnerWithKiSource(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();
		$claude = new FakeClaudeClient();
		$mailId = $this->seedMail($tenantId, $mailboxId, 'Hi Marc!',
			[['address' => 'marc@example.de', 'name' => 'Marc']]);
		$claude->scriptJson(['results' => [[
			'id' => $mailId, 'label' => 'direct', 'sub_label' => null, 'sub_label_is_new' => false,
			'action_required' => false, 'action_owner' => 'user', 'action_owner_confidence' => 85,
			'priority' => 3, 'summary' => 'Test', 'reasoning' => 'x',
		]]]);

		$this->makeService($claude)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$this->fetchMail($mailId)]);

		$score = $this->pdo()->query('SELECT action_owner, action_owner_confidence, action_owner_source
			FROM mail_scores LIMIT 1')->fetch();
		$this->assertSame('user', $score['action_owner']);
		$this->assertSame(85,     (int)$score['action_owner_confidence']);
		$this->assertSame('ki',   $score['action_owner_source']);
	}

	public function testInvalidActionOwnerFromClaudeIsCoercedToUnsure(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();
		$claude = new FakeClaudeClient();
		$mailId = $this->seedMail($tenantId, $mailboxId, 'body',
			[['address' => 'marc@example.de', 'name' => 'Marc']]);
		// Claude liefert garbage in action_owner — Service muss auf
		// 'unsure' clampen statt den Enum-Constraint zu verletzen.
		$claude->scriptJson(['results' => [[
			'id' => $mailId, 'label' => 'auto', 'sub_label_is_new' => false,
			'action_required' => false, 'action_owner' => 'bogus_value', 'action_owner_confidence' => 999,
			'priority' => 2, 'summary' => 'x', 'reasoning' => 'y',
		]]]);

		$this->makeService($claude)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$this->fetchMail($mailId)]);

		$score = $this->pdo()->query('SELECT action_owner, action_owner_confidence
			FROM mail_scores LIMIT 1')->fetch();
		$this->assertSame('unsure', $score['action_owner']);
		$this->assertSame(100, (int)$score['action_owner_confidence'],
			'confidence darf 100 nicht überschreiten');
	}

	public function testRecipientsArrayInPromptPayload(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();
		$claude = new FakeClaudeClient();
		$mailId = $this->seedMail($tenantId, $mailboxId, 'Hallo Marc',
			[
				['address' => 'marc@example.de',   'name' => 'Marc'],
				['address' => 'klaus@kunde.de',    'name' => 'Klaus'],
			]);
		$claude->scriptJson(['results' => [[
			'id' => $mailId, 'label' => 'direct', 'sub_label_is_new' => false,
			'action_required' => false, 'action_owner' => 'user', 'action_owner_confidence' => 70,
			'priority' => 3, 'summary' => 'x', 'reasoning' => 'y',
		]]]);

		$this->makeService($claude)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$this->fetchMail($mailId)]);

		$lastCall = $claude->lastCall();
		$userMessage = $lastCall['messages'][0]['content'] ?? '';
		$this->assertStringContainsString('"recipients"', $userMessage,
			'recipients-Array muss im User-Prompt landen');
		$this->assertStringContainsString('"is_user":true', $userMessage,
			'User-Empfänger muss als is_user:true markiert sein');
	}

	public function testSystemPromptHasExtendedCacheControl(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();
		$claude = new FakeClaudeClient();
		$mailId = $this->seedMail($tenantId, $mailboxId, 'body',
			[['address' => 'marc@example.de', 'name' => 'Marc']]);
		$claude->scriptJson(['results' => [[
			'id' => $mailId, 'label' => 'auto', 'sub_label_is_new' => false,
			'action_required' => false, 'action_owner' => 'unsure', 'action_owner_confidence' => 0,
			'priority' => 2, 'summary' => 'x', 'reasoning' => 'y',
		]]]);

		$this->makeService($claude)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$this->fetchMail($mailId)]);

		$call = $claude->lastCall();
		$this->assertIsArray($call['system'] ?? null, 'system muss segmentiert sein für Prompt-Caching');
		$this->assertSame('ephemeral', $call['system'][0]['cache_control']['type'] ?? null);
		$this->assertSame('1h', $call['system'][0]['cache_control']['ttl'] ?? null,
			'Sprint 6a §5.2: 1h-Extended-TTL ist Pflicht');
	}
}
