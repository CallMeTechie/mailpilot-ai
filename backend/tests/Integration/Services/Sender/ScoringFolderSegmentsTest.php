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
 * Phase 3b — pinnt: KI-gelieferte folder_segments + inbox_score landen in
 * mail_scores. Defaults (KI laesst Feld weg) → null/null.
 */
final class ScoringFolderSegmentsTest extends TestCase
{
	private function pslPath(): string
	{
		return dirname(__DIR__, 4) . '/var/psl/public_suffix_list.dat';
	}

	private function makeService(FakeClaudeClient $claude): MailScoringService
	{
		$pdo = $this->pdo();
		$repo = new SenderRepository($pdo);
		return new MailScoringService(
			$claude,
			new MailRepository($pdo),
			new ScoreRepository($pdo),
			new CacheRepository($pdo, 30),
			new RedactionService(),
			new BudgetService(
				new SettingsRepository($pdo),
				new UsageRepository($pdo),
				new PricingRepository($pdo),
				$this->logger(),
			),
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
			new SenderResolver($this->pslPath(), $repo, $this->logger()),
			new LookalikeDetector($repo, $this->logger()),
		);
	}

	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testFolderSegmentsAndInboxScoreArePersisted(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'noreply@amazon.de',
			'subject'    => 'Dein Einmalpasswort: 482931',
		]);

		$claude = new FakeClaudeClient();
		$claude->scriptJson([
			'results' => [[
				'id' => $mailId,
				'label' => 'action',
				'action_required' => true,
				'priority' => 5,
				'folder_segments' => ['Amazon', 'OTP'],
				'inbox_score' => 95,
				'summary' => 'Einmalpasswort',
				'reasoning' => 'OTP',
			]],
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$this->makeService($claude)->scoreBatch(
			$tenantId,
			['user_id' => $userId, 'email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []],
			$mails,
		);

		$row = $this->pdo()->query(
			"SELECT folder_segments, inbox_score FROM mail_scores WHERE mail_id = " . $this->pdo()->quote($mailId)
		)->fetch(\PDO::FETCH_ASSOC);

		$this->assertSame(95, (int)$row['inbox_score']);
		$this->assertSame(['Amazon', 'OTP'], json_decode((string)$row['folder_segments'], true));
	}

	public function testMissingFieldsBecomeNull(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'noreply@example.com']);

		$claude = new FakeClaudeClient();
		// Kein folder_segments, kein inbox_score in der Antwort
		$claude->scriptJson([
			'results' => [[
				'id' => $mailId,
				'label' => 'auto',
				'action_required' => false,
				'priority' => 2,
				'summary' => 'x',
				'reasoning' => 'x',
			]],
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$this->makeService($claude)->scoreBatch(
			$tenantId,
			['user_id' => $userId, 'email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []],
			$mails,
		);

		$row = $this->pdo()->query(
			"SELECT folder_segments, inbox_score FROM mail_scores WHERE mail_id = " . $this->pdo()->quote($mailId)
		)->fetch(\PDO::FETCH_ASSOC);

		$this->assertNull($row['folder_segments']);
		$this->assertNull($row['inbox_score']);
	}

	public function testOversizedSegmentsAreCapped(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'a@github.com']);

		$claude = new FakeClaudeClient();
		// 5 Segments — Sanitizer muss auf 3 cappen. Eins ist leer (rausfiltern).
		$claude->scriptJson([
			'results' => [[
				'id' => $mailId,
				'label' => 'auto',
				'action_required' => false,
				'priority' => 2,
				'folder_segments' => ['GitHub', '', 'GateControl', 'Security', 'TooMany'],
				'inbox_score' => 200, // ueber Cap → muss auf 100 geklemmt werden
				'summary' => 'x',
				'reasoning' => 'x',
			]],
		]);

		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$this->makeService($claude)->scoreBatch(
			$tenantId,
			['user_id' => $userId, 'email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []],
			$mails,
		);

		$row = $this->pdo()->query(
			"SELECT folder_segments, inbox_score FROM mail_scores WHERE mail_id = " . $this->pdo()->quote($mailId)
		)->fetch(\PDO::FETCH_ASSOC);

		$segments = json_decode((string)$row['folder_segments'], true);
		$this->assertSame(['GitHub', 'GateControl', 'Security'], $segments, 'Leerer Eintrag raus + max 3');
		$this->assertSame(100, (int)$row['inbox_score'], 'inbox_score muss auf 100 geklemmt sein');
	}
}
