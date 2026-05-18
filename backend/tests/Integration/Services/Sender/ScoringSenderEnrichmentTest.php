<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services\Sender;

use MailPilot\Repositories\AutoSortCorrectionRepository;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\SenderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Services\BudgetService;
use MailPilot\Services\MailScoringService;
use MailPilot\Services\RedactionService;
use MailPilot\Services\Sender\LookalikeDetector;
use MailPilot\Services\Sender\SenderResolver;
use MailPilot\Tests\Fixtures\FakeClaudeClient;
use MailPilot\Tests\TestCase;

/**
 * Phase 3a — pinnt das neue Verhalten:
 *   nach jedem scoreBatch werden Sender registriert + Spoofs geflaggt.
 */
final class ScoringSenderEnrichmentTest extends TestCase
{
	private function pslPath(): string
	{
		return dirname(__DIR__, 4) . '/var/psl/public_suffix_list.dat';
	}

	private function makeService(FakeClaudeClient $claude, SenderResolver $resolver, LookalikeDetector $detector): MailScoringService
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
			new PendingActionRepository($pdo),
			new AutoSortCorrectionRepository($pdo),
			$resolver,
			$detector,
		);
	}

	private function scriptOneClaudeResult(FakeClaudeClient $claude, string $mailId, string $label = 'auto'): void
	{
		$claude->scriptJson([
			'results' => [[
				'id'              => $mailId,
				'label'           => $label,
				'action_required' => false,
				'priority'        => 2,
				'summary'         => 'fixture',
				'reasoning'       => 'fixture',
			]],
		]);
	}

	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testNewSenderGetsRegistered(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'bestellung@amazon.de']);

		$repo     = new SenderRepository($this->pdo());
		$resolver = new SenderResolver($this->pslPath(), $repo, $this->logger());
		$detector = new LookalikeDetector($repo, $this->logger());

		$claude = new FakeClaudeClient();
		$this->scriptOneClaudeResult($claude, $mailId);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$this->makeService($claude, $resolver, $detector)->scoreBatch(
			$tenantId,
			['user_id' => $userId, 'email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []],
			$mails,
		);

		$senders = $repo->listForTenant($tenantId);
		$this->assertCount(1, $senders, 'Nach scoreBatch muss EIN Sender-Eintrag fuer amazon entstanden sein');
		$this->assertSame('amazon', $senders[0]['sender_key']);
		$this->assertContains('amazon.de', $senders[0]['registrable_domains']);

		$score = $this->pdo()->query("SELECT spoof_suspect FROM mail_scores WHERE mail_id = " . $this->pdo()->quote($mailId))->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(0, (int)$score['spoof_suspect'], 'Unbekannter Sender ohne Lookalike → spoof_suspect=0');
	}

	public function testSpoofMailFlipsSpoofSuspect(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		// Erst Amazon trusted seeden — der echte Sender muss bekannt sein,
		// damit der LookalikeDetector ueberhaupt vergleichen kann.
		$repo = new SenderRepository($this->pdo());
		$repo->create($tenantId, 'amazon', ['amazon.de'], 'Amazon', 'Amazon', 'trusted');

		$mailId = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'no-reply@amazon-email.com']);

		$resolver = new SenderResolver($this->pslPath(), $repo, $this->logger());
		$detector = new LookalikeDetector($repo, $this->logger());

		$claude = new FakeClaudeClient();
		$this->scriptOneClaudeResult($claude, $mailId, 'newsletter');

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$this->makeService($claude, $resolver, $detector)->scoreBatch(
			$tenantId,
			['user_id' => $userId, 'email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []],
			$mails,
		);

		$lookalike = $repo->findByKey($tenantId, 'amazon-email');
		$this->assertNotNull($lookalike, 'amazon-email.com muss als neuer Bucket angelegt sein');
		$this->assertSame('suspected_spoof', $lookalike['trust_status'], 'LookalikeDetector muss trust_status flippen');
		$this->assertNotNull($lookalike['spoof_of_sender_id']);

		$score = $this->pdo()->query("SELECT spoof_suspect FROM mail_scores WHERE mail_id = " . $this->pdo()->quote($mailId))->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(1, (int)$score['spoof_suspect'], 'mail_scores.spoof_suspect muss 1 sein nach Spoof-Treffer');
	}

	public function testTwoMailsFromSameSenderProduceOneBucket(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$id1 = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'a@ebay.de']);
		$id2 = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'b@info.ebay.de']);

		$repo     = new SenderRepository($this->pdo());
		$resolver = new SenderResolver($this->pslPath(), $repo, $this->logger());
		$detector = new LookalikeDetector($repo, $this->logger());

		$claude = new FakeClaudeClient();
		$claude->scriptJson([
			'results' => [
				['id' => $id1, 'label' => 'auto', 'action_required' => false, 'priority' => 2, 'summary' => 'x', 'reasoning' => 'x'],
				['id' => $id2, 'label' => 'auto', 'action_required' => false, 'priority' => 2, 'summary' => 'x', 'reasoning' => 'x'],
			],
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$this->makeService($claude, $resolver, $detector)->scoreBatch(
			$tenantId,
			['user_id' => $userId, 'email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []],
			$mails,
		);

		$senders = $repo->listForTenant($tenantId);
		$ebay = array_values(array_filter($senders, fn(array $s): bool => $s['sender_key'] === 'ebay'));
		$this->assertCount(1, $ebay, 'Zwei Mails vom selben PSL-Stem → EIN Bucket');
		$this->assertContains('ebay.de', $ebay[0]['registrable_domains']);
	}
}
