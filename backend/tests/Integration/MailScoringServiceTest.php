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
 * @group integration
 */
final class MailScoringServiceTest extends TestCase
{
	use SeedsScoreRouting;

	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c1';

	protected function setUp(): void
	{
		$this->truncateAll();
		// Sprint 6c: Bestandstests prüfen Auto-Discovery-Verhalten (KI legt
		// Sub-Label + Rule sofort an). Migration 0018 seedet 'suggest' als
		// Default — auf 'auto' setzen damit Tests durchgehen.
		$this->pdo()->prepare("UPDATE system_settings SET `value`='auto'
			WHERE `key`='autosort_create_topic_mode'")->execute();
		$this->seedScoreRouting(self::PROVIDER_ID, 'TestScore');
	}

	private function makeService(ScriptedLlmProvider $provider, FakeClaudeClient $claude): MailScoringService
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
			new PendingActionRepository($pdo),
			null,
			null,
			null,
			null,
			$router,
		);
	}

	/**
	 * The List-Unsubscribe pre-filter used to short-circuit every mail
	 * with that header to a hard-coded "newsletter" preset without ever
	 * calling Claude. That heuristic was removed (List-Unsubscribe is
	 * mandatory for nearly every transactional sender under DSGVO, so
	 * it consistently mislabelled important mail). This test pins the
	 * new behaviour: such mails do reach the scoring LLM and can be
	 * classified to any label it returns.
	 */
	public function testListUnsubscribeMailReachesScoringLlm(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, [
			'list_unsubscribe' => 1,
			'from_email'       => 'lawyer@example.com',
			'subject'          => 'Wichtige Mandatsinformation',
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'direct',
			'action_required' => true,
			'priority' => 5,
			'summary' => 'Anwalt schickt Mandatsinformation',
			'reasoning' => 'transactional sender',
		]]);
		$service = $this->makeService($provider, new FakeClaudeClient());

		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertCount(1, $scores);
		$this->assertSame(1, $provider->callCount(), 'List-Unsubscribe must NOT bypass the scoring LLM any more');
		$this->assertSame('direct', $scores[0]['label']);
	}

	public function testVipSenderClassifiedByClaude(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, [
			'list_unsubscribe' => 1,
			'from_email'       => 'boss@example.com',
			'subject'          => 'Wichtige Info zur Kampagne',
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'direct',
			'action_required' => false,
			'priority' => 4,
			'summary' => 'Chef schickt wichtige Kampagneninfo',
			'reasoning' => 'vip sender',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => ['boss@example.com'], 'project_keywords' => [],
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertSame(1, $provider->callCount());
		$this->assertSame('direct', $scores[0]['label']);
	}

	public function testClaudeResultsAreCachedByContentHash(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$this->insertMail($tenantId, $mailboxId, ['from_email' => 'x@a.de', 'subject' => 'Same', 'body_text' => 'Same body']);
		$repo = new MailRepository($this->pdo());
		$first = $repo->findUnscoredForMailbox($tenantId, $mailboxId);

		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $first[0]['id'],
			'label' => 'direct',
			'action_required' => false,
			'priority' => 3,
			'summary' => 'Test',
			'reasoning' => 'r',
		]]);
		// Mini-Call (action_owner) bei Cache-Hit läuft NICHT über den Router,
		// sondern via ActionOwnerResolver → FakeClaudeClient. Daher hier ein
		// echter FakeClaudeClient mit gescripteter Mini-Call-Antwort.
		$claude = new FakeClaudeClient();
		$service = $this->makeService($provider, $claude);
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$service->scoreBatch($tenantId, $profile, $first);
		$this->assertSame(1, $provider->callCount(), 'Erste Mail → genau ein Router-Score-Call');

		// Second batch: another mail with identical content — must hit cache
		// for the SCORE, but Sprint 6a fires a separate Mini-Call for
		// action_owner (post-cache). Score-Klassifizierung kommt aus dem
		// Cache (cached=1); der Mini-Call läuft über FakeClaudeClient.
		$this->insertMail($tenantId, $mailboxId, ['from_email' => 'x@a.de', 'subject' => 'Same', 'body_text' => 'Same body']);
		$second = $repo->findUnscoredForMailbox($tenantId, $mailboxId);
		$this->assertCount(1, $second, 'Only the new unscored mail should remain');

		// Mini-Call-Antwort scripten — sonst krasht FakeClaudeClient (no
		// scripted response). Antwort enthält action_owner, damit der
		// Service ihn auch persistieren kann.
		$claude->scriptJson(['results' => [[
			'mail_id' => $second[0]['id'],
			'action_owner' => 'user',
			'confidence' => 75,
		]]]);

		$scores = $service->scoreBatch($tenantId, $profile, $second);
		$this->assertSame(1, $provider->callCount(),
			'Score kommt aus dem Cache → KEIN zweiter Router-Score-Call');
		$this->assertSame(1, $claude->callCount(),
			'action_owner-Mini-Call läuft über FakeClaudeClient (Sprint 6a)');
		$this->assertCount(1, $scores);
		$this->assertSame(1, (int)$scores[0]['cached']);
	}

	public function testInvalidLabelIsCoercedToAuto(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'INVENTED_LABEL',
			'action_required' => true,
			'priority' => 99,  // out of range, must clamp
			'summary' => str_repeat('x', 300),  // oversized, must truncate
			'reasoning' => 'r',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertSame('auto', $scores[0]['label']);
		$this->assertSame(5, $scores[0]['priority'], 'priority must be clamped to 1..5');
		$this->assertLessThanOrEqual(200, mb_strlen($scores[0]['summary']));
	}

	public function testBodyIsRedactedBeforeSendToClaude(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, [
			'body_text' => 'Meine IBAN ist DE89 3704 0044 0532 0130 00 bitte merken.',
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'], 'label' => 'direct',
			'action_required' => false, 'priority' => 3,
			'summary' => 's', 'reasoning' => 'r',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$service->scoreBatch($tenantId, $profile, $mails);

		// Was der Provider tatsächlich gesehen hat (System + User-Message).
		$seen = $provider->seen;
		$this->assertNotNull($seen);
		$sent = json_encode([$seen->systemPrompt, $seen->messages], JSON_UNESCAPED_UNICODE);
		$this->assertStringNotContainsString('DE89', $sent, 'IBAN must not reach the scoring LLM');
		$this->assertStringContainsString('[IBAN-REDACTED]', $sent);
	}

	// --- Stage 5b: sub-labels ---------------------------------------

	public function testSubLabelChosenByClaudeIsPersisted(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'notifications@github.com',
			'subject'    => '[mailpilot-ai] CI run #1234 success',
		]);

		(new SubLabelRepository($this->pdo()))->create($tenantId, $userId, 'auto', 'GitHub CI', 'CI pipeline mails', null);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => 'GitHub CI',
			'action_required' => false,
			'priority' => 2,
			'summary' => 'CI passed',
			'reasoning' => 'github notification',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => [], 'project_keywords' => [],
			'tenant_id' => $tenantId, 'user_id' => $userId,
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertSame('auto', $scores[0]['label']);
		$this->assertSame('GitHub CI', $scores[0]['sub_label']);

		// Prompt actually contained the USER_SUBLABELS block (System-Segment 3,
		// das callViaRouter in systemPrompt flacht) + den Sub-Label-Namen.
		$sentToLlm = $this->sentPrompt($provider);
		$this->assertStringContainsString('USER_SUBLABELS', $sentToLlm);
		$this->assertStringContainsString('GitHub CI', $sentToLlm);
	}

	public function testHallucinatedSubLabelCollapsesToNull(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId);

		(new SubLabelRepository($this->pdo()))->create($tenantId, $userId, 'auto', 'GitHub CI', null, null);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => 'Made Up Bucket',   // not in user's pool
			'action_required' => false,
			'priority' => 2,
			'summary' => 's',
			'reasoning' => 'r',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => [], 'project_keywords' => [],
			'tenant_id' => $tenantId, 'user_id' => $userId,
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertSame('auto', $scores[0]['label']);
		$this->assertNull($scores[0]['sub_label'], 'Off-pool sub_label must collapse to NULL');
	}

	public function testSubLabelFromDifferentPrimaryIsRejected(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId);

		// User has "GitHub CI" under auto only — Claude must not borrow it
		// for a mail labelled `direct`.
		(new SubLabelRepository($this->pdo()))->create($tenantId, $userId, 'auto', 'GitHub CI', null, null);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'direct',
			'sub_label' => 'GitHub CI',   // valid name, wrong parent
			'action_required' => true,
			'priority' => 4,
			'summary' => 's',
			'reasoning' => 'r',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => [], 'project_keywords' => [],
			'tenant_id' => $tenantId, 'user_id' => $userId,
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertSame('direct', $scores[0]['label']);
		$this->assertNull($scores[0]['sub_label'], 'Sub-label only valid under its declared primary');
	}

	public function testEmptySubLabelPoolKeepsNullAndOmitsBlock(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'direct',
			'sub_label' => 'anything',
			'action_required' => false,
			'priority' => 3,
			'summary' => 's',
			'reasoning' => 'r',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => [], 'project_keywords' => [],
			'tenant_id' => $tenantId, 'user_id' => $userId,
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertNull($scores[0]['sub_label']);
		$sentToLlm = $this->sentPrompt($provider);
		// Pool-Header (mit Bucket-Liste) darf NICHT im Prompt sein — kein User-Pool.
		// Das Wort USER_SUBLABELS taucht aber in der TOPIC_DISCOVERY-Anweisung
		// als Referenz auf — das ist gewollt.
		$this->assertStringNotContainsString('USER_SUBLABELS (existing buckets', $sentToLlm,
			'Empty pool ⇒ no existing-bucket header');
		$this->assertStringContainsString('TOPIC_DISCOVERY', $sentToLlm,
			'Discovery block must always be present (Phase 6b)');
	}

	/**
	 * Alles was der Score-LLM gesehen hat: System-Prompt (callViaRouter flacht
	 * die Anthropic-Segmente in NormalizedRequest->systemPrompt) + User-Message.
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

	// --- Phase 6b: Topic-Discovery ---------------------------------

	public function testKiProposesNewTopicCreatesSubLabelAndRule(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'notifications@stripe.com',
			'subject'    => 'Payment received',
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => 'Stripe Payments',
			'sub_label_is_new' => true,
			'action_required' => false,
			'priority' => 2,
			'summary' => 'Zahlung erhalten',
			'reasoning' => 'stripe notification',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => [], 'project_keywords' => [],
			'tenant_id' => $tenantId, 'user_id' => $userId,
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertSame('Stripe Payments', $scores[0]['sub_label']);

		// user_sublabels: neuer Eintrag mit created_by='ki'
		$subs = (new SubLabelRepository($this->pdo()))->listForUser($tenantId, $userId);
		$this->assertCount(1, $subs);
		$this->assertSame('Stripe Payments', $subs[0]['name']);
		$this->assertSame('auto', $subs[0]['parent']);
		$this->assertSame('ki', $subs[0]['created_by']);

		// auto_sort_rules: passende Sub-Rule angelegt. Sprint 6c: das Test-
		// Setup zwingt autosort_create_topic_mode='auto' → Rule ist enabled.
		// Im 'suggest'-Modus (Default Production) wäre sie disabled + es
		// gäbe eine pending_action(create_topic); das pinnt PendingActionsTest.
		$rule = (new AutoSortRepository($this->pdo()))
			->findRule($tenantId, $userId, 'auto', 'Stripe Payments');
		$this->assertNotNull($rule);
		$this->assertTrue($rule['enabled'], 'Auto-Modus enabled die KI-Rule sofort (Sprint 6c)');
		$this->assertStringContainsString('Stripe Payments', $rule['folder_name']);
	}

	public function testFuzzyMergeReusesExistingTopicInsteadOfCreatingDuplicate(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId);

		// User hat bereits "GitHub CI" — Claude schlaegt "GitHub Actions"
		// vor (Levenshtein-Distanz <= 3 ggue. "GitHub CI"? "GitHub Actions"
		// und "GitHub CI" sind Distanz ~6; baue auf nahem Match auf).
		(new SubLabelRepository($this->pdo()))
			->create($tenantId, $userId, 'auto', 'GitHub CI', null, null);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => 'Github CI',   // Tippfehler: kleines 'h', Distanz 1
			'sub_label_is_new' => true,
			'action_required' => false,
			'priority' => 2,
			'summary' => 's',
			'reasoning' => 'r',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => [], 'project_keywords' => [],
			'tenant_id' => $tenantId, 'user_id' => $userId,
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertSame('GitHub CI', $scores[0]['sub_label'],
			'Tippfehler-Variante muss auf existing Topic gemerged werden');

		// Es darf weiterhin nur den einen existing Topic geben
		$subs = (new SubLabelRepository($this->pdo()))->listForUser($tenantId, $userId);
		$this->assertCount(1, $subs);
		$this->assertSame('user', $subs[0]['created_by'],
			'Der existing Topic wurde nicht ueberschrieben');
	}

	public function testInvalidNewTopicNameRejected(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$provider = new ScriptedLlmProvider();
		$provider->scriptResults([[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => str_repeat('A', 50),   // > 30 chars → rejected
			'sub_label_is_new' => true,
			'action_required' => false,
			'priority' => 2,
			'summary' => 's',
			'reasoning' => 'r',
		]]);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => [], 'project_keywords' => [],
			'tenant_id' => $tenantId, 'user_id' => $userId,
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertNull($scores[0]['sub_label']);
		$this->assertSame([], (new SubLabelRepository($this->pdo()))->listForUser($tenantId, $userId),
			'Format-invalid name darf nichts in user_sublabels schreiben');
	}

	// --- Task 1: scoring.batch_size als Laufzeit-Setting -------------

	/**
	 * Helper: schreibt scoring.batch_size in system_settings (UPSERT). Das
	 * SettingsRepository cached 30s — die hier frisch konstruierten Repos in
	 * makeService() sind aber jeweils neu, lesen also den aktuellen Wert.
	 */
	private function setScoringBatchSize(int $n): void
	{
		$this->pdo()->prepare(
			'INSERT INTO system_settings (`key`, `value`, `type`) VALUES ("scoring.batch_size", :v, "int")
			 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)'
		)->execute([':v' => (string)$n]);
	}

	/**
	 * @return list<array<string,mixed>> drei unscored Mails (distinkte Inhalte)
	 */
	private function seedThreeUnscoredMails(string $tenantId, string $mailboxId): array
	{
		$this->insertMail($tenantId, $mailboxId, ['from_email' => 'a@ex.de', 'subject' => 'Mail A', 'body_text' => 'Body A unique']);
		$this->insertMail($tenantId, $mailboxId, ['from_email' => 'b@ex.de', 'subject' => 'Mail B', 'body_text' => 'Body B unique']);
		$this->insertMail($tenantId, $mailboxId, ['from_email' => 'c@ex.de', 'subject' => 'Mail C', 'body_text' => 'Body C unique']);
		return (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
	}

	/**
	 * @param list<array<string,mixed>> $chunk
	 */
	private function scriptScoreResults(ScriptedLlmProvider $provider, array $chunk): void
	{
		$results = [];
		foreach ($chunk as $mail) {
			$results[] = [
				'id'              => $mail['id'],
				'label'           => 'direct',
				'action_required' => false,
				'priority'        => 3,
				'summary'         => 's',
				'reasoning'       => 'r',
			];
		}
		$provider->scriptResults($results);
	}

	public function testBatchSizeTwoSplitsThreeMailsIntoTwoCalls(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mails = $this->seedThreeUnscoredMails($tenantId, $mailboxId);
		$this->assertCount(3, $mails);

		$this->setScoringBatchSize(2);

		$provider = new ScriptedLlmProvider();
		// ceil(3/2) = 2 Chunks: erst 2 Mails, dann 1 Mail.
		$this->scriptScoreResults($provider, array_slice($mails, 0, 2));
		$this->scriptScoreResults($provider, array_slice($mails, 2, 1));

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertCount(3, $scores);
		$this->assertSame(2, $provider->callCount(), 'batch_size=2 → ceil(3/2)=2 Chunks/Calls');
	}

	public function testBatchSizeFiveScoresThreeMailsInOneCall(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mails = $this->seedThreeUnscoredMails($tenantId, $mailboxId);
		$this->assertCount(3, $mails);

		$this->setScoringBatchSize(5);

		$provider = new ScriptedLlmProvider();
		// batch_size=5 >= 3 → genau 1 Chunk mit allen 3 Mails.
		$this->scriptScoreResults($provider, $mails);

		$service = $this->makeService($provider, new FakeClaudeClient());
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertCount(3, $scores);
		$this->assertSame(1, $provider->callCount(), 'batch_size=5 ≥ 3 Mails → ein einziger Call');
	}
}
