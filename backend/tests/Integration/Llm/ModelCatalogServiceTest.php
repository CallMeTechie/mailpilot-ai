<?php
declare(strict_types=1);

namespace MailPilot\Tests\Integration\Llm;

use MailPilot\Llm\LlmProvider;
use MailPilot\Llm\LlmUnavailableException;
use MailPilot\Llm\ModelCatalogService;
use MailPilot\Llm\ModelDescriptor;
use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\NormalizedResponse;
use MailPilot\Repositories\LlmModelCatalogRepository;
use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Tests\TestCase;
use Psr\Log\NullLogger;

/**
 * @group integration
 */
final class ModelCatalogServiceTest extends TestCase
{
	private const PROVIDER_ID = '00000000-0000-4000-8000-0000000000c2';

	protected function setUp(): void
	{
		$this->truncateAll();
		$pdo = $this->pdo();
		$pdo->exec("DELETE FROM llm_model_catalog WHERE provider_id = '" . self::PROVIDER_ID . "'");
		$pdo->exec("DELETE FROM llm_providers WHERE id = '" . self::PROVIDER_ID . "'");
		$pdo->prepare(
			"INSERT INTO llm_providers (id, name, kind, base_url, is_local, enabled, priority)
			 VALUES (?, 'TestCat', 'anthropic', 'http://cat.test', 0, 1, 10)"
		)->execute([self::PROVIDER_ID]);
	}

	/** @param list<ModelDescriptor>|null $models */
	private function provider(?array $models, bool $throw = false): LlmProvider
	{
		return new class($models, $throw) implements LlmProvider {
			/** @param list<ModelDescriptor>|null $models */
			public function __construct(private readonly ?array $models, private readonly bool $throw) {}
			public function kind(): string { return 'anthropic'; }
			public function isHealthy(): bool { return true; }
			public function complete(NormalizedRequest $r): NormalizedResponse {
				return new NormalizedResponse('', ['inputTokens' => 0, 'outputTokens' => 0], 'stop', $r->modelHint, 'anthropic');
			}
			public function listModels(): array {
				if ($this->throw) { throw new LlmUnavailableException('down'); }
				return $this->models ?? [];
			}
		};
	}

	private function service(LlmProvider $p): ModelCatalogService
	{
		$pdo = $this->pdo();
		return new ModelCatalogService(
			['anthropic' => $p],
			new LlmModelCatalogRepository($pdo),
			new LlmProviderRepository($pdo),
			new NullLogger(),
		);
	}

	public function testOkProviderUpsertsAndMarksStale(): void
	{
		$repo = new LlmModelCatalogRepository($this->pdo());
		$repo->upsert(self::PROVIDER_ID, new ModelDescriptor('old-model', 'Old', []));

		$res = $this->service($this->provider([new ModelDescriptor('m1', 'M1', ['low'])]))
			->refreshProvider(self::PROVIDER_ID, 'anthropic');

		self::assertSame(1, $res['discovered']);
		self::assertNull($res['error']);
		$available = array_column($repo->listByProvider(self::PROVIDER_ID, availableOnly: true), 'model_id');
		self::assertSame(['m1'], $available, 'm1 verfuegbar, old-model auf available=0');
	}

	public function testFailingProviderDoesNotEmptyCatalog(): void
	{
		$repo = new LlmModelCatalogRepository($this->pdo());
		$repo->upsert(self::PROVIDER_ID, new ModelDescriptor('keep-me', 'Keep', []));

		$res = $this->service($this->provider(null, throw: true))
			->refreshProvider(self::PROVIDER_ID, 'anthropic');

		self::assertSame(0, $res['discovered']);
		self::assertNotNull($res['error']);
		self::assertCount(1, $repo->listByProvider(self::PROVIDER_ID, availableOnly: true),
			'transienter Fehler darf den Katalog NICHT leeren (kein markStale)');
	}
}
