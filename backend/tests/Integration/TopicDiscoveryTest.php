<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Llm\LlmRouter;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
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
use MailPilot\Tests\Fixtures\ScriptedLlmProvider;
use MailPilot\Tests\Support\SeedsScoreRouting;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * Sprint 6b — pinnt Autonome Topic-Discovery:
 *   - KI discovered Sub-Label → AutoSortRule + created_by-Spuren
 *   - USER_TOPICS landet im Score-Prompt (Sub-Label-Pool)
 *   - Empty Pool: kein USER_TOPICS-Block (kein wasted cache_creation)
 *
 * Spec 1 (2026-06-02): Score läuft IMMER über den LlmRouter. Der frühere
 * direkte Anthropic-Segment-Assert (`$call['system'][N]['cache_control']`)
 * ist nicht mehr möglich — callViaRouter flacht die Segmente in einen
 * systemPrompt-String. Die Tests prüfen stattdessen, dass der Pool-Inhalt
 * im Score-Prompt landet (bzw. bei leerem Pool eben NICHT).
 *
 * @group integration
 */
final class TopicDiscoveryTest extends TestCase
{
	use SeedsScoreRouting;

	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c2';

	protected function setUp(): void
	{
		$this->truncateAll();
		// Sprint 6c: 'auto'-Mode für Discovery, damit Rule sofort enabled
		// wird (TopicDiscoveryTest pinnt das Sprint-6b-Verhalten).
		$this->pdo()->prepare("UPDATE system_settings SET `value`='auto'
			WHERE `key`='autosort_create_topic_mode'")->execute();
		$this->seedScoreRouting(self::PROVIDER_ID, 'TestScoreTopic');
	}

	private function makeService(ScriptedLlmProvider $provider): MailScoringService
	{
		$pdo = $this->pdo();
		$budget = new BudgetService(
			new SettingsRepository($pdo),
			new UsageRepository($pdo),
			new PricingRepository($pdo),
			$this->logger(),
		);
		$router = new LlmRouter(
			['anthropic' => $provider],
			new LlmProviderRepository($pdo),
			new SettingsRepository($pdo),
			new NullLogger(),
			new LlmModelRepository($pdo),
		);
		return new MailScoringService(
			new FakeClaudeClient(),
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
			new PendingActionRepository($pdo),
			null,
			null,
			null,
			null,
			$router,
		);
	}

	/**
	 * Alles was der Score-LLM gesehen hat: System-Prompt (geflachte Segmente)
	 * + User-Message.
	 */
	private function sentPrompt(ScriptedLlmProvider $provider): string
	{
		$seen = $provider->seen;
		$this->assertNotNull($seen, 'Score MUSS über den Router gelaufen sein');
		$parts = [$seen->systemPrompt];
		foreach ($seen->messages as $m) {
			$parts[] = (string)($m['content'] ?? '');
		}
		return implode("\n", $parts);
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
		$mailId = $this->seedMail($tenantId, $mailboxId);

		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mailId, 'label' => 'auto',
			'sub_label' => 'GitHub CI', 'sub_label_is_new' => true,
			'action_required' => false,
			'action_owner' => 'group', 'action_owner_confidence' => 50,
			'priority' => 2, 'summary' => 'CI passed', 'reasoning' => 'auto',
		]]);

		$this->makeService($provider)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$this->pdo()->query("SELECT * FROM mails WHERE id = " . $this->pdo()->quote($mailId))->fetch()]);

		$rule = $this->pdo()->query("SELECT enabled, created_by, folder_name
			FROM auto_sort_rules WHERE sub_label = 'GitHub CI' LIMIT 1")->fetch();
		$this->assertIsArray($rule, 'KI-Discovery muss eine AutoSortRule erzeugen');
		// Sprint 6c im auto-Modus: Rule sofort enabled. Suggest-Modus =
		// disabled + pending_action — gepinnt in PendingActionsTest.
		$this->assertSame(1,     (int)$rule['enabled'],     'Auto-Modus enabled die Rule sofort');
		// created_by ist 'user' im auto-Pfad (upsert vs suggestKiRule).
		// Der KI-Badge im UI greift nur über sub_labels.created_by='ki'
		// (das bleibt korrekt gesetzt durch SubLabelRepository::create).
		$this->assertStringContainsString('GitHub CI', $rule['folder_name'],
			'folder_name sollte den Topic-Namen enthalten');
	}

	public function testEmptySubLabelPoolOmitsUserTopicsSegment(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();
		$mailId = $this->seedMail($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mailId, 'label' => 'auto',
			'sub_label_is_new' => false,
			'action_required' => false,
			'action_owner' => 'group', 'action_owner_confidence' => 30,
			'priority' => 2, 'summary' => 'x', 'reasoning' => 'y',
		]]);

		$this->makeService($provider)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$this->pdo()->query("SELECT * FROM mails WHERE id = " . $this->pdo()->quote($mailId))->fetch()]);

		// Leerer Pool → KEIN existing-bucket-Header (USER_SUBLABELS-Pool-Block)
		// im Score-Prompt. callViaRouter flacht die Segmente; der frühere
		// Segment-Count-Assert auf $call['system'] entfällt.
		$sentToLlm = $this->sentPrompt($provider);
		$this->assertStringNotContainsString('USER_SUBLABELS (existing buckets', $sentToLlm,
			'Leerer Sub-Label-Pool darf keinen USER_SUBLABELS-Pool-Block erzeugen');
	}

	public function testPopulatedPoolPutsUserTopicsAsThirdCachedSegment(): void
	{
		[$tenantId, $userId, $mailboxId] = $this->seedTenantAndMailbox();
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO user_sublabels (id, tenant_id, user_id, parent, name, description, created_by)
			VALUES (:id, :t, :u, "auto", "Bestellung", "Versand", "user")')
			->execute([':id' => Uuid::v4(), ':t' => $tenantId, ':u' => $userId]);

		$mailId = $this->seedMail($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mailId, 'label' => 'auto',
			'sub_label' => 'Bestellung', 'sub_label_is_new' => false,
			'action_required' => false,
			'action_owner' => 'group', 'action_owner_confidence' => 50,
			'priority' => 2, 'summary' => 'x', 'reasoning' => 'y',
		]]);

		$this->makeService($provider)->scoreBatch($tenantId, [
			'email' => 'marc@example.de', 'display_name' => 'Marc',
			'tenant_id' => $tenantId, 'user_id' => $userId,
			'language' => 'de', 'aliases' => ['Marc'],
		], [$pdo->query("SELECT * FROM mails WHERE id = " . $pdo->quote($mailId))->fetch()]);

		// Mit gefülltem Pool muss der USER_SUBLABELS-Pool-Block mit dem
		// Sub-Label-Namen im Score-Prompt landen (System-Segment 3, geflacht).
		$sentToLlm = $this->sentPrompt($provider);
		$this->assertStringContainsString('USER_SUBLABELS', $sentToLlm,
			'Mit gefülltem Pool muss der USER_SUBLABELS-Block im Prompt sein');
		$this->assertStringContainsString('Bestellung', $sentToLlm,
			'USER_SUBLABELS-Block muss die Sub-Label-Namen enthalten');
	}
}
