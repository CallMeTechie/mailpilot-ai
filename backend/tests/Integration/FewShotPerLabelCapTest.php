<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;

/**
 * Phase 9o (Marc 2026-05-21) — Few-Shot-Cap pro corrected_label.
 *
 * Pinnt das Verhalten von CorrectionRepository::forFewShotPrompt mit dem
 * neuen $perLabelLimit-Parameter:
 *   - Bei perLabelLimit=null bleibt Verhalten Legacy-global.
 *   - Bei perLabelLimit=N werden max N juengste pro corrected_label
 *     geliefert, ueber alle Labels deterministisch sortiert.
 *
 * @group integration
 */
final class FewShotPerLabelCapTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
	}

	public function testPerLabelCapLimitsEachLabelIndependently(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$repo = new CorrectionRepository($this->pdo());

		// 8 newsletter-Korrekturen + 3 direct-Korrekturen + 2 action-Korrekturen.
		// Per-Label-Cap=5 muss liefern: 5 newsletter + 3 direct + 2 action = 10.
		$this->seedCorrections($tenantId, $userId, $mailboxId, 'newsletter', 1, 8);
		$this->seedCorrections($tenantId, $userId, $mailboxId, 'direct',     4, 3);
		$this->seedCorrections($tenantId, $userId, $mailboxId, 'action',     5, 2);

		$result = $repo->forFewShotPrompt($tenantId, $userId, limit: 999, windowDays: 30, perLabelLimit: 5);

		$byLabel = [];
		foreach ($result as $row) {
			$byLabel[$row['corrected_label']] = ($byLabel[$row['corrected_label']] ?? 0) + 1;
		}

		self::assertSame(5, $byLabel['newsletter'] ?? 0, 'newsletter wird auf 5 gecapped');
		self::assertSame(3, $byLabel['direct']     ?? 0, 'direct unter Cap bleibt voll');
		self::assertSame(2, $byLabel['action']     ?? 0, 'action unter Cap bleibt voll');
		self::assertCount(10, $result, '5+3+2=10 total');
	}

	public function testPerLabelCapPicksYoungestPerLabel(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$repo = new CorrectionRepository($this->pdo());

		// 6 newsletter-Korrekturen, jede mit unterschiedlichem created_at
		// (1h, 2h, 3h, ... 6h alt). Per-Label-Cap=3 muss die 3 juengsten
		// (1h/2h/3h) liefern, NICHT die aeltesten.
		for ($hoursAgo = 1; $hoursAgo <= 6; $hoursAgo++) {
			$mailId = $this->insertMail($tenantId, $mailboxId, ['from_email' => "n{$hoursAgo}@x.de"]);
			$this->pdo()->prepare('INSERT INTO mail_score_corrections
				(id, tenant_id, user_id, mail_id, corrected_label, corrected_priority, corrected_action, created_at)
				VALUES (:id, :t, :u, :m, "newsletter", 1, 0, UTC_TIMESTAMP(3) - INTERVAL :h HOUR)')
				->execute([
					':id' => Uuid::v4(), ':t' => $tenantId, ':u' => $userId,
					':m'  => $mailId,    ':h' => $hoursAgo,
				]);
		}

		$result = $repo->forFewShotPrompt($tenantId, $userId, limit: 999, windowDays: 30, perLabelLimit: 3);

		self::assertCount(3, $result);
		$emails = array_column($result, 'from_email');
		// ORDER BY corrected_label ASC, created_at ASC innerhalb des Labels —
		// also juengste 3 (1h/2h/3h) aufsteigend nach created_at.
		self::assertSame(['n3@x.de', 'n2@x.de', 'n1@x.de'], $emails,
			'liefert die 3 juengsten newsletter-Korrekturen, aelteste-first innerhalb des Top-3-Pools');
	}

	public function testLegacyGlobalLimitWhenPerLabelLimitNull(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$repo = new CorrectionRepository($this->pdo());

		// 8 newsletter-Korrekturen — Legacy-Modus (perLabelLimit=null) capped
		// global auf $limit=5, ignoriert die Label-Verteilung.
		$this->seedCorrections($tenantId, $userId, $mailboxId, 'newsletter', 1, 8);

		$result = $repo->forFewShotPrompt($tenantId, $userId, limit: 5, windowDays: 30, perLabelLimit: null);
		self::assertCount(5, $result, 'Legacy-Mode: globaler LIMIT 5');
	}

	public function testWindowFilterAlsoAppliesToPerLabelCap(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$repo = new CorrectionRepository($this->pdo());

		// 3 newsletter innerhalb 30d + 3 alte (> 30d) sollen ignoriert werden.
		$this->seedCorrections($tenantId, $userId, $mailboxId, 'newsletter', 1, 3);
		for ($i = 1; $i <= 3; $i++) {
			$mailId = $this->insertMail($tenantId, $mailboxId, ['from_email' => "old{$i}@x.de"]);
			$this->pdo()->prepare('INSERT INTO mail_score_corrections
				(id, tenant_id, user_id, mail_id, corrected_label, corrected_priority, corrected_action, created_at)
				VALUES (:id, :t, :u, :m, "newsletter", 1, 0, UTC_TIMESTAMP(3) - INTERVAL 45 DAY)')
				->execute([':id' => Uuid::v4(), ':t' => $tenantId, ':u' => $userId, ':m' => $mailId]);
		}

		$result = $repo->forFewShotPrompt($tenantId, $userId, limit: 999, windowDays: 30, perLabelLimit: 10);
		self::assertCount(3, $result, '30d-Fenster filtert alte Korrekturen aus auch im Per-Label-Mode');
	}

	private function seedCorrections(string $tenantId, string $userId, string $mailboxId, string $label, int $priority, int $count): void
	{
		for ($i = 1; $i <= $count; $i++) {
			$mailId = $this->insertMail($tenantId, $mailboxId, ['from_email' => "{$label}{$i}@x.de"]);
			$this->pdo()->prepare('INSERT INTO mail_score_corrections
				(id, tenant_id, user_id, mail_id, corrected_label, corrected_priority, corrected_action)
				VALUES (:id, :t, :u, :m, :l, :p, 0)')
				->execute([
					':id' => Uuid::v4(), ':t' => $tenantId, ':u' => $userId, ':m' => $mailId,
					':l'  => $label, ':p' => $priority,
				]);
		}
	}
}
