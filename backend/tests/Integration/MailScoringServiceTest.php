<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\ScoreRepository;
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
		return new MailScoringService(
			$claude,
			new MailRepository($pdo),
			new ScoreRepository($pdo),
			new CacheRepository($pdo, 30),
			new RedactionService(),
			'claude-haiku-4-5-20251001',
			20,
			2048,
			$this->logger(),
		);
	}

	public function testNewsletterIsPreFilteredWithoutCallingClaude(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId, [
			'list_unsubscribe' => 1,
			'from_email'       => 'marketing@newsletter.com',
			'subject'          => 'Sommer-Sale 50% Rabatt',
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$claude = new FakeClaudeClient();
		$service = $this->makeService($claude);

		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertCount(1, $scores);
		$this->assertSame('newsletter', $scores[0]['label']);
		$this->assertSame(0, $claude->callCount(), 'Newsletter must not hit Claude');
	}

	public function testVipSenderBypassesNewsletterPreFilter(): void
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

		// Second batch: another mail with identical content — must hit cache,
		// must NOT trigger a new Claude call.
		$this->insertMail($tenantId, $mailboxId, ['from_email' => 'x@a.de', 'subject' => 'Same', 'body_text' => 'Same body']);
		$second = $repo->findUnscoredForMailbox($tenantId, $mailboxId);
		$this->assertCount(1, $second, 'Only the new unscored mail should remain');

		$scores = $service->scoreBatch($tenantId, $profile, $second);
		$this->assertSame(1, $claude->callCount(), 'Second mail must hit cache, not Claude');
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
}
