<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Llm\LlmRouter;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageCounterRepository;
use MailPilot\Services\QuotaExceededException;
use MailPilot\Services\RedactionService;
use MailPilot\Services\RuleInferenceService;
use MailPilot\Tests\Fixtures\ScriptedLlmProvider;
use MailPilot\Tests\Support\SeedsInferenceRouting;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * Sprint 6g — Pin-Tests für die vier DA-eingebauten Schutzschichten:
 *   - Force-Pending bei backfill_range=all (DA-R1 Crit 2)
 *   - Auto-Apply nur bei range=future_only (DA-R1 Crit 2)
 *   - Fuzzy-Merge dedupliziert Sub-Labels (DA-R1 High 3)
 *   - Idempotenz blockt zweiten Submit (DA-R1 Med 4)
 *
 * Plus Quota-Cap (DA-R2 High 2). Redaction wird im RedactionServiceTest
 * separat gepinnt.
 *
 * Task 7 (2026-06-02): Regel-Extraktion laeuft jetzt ueber den LlmRouter
 * (Rolle 'inference'). Der gescriptete ScriptedLlmProvider ersetzt die
 * frueheren FakeClaudeClient::scriptJson-Calls.
 *
 * @group integration
 */
final class RuleInferenceServiceTest extends TestCase
{
	use SeedsInferenceRouting;

	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c8';

	protected function setUp(): void
	{
		$this->truncateAll();
		// truncateAll laesst system_settings stehen — Tests in InferAll-
		// FromCorrectionTest schalten rule_inference_enabled='0' und resetten
		// nicht. Wir setzen den Default hier hart wieder zurueck, damit der
		// Test-Order keinen Einfluss hat. (Phase 9q-A, Marc 2026-05-22.)
		$this->setSetting('rule_inference_enabled', '1');
		$this->seedInferenceRouting(self::PROVIDER_ID, 'RuleInferenceTestProv');
	}

	private function makeService(ScriptedLlmProvider $provider): RuleInferenceService
	{
		$pdo = $this->pdo();
		$settings = new SettingsRepository($pdo);
		$router = new LlmRouter(
			['anthropic' => $provider],
			new LlmProviderRepository($pdo),
			$settings,
			new NullLogger(),
			new LlmModelRepository($pdo),
		);
		return new RuleInferenceService(
			$pdo,
			$router,
			new RedactionService(),
			$settings,
			new UsageCounterRepository($pdo),
			new AutoSortRepository($pdo, $settings),
			new PendingActionRepository($pdo),
			new PromptRepository($pdo),
			new NullLogger(),
		);
	}

	private function scriptDefaultRule(ScriptedLlmProvider $provider, int $confidence = 90, ?string $subLabel = 'Zertifikate'): void
	{
		$provider->scriptRawJson([
			'create_rule'       => true,
			'label'             => 'noise',
			'sub_label'         => $subLabel,
			'folder_name'       => $subLabel === null ? 'MailPilot/Noise' : "MailPilot/Noise/{$subLabel}",
			'match_signals'     => ['from_domain:mbnet-it.com'],
			'confidence'        => $confidence,
			'reasoning_summary' => 'fixture',
		]);
	}

	public function testRangeAllForcesPendingEvenWithHighConfidence(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'noreply@mbnet-it.com']);

		// Modi auf auto + range auf all setzen — laut DA muss das trotzdem pending werden.
		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'all');

		$provider = new ScriptedLlmProvider();
		$this->scriptDefaultRule($provider, 95);

		$result = $this->makeService($provider)->infer($tenantId, $userId, $mailId, 'SSL-Mails von mbnet-it.com können in Noise/Zertifikate');

		$this->assertSame('pending', $result['action'], 'range=all muss Pending erzwingen — DA-R1 Critical 2');
		$pendings = $this->pdo()->query("SELECT COUNT(*) FROM pending_actions WHERE kind='rule_suggestion'")->fetchColumn();
		$this->assertSame(1, (int)$pendings);
	}

	public function testFutureOnlyWithHighConfidenceAndAutoModeAppliesImmediately(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'noreply@mbnet-it.com']);

		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'future_only');

		$provider = new ScriptedLlmProvider();
		$this->scriptDefaultRule($provider, 95);

		$result = $this->makeService($provider)->infer($tenantId, $userId, $mailId, 'SSL-Mails von mbnet-it.com können in Noise/Zertifikate');

		$this->assertSame('applied', $result['action']);
		$rules = $this->pdo()->query("SELECT label, sub_label, enabled FROM auto_sort_rules WHERE tenant_id=" . $this->pdo()->quote($tenantId))->fetchAll();
		$this->assertCount(1, $rules);
		$this->assertSame('noise',       $rules[0]['label']);
		$this->assertSame('Zertifikate', $rules[0]['sub_label']);
		$this->assertSame(1,             (int)$rules[0]['enabled']);
	}

	/**
	 * 2026-05-18 Marc-Bug-Fix: vor dem Fix landete diese Konstellation immer
	 * im Pending Tab, obwohl der User "Sofort verschieben" gewaehlt hatte.
	 * Default-Range last_30_days + auto-mode muss jetzt direkt applied sein.
	 */
	public function testLast30DaysWithHighConfidenceAndAutoModeAppliesImmediately(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'noreply@mbnet-it.com']);

		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'last_30_days');

		$provider = new ScriptedLlmProvider();
		$this->scriptDefaultRule($provider, 95);

		$result = $this->makeService($provider)->infer($tenantId, $userId, $mailId, 'SSL-Mails von mbnet-it.com können in Noise/Zertifikate');

		$this->assertSame('applied', $result['action'],
			'last_30_days + auto + confidence>=floor + matches<=cap MUSS direkt anwenden — User-Wunsch „Sofort verschieben"');
		$pendings = (int)$this->pdo()->query("SELECT COUNT(*) FROM pending_actions WHERE kind='rule_suggestion'")->fetchColumn();
		$this->assertSame(0, $pendings, 'Keine Pending-Action erzeugen wenn direkt applied');
	}

	public function testFuzzyMergeReusesExistingSubLabel(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'ci@github.com']);

		// Existing Rule mit sub_label „CI" — die KI schlägt „ci" (kleinklein) vor.
		(new AutoSortRepository($this->pdo(), new SettingsRepository($this->pdo())))
			->upsert($tenantId, $userId, 'noise', 'CI', true, 'MailPilot/Noise/CI');

		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'future_only');

		$provider = new ScriptedLlmProvider();
		$this->scriptDefaultRule($provider, 95, 'ci');

		$result = $this->makeService($provider)->infer($tenantId, $userId, $mailId, 'GitHub CI-Mails sind Noise');

		$this->assertSame('applied', $result['action']);
		$this->assertSame('CI', $result['sub_label'], 'Fuzzy-Merge muss existierendes „CI" wiederverwenden, kein zweites „ci" anlegen');
		$count = (int)$this->pdo()->query("SELECT COUNT(*) FROM auto_sort_rules WHERE tenant_id=" . $this->pdo()->quote($tenantId))->fetchColumn();
		$this->assertSame(1, $count, 'Nur eine Rule — kein Drift durch case-Variation');
	}

	public function testDuplicateSubmitIsSkipped(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'noreply@mbnet-it.com']);

		// Korrektur-Row anlegen, sonst kann der Hash nicht gestempelt werden.
		$this->pdo()->prepare('INSERT INTO mail_score_corrections
			(id, tenant_id, user_id, mail_id, corrected_label, corrected_priority, corrected_action)
			VALUES (:id, :t, :u, :m, "noise", 1, 0)')
			->execute([':id' => Uuid::v4(), ':t' => $tenantId, ':u' => $userId, ':m' => $mailId]);

		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'future_only');

		$provider = new ScriptedLlmProvider();
		$this->scriptDefaultRule($provider, 95);

		$reasoning = 'SSL-Mails von mbnet-it.com sind Noise';
		$svc = $this->makeService($provider);
		$first  = $svc->infer($tenantId, $userId, $mailId, $reasoning);
		$second = $svc->infer($tenantId, $userId, $mailId, $reasoning);

		$this->assertSame('applied',  $first['action']);
		$this->assertSame('skipped',  $second['action'], 'Zweiter Submit derselben (mail, reasoning) muss durch Idempotenz-Hash blockiert werden');
		$this->assertSame('duplicate_submit', $second['reason']);
		$this->assertSame(1, $provider->callCount(), 'Der Router-Provider darf nur einmal angerufen werden — sonst leakt Hash-Idempotenz');
	}

	public function testQuotaExceededThrows(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'noreply@mbnet-it.com']);

		$this->setSetting('rule_inference_max_per_user_per_day', '1');
		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'future_only');

		$provider = new ScriptedLlmProvider();
		$this->scriptDefaultRule($provider, 95);
		$this->scriptDefaultRule($provider, 95);

		$svc = $this->makeService($provider);
		// Erster Call inkrementiert auf 1 — OK.
		$svc->infer($tenantId, $userId, $mailId, 'Erste Begründung');

		// Zweiter Call (anderes Reasoning, damit Idempotenz nicht greift)
		// trifft Cap=1, danach incrementOrFail wirft.
		$this->expectException(QuotaExceededException::class);
		$svc->infer($tenantId, $userId, $mailId, 'Andere Begründung');
	}

	private function setSetting(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES (:k, :v, "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
			->execute([':k' => $key, ':v' => $value]);
	}
}
