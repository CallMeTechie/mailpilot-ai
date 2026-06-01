<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\Providers\AnthropicProvider;
use PHPUnit\Framework\TestCase;

final class AnthropicListModelsTest extends TestCase
{
	public function testParsesEffortLevelsAndStripsUnsupported(): void
	{
		$json = [
			'data' => [
				[
					'id' => 'claude-opus-4-8',
					'display_name' => 'Claude Opus 4.8',
					'created_at' => '2026-05-01T00:00:00Z',
					'max_tokens' => 128000,
					'max_input_tokens' => 1000000,
					'capabilities' => ['effort' => [
						'supported' => true,
						'low' => ['supported' => true],
						'medium' => ['supported' => true],
						'high' => ['supported' => true],
						'xhigh' => ['supported' => true],
						'max' => ['supported' => true],
					]],
				],
				[
					'id' => 'claude-haiku-4-5-20251001',
					'display_name' => 'Claude Haiku 4.5',
					'created_at' => '2025-10-01T00:00:00Z',
					'max_tokens' => 64000,
					'max_input_tokens' => 200000,
					'capabilities' => ['effort' => ['supported' => false]],
				],
			],
			'has_more' => false,
			'last_id' => 'claude-haiku-4-5-20251001',
		];

		$models = AnthropicProvider::parseModelsResponse($json);

		self::assertCount(2, $models);
		self::assertSame('claude-opus-4-8', $models[0]->modelId);
		self::assertSame(['low', 'medium', 'high', 'xhigh', 'max'], $models[0]->effortLevels);
		self::assertSame(128000, $models[0]->maxOutputTokens);
		self::assertSame([], $models[1]->effortLevels, 'Haiku without effort support → []');
	}

	public function testZeroTokensBecomeNull(): void
	{
		$json = ['data' => [[
			'id' => 'x', 'display_name' => 'X', 'max_tokens' => 0, 'max_input_tokens' => 0,
			'capabilities' => ['effort' => ['supported' => false]],
		]]];
		$models = AnthropicProvider::parseModelsResponse($json);
		self::assertNull($models[0]->maxOutputTokens);
		self::assertNull($models[0]->maxContextTokens);
	}
}
