<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\GeminiProvider;
use PHPUnit\Framework\TestCase;

final class GeminiListModelsTest extends TestCase
{
	public function testKeepsOnlyGenerateContentModelsAndStripsPrefix(): void
	{
		$json = ['models' => [
			[
				'name' => 'models/gemini-2.5-pro',
				'displayName' => 'Gemini 2.5 Pro',
				'supportedGenerationMethods' => ['generateContent', 'countTokens'],
				'outputTokenLimit' => 65536,
				'inputTokenLimit' => 1048576,
			],
			[
				'name' => 'models/text-embedding-004',
				'displayName' => 'Text Embedding 004',
				'supportedGenerationMethods' => ['embedContent'],
			],
		]];
		$models = GeminiProvider::parseModelsResponse($json);

		self::assertCount(1, $models);
		self::assertSame('gemini-2.5-pro', $models[0]->modelId);
		self::assertSame([], $models[0]->effortLevels);
		self::assertSame(65536, $models[0]->maxOutputTokens);
	}
}
