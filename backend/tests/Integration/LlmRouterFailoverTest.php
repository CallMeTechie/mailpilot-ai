<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration;

use MailPilot\Llm\LlmAllProvidersDownException;
use MailPilot\Llm\LlmOverloadedException;
use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmRouter;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmModelRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Util\Uuid;
use MailPilot\Tests\TestCase;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

/**
 * Phase 9q-B (Marc 2026-05-22) — LlmRouter Failover-Verhalten.
 *
 * @group integration
 */
final class LlmRouterFailoverTest extends TestCase
{
	private const PRIMARY_ID  = '00000000-0000-4000-8000-0000000000a1';
	private const FALLBACK_ID = '00000000-0000-4000-8000-0000000000a2';
	private const LOCAL_ID    = '00000000-0000-4000-8000-0000000000a3';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		// llm_providers ist nicht in truncateAll — wir loeschen Test-Rows manuell
		// und seedeen 2 minimale Provider mit aktivierten Stati.
		$pdo->exec("DELETE FROM llm_providers WHERE id IN ('" . self::PRIMARY_ID . "','" . self::FALLBACK_ID . "')");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, api_key_env_fallback, is_local, enabled, priority)
			 VALUES (?, 'TestPrimary', 'anthropic', 'http://primary.test', 'TEST_PRIMARY_KEY', 0, 1, 10),
			        (?, 'TestFallback', 'openai',   'http://fallback.test', 'TEST_FALLBACK_KEY', 0, 1, 20)"
		)->execute([self::PRIMARY_ID, self::FALLBACK_ID]);

		$this->setSetting('llm.score.fallback_chain',
			'["' . self::PRIMARY_ID . '","' . self::FALLBACK_ID . '"]');
		// Phase 9q-E (Marc 2026-05-23): privacy_mode-Reset auf Default —
		// testLocalOnly*-Tests setzen 'local_only', das leakt sonst in
		// nachfolgende Tests und filtert alle Cloud-Provider weg.
		$this->setSetting('llm.privacy_mode', 'cloud_allowed');
	}

	private function setSetting(string $key, string $value): void
	{
		$this->pdo()->prepare('INSERT INTO system_settings (`key`, `value`, `type`)
			VALUES (:k, :v, "string")
			ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
			->execute([':k' => $key, ':v' => $value]);
	}

	private function makeRouter(
		LlmProvider $anthropic,
		LlmProvider $openai,
		?LlmProvider $local = null,
		?LlmModelRepository $models = null,
	): LlmRouter {
		$logger = new Logger('test');
		$logger->pushHandler(new NullHandler());
		$providers = ['anthropic' => $anthropic, 'openai' => $openai];
		if ($local !== null) {
			$providers['openai_compatible'] = $local;
		}
		return new LlmRouter(
			$providers,
			new LlmProviderRepository($this->pdo()),
			new SettingsRepository($this->pdo()),
			$logger,
			$models,
		);
	}

	private function seedLocalProvider(): void
	{
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::LOCAL_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			 VALUES (?, 'TestLocal', 'openai_compatible', 'http://localhost:11434', 1, 1, 5)"
		)->execute([self::LOCAL_ID]);
	}

	private function makeRequest(): NormalizedRequest
	{
		return new NormalizedRequest(
			systemPrompt: 'You are a test assistant',
			messages:     [['role' => 'user', 'content' => 'ping']],
			maxTokens:    100,
			temperature:  0.1,
			modelHint:    'test-model',
		);
	}

	private function fakeProvider(string $kind, bool $overload = false, bool $unavailable = false): LlmProvider
	{
		return new class($kind, $overload, $unavailable) implements LlmProvider {
			public int $callCount = 0;
			public function __construct(
				private readonly string $kind,
				private readonly bool $overload,
				private readonly bool $unavailable,
			) {}
			public function kind(): string { return $this->kind; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $request): NormalizedResponse
			{
				$this->callCount++;
				if ($this->overload) {
					throw new LlmOverloadedException("simulated overload on {$this->kind}", 30);
				}
				if ($this->unavailable) {
					throw new \MailPilot\Llm\LlmUnavailableException("simulated outage on {$this->kind}");
				}
				return new NormalizedResponse(
					content:      sprintf('{"result":"from-%s"}', $this->kind),
					usage:        ['inputTokens' => 10, 'outputTokens' => 5],
					finishReason: 'stop',
					modelId:      $request->modelHint,
					providerKind: $this->kind,
				);
			}
			public function listModels(): array { return []; }
		};
	}

	public function testPrimarySucceedsNoFailover(): void
	{
		$anthropic = $this->fakeProvider('anthropic', overload: false);
		$openai    = $this->fakeProvider('openai',    overload: false);

		$resp = $this->makeRouter($anthropic, $openai)->complete($this->makeRequest(), 'score');

		self::assertSame('anthropic', $resp->providerKind, 'Primary haette ohne Fehler liefern muessen');
		self::assertSame('{"result":"from-anthropic"}', $resp->content);
		self::assertSame(1, $anthropic->callCount);
		self::assertSame(0, $openai->callCount, 'Fallback darf nicht beruehrt worden sein');
	}

	public function testFailoverWhenPrimaryOverloaded(): void
	{
		$anthropic = $this->fakeProvider('anthropic', overload: true);
		$openai    = $this->fakeProvider('openai',    overload: false);

		$resp = $this->makeRouter($anthropic, $openai)->complete($this->makeRequest(), 'score');

		self::assertSame('openai', $resp->providerKind, 'Fallback haette greifen muessen');
		self::assertSame('{"result":"from-openai"}', $resp->content);
		self::assertSame(1, $anthropic->callCount, 'Primary wurde versucht');
		self::assertSame(1, $openai->callCount, 'Fallback wurde getriggert');
	}

	public function testFailoverWhenPrimaryUnavailable(): void
	{
		$anthropic = $this->fakeProvider('anthropic', unavailable: true);
		$openai    = $this->fakeProvider('openai',    overload: false);

		$resp = $this->makeRouter($anthropic, $openai)->complete($this->makeRequest(), 'score');

		self::assertSame('openai', $resp->providerKind, 'Unavailable triggert Failover');
	}

	public function testAllProvidersDownThrows(): void
	{
		$anthropic = $this->fakeProvider('anthropic', overload: true);
		$openai    = $this->fakeProvider('openai',    overload: true);

		$this->expectException(LlmAllProvidersDownException::class);
		$this->makeRouter($anthropic, $openai)->complete($this->makeRequest(), 'score');
	}

	public function testEmptyChainThrows(): void
	{
		// Chain auf leere Liste setzen — Router sollte sofort werfen.
		$this->setSetting('llm.score.fallback_chain', '[]');
		$this->setSetting('llm.primary_provider_id', '');

		$anthropic = $this->fakeProvider('anthropic');
		$openai    = $this->fakeProvider('openai');

		$this->expectException(LlmAllProvidersDownException::class);
		$this->makeRouter($anthropic, $openai)->complete($this->makeRequest(), 'score');
	}

	public function testDisabledProviderSkipped(): void
	{
		// Primary disablen — Chain enthaelt nur den Fallback dann.
		$this->pdo()->prepare('UPDATE llm_providers SET enabled = 0 WHERE id = ?')
			->execute([self::PRIMARY_ID]);

		$anthropic = $this->fakeProvider('anthropic'); // wird nie gerufen
		$openai    = $this->fakeProvider('openai');

		$resp = $this->makeRouter($anthropic, $openai)->complete($this->makeRequest(), 'score');

		self::assertSame('openai', $resp->providerKind);
		self::assertSame(0, $anthropic->callCount, 'Disabled Provider darf nicht gecallt werden');
	}

	// =============================================================
	// Phase 9q-C: Privacy-Mode + Model-Resolution
	// =============================================================

	public function testLocalOnlyFiltersAllCloudProviders(): void
	{
		// Chain enthaelt einen lokalen + zwei Cloud-Provider.
		$this->seedLocalProvider();
		$this->setSetting('llm.score.fallback_chain',
			'["' . self::PRIMARY_ID . '","' . self::FALLBACK_ID . '","' . self::LOCAL_ID . '"]');
		$this->setSetting('llm.privacy_mode', 'local_only');

		$anthropic = $this->fakeProvider('anthropic'); // soll NICHT gerufen werden
		$openai    = $this->fakeProvider('openai');    // soll NICHT gerufen werden
		$local     = $this->fakeProvider('openai_compatible');

		$resp = $this->makeRouter($anthropic, $openai, $local)->complete($this->makeRequest(), 'score');

		self::assertSame('openai_compatible', $resp->providerKind);
		self::assertSame(0, $anthropic->callCount, 'Cloud-Provider darf in local_only nicht gerufen werden');
		self::assertSame(0, $openai->callCount);
		self::assertSame(1, $local->callCount);
	}

	public function testLocalOnlyAllDownThrows(): void
	{
		$this->seedLocalProvider();
		$this->setSetting('llm.score.fallback_chain',
			'["' . self::PRIMARY_ID . '","' . self::LOCAL_ID . '"]');
		$this->setSetting('llm.privacy_mode', 'local_only');

		$anthropic = $this->fakeProvider('anthropic');                    // gefiltert weg
		$openai    = $this->fakeProvider('openai');
		$local     = $this->fakeProvider('openai_compatible', unavailable: true);

		$this->expectException(LlmAllProvidersDownException::class);
		$this->makeRouter($anthropic, $openai, $local)->complete($this->makeRequest(), 'score');
	}

	public function testLocalPreferredSortsLocalFirst(): void
	{
		// Chain ist Anthropic→OpenAI→Local. local_preferred → Local→Anthropic→OpenAI.
		$this->seedLocalProvider();
		$this->setSetting('llm.score.fallback_chain',
			'["' . self::PRIMARY_ID . '","' . self::FALLBACK_ID . '","' . self::LOCAL_ID . '"]');
		$this->setSetting('llm.privacy_mode', 'local_preferred');

		$anthropic = $this->fakeProvider('anthropic');                  // wird nicht gerufen
		$openai    = $this->fakeProvider('openai');
		$local     = $this->fakeProvider('openai_compatible');          // wird zuerst gerufen

		$resp = $this->makeRouter($anthropic, $openai, $local)->complete($this->makeRequest(), 'score');

		self::assertSame('openai_compatible', $resp->providerKind, 'Lokales Modell hatte Vorrang');
		self::assertSame(1, $local->callCount);
		self::assertSame(0, $anthropic->callCount);
	}

	public function testModelHintResolvedFromRepoWhenEmpty(): void
	{
		// Test-Model in llm_models seedeen + Request mit leerem modelHint.
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_models WHERE provider_id = '" . self::PRIMARY_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_models (id, provider_id, model_id, role, enabled, priority)
			 VALUES (?, ?, 'resolved-test-model', 'score', 1, 10)"
		)->execute([Uuid::v4(), self::PRIMARY_ID]);

		$capturedHint = '';
		$anthropic = new class('anthropic', $capturedHint) implements LlmProvider {
			public int $callCount = 0;
			public string $capturedHint = '';
			public function __construct(private readonly string $k, string &$cap) {}
			public function kind(): string { return $this->k; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $request): NormalizedResponse {
				$this->callCount++;
				$this->capturedHint = $request->modelHint;
				return new NormalizedResponse(
					content: '{}', usage: ['inputTokens' => 0, 'outputTokens' => 0],
					finishReason: 'stop', modelId: $request->modelHint, providerKind: 'anthropic',
				);
			}
			public function listModels(): array { return []; }
		};
		$openai = $this->fakeProvider('openai');

		$request = new NormalizedRequest(
			systemPrompt: 'sys', messages: [['role' => 'user', 'content' => 'x']],
			maxTokens: 100, temperature: 0.1, modelHint: '',  // leer!
		);

		$models = new LlmModelRepository($this->pdo());
		$router = $this->makeRouter($anthropic, $openai, models: $models);
		$router->complete($request, 'score');

		self::assertSame('resolved-test-model', $anthropic->capturedHint,
			'Router resolved modelHint aus llm_models wenn Request leer ist');
	}
}
