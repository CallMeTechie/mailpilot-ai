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
final class LlmRouterCompleteBatchTest extends TestCase
{
	public function testCompleteBatchReturnsPerItemAndNullOnError(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pid = Uuid::v4();
		$pdo->prepare('INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			VALUES (:id, "Anthropic", "anthropic", "https://x", 0, 1, 10)')->execute([':id' => $pid]);
		$pdo->prepare('INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			VALUES (:id, :p, "claude-haiku-4-5-20251001", "inference", 1, 10)')->execute([':id' => Uuid::v4(), ':p' => $pid]);
		$set = new SettingsRepository($pdo);
		$set->set('llm.routing_mode', 'router');
		$set->set('llm.inference.fallback_chain', json_encode([$pid]));
		$set->set('llm.privacy_mode', 'cloud_allowed');

		// Provider wirft bei content "boom" eine generische Exception (kein Failover-Typ).
		$provider = new class implements LlmProvider {
			public function kind(): string { return 'anthropic'; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				if ($r->messages[0]['content'] === 'boom') { throw new \RuntimeException('boom'); }
				return new NormalizedResponse('ok:' . $r->messages[0]['content'], ['inputTokens' => 1, 'outputTokens' => 1], 'stop', $r->modelHint, 'anthropic');
			}
			public function isHealthy(): bool { return true; }
			public function listModels(): array { return []; }
		};
		$router = new LlmRouter(['anthropic' => $provider], new LlmProviderRepository($pdo), $set, new NullLogger(), new LlmModelRepository($pdo), null);

		$mk = static fn(string $c): NormalizedRequest => new NormalizedRequest('s', [['role' => 'user', 'content' => $c]], 50, 0.1, '');
		$out = $router->completeBatch([$mk('a'), $mk('boom'), $mk('c')], 'inference');

		self::assertCount(3, $out);
		self::assertSame('ok:a', $out[0]?->content);
		self::assertNull($out[1], 'Fehler-Item → null-Slot');
		self::assertSame('ok:c', $out[2]?->content);

		self::assertSame([], $router->completeBatch([], 'inference'), 'leerer Batch → leeres Ergebnis');
	}
}
