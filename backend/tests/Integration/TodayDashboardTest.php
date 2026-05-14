<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\AutoSortCorrectionRepository;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

/**
 * Sprint 6e — Heute-Dashboard + Few-Shot-Stabilität + Per-Feld-Sticky.
 *
 * Pinnt DA-Pre-Impl-Findings:
 *   #2: forFewShotPrompt liefert deterministische Reihenfolge (älteste
 *       first), neue Korrekturen hängen hinten an → Top-Block stabil.
 *   #3: user_corrected_fields ist Per-Feld. ScoreRepository.upsertMany
 *       überschreibt label/priority weiter, action_owner bleibt sticky.
 *
 * @group integration
 */
final class TodayDashboardTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	private function seedScore(string $tenantId, string $mailId, array $overrides = []): void
	{
		$row = array_merge([
			'id' => Uuid::v4(), 'tenant_id' => $tenantId, 'mail_id' => $mailId,
			'label' => 'auto', 'sub_label' => null, 'action_required' => 0,
			'action_owner' => 'unsure', 'action_owner_confidence' => null,
			'action_owner_source' => null,
			'priority' => 2, 'summary' => 's', 'reasoning' => 'r',
			'prompt_version' => 'P-SCORE@1.3', 'model' => 'haiku', 'cached' => 0,
		], $overrides);
		(new ScoreRepository($this->pdo()))->upsertMany([$row]);
	}

	public function testPerFieldStickyKeepsActionOwnerButRefreshesLabel(): void
	{
		[$tenantId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, Uuid::v4());
		$mailId = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'kunde@x.de']);

		// Initial KI-Score
		$this->seedScore($tenantId, $mailId, [
			'label' => 'action', 'priority' => 4,
			'action_owner' => 'user', 'action_owner_source' => 'ki',
		]);

		// User korrigiert action_owner via TodayController-Pfad
		$this->pdo()->prepare('UPDATE mail_scores
			SET action_owner = "other",
			    action_owner_source = "user_corrected",
			    user_corrected_at = UTC_TIMESTAMP(3),
			    user_corrected_fields = "action_owner"
			WHERE mail_id = :m')->execute([':m' => $mailId]);

		// KI re-scored mit neuem label + priority + action_owner='user'.
		// Per-Feld-Sticky: nur action_owner soll erhalten bleiben.
		$this->seedScore($tenantId, $mailId, [
			'label' => 'cc', 'priority' => 2,
			'action_owner' => 'user', 'action_owner_source' => 'ki',
		]);

		$row = $this->pdo()->query("SELECT label, priority, action_owner, action_owner_source
			FROM mail_scores WHERE mail_id = " . $this->pdo()->quote($mailId))->fetch();
		$this->assertSame('cc',             $row['label'],               'label darf weiter von KI überschrieben werden');
		$this->assertSame(2,                (int)$row['priority'],       'priority darf weiter von KI überschrieben werden');
		$this->assertSame('other',          $row['action_owner'],        'action_owner ist sticky nach User-Korrektur');
		$this->assertSame('user_corrected', $row['action_owner_source'], 'Source bleibt user_corrected');
	}

	public function testFewShotPromptOrderStaysStableWhenNewCorrectionAdded(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$repo = new CorrectionRepository($this->pdo());

		// Drei Korrekturen mit unterschiedlichen created_at (per Backdate)
		for ($i = 1; $i <= 3; $i++) {
			$mailId = $this->insertMail($tenantId, $mailboxId, ['from_email' => "k{$i}@x.de"]);
			$cid = Uuid::v4();
			$this->pdo()->prepare('INSERT INTO mail_score_corrections
				(id, tenant_id, user_id, mail_id, corrected_label, corrected_priority, corrected_action, created_at)
				VALUES (:id, :t, :u, :m, "action", 4, 1, UTC_TIMESTAMP(3) - INTERVAL :h HOUR)')
				->execute([':id' => $cid, ':t' => $tenantId, ':u' => $userId, ':m' => $mailId, ':h' => 10 - $i]);
		}

		$first = $repo->forFewShotPrompt($tenantId, $userId, 10);
		$this->assertCount(3, $first);
		$firstEmails = array_column($first, 'from_email');

		// Neue Korrektur dazu — älteste 3 dürfen nicht ihre Position ändern
		$newMail = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'new@x.de']);
		$this->pdo()->prepare('INSERT INTO mail_score_corrections
			(id, tenant_id, user_id, mail_id, corrected_label, corrected_priority, corrected_action)
			VALUES (:id, :t, :u, :m, "newsletter", 1, 0)')
			->execute([':id' => Uuid::v4(), ':t' => $tenantId, ':u' => $userId, ':m' => $newMail]);

		$second = $repo->forFewShotPrompt($tenantId, $userId, 10);
		$this->assertCount(4, $second);
		$this->assertSame($firstEmails[0], $second[0]['from_email']);
		$this->assertSame($firstEmails[1], $second[1]['from_email']);
		$this->assertSame($firstEmails[2], $second[2]['from_email']);
		$this->assertSame('new@x.de',      $second[3]['from_email'], 'Neue Korrektur hängt hinten an');
	}

	public function testAutoSortCorrectionFewShotOnlyStabilized(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new AutoSortCorrectionRepository($this->pdo());

		// 2 stabilisierte Korrekturen + 1 fresh (nicht stabilisiert)
		$repo->create($tenantId, $userId, 'm-old1', 'MailPilot/Auto', 'MailPilot/Direct', 'CI', 'Notifications');
		$repo->create($tenantId, $userId, 'm-old2', 'MailPilot/Auto', 'MailPilot/Direct', 'CI', 'Notifications');
		$this->pdo()->prepare('UPDATE auto_sort_corrections SET created_at = (UTC_TIMESTAMP(3) - INTERVAL 2 HOUR)')->execute();
		$repo->promoteStable(60);
		$repo->create($tenantId, $userId, 'm-fresh', 'X', 'Y', 's1', 's2');

		$prompt = $repo->forFewShotPrompt($tenantId, $userId, 10);
		$this->assertCount(2, $prompt, 'Nur stabilisierte Korrekturen kommen in den Few-Shot');
	}
}
