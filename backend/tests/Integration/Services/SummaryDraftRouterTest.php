<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Services;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
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
use MailPilot\Tests\Fixtures\FakeClaudeClient;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * @group integration
 */
final class SummaryDraftRouterTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c4';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare("INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (?, 'TestSum', 'anthropic', 'http://sum.test', 0, 1, 10)")->execute([self::PROVIDER_ID]);
		$pdo->prepare("INSERT INTO llm_models (id, provider_id, model_id, role, effort, enabled, priority)
			VALUES (?, ?, 'claude-opus-4-8', 'summary', 'medium', 1, 10)")->execute([Uuid::v4(), self::PROVIDER_ID]);
		foreach ([
			['llm.primary_provider_id', self::PROVIDER_ID],
			['llm.privacy_mode', 'cloud_allowed'],
			['llm.summary.fallback_chain', '["' . self::PROVIDER_ID . '"]'],
		] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`,`value`,`type`) VALUES (?,?,"string")
				ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)')->execute([$k, $v]);
		}
	}

	public function testSummaryRoutesAndPersistsResolvedModel(): void
	{
		[$tenantId, $userId] = $this->insertTenantAndUser();
		$mailboxId = $this->insertMailbox($tenantId, $userId);
		// insertMail() returns the new mail id directly (see tests/TestCase.php),
		// no need to round-trip through MailRepository::findUnscoredForMailbox.
		$mailId = $this->insertMail($tenantId, $mailboxId, [
			'from_email' => 'a@x.de', 'from_name' => 'A', 'subject' => 'Betreff', 'body_text' => 'Hallo Welt',
		]);
		$pdo = $this->pdo();

		$fakeAnthropic = new class implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse {
				$this->seen = $r;
				return new NormalizedResponse('Kurz-Zusammenfassung', ['inputTokens' => 10, 'outputTokens' => 5, 'raw' => ['input_tokens' => 10, 'output_tokens' => 5]], 'end_turn', $r->modelHint, 'anthropic');
			}
			public function listModels(): array { return []; }
		};

		$router = new LlmRouter(
			['anthropic' => $fakeAnthropic],
			new LlmProviderRepository($pdo),
			new SettingsRepository($pdo),
			new NullLogger(),
			new LlmModelRepository($pdo),
		);
		$budget = new BudgetService(
			new SettingsRepository($pdo), new UsageRepository($pdo), new PricingRepository($pdo), new NullLogger(),
		);
		$service = new MailSummaryService(
			$router,
			new MailRepository($pdo),
			new SummaryRepository($pdo),
			new RedactionService(),
			$budget,
			new PromptRepository($pdo),
			new LlmModelRepository($pdo),
			new FakeClaudeClient(),
		);

		$text = $service->summarize($tenantId, $mailId, 'a@x.de', 'de', $userId);

		self::assertSame('Kurz-Zusammenfassung', $text);
		// Service uebergibt einen leeren modelHint; der Router resolved daraus
		// das role='summary'-Modell aus llm_models. Der Provider sieht daher den
		// aufgeloesten Wert (nicht den leeren Service-Hint) — das beweist, dass
		// der Service das Resolven dem Router ueberlaesst.
		self::assertSame('claude-opus-4-8', $fakeAnthropic->seen->modelHint, 'Router resolved das Modell aus llm_models (Service-Hint war leer)');
		self::assertSame([], $fakeAnthropic->seen->cacheSegments);
		$row = (new SummaryRepository($pdo))->findByMailId($tenantId, $mailId);
		self::assertNotNull($row);
		self::assertSame('claude-opus-4-8', $row['model'], 'Persistiert das vom Router aufgeloeste Modell');
	}
}
