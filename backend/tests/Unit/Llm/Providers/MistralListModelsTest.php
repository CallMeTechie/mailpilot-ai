<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\MistralProvider;
use PHPUnit\Framework\TestCase;

final class MistralListModelsTest extends TestCase
{
	public function testKeepsChatCapableOnly(): void
	{
		$json = ['data' => [
			['id' => 'mistral-large-latest', 'capabilities' => ['completion_chat' => true]],
			['id' => 'mistral-embed', 'capabilities' => ['completion_chat' => false]],
		]];
		$models = MistralProvider::parseModelsResponse($json);
		$ids = array_map(static fn($m) => $m->modelId, $models);
		self::assertSame(['mistral-large-latest'], $ids);
		self::assertSame([], $models[0]->effortLevels);
	}
}
