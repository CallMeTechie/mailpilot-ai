<?php
declare(strict_types=1);

namespace MailPilot\Tests\Fixtures;

use MailPilot\Claude\ClaudeClient;
use Psr\Log\NullLogger;

/**
 * Test double for ClaudeClient. Records calls and returns pre-scripted responses.
 */
final class FakeClaudeClient extends ClaudeClient
{
	/** @var list<array<string, mixed>> */
	public array $calls = [];

	/** @var list<array<string, mixed>> */
	private array $scriptedResponses = [];

	public function __construct()
	{
		parent::__construct('fake-key', 'https://fake', '2023-06-01', 30, new NullLogger());
	}

	public function scriptResponse(string $text): void
	{
		$this->scriptedResponses[] = [
			'content' => [['type' => 'text', 'text' => $text]],
			'stop_reason' => 'end_turn',
		];
	}

	public function scriptJson(array $data): void
	{
		$this->scriptResponse(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	public function messages(array $payload): array
	{
		$this->calls[] = $payload;
		if ($this->scriptedResponses === []) {
			throw new \RuntimeException('FakeClaudeClient: no scripted response for call #' . count($this->calls));
		}
		return array_shift($this->scriptedResponses);
	}

	public function callCount(): int
	{
		return count($this->calls);
	}

	public function lastCall(): array
	{
		return end($this->calls) ?: [];
	}
}
