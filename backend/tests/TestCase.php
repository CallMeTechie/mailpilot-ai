<?php
declare(strict_types=1);

namespace MailPilot\Tests;

use MailPilot\Util\Uuid;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Log\NullLogger;

abstract class TestCase extends BaseTestCase
{
	protected static ?PDO $sharedPdo = null;

	protected function logger(): NullLogger
	{
		return new NullLogger();
	}

	protected function uuid(): string
	{
		return Uuid::v4();
	}

	/**
	 * Shared test DB connection; schema is set up once per test run.
	 */
	protected function pdo(): PDO
	{
		if (self::$sharedPdo !== null) {
			return self::$sharedPdo;
		}
		$dsn = sprintf(
			'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
			getenv('DB_HOST'),
			getenv('DB_PORT'),
			getenv('DB_NAME'),
		);
		self::$sharedPdo = new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS') ?: '', [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		]);
		return self::$sharedPdo;
	}

	/**
	 * Wipes all tables (FKs off). Call in setUp() for integration tests.
	 */
	protected function truncateAll(): void
	{
		$pdo = $this->pdo();
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
		$tables = ['tenants','users','tenant_user','mailboxes','mails','mail_scores',
			'mail_summaries','reply_drafts','claude_cache','vip_senders',
			'redaction_rules','project_keywords','prompt_versions','audit_log',
			'sync_jobs','oauth_states',
			// 0005_token_budgets_and_usage — keep system_settings and
			// model_pricing untouched (they hold seeded defaults the
			// BudgetService relies on); only the per-call ledgers reset.
			'api_usage','usage_daily',
			// 0006_auto_sort_rules — per-user, no seeded defaults; safe to truncate
			'auto_sort_rules'];
		foreach ($tables as $t) {
			try { $pdo->exec("TRUNCATE TABLE {$t}"); } catch (\Throwable) {}
		}
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	}

	protected function insertTenantAndUser(string $email = 'marc@test.de'): array
	{
		$tenantId = $this->uuid();
		$userId   = $this->uuid();
		$pdo = $this->pdo();
		$pdo->prepare('INSERT INTO tenants (id, name, plan) VALUES (:id, :n, "free")')
			->execute([':id' => $tenantId, ':n' => 'Test']);
		$pdo->prepare('INSERT INTO users (id, email, display_name) VALUES (:id, :e, :n)')
			->execute([':id' => $userId, ':e' => $email, ':n' => 'Tester']);
		$pdo->prepare('INSERT INTO tenant_user (tenant_id, user_id, role) VALUES (:t, :u, "owner")')
			->execute([':t' => $tenantId, ':u' => $userId]);
		return [$tenantId, $userId];
	}

	protected function insertMailbox(string $tenantId, string $userId): string
	{
		$id = $this->uuid();
		$this->pdo()->prepare('INSERT INTO mailboxes
			(id, tenant_id, user_id, email, refresh_token_enc, scopes)
			VALUES (:id, :t, :u, :e, :rt, :s)')
			->execute([
				':id' => $id, ':t' => $tenantId, ':u' => $userId,
				':e' => 'marc@test.de', ':rt' => 'dummy',
				':s' => 'Mail.Read',
			]);
		return $id;
	}

	protected function insertMail(string $tenantId, string $mailboxId, array $overrides = []): string
	{
		$id = $this->uuid();
		$defaults = [
			'ms_message_id'   => 'msg_' . $id,
			'from_email'      => 'alice@example.com',
			'from_name'       => 'Alice',
			'to_json'         => '["marc@test.de"]',
			'cc_json'         => '[]',
			'subject'         => 'Test Subject',
			'body_text'       => 'Hallo Marc, kurze Frage.',
			'has_attachment'  => 0,
			'is_reply'        => 0,
			'list_unsubscribe'=> 0,
			'received_at'     => gmdate('Y-m-d H:i:s.000'),
		];
		$row = array_merge($defaults, $overrides);

		$this->pdo()->prepare('INSERT INTO mails
			(id, tenant_id, mailbox_id, ms_message_id, from_email, from_name,
			 to_json, cc_json, subject, body_text, has_attachment, is_reply,
			 list_unsubscribe, received_at)
			VALUES (:id, :t, :mb, :mid, :fe, :fn, :toj, :ccj, :sub, :body,
					:att, :rep, :lu, :rcv)')
			->execute([
				':id'  => $id, ':t' => $tenantId, ':mb' => $mailboxId,
				':mid' => $row['ms_message_id'], ':fe' => $row['from_email'],
				':fn'  => $row['from_name'], ':toj' => $row['to_json'],
				':ccj' => $row['cc_json'], ':sub' => $row['subject'],
				':body' => $row['body_text'], ':att' => $row['has_attachment'],
				':rep' => $row['is_reply'], ':lu' => $row['list_unsubscribe'],
				':rcv' => $row['received_at'],
			]);
		return $id;
	}
}
