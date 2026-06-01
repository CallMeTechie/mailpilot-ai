<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\OpenAiCompatibleProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiCompatibleListModelsTest extends TestCase
{
	public function testReturnsAllModelsNoEffort(): void
	{
		$json = ['data' => [['id' => 'qwen3:32b'], ['id' => 'llama3.1:8b']]];
		$models = OpenAiCompatibleProvider::parseModelsResponse($json);
		$ids = array_map(static fn($m) => $m->modelId, $models);
		self::assertSame(['qwen3:32b', 'llama3.1:8b'], $ids);
		self::assertSame([], $models[0]->effortLevels);
	}
}
