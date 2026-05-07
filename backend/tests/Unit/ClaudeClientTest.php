<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Tests\TestCase;

final class ClaudeClientTest extends TestCase
{
	public function testExtractTextSingleBlock(): void
	{
		$resp = ['content' => [['type' => 'text', 'text' => 'Hello']]];
		$this->assertSame('Hello', ClaudeClient::extractText($resp));
	}

	public function testExtractTextMultipleBlocks(): void
	{
		$resp = ['content' => [
			['type' => 'text', 'text' => 'Line 1'],
			['type' => 'text', 'text' => 'Line 2'],
		]];
		$this->assertSame("Line 1\nLine 2", ClaudeClient::extractText($resp));
	}

	public function testExtractTextIgnoresNonTextBlocks(): void
	{
		$resp = ['content' => [
			['type' => 'tool_use', 'name' => 'foo'],
			['type' => 'text', 'text' => 'Only this'],
		]];
		$this->assertSame('Only this', ClaudeClient::extractText($resp));
	}

	public function testExtractTextEmptyResponse(): void
	{
		$this->assertSame('', ClaudeClient::extractText([]));
		$this->assertSame('', ClaudeClient::extractText(['content' => []]));
	}
}
