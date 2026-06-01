<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm\Providers;

use MailPilot\Llm\NormalizedRequest;
use MailPilot\Llm\Providers\AnthropicProvider;
use MailPilot\Llm\Providers\OpenAiProvider;
use PHPUnit\Framework\TestCase;

final class EffortEmissionTest extends TestCase
{
	private function req(?string $effort): NormalizedRequest
	{
		return new NormalizedRequest(
			systemPrompt: 'sys', messages: [['role' => 'user', 'content' => 'hi']],
			maxTokens: 400, temperature: 0.3, modelHint: 'claude-opus-4-8',
			responseFormat: null, cacheSegments: [], effort: $effort,
		);
	}

	public function testAnthropicEmitsOutputConfigWhenEffortSet(): void
	{
		$with = AnthropicProvider::buildPayload($this->req('medium'));
		self::assertSame(['effort' => 'medium'], $with['output_config']);

		$without = AnthropicProvider::buildPayload($this->req(null));
		self::assertArrayNotHasKey('output_config', $without);
	}

	public function testOpenAiEmitsReasoningEffortWhenSet(): void
	{
		$with = OpenAiProvider::buildPayload($this->req('high'));
		self::assertSame('high', $with['reasoning_effort']);

		$without = OpenAiProvider::buildPayload($this->req(null));
		self::assertArrayNotHasKey('reasoning_effort', $without);
	}
}
