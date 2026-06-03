<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageCounterRepository;
use MailPilot\Services\RedactionService;
use MailPilot\Services\RuleInferenceService;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * Task 7 — Regel-Extraktion (RuleInferenceService) läuft über den LlmRouter
 * (Rolle 'inference'), nicht mehr direkt über ClaudeClient.
 *
 * Pinnt: im routing_mode='router' resolved der LlmRouter Modell/Effort für die
 * Rolle `inference` aus llm_models. RuleInferenceService sendet modelHint='' in
 * den Router; der Router füllt es. Beweis: der anon LlmProvider fängt den
 * NormalizedRequest ein und sein modelHint ist das llm_models-Inference-Modell.
 *
 * @group integration
 */
final class InferenceViaRouterTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c7';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			 VALUES (?, 'TestInferenceRouter', 'anthropic', 'http://inference.test', 0, 1, 10)"
		)->execute([self::PROVIDER_ID]);
		// Inference-Row mit Modell aus llm_models — abweichend vom P-RULE-EXTRACT-
		// Prompt-Modell. Beweist, dass der Router das Modell aus llm_models
		// (Rolle 'inference') resolved.
		$pdo->prepare(
			"INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			 VALUES (?, ?, 'claude-haiku-4-5-20251001', 'inference', 1, 10)"
		)->execute([Uuid::v4(), self::PROVIDER_ID]);

		foreach ([
			['rule_inference_enabled', '1'],
			['llm.routing_mode', 'router'],
			['llm.primary_provider_id', self::PROVIDER_ID],
			['llm.privacy_mode', 'cloud_allowed'],
			['llm.inference.fallback_chain', '["' . self::PROVIDER_ID . '"]'],
		] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`) VALUES (?, ?, "string")
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
		}
	}

	public function testInferenceRoutesThroughRouterWithInferenceModel(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		$mailId    = $this->insertMail($tenantId, $mailboxId, ['from_email' => 'noreply@mbnet-it.com']);

		$this->setSetting('autosort_move_mode', 'auto');
		$this->setSetting('rule_inference_backfill_range', 'future_only');

		// Anonymer LlmProvider, der den (nach Router-Resolution) NormalizedRequest
		// einfaengt und valide P-RULE-EXTRACT-JSON liefert.
		$capture = new class implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				$this->seen = $r;
				$json = json_encode([
					'create_rule'       => true,
					'label'             => 'noise',
					'sub_label'         => 'Zertifikate',
					'folder_name'       => 'MailPilot/Noise/Zertifikate',
					'match_signals'     => ['from_domain:mbnet-it.com'],
					'confidence'        => 95,
					'reasoning_summary' => 'router-fixture',
				], JSON_THROW_ON_ERROR);
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

		$pdo    = $this->pdo();
		$router = new LlmRouter(
			['anthropic' => $capture],
			new LlmProviderRepository($pdo),
			new SettingsRepository($pdo),
			new NullLogger(),
			new LlmModelRepository($pdo),
		);

		$service = $this->makeServiceWithRouter($router);
		$result  = $service->infer($tenantId, $userId, $mailId, 'SSL-Mails von mbnet-it.com können in Noise/Zertifikate');

		// Die Inferenz ist erfolgreich und legt eine Regel an (Beleg: der
		// Router-Response wurde durch parseClaudeResponse korrekt verarbeitet).
		self::assertSame('applied', $result['action']);

		self::assertNotNull($capture->seen, 'Inferenz MUSS über den Router laufen');
		self::assertSame('claude-haiku-4-5-20251001', $capture->seen->modelHint,
			'inference-Modell aus llm_models (Rolle inference)');
	}

	private function makeServiceWithRouter(LlmRouter $router): RuleInferenceService
	{
		$pdo      = $this->pdo();
		$settings = new SettingsRepository($pdo);
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

	private function setSetting(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES (:k, :v, "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
			->execute([':k' => $key, ':v' => $value]);
	}
}
