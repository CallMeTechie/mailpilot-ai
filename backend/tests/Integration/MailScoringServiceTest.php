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

/**
 * @group integration
 */
final class MailScoringServiceTest extends TestCase
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
			new AutoSortRepository($pdo),
			new PromptRepository($pdo),
			new SettingsRepository($pdo),
			20,
			2048,
			$this->logger(),
		);
	}

	/**
	 * The List-Unsubscribe pre-filter used to short-circuit every mail
	 * with that header to a hard-coded "newsletter" preset without ever
	 * calling Claude. That heuristic was removed (List-Unsubscribe is
	 * mandatory for nearly every transactional sender under DSGVO, so
	 * it consistently mislabelled important mail). This test pins the
	 * new behaviour: such mails do reach Claude and can be classified
	 * to any label Claude returns.
	 */
	public function testListUnsubscribeMailReachesClaude(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, [
			'list_unsubscribe' => 1,
			'from_email'       => 'lawyer@example.com',
			'subject'          => 'Wichtige Mandatsinformation',
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$claude = new FakeClaudeClient();
		$claude->scriptJson([
			'results' => [[
				'id' => $mails[0]['id'],
				'label' => 'direct',
				'action_required' => true,
				'priority' => 5,
				'summary' => 'Anwalt schickt Mandatsinformation',
				'reasoning' => 'transactional sender',
			]],
		]);
		$service = $this->makeService($claude);

		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertCount(1, $scores);
		$this->assertSame(1, $claude->callCount(), 'List-Unsubscribe must NOT bypass Claude any more');
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
		$claude = new FakeClaudeClient();
		$claude->scriptJson([
			'results' => [[
				'id' => $mails[0]['id'],
				'label' => 'direct',
				'action_required' => false,
				'priority' => 4,
				'summary' => 'Chef schickt wichtige Kampagneninfo',
				'reasoning' => 'vip sender',
			]],
		]);

		$service = $this->makeService($claude);
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => ['boss@example.com'], 'project_keywords' => [],
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertSame(1, $claude->callCount());
		$this->assertSame('direct', $scores[0]['label']);
	}

	public function testClaudeResultsAreCachedByContentHash(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$this->insertMail($tenantId, $mailboxId, ['from_email' => 'x@a.de', 'subject' => 'Same', 'body_text' => 'Same body']);
		$repo = new MailRepository($this->pdo());
		$first = $repo->findUnscoredForMailbox($tenantId, $mailboxId);

		$claude = new FakeClaudeClient();
		$claude->scriptJson([
			'results' => [[
				'id' => $first[0]['id'],
				'label' => 'direct',
				'action_required' => false,
				'priority' => 3,
				'summary' => 'Test',
				'reasoning' => 'r',
			]],
		]);
		$service = $this->makeService($claude);
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$service->scoreBatch($tenantId, $profile, $first);
		$this->assertSame(1, $claude->callCount());

		// Second batch: another mail with identical content — must hit cache
		// for the SCORE, but Sprint 6a fires a separate Mini-Call for
		// action_owner (post-cache). Score-Klassifizierung kommt aus dem
		// Cache (cached=1), und exakt EIN zusätzlicher Mini-Call läuft.
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
		$this->assertSame(2, $claude->callCount(),
			'Score kommt aus dem Cache; Mini-Call für action_owner zählt als zweiter Call (Sprint 6a)');
		$this->assertCount(1, $scores);
		$this->assertSame(1, (int)$scores[0]['cached']);
	}

	public function testInvalidLabelIsCoercedToAuto(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$claude = new FakeClaudeClient();
		$claude->scriptJson([
			'results' => [[
				'id' => $mails[0]['id'],
				'label' => 'INVENTED_LABEL',
				'action_required' => true,
				'priority' => 99,  // out of range, must clamp
				'summary' => str_repeat('x', 300),  // oversized, must truncate
				'reasoning' => 'r',
			]],
		]);

		$service = $this->makeService($claude);
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
		$claude = new FakeClaudeClient();
		$claude->scriptJson(['results' => [[
			'id' => $mails[0]['id'], 'label' => 'direct',
			'action_required' => false, 'priority' => 3,
			'summary' => 's', 'reasoning' => 'r',
		]]]);

		$service = $this->makeService($claude);
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$service->scoreBatch($tenantId, $profile, $mails);

		$payload = $claude->lastCall();
		$sent = json_encode($payload, JSON_UNESCAPED_UNICODE);
		$this->assertStringNotContainsString('DE89', $sent, 'IBAN must not reach Claude');
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
		$claude = new FakeClaudeClient();
		$claude->scriptJson(['results' => [[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => 'GitHub CI',
			'action_required' => false,
			'priority' => 2,
			'summary' => 'CI passed',
			'reasoning' => 'github notification',
		]]]);

		$service = $this->makeService($claude);
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => [], 'project_keywords' => [],
			'tenant_id' => $tenantId, 'user_id' => $userId,
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertSame('auto', $scores[0]['label']);
		$this->assertSame('GitHub CI', $scores[0]['sub_label']);

		// Prompt actually contained the USER_SUBLABELS block
		$prompt = (string)$claude->lastCall()['messages'][0]['content'];
		$this->assertStringContainsString('USER_SUBLABELS', $prompt);
		$this->assertStringContainsString('GitHub CI', $prompt);
	}

	public function testHallucinatedSubLabelCollapsesToNull(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId);

		(new SubLabelRepository($this->pdo()))->create($tenantId, $userId, 'auto', 'GitHub CI', null, null);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$claude = new FakeClaudeClient();
		$claude->scriptJson(['results' => [[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => 'Made Up Bucket',   // not in user's pool
			'action_required' => false,
			'priority' => 2,
			'summary' => 's',
			'reasoning' => 'r',
		]]]);

		$service = $this->makeService($claude);
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
		$claude = new FakeClaudeClient();
		$claude->scriptJson(['results' => [[
			'id' => $mails[0]['id'],
			'label' => 'direct',
			'sub_label' => 'GitHub CI',   // valid name, wrong parent
			'action_required' => true,
			'priority' => 4,
			'summary' => 's',
			'reasoning' => 'r',
		]]]);

		$service = $this->makeService($claude);
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
		$claude = new FakeClaudeClient();
		$claude->scriptJson(['results' => [[
			'id' => $mails[0]['id'],
			'label' => 'direct',
			'sub_label' => 'anything',
			'action_required' => false,
			'priority' => 3,
			'summary' => 's',
			'reasoning' => 'r',
		]]]);

		$service = $this->makeService($claude);
		$profile = [
			'email' => 'marc@test.de', 'language' => 'de',
			'vip_senders' => [], 'project_keywords' => [],
			'tenant_id' => $tenantId, 'user_id' => $userId,
		];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertNull($scores[0]['sub_label']);
		$prompt = (string)$claude->lastCall()['messages'][0]['content'];
		// Pool-Header (mit Bucket-Liste) darf NICHT im Prompt sein — kein User-Pool.
		// Das Wort USER_SUBLABELS taucht aber in der TOPIC_DISCOVERY-Anweisung
		// als Referenz auf — das ist gewollt.
		$this->assertStringNotContainsString('USER_SUBLABELS (existing buckets', $prompt,
			'Empty pool ⇒ no existing-bucket header');
		$this->assertStringContainsString('TOPIC_DISCOVERY', $prompt,
			'Discovery block must always be present (Phase 6b)');
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
		$claude = new FakeClaudeClient();
		$claude->scriptJson(['results' => [[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => 'Stripe Payments',
			'sub_label_is_new' => true,
			'action_required' => false,
			'priority' => 2,
			'summary' => 'Zahlung erhalten',
			'reasoning' => 'stripe notification',
		]]]);

		$service = $this->makeService($claude);
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

		// auto_sort_rules: passende Sub-Rule angelegt — Sprint 6b: disabled
		// + created_by='ki' (User muss in den Settings erst aktivieren).
		// Vorher (Mini-6b) war enabled=true; PRD §3.1 fordert „kein silent
		// retroactive move", also disabled bis Approve.
		$rule = (new AutoSortRepository($this->pdo()))
			->findRule($tenantId, $userId, 'auto', 'Stripe Payments');
		$this->assertNotNull($rule);
		$this->assertFalse($rule['enabled'], 'KI-Discovery erzeugt disabled Rule (Sprint 6b)');
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
		$claude = new FakeClaudeClient();
		$claude->scriptJson(['results' => [[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => 'Github CI',   // Tippfehler: kleines 'h', Distanz 1
			'sub_label_is_new' => true,
			'action_required' => false,
			'priority' => 2,
			'summary' => 's',
			'reasoning' => 'r',
		]]]);

		$service = $this->makeService($claude);
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
		$claude = new FakeClaudeClient();
		$claude->scriptJson(['results' => [[
			'id' => $mails[0]['id'],
			'label' => 'auto',
			'sub_label' => str_repeat('A', 50),   // > 30 chars → rejected
			'sub_label_is_new' => true,
			'action_required' => false,
			'priority' => 2,
			'summary' => 's',
			'reasoning' => 'r',
		]]]);

		$service = $this->makeService($claude);
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
}
