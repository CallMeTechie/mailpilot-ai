<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\ScoreRepository;
use MailPilot\Tests\TestCase;

/**
 * @group integration
 */
final class ScoreRepositoryTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testCountByLabelSince(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);

		$repo = new ScoreRepository($this->pdo());

		$scores = [];
		foreach (['direct','direct','action','cc','newsletter','newsletter','newsletter'] as $label) {
			$mailId = $this->insertMail($tenantId, $mailboxId);
			$scores[] = [
				'id' => $this->uuid(), 'tenant_id' => $tenantId, 'mail_id' => $mailId,
				'label' => $label, 'action_required' => 0, 'priority' => 3,
				'summary' => 'x', 'reasoning' => 'r',
				'prompt_version' => 'P-SCORE@1.0', 'model' => 'haiku', 'cached' => 0,
			];
		}
		$repo->upsertMany($scores);

		$since = gmdate('Y-m-d 00:00:00.000');
		$counts = $repo->countByLabelSince($tenantId, $mailboxId, $since);

		$this->assertSame(2, $counts['direct']);
		$this->assertSame(1, $counts['action']);
		$this->assertSame(1, $counts['cc']);
		$this->assertSame(3, $counts['newsletter']);
		$this->assertSame(0, $counts['auto']);
	}

	public function testTopPriorityOrdersByPriorityDesc(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$repo = new ScoreRepository($this->pdo());

		$priorities = [2, 5, 3, 4, 1];
		$scores = [];
		foreach ($priorities as $p) {
			$mailId = $this->insertMail($tenantId, $mailboxId);
			$scores[] = [
				'id' => $this->uuid(), 'tenant_id' => $tenantId, 'mail_id' => $mailId,
				'label' => 'direct', 'action_required' => 0, 'priority' => $p,
				'summary' => "p={$p}", 'reasoning' => 'r',
				'prompt_version' => 'P-SCORE@1.0', 'model' => 'haiku', 'cached' => 0,
			];
		}
		$repo->upsertMany($scores);

		$top = $repo->topPrioritySince($tenantId, $mailboxId, gmdate('Y-m-d 00:00:00.000'), 10);
		$got = array_map(fn($r) => (int)$r['priority'], $top);
		$this->assertSame([5, 4, 3, 2, 1], $got);
	}

	public function testTopPriorityExcludesNoiseAndNewsletter(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$repo = new ScoreRepository($this->pdo());

		$scores = [];
		foreach (['direct','action','cc','newsletter','noise','auto'] as $label) {
			$mailId = $this->insertMail($tenantId, $mailboxId);
			$scores[] = [
				'id' => $this->uuid(), 'tenant_id' => $tenantId, 'mail_id' => $mailId,
				'label' => $label, 'action_required' => 0, 'priority' => 3,
				'summary' => $label, 'reasoning' => 'r',
				'prompt_version' => 'P-SCORE@1.0', 'model' => 'haiku', 'cached' => 0,
			];
		}
		$repo->upsertMany($scores);

		$top = $repo->topPrioritySince($tenantId, $mailboxId, gmdate('Y-m-d 00:00:00.000'));
		$labels = array_column($top, 'label');

		$this->assertContains('direct', $labels);
		$this->assertContains('action', $labels);
		$this->assertNotContains('cc', $labels, 'CC excluded from top priority');
		$this->assertNotContains('newsletter', $labels);
		$this->assertNotContains('noise', $labels);
	}

	public function testUpsertIsIdempotent(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId = $this->insertMail($tenantId, $mailboxId);
		$repo = new ScoreRepository($this->pdo());

		$row = [
			'id' => $this->uuid(), 'tenant_id' => $tenantId, 'mail_id' => $mailId,
			'label' => 'direct', 'action_required' => 0, 'priority' => 3,
			'summary' => 'first', 'reasoning' => 'r',
			'prompt_version' => 'P-SCORE@1.0', 'model' => 'haiku', 'cached' => 0,
		];
		$repo->upsertMany([$row]);
		$row['summary'] = 'updated';
		$row['priority'] = 5;
		$repo->upsertMany([$row]);

		$stmt = $this->pdo()->prepare('SELECT summary, priority FROM mail_scores WHERE mail_id = :m');
		$stmt->execute([':m' => $mailId]);
		$result = $stmt->fetch();
		$this->assertSame('updated', $result['summary']);
		$this->assertSame(5, (int)$result['priority']);
	}
}
