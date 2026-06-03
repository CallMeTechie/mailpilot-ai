<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\Scoring\MatchScorer;
use MailPilot\Services\ScoreOverrideService;
use MailPilot\Tests\TestCase;

/**
 * Spec 2 (Marc 2026-06-03) — Match-Score + Bänder.
 *
 * Drei user-derived Regeln gegen EINE Test-Mail (sender_key=notifications@github.com):
 *   - exakter sender_key   → MatchScorer 80 → band=auto    → $score wird mutiert
 *   - nur Domain + Betreff → MatchScorer 65 → band=suggest → pending_actions-Row, $score unverändert
 *   - kein Signal          → MatchScorer 0  → band=ignore  → keine Änderung / kein pending
 *
 * match_mode=deterministic → KEIN LLM-Match (RuleMatchService wird zwar
 * injiziert, aber nie aufgerufen). Real-Objekt statt Mock (Projekt-Konvention).
 */
final class ScoreOverrideBandsTest extends TestCase
{
	protected function setUp(): void
	{
		$this->truncateAll();
		$this->pdo()->exec('TRUNCATE TABLE score_override_rules');
	}

	private function makeService(): ScoreOverrideService
	{
		$settings = new SettingsRepository($this->pdo());
		// match_mode=deterministic ist der Migration-0065-Default; explizit setzen,
		// damit der Test nicht von der Seed-Reihenfolge abhängt.
		$this->pdo()->prepare(
			"INSERT INTO system_settings (`key`, `value`, `type`) VALUES ('learning.match_mode','deterministic','string')
			 ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)"
		)->execute();

		// match_mode=deterministic → RuleMatchService wird NIE aufgerufen, daher
		// hier bewusst null (optionale Abhängigkeit). Der LlmRouter ist `final`
		// und im deterministischen Pfad irrelevant — kein Bedarf, ihn zu bauen.
		return new ScoreOverrideService(
			new ScoreOverrideRepository($this->pdo()),
			$this->logger(),
			new MatchScorer(80, 50),
			null,
			$settings,
			new PendingActionRepository($this->pdo()),
		);
	}

	public function testHighMatchApplies_MidMatchSuggests_LowIgnores(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$repo = new ScoreOverrideRepository($this->pdo());

		// AUTO: exakter sender_key → 80 Punkte → band=auto.
		$autoRuleId = $repo->create($tenantId, $userId, [
			'match_sender_key'     => 'notifications@github.com',
			'set_priority'         => 5,
			'origin_correction_id' => $this->fakeCorrectionId(),
		]);
		// SUGGEST: nur gleiche Domain (35) + Betreff-Match (30) = 65 → band=suggest.
		$suggestRuleId = $repo->create($tenantId, $userId, [
			'match_sender_key'     => 'noreply@github.com',
			'match_subject_regex'  => '/build/i',
			'set_label'            => 'newsletter',
			'origin_correction_id' => $this->fakeCorrectionId(),
		]);
		// IGNORE: fremde Domain, kein weiteres Signal → 0 → band=ignore.
		$ignoreRuleId = $repo->create($tenantId, $userId, [
			'match_sender_key'     => 'someone@gitlab.com',
			'set_priority'         => 1,
			'origin_correction_id' => $this->fakeCorrectionId(),
		]);

		$mail = [
			'id'         => 'm1',
			'subject'    => 'Build #42 failed on main',
			'from_email' => 'notifications@github.com',
		];
		$score  = ['label' => 'auto', 'priority' => 2, 'action_required' => false];
		$bucket = ['sender_key' => 'notifications@github.com'];

		$result = $this->makeService()->apply($tenantId, $userId, $mail, $score, $bucket);

		// AUTO-Regel hat priority mutiert.
		$this->assertTrue($result['matched']);
		$this->assertSame(5, $score['priority'], 'auto-Band mutiert den Score');
		$this->assertSame($autoRuleId, $result['rule_id']);
		// SUGGEST-Regel hätte set_label=newsletter gesetzt — darf den Score NICHT verändert haben.
		$this->assertSame('auto', $score['label'], 'suggest-Band schreibt KEINEN Score');

		// SUGGEST-Regel → genau eine pending_actions-Row kind=score_suggestion.
		$this->assertSame([$suggestRuleId], $result['suggested'] ?? []);
		$stmt = $this->pdo()->prepare(
			"SELECT payload FROM pending_actions
			 WHERE tenant_id = :t AND user_id = :u AND kind = 'score_suggestion'"
		);
		$stmt->execute([':t' => $tenantId, ':u' => $userId]);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
		$this->assertCount(1, $rows, 'genau ein score_suggestion-Vorschlag');
		$payload = json_decode((string)$rows[0]['payload'], true);
		$this->assertSame($suggestRuleId, $payload['rule_id']);
		$this->assertSame('m1', $payload['mail_id']);
		$this->assertSame('newsletter', $payload['proposed']['label']);

		// IGNORE-Regel → weder Score-Change noch Vorschlag.
		$this->assertNotContains($ignoreRuleId, $result['suggested'] ?? []);
		$this->assertNotContains($ignoreRuleId, $result['rule_ids'] ?? []);
	}

	/** Erzeugt eine syntaktisch gültige UUID; markiert die Regel als user-derived. */
	private function fakeCorrectionId(): string
	{
		return \MailPilot\Util\Uuid::v4();
	}
}
