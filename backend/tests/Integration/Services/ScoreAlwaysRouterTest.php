<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\CorrectionRepository;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\ScoreRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Services\BudgetService;
use MailPilot\Services\MailScoringService;
use MailPilot\Services\RedactionService;
use MailPilot\Tests\Fixtures\FakeClaudeClient;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * Spec 1: score läuft IMMER über den Router — auch im routing_mode='direct'.
 * Im direct-Mode wird die Chain auf den Primary gekürzt, das Modell kommt aber
 * weiterhin aus llm_models (Rolle 'score'), NICHT aus dem P-SCORE-Prompt.
 * Muster 1:1 wie backend/tests/Integration/Llm/ScoreRouterModelTest.php.
 *
 * @group integration
 */
final class ScoreAlwaysRouterTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000e1';

	public function testDirectModeStillUsesLlmModelsModelViaRouter(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'TestScoreDirect', 'anthropic', 'http://x', 0, 1, 10)")->execute([self::PROVIDER_ID]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (?, ?, 'claude-haiku-4-5-20251001', 'score', 1, 10)")->execute([Uuid::v4(), self::PROVIDER_ID]);
		foreach ([
			['llm.routing_mode', 'direct'],   // direct! — trotzdem muss der Router genutzt werden
			['llm.privacy_mode', 'cloud_allowed'],
			['llm.score.fallback_chain', '["' . self::PROVIDER_ID . '"]'],
		] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`) VALUES (?, ?, "string")
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
		}

		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, ['from_email' => 'a@example.com', 'subject' => 'Hi', 'body_text' => 'Test.']);
		$mails = (new MailRepository($pdo))->findUnscoredForMailbox($tenantId, $mailboxId);

		$capture = new class ($mails[0]['id']) implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function __construct(private string $mailId) {}
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				$this->seen = $r;
				$json = json_encode(['results' => [[
					'id' => $this->mailId, 'label' => 'direct', 'action_required' => false,
					'priority' => 3, 'summary' => 's', 'reasoning' => 'r',
				]]], JSON_THROW_ON_ERROR);
				return new NormalizedResponse($json, ['inputTokens' => 10, 'outputTokens' => 5], 'end_turn', $r->modelHint, 'anthropic');
			}
			public function listModels(): array { return []; }
		};

		$router = new LlmRouter(
			['anthropic' => $capture], new LlmProviderRepository($pdo),
			new SettingsRepository($pdo), new NullLogger(), new LlmModelRepository($pdo),
		);
		$service = $this->makeServiceWithRouter(new FakeClaudeClient(), $router);
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		self::assertCount(1, $scores);
		self::assertNotNull($capture->seen, 'score MUSS auch im direct-Mode über den Router laufen');
		self::assertSame('claude-haiku-4-5-20251001', $capture->seen->modelHint, 'Modell aus llm_models, nicht aus dem Prompt');
	}

	private function makeServiceWithRouter(FakeClaudeClient $claude, LlmRouter $router): MailScoringService
	{
		$pdo = $this->pdo();
		$budget = new BudgetService(new SettingsRepository($pdo), new UsageRepository($pdo), new PricingRepository($pdo), $this->logger());
		return new MailScoringService(
			$claude, new MailRepository($pdo), new ScoreRepository($pdo), new CacheRepository($pdo, 30),
			new RedactionService(), $budget, new CorrectionRepository($pdo), new SubLabelRepository($pdo),
			new AutoSortRepository($pdo, new SettingsRepository($pdo)), new PromptRepository($pdo),
			new SettingsRepository($pdo), 20, 2048, $this->logger(), new PendingActionRepository($pdo),
			null, null, null, null, $router,
		);
	}
}
