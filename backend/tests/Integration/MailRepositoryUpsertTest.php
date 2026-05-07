<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\MailRepository;
use MailPilot\Tests\TestCase;

/**
 * @group integration
 */
final class MailRepositoryUpsertTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testUpsertFromGraphCapsBodyTextAt1MB(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$bigBody = str_repeat('a', 2_000_000);
		$msg = [
			'id'            => 'graph-msg-1',
			'subject'       => 'Big body',
			'bodyPreview'   => 'preview',
			'body'          => ['contentType' => 'text', 'content' => $bigBody],
			'from'          => ['emailAddress' => ['address' => 'a@x.de', 'name' => 'A']],
			'toRecipients'  => [['emailAddress' => ['address' => 'marc@test.de']]],
			'receivedDateTime' => gmdate('c'),
		];

		$repo = new MailRepository($this->pdo());
		$id = $repo->upsertFromGraph($tenantId, $mailboxId, $msg);

		$row = $repo->findById($tenantId, $id);
		$this->assertNotNull($row);
		$this->assertSame(1_000_000, strlen((string)$row['body_text']));
	}

	public function testUpsertFromGraphStripsHtmlAndDecodesEntities(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$msg = [
			'id'            => 'graph-msg-2',
			'subject'       => 'HTML',
			'body'          => ['contentType' => 'html', 'content' => '<p>Hallo&nbsp;Welt &amp; Co</p>'],
			'from'          => ['emailAddress' => ['address' => 'a@x.de']],
			'toRecipients'  => [['emailAddress' => ['address' => 'marc@test.de']]],
			'receivedDateTime' => gmdate('c'),
		];

		$repo = new MailRepository($this->pdo());
		$id = $repo->upsertFromGraph($tenantId, $mailboxId, $msg);
		$row = $repo->findById($tenantId, $id);

		$this->assertStringNotContainsString('<p>', (string)$row['body_text']);
		$this->assertStringContainsString('Hallo', (string)$row['body_text']);
		$this->assertStringContainsString('& Co', (string)$row['body_text']);
	}

	public function testUpsertDetectsListUnsubscribeHeader(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$msg = [
			'id'            => 'graph-msg-3',
			'subject'       => 'Newsletter',
			'body'          => ['contentType' => 'text', 'content' => 'hi'],
			'from'          => ['emailAddress' => ['address' => 'news@x.de']],
			'toRecipients'  => [['emailAddress' => ['address' => 'marc@test.de']]],
			'receivedDateTime' => gmdate('c'),
			'internetMessageHeaders' => [
				['name' => 'List-Unsubscribe', 'value' => '<https://x.de/unsub>'],
			],
		];

		$repo = new MailRepository($this->pdo());
		$id = $repo->upsertFromGraph($tenantId, $mailboxId, $msg);
		$row = $repo->findById($tenantId, $id);

		$this->assertSame(1, (int)$row['list_unsubscribe']);
	}
}
