<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Services\ScoreOverrideService;
use MailPilot\Tests\TestCase;

/**
 * Phase 9a — pinnt Marcs CI-Run-Use-Case:
 *   match_sender_key=github, match_subject_regex=/build.*fail/i,
 *   match_label=action, match_priority_min=3
 *   set_priority=2, set_action_required=0
 * → kommt eine GitHub-Mail „Build #42 failed" mit KI-Score action/4/true:
 *   wird zu action/2/false.
 */
final class ScoreOverrideServiceTest extends TestCase
{
	private function makeService(): ScoreOverrideService
	{
		return new ScoreOverrideService(
			new ScoreOverrideRepository($this->pdo()),
			$this->logger(),
		);
	}

	protected function setUp(): void
	{
		$this->truncateAll();
		$this->pdo()->exec('TRUNCATE TABLE score_override_rules');
	}

	public function testMarcCiRunCaseFromBugReport(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());
		$repo->create($tenantId, $userId, [
			'match_sender_key'    => 'github',
			'match_subject_regex' => '/build.*fail/i',
			'match_label'         => 'action',
			'match_priority_min'  => 3,
			'set_priority'        => 2,
			'set_action_required' => false,
		]);

		$mail = [
			'id' => 'm1',
			'subject' => '[mailpilot-ai] Build #42 failed on main',
			'from_email' => 'notifications@github.com',
		];
		$score = ['label' => 'action', 'priority' => 4, 'action_required' => true];
		$bucket = ['sender_key' => 'github'];

		$result = $this->makeService()->apply($tenantId, $userId, $mail, $score, $bucket);

		$this->assertTrue($result['matched']);
		$this->assertSame(2, $score['priority']);
		$this->assertSame(0, $score['action_required']);
		$this->assertSame('action', $score['label'], 'set_label war null → label bleibt');
	}

	public function testNoMatchWhenSenderDiffers(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());
		$repo->create($tenantId, $userId, [
			'match_sender_key' => 'github',
			'set_priority'     => 2,
		]);

		$score = ['label' => 'action', 'priority' => 4, 'action_required' => true];
		$result = $this->makeService()->apply($tenantId, $userId,
			['id'=>'m','subject'=>'x','from_email'=>'foo@gitlab.com'],
			$score,
			['sender_key' => 'gitlab'],
		);
		$this->assertFalse($result['matched']);
		$this->assertSame(4, $score['priority'], 'unveraendert');
	}

	public function testPriorityMinGate(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());
		$repo->create($tenantId, $userId, [
			'match_sender_key'   => 'github',
			'match_priority_min' => 3,
			'set_priority'       => 2,
		]);

		// Score 2 < min 3 → kein Match (Regel soll nur Hochstufungen daempfen)
		$score = ['label' => 'auto', 'priority' => 2, 'action_required' => false];
		$result = $this->makeService()->apply($tenantId, $userId,
			['id'=>'m','subject'=>'x','from_email'=>'x@github.com'],
			$score,
			['sender_key' => 'github'],
		);
		$this->assertFalse($result['matched']);
	}

	public function testFirstRuleWins(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());
		// Erst Regel A (priority=2), dann Regel B (priority=5) — A muss gewinnen.
		$repo->create($tenantId, $userId, ['match_sender_key' => 'github', 'set_priority' => 2]);
		// Mini-Pause damit created_at strikt unterschiedlich ist (DATETIME(3) sollte reichen)
		usleep(2000);
		$repo->create($tenantId, $userId, ['match_sender_key' => 'github', 'set_priority' => 5]);

		$score = ['label' => 'action', 'priority' => 4, 'action_required' => true];
		$result = $this->makeService()->apply($tenantId, $userId,
			['id'=>'m','subject'=>'x','from_email'=>'x@github.com'],
			$score,
			['sender_key' => 'github'],
		);
		$this->assertTrue($result['matched']);
		$this->assertSame(2, $score['priority'], 'Erste Regel (created_at ASC) gewinnt');
	}

	public function testBrokenRegexDoesNotCrash(): void
	{
		// Repository validiert beim Insert — wir testen den Apply-Pfad fuer
		// Regeln die durchgerutscht sind (z.B. manuell editiert per SQL).
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$this->pdo()->prepare('INSERT INTO score_override_rules
			(id, tenant_id, user_id, match_subject_regex, set_priority)
			VALUES (UUID(), :t, :u, :p, 2)')
			->execute([':t' => $tenantId, ':u' => $userId, ':p' => '/(invalid[/']);

		$score = ['label' => 'action', 'priority' => 4, 'action_required' => true];
		$result = $this->makeService()->apply($tenantId, $userId,
			['id'=>'m','subject'=>'x','from_email'=>'x@github.com'],
			$score,
			null,
		);
		// Ungueltige Regex → preg_match returnt false → Match scheitert → no-op
		$this->assertFalse($result['matched']);
		$this->assertSame(4, $score['priority']);
	}

	public function testRejectsRuleWithoutMatchField(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());
		$this->expectException(\InvalidArgumentException::class);
		$repo->create($tenantId, $userId, ['set_priority' => 2]);
	}

	public function testRecordApplyIncrementsCounter(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());
		$id = $repo->create($tenantId, $userId, ['match_sender_key' => 'github', 'set_priority' => 2]);

		$score = ['label' => 'action', 'priority' => 4, 'action_required' => true];
		$this->makeService()->apply($tenantId, $userId,
			['id'=>'m','subject'=>'x','from_email'=>'x@github.com'],
			$score,
			['sender_key' => 'github'],
		);
		$row = $this->pdo()->prepare('SELECT applies_count, last_applied_at FROM score_override_rules WHERE id=:id');
		$row->execute([':id' => $id]);
		$r = $row->fetch(\PDO::FETCH_ASSOC);
		$this->assertSame(1, (int)$r['applies_count']);
		$this->assertNotNull($r['last_applied_at']);
	}
}
