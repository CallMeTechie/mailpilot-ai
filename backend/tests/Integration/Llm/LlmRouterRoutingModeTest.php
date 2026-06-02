<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Llm\LlmOverloadedException;
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

final class LlmRouterRoutingModeTest extends TestCase
{
	private const PID_A = '00000000-0000-4000-8000-0000000000a0';
	private const PID_B = '00000000-0000-4000-8000-0000000000b0';

	private function seedTwoProviderChain(string $mode): SettingsRepository
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		// truncateAll() leert llm_providers/llm_models NICHT → fixe UUIDs vorher
		// löschen, sonst Duplicate-Key beim zweiten Lauf gegen dieselbe DB.
		$pdo->exec("DELETE FROM llm_models WHERE provider_id IN ('" . self::PID_A . "','" . self::PID_B . "')");
		$pdo->exec("DELETE FROM llm_providers WHERE id IN ('" . self::PID_A . "','" . self::PID_B . "')");
		foreach ([[self::PID_A, 'Anthropic', 'anthropic', 10], [self::PID_B, 'OpenAI', 'openai', 20]] as [$id, $name, $kind, $prio]) {
			$pdo->prepare('INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
				VALUES (:id, :n, :k, "https://x", 0, 1, :p)')->execute([':id' => $id, ':n' => $name, ':k' => $kind, ':p' => $prio]);
			$pdo->prepare('INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
				VALUES (:id, :p, :m, "score", 1, 10)')->execute([':id' => Uuid::v4(), ':p' => $id, ':m' => $kind . '-model']);
		}
		$set = new SettingsRepository($pdo);
		$set->set('llm.routing_mode', $mode);
		$set->set('llm.score.fallback_chain', json_encode([self::PID_A, self::PID_B]));
		$set->set('llm.privacy_mode', 'cloud_allowed');
		return $set;
	}

	/** @param array<string,int> $calls */
	private function provider(string $kind, bool $overloaded, array &$calls): LlmProvider
	{
		return new class($kind, $overloaded, $calls) implements LlmProvider {
			/** @param array<string,int> $calls */
			public function __construct(private string $kind, private bool $overloaded, private array &$calls) {}
			public function kind(): string { return $this->kind; }
			public function complete(NormalizedRequest $r): NormalizedResponse
			{
				$this->calls[$this->kind] = ($this->calls[$this->kind] ?? 0) + 1;
				if ($this->overloaded) { throw new LlmOverloadedException($this->kind . ' overloaded'); }
				return new NormalizedResponse('ok', ['inputTokens' => 1, 'outputTokens' => 1], 'stop', $r->modelHint, $this->kind);
			}
			public function isHealthy(): bool { return true; }
			public function listModels(): array { return []; }
		};
	}

	public function testDirectModeUsesOnlyPrimaryNoFailover(): void
	{
		$calls = [];
		$set = $this->seedTwoProviderChain('direct');
		$router = new LlmRouter(
			['anthropic' => $this->provider('anthropic', true, $calls), 'openai' => $this->provider('openai', false, $calls)],
			new LlmProviderRepository($this->pdo()), $set, new NullLogger(), new LlmModelRepository($this->pdo()), null,
		);
		$req = new NormalizedRequest('sys', [['role' => 'user', 'content' => 'x']], 100, 0.1, '');
		$this->expectException(\MailPilot\Llm\LlmAllProvidersDownException::class);
		try {
			$router->complete($req, 'score');
		} finally {
			self::assertSame(1, $calls['anthropic'] ?? 0, 'Primary einmal versucht');
			self::assertArrayNotHasKey('openai', $calls, 'direct: KEIN Failover auf OpenAI');
		}
	}

	public function testRouterModeFailsOverToSecond(): void
	{
		$calls = [];
		$set = $this->seedTwoProviderChain('router');
		$router = new LlmRouter(
			['anthropic' => $this->provider('anthropic', true, $calls), 'openai' => $this->provider('openai', false, $calls)],
			new LlmProviderRepository($this->pdo()), $set, new NullLogger(), new LlmModelRepository($this->pdo()), null,
		);
		$req = new NormalizedRequest('sys', [['role' => 'user', 'content' => 'x']], 100, 0.1, '');
		$resp = $router->complete($req, 'score');
		self::assertSame('openai', $resp->providerKind);
		self::assertSame(1, $calls['anthropic'] ?? 0);
		self::assertSame(1, $calls['openai'] ?? 0);
	}
}
