<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

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
 * Task 2 (Score-Modell/Effort über llm_models im router-Mode).
 *
 * Pinnt: im routing_mode='router' resolved der LlmRouter Modell UND Effort
 * für die Rolle `score` aus llm_models — NICHT aus dem aktiven P-SCORE-Prompt
 * (der laut Seed-Migration 'claude-haiku-4-5-20251001' ohne Effort nutzt).
 * MailScoringService sendet modelHint='' in den Router; der Router fuellt es.
 *
 * @group integration
 */
final class ScoreRouterModelTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000d7';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			 VALUES (?, 'TestScoreRouter', 'anthropic', 'http://score.test', 0, 1, 10)"
		)->execute([self::PROVIDER_ID]);
		// Score-Row mit Modell + Effort, beide abweichend vom P-SCORE-Prompt-
		// Modell (claude-haiku-4-5-..., kein Effort). Beweist, dass der Router
		// BEIDE aus llm_models resolved.
		$pdo->prepare(
			"INSERT INTO llm_models (id, provider_id, model_id, role, effort, enabled, priority)
			 VALUES (?, ?, 'claude-opus-4-8', 'score', 'medium', 1, 10)"
		)->execute([Uuid::v4(), self::PROVIDER_ID]);

		foreach ([
			['llm.routing_mode', 'router'],
			['llm.primary_provider_id', self::PROVIDER_ID],
			['llm.privacy_mode', 'cloud_allowed'],
			// system_settings wird nicht truncated; die echte score-Chain zeigt
			// sonst auf den geseedeten Anthropic-Provider und resolved dessen
			// Modell statt TestScoreRouter.
			['llm.score.fallback_chain', '["' . self::PROVIDER_ID . '"]'],
		] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`) VALUES (?, ?, "string")
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
		}
	}

	public function testRouterModeResolvesScoreModelAndEffortFromLlmModels(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'someone@example.com',
			'subject'    => 'Bitte um Rueckmeldung',
			'body_text'  => 'Kurze Frage zum Projekt.',
		]);
		$mails = (new MailRepository($this->pdo()))->findUnscoredForMailbox($tenantId, $mailboxId);
		$this->assertCount(1, $mails);

		// Anonymer LlmProvider, der den (nach Router-Resolution) NormalizedRequest
		// einfaengt und eine kanonische {"results":[...]}-Antwort liefert.
		$capture = new class ($mails[0]['id']) implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function __construct(private string $mailId) {}
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				$this->seen = $r;
				$json = json_encode(['results' => [[
					'id'              => $this->mailId,
					'label'           => 'direct',
					'action_required' => false,
					'priority'        => 3,
					'summary'         => 's',
					'reasoning'       => 'r',
				]]], JSON_THROW_ON_ERROR);
				return new NormalizedResponse(
					$json,
					['inputTokens' => 10, 'outputTokens' => 5],
					'end_turn',
					$r->modelHint,
					'anthropic',
				);
			}
			public function listModels(): array { return []; }
		};

		$pdo = $this->pdo();
		$router = new LlmRouter(
			['anthropic' => $capture],
			new LlmProviderRepository($pdo),
			new SettingsRepository($pdo),
			new NullLogger(),
			new LlmModelRepository($pdo),
		);

		$service = $this->makeServiceWithRouter(new FakeClaudeClient(), $router);
		$profile = ['email' => 'marc@test.de', 'language' => 'de', 'vip_senders' => [], 'project_keywords' => []];
		$scores = $service->scoreBatch($tenantId, $profile, $mails);

		$this->assertCount(1, $scores);
		$this->assertSame('direct', $scores[0]['label']);

		// Beweis: der Router hat Modell + Effort aus llm_models resolved,
		// NICHT das Prompt-Modell (claude-haiku-4-5-..., kein Effort).
		$this->assertNotNull($capture->seen, 'Provider muss aufgerufen worden sein (router-Mode)');
		$this->assertSame('claude-opus-4-8', $capture->seen->modelHint,
			'Modell muss aus llm_models (role=score) kommen, nicht aus dem P-SCORE-Prompt');
		$this->assertSame('medium', $capture->seen->effort,
			'Effort muss aus llm_models (role=score) durchgereicht werden');
	}

	/**
	 * Wie MailScoringServiceTest::makeService, aber mit echtem LlmRouter
	 * injiziert (router ist ein spaeter optionaler ctor-Param). FakeClaudeClient
	 * ist der direct-Mode-Fallback und bleibt im router-Mode ungenutzt.
	 */
	private function makeServiceWithRouter(FakeClaudeClient $claude, LlmRouter $router): MailScoringService
	{
		$pdo = $this->pdo();
		$budget = new BudgetService(
			new SettingsRepository($pdo),
			new UsageRepository($pdo),
			new PricingRepository($pdo),
			$this->logger(),
		);
		return new MailScoringService(
			$claude,
			new MailRepository($pdo),
			new ScoreRepository($pdo),
			new CacheRepository($pdo, 30),
			new RedactionService(),
			$budget,
			new CorrectionRepository($pdo),
			new SubLabelRepository($pdo),
			new AutoSortRepository($pdo, new SettingsRepository($pdo)),
			new PromptRepository($pdo),
			new SettingsRepository($pdo),
			20,
			2048,
			$this->logger(),
			new PendingActionRepository($pdo),
			null,   // autoSortCorrections
			null,   // senderResolver
			null,   // lookalikeDetector
			null,   // scoreOverride
			$router,
		);
	}
}
