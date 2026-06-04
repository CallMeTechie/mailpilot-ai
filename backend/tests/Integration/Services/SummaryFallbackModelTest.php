<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Claude\ClaudeProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\PricingRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\SummaryRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Services\BudgetService;
use MailPilot\Services\MailSummaryService;
use MailPilot\Services\RedactionService;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * Spec 1 – Fallback-Modell stammt aus llm_models (Rolle 'summary'),
 * nicht mehr aus prompt.model.
 *
 * Setup: leere fallback_chain → LlmRouter wirft LlmAllProvidersDownException
 * → Safety-Net greift → claudeFallback->messages(['model' => $model, ...]).
 * Der übergebene $model-Wert muss 'sentinel-summary-model' sein (aus llm_models),
 * nicht der Wert, den P-SUMMARY prompt.model hält.
 *
 * @group integration
 */
final class SummaryFallbackModelTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000f1';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		// Vollständig bereinigen: andere Integration-Tests (z.B. SummaryDraftRouterTest)
		// lassen llm_providers/llm_models stehen (nicht in truncateAll). Da listByRole
		// cross-provider sortiert, würden Fremd-Einträge den Sentinel-Wert verdrängen.
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
		$pdo->exec('TRUNCATE TABLE llm_models');
		$pdo->exec('TRUNCATE TABLE llm_providers');
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
		// tearDown() stellt die Migrations-Seeds zurueck (Test-Isolation).
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'FallbackTestProv', 'anthropic', 'http://fallback.test', 0, 1, 10)")
			->execute([self::PROVIDER_ID]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, effort, enabled, priority)
			VALUES (?, ?, 'sentinel-summary-model', 'summary', 'medium', 1, 10)")
			->execute([Uuid::v4(), self::PROVIDER_ID]);
		foreach ([
			['llm.primary_provider_id', self::PROVIDER_ID],
			['llm.privacy_mode', 'cloud_allowed'],
			// Leere Chain → Router wirft LlmAllProvidersDownException → Safety-Net greift
			['llm.summary.fallback_chain', '[]'],
			['llm.routing_mode', 'router'],
		] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`,`value`,`type`) VALUES (?,?,"string")
				ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$k, $v]);
		}
	}

	/**
	 * Stellt die Migrations-Seed-Daten fuer llm_providers und llm_models wieder
	 * her (Test-Isolation: setUp() trunciert diese Tabellen komplett, was nach-
	 * folgende Tests — insbesondere MigrationLearningLoopTest — bricht).
	 */
	protected function tearDown(): void
	{
		parent::tearDown();

		$pdo = $this->pdo();

		// 0050 — Anthropic-Provider.
		$pdo->exec("INSERT IGNORE INTO llm_providers
			(id, name, kind, base_url, api_key_env_fallback, is_local, enabled, priority)
			VALUES (
				'00000000-0000-4000-8000-000000000050',
				'Anthropic', 'anthropic', 'https://api.anthropic.com',
				'ANTHROPIC_API_KEY', 0, 1, 10
			)");

		// 0052 + 0062 — Anthropic-Modelle (finale Werte nach allen Migrations).
		$pdo->exec("INSERT IGNORE INTO llm_models
			(id, provider_id, model_id, role, cost_per_mtok_in, cost_per_mtok_out,
			 supports_caching, max_context, enabled, priority)
			VALUES
				('00000000-0000-4000-8001-000000000050',
				 '00000000-0000-4000-8000-000000000050',
				 'claude-haiku-4-5-20251001', 'score', 0.80, 4.00, 1, 200000, 1, 10),
				('00000000-0000-4000-8001-000000000051',
				 '00000000-0000-4000-8000-000000000050',
				 'claude-haiku-4-5-20251001', 'inference', 0.80, 4.00, 1, 200000, 1, 10),
				('00000000-0000-4000-8001-000000000052',
				 '00000000-0000-4000-8000-000000000050',
				 'claude-opus-4-8', 'summary', 5.00, 25.00, 1, 200000, 1, 10),
				('00000000-0000-4000-8001-000000000053',
				 '00000000-0000-4000-8000-000000000050',
				 'claude-opus-4-8', 'draft', 5.00, 25.00, 1, 200000, 1, 10)");

		$pdo->exec("UPDATE llm_models SET effort = 'medium'
			WHERE role IN ('summary', 'draft')
			  AND provider_id = '00000000-0000-4000-8000-000000000050'
			  AND deleted_at IS NULL");

		// 0065 — match-Modell.
		$pdo->exec("INSERT IGNORE INTO llm_models
			(id, provider_id, model_id, role, cost_per_mtok_in, cost_per_mtok_out,
			 supports_caching, max_context, enabled, priority)
			VALUES (
				'00000000-0000-4000-8001-000000000054',
				'00000000-0000-4000-8000-000000000050',
				'claude-haiku-4-5-20251001', 'match', 0.80, 4.00, 1, 200000, 1, 10
			)");
	}

	public function testFallbackModelDerivedFromLlmModels(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'sender@example.com',
			'from_name'  => 'Sender',
			'subject'    => 'Fallback-Test',
			'body_text'  => 'Testinhalt für Fallback-Modell-Ableitung',
		]);
		$pdo = $this->pdo();

		// Router hat KEINE gemappten LlmProvider → resolveChain('summary') bleibt leer
		// → LlmAllProvidersDownException → Safety-Net (claudeFallback) greift.
		$router = new LlmRouter(
			[],   // keine kind-gemappten Provider
			new LlmProviderRepository($pdo),
			new SettingsRepository($pdo),
			new NullLogger(),
			new LlmModelRepository($pdo),
		);
		$budget = new BudgetService(
			new SettingsRepository($pdo),
			new UsageRepository($pdo),
			new PricingRepository($pdo),
			new NullLogger(),
		);

		// Aufzeichnender anonymer ClaudeProvider — merkt sich den 'model'-Wert
		// aus dem messages()-Aufruf (= der Fallback-Pfad übergibt dort $model).
		$recordingFallback = new class implements ClaudeProvider {
			public ?string $capturedModel = null;

			public function messages(array $payload): array
			{
				$this->capturedModel = (string)($payload['model'] ?? '');
				return [
					'content'     => [['type' => 'text', 'text' => 'Fallback-Zusammenfassung']],
					'stop_reason' => 'end_turn',
					'usage'       => ['input_tokens' => 5, 'output_tokens' => 3],
				];
			}
		};

		$service = new MailSummaryService(
			$router,
			new MailRepository($pdo),
			new SummaryRepository($pdo),
			new RedactionService(),
			$budget,
			new PromptRepository($pdo),
			new LlmModelRepository($pdo),
			$recordingFallback,
		);

		$text = $service->summarize($tenantId, $mailId, 'sender@example.com', 'de', $userId);
		self::assertSame('Fallback-Zusammenfassung', $text);

		// Primäre Beobachtung: claudeFallback->messages() wurde mit dem Modell
		// aus llm_models (Rolle 'summary') aufgerufen, nicht mit prompt.model.
		self::assertSame(
			'sentinel-summary-model',
			$recordingFallback->capturedModel,
			'Fallback-Modell stammt aus llm_models (Rolle summary), nicht aus prompt.model',
		);

		// Sekundäre Beobachtung: persistierte summary.model stimmt überein.
		$row = (new SummaryRepository($pdo))->findByMailId($tenantId, $mailId);
		self::assertNotNull($row);
		self::assertSame(
			'sentinel-summary-model',
			$row['model'],
			'Persistiertes summary.model stimmt mit llm_models-Eintrag überein',
		);
	}
}
