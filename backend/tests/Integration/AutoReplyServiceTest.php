<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\DraftRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageCounterRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Services\AutoReplyService;
use MailPilot\Services\BudgetService;
use MailPilot\Services\RedactionService;
use MailPilot\Services\ReplyDraftService;
use MailPilot\Services\TokenService;
use MailPilot\Tests\Fixtures\FakeClaudeClient;
use MailPilot\Tests\Fixtures\FakeGraphClient;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * Sprint 6f — Pin-Tests für die DA-eingebauten Schutzschichten:
 *   - FYI-Pre-Filter (DA-R1 Finding 4): Bestätigungs-Subject skipt Opus
 *   - Sent-Match (DA-R1 Finding 1 + R2 Finding 1): User hat in Outlook
 *     bereits geantwortet → skip + Stale-Marker
 *   - Cold-Start-Schutz (DA-R1 Finding 2): enabled_at filtert Backlog
 *   - Master-Toggle: disabled liefert exit
 *
 * @group integration
 */
final class AutoReplyServiceTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	private function setSetting(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES (:k, :v, "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
			->execute([':k' => $key, ':v' => $value]);
	}

	private function seedScore(string $tenantId, string $mailId, int $priority = 5): void
	{
		$this->pdo()->prepare('INSERT INTO mail_scores
			(id, tenant_id, mail_id, label, priority, action_required, action_owner,
			 summary, reasoning, prompt_version, model)
			VALUES (:id, :t, :m, "action", :p, 1, "user", "s", "r", "P-SCORE@1.0", "haiku")')
			->execute([
				':id' => Uuid::v4(), ':t' => $tenantId, ':m' => $mailId, ':p' => $priority,
			]);
	}

	private function makeService(FakeGraphClient $graph, FakeClaudeClient $claude): AutoReplyService
	{
		$pdo      = $this->pdo();
		$settings = new SettingsRepository($pdo);
		$drafts   = new DraftRepository($pdo);
		$mailRepo = new MailRepository($pdo);
		$mboxRepo = new MailboxRepository($pdo);
		$tokens   = new TokenService($graph, $mboxRepo, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'fake', 'fake');
		$prompts  = new PromptRepository($pdo);
		$budget   = new BudgetService(
			$settings,
			new UsageRepository($pdo),
			new PricingRepository($pdo),
			new NullLogger(),
		);
		$reply = new ReplyDraftService(
			$claude, $mailRepo, $drafts, new RedactionService(),
			$budget, $prompts,
		);
		return new AutoReplyService(
			$pdo, $graph, $tokens, $settings,
			new UsageCounterRepository($pdo),
			$drafts, $mboxRepo, $reply,
			new NullLogger(),
		);
	}

	public function testDisabledByDefaultExitsEarly(): void
	{
		$res = $this->makeService(new FakeGraphClient(), new FakeClaudeClient())->tick();
		$this->assertSame(['disabled' => 1], $res['skipped']);
		$this->assertSame(0, $res['generated']);
	}

	public function testFyiSubjectIsSkipped(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId, [
			'subject'   => 'Bestätigung: Bestellung 12345',
			'body_text' => str_repeat('Lorem ipsum dolor sit amet. ', 30),
		]);
		$this->seedScore($tenantId, $mailId);
		$this->setSetting('autoreply_enabled', '1');
		$this->setSetting('autoreply_skip_subject_regex', '/best[äa]tigung|confirmation/i');

		$res = $this->makeService(new FakeGraphClient(), new FakeClaudeClient())->tick();
		$this->assertSame(0, $res['generated']);
		$this->assertSame(1, $res['skipped']['fyi_filter']);
	}

	public function testShortBodyIsSkipped(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId, [
			'subject'   => 'Kurz und knapp',
			'body_text' => 'OK danke',
		]);
		$this->seedScore($tenantId, $mailId);
		$this->setSetting('autoreply_enabled', '1');
		$this->setSetting('autoreply_skip_body_min_chars', '200');
		$this->setSetting('autoreply_skip_subject_regex', '');

		$res = $this->makeService(new FakeGraphClient(), new FakeClaudeClient())->tick();
		$this->assertSame(0, $res['generated']);
		$this->assertSame(1, $res['skipped']['fyi_filter']);
	}

	public function testSentMatchSkipsAndMarksStale(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser('marc@example.com');
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId, [
			'subject'   => 'Wichtige Frage',
			'body_text' => str_repeat('Hallo Marc, kannst du dazu Stellung nehmen? ', 10),
		]);
		// conversation_id manuell setzen (insertMail-Helper schreibt sie nicht)
		// und received_at vor "jetzt" — Sent-Match prüft Δt > 30 s.
		$convId = 'conv-' . $mailId;
		$this->pdo()->prepare('UPDATE mails
			SET conversation_id = :c, received_at = (UTC_TIMESTAMP(3) - INTERVAL 1 HOUR)
			WHERE id = :m')
			->execute([':c' => $convId, ':m' => $mailId]);
		$this->seedScore($tenantId, $mailId);
		$this->setSetting('autoreply_enabled', '1');
		$this->setSetting('autoreply_skip_subject_regex', '');
		$this->setSetting('autoreply_skip_body_min_chars', '0');

		// Vorab eine aktive Auto-Draft, die durch sent-match stale werden soll.
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO reply_drafts
			(id, tenant_id, mail_id, draft_text, prompt_version, model, created_by, conversation_id)
			VALUES (:id, :t, :m, "alter entwurf", "P-REPLY@1.0", "opus", "auto", :c)')
			->execute([':id' => Uuid::v4(), ':t' => $tenantId, ':m' => $mailId, ':c' => $convId]);

		$graph = new FakeGraphClient();
		$graph->scriptConversationLastMessage($convId, [
			'id'          => 'sent-1',
			'from_email'  => 'marc@example.com',
			'received_at' => gmdate('Y-m-d\TH:i:s\Z'),
			'sent_at'     => gmdate('Y-m-d\TH:i:s\Z'),
		]);

		// Mailbox-Helper hat keinen access_token_enc — TokenService würde failen.
		// Daher: wir patchen die mailbox-Row direkt mit einem encryptable Dummy.
		// (Alternativ: TokenService skippen mit MockMailbox — würde Test-Klasse
		// aufblähen. Workaround via Reflection-frei: setze access_token_enc
		// auf nicht-leer und access_token_expires_at in die Zukunft.)
		$pdo->prepare('UPDATE mailboxes SET access_token_enc = "dummy-token", access_token_expires_at = (UTC_TIMESTAMP() + INTERVAL 1 HOUR) WHERE id = :m')
			->execute([':m' => $mailboxId]);

		$res = $this->makeService($graph, new FakeClaudeClient())->tick();

		$this->assertSame(0, $res['generated']);
		$this->assertSame(1, $res['skipped']['sent_match']);

		$stale = (int)$pdo->query("SELECT COUNT(*) FROM reply_drafts WHERE stale_at IS NOT NULL")->fetchColumn();
		$this->assertSame(1, $stale, 'existierende Draft muss stale markiert sein');
	}

	public function testEnabledAtFiltersBacklog(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId, [
			'subject'     => 'Alte Frage',
			'body_text'   => str_repeat('lang genug ', 50),
			'received_at' => '2026-04-01 10:00:00.000',
		]);
		$this->seedScore($tenantId, $mailId);
		$this->setSetting('autoreply_enabled', '1');
		$this->setSetting('autoreply_enabled_at', '2026-05-01T00:00:00Z');
		$this->setSetting('autoreply_skip_subject_regex', '');

		$res = $this->makeService(new FakeGraphClient(), new FakeClaudeClient())->tick();
		$this->assertSame(0, $res['generated']);
		$this->assertSame(0, $res['candidates'], 'Mail vor enabled_at darf nicht als Kandidat erscheinen');
	}
}
