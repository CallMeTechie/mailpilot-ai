<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm;

use MailPilot\Llm\ModelDescriptor;
use PHPUnit\Framework\TestCase;

final class ModelDescriptorTest extends TestCase
{
	public function testHoldsFieldsAndDefaults(): void
	{
		$d = new ModelDescriptor(
			modelId: 'claude-opus-4-8',
			displayName: 'Claude Opus 4.8',
			effortLevels: ['low', 'medium', 'high', 'xhigh', 'max'],
		);
		self::assertSame('claude-opus-4-8', $d->modelId);
		self::assertSame(['low', 'medium', 'high', 'xhigh', 'max'], $d->effortLevels);
		self::assertNull($d->maxOutputTokens);
		self::assertNull($d->maxContextTokens);
		self::assertNull($d->releasedAt);
	}

	public function testToRowEncodesEffortLevelsAsJson(): void
	{
		$d = new ModelDescriptor('m', 'M', ['low'], 100, 200, '2026-01-01T00:00:00Z');
		$row = $d->toRow();
		self::assertSame('["low"]', $row['effort_levels']);
		self::assertSame(100, $row['max_output_tokens']);
		self::assertSame('2026-01-01T00:00:00Z', $row['released_at']);
	}
}
