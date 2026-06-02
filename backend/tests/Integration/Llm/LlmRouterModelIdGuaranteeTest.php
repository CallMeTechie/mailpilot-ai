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
 * Task 3 — Router garantiert nicht-leere modelId (Cost-Dashboard-Integrität).
 *
 * @group integration
 */
final class LlmRouterModelIdGuaranteeTest extends TestCase
{
	public function testEmptyModelIdIsBackfilledFromResolvedModel(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pid = Uuid::v4();
		$pdo->prepare('INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (:id, "Local", "openai_compatible", "http://x", 1, 1, 10)')->execute([':id' => $pid]);
		$pdo->prepare('INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (:id, :p, "qwen3:32b", "score", 1, 10)')->execute([':id' => Uuid::v4(), ':p' => $pid]);
		$set = new SettingsRepository($pdo);
		$set->set('llm.routing_mode', 'router');
		$set->set('llm.score.fallback_chain', json_encode([$pid]));
		$set->set('llm.privacy_mode', 'cloud_allowed');

		$emptyModelProvider = new class implements LlmProvider {
			public function kind(): string { return 'openai_compatible'; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{ return new NormalizedResponse('ok', ['inputTokens' => 1, 'outputTokens' => 1], 'stop', '', 'openai_compatible'); }
			public function isHealthy(): bool { return true; }
			public function listModels(): array { return []; }
		};

		$router = new LlmRouter(
			['openai_compatible' => $emptyModelProvider],
			new LlmProviderRepository($pdo), $set, new NullLogger(), new LlmModelRepository($pdo), null,
		);
		$resp = $router->complete(new NormalizedRequest('s', [['role' => 'user', 'content' => 'x']], 50, 0.1, ''), 'score');
		self::assertSame('qwen3:32b', $resp->modelId, 'leerer modelId → resolvtes Modell');
	}
}
