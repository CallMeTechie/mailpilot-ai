<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Tests\TestCase;
use MailPilot\Util\Uuid;
use Psr\Log\NullLogger;

/**
 * @group integration
 */
final class RouterEffortTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c3';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			 VALUES (?, 'TestEffort', 'anthropic', 'http://eff.test', 0, 1, 10)"
		)->execute([self::PROVIDER_ID]);
		$pdo->prepare(
			"INSERT INTO llm_models (id, provider_id, model_id, role, effort, enabled, priority)
			 VALUES (?, ?, 'claude-opus-4-8', 'summary', 'medium', 1, 10)"
		)->execute([Uuid::v4(), self::PROVIDER_ID]);
		foreach ([
			['llm.primary_provider_id', self::PROVIDER_ID],
			['llm.privacy_mode', 'cloud_allowed'],
			// system_settings wird nicht truncated; die geseedete
			// llm.summary.fallback_chain zeigt sonst auf den echten
			// Anthropic-Provider und resolved dessen Model statt TestEffort.
			['llm.summary.fallback_chain', '["' . self::PROVIDER_ID . '"]'],
		] as [$k, $v]) {
			$pdo->prepare('INSERT INTO system_settings (`key`, `value`, `type`) VALUES (?, ?, "string")
				ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')->execute([$k, $v]);
		}
	}

	public function testRouterPassesEffortFromModelRow(): void
	{
		$capture = new class implements LlmProvider {
			public ?NormalizedRequest $seen = null;
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse {
				$this->seen = $r;
				return new NormalizedResponse('ok', ['inputTokens' => 1, 'outputTokens' => 1], 'end_turn', $r->modelHint, 'anthropic');
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
		$router->complete(new NormalizedRequest('sys', [['role' => 'user', 'content' => 'x']], 400, 0.3, ''), 'summary');

		self::assertNotNull($capture->seen);
		self::assertSame('claude-opus-4-8', $capture->seen->modelHint);
		self::assertSame('medium', $capture->seen->effort);
	}
}
