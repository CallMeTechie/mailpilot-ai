<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\OpenAiProvider;
use PHPUnit\Framework\TestCase;

final class OpenAiListModelsTest extends TestCase
{
	public function testKeepsChatModelsDropsEmbeddingsAndAudio(): void
	{
		$json = ['data' => [
			['id' => 'gpt-4o'],
			['id' => 'o3-mini'],
			['id' => 'chatgpt-4o-latest'],
			['id' => 'text-embedding-3-large'],
			['id' => 'whisper-1'],
			['id' => 'dall-e-3'],
			['id' => 'omni-moderation-latest'],
			['id' => 'davinci-002'],
			['id' => 'gpt-4o-transcribe'],
			['id' => 'gpt-4o-audio-preview'],
		]];
		$models = OpenAiProvider::parseModelsResponse($json);
		$ids = array_map(static fn($m) => $m->modelId, $models);

		self::assertContains('gpt-4o', $ids);
		self::assertContains('o3-mini', $ids);
		self::assertContains('chatgpt-4o-latest', $ids);
		self::assertNotContains('text-embedding-3-large', $ids);
		self::assertNotContains('whisper-1', $ids);
		self::assertNotContains('dall-e-3', $ids);
		self::assertNotContains('omni-moderation-latest', $ids);
		self::assertNotContains('davinci-002', $ids);
		self::assertNotContains('gpt-4o-transcribe', $ids);
		self::assertNotContains('gpt-4o-audio-preview', $ids);
	}

	public function testChatModelsOfferStandardEffortLevels(): void
	{
		$models = OpenAiProvider::parseModelsResponse(['data' => [['id' => 'gpt-4o']]]);
		self::assertSame(['low', 'medium', 'high'], $models[0]->effortLevels);
	}
}
