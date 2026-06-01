<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Llm;

use MailPilot\Admin\Controllers\LlmController;
use PHPUnit\Framework\TestCase;

final class ParseChainInputTest extends TestCase
{
	public function testArrayInputDedupedAndOrdered(): void
	{
		$r = LlmController::parseChainInput(['p1', '', 'p2', 'p1', ' p3 ']);
		self::assertSame(['p1', 'p2', 'p3'], $r);
	}

	public function testCommaStringBackwardCompat(): void
	{
		self::assertSame(['a', 'b'], LlmController::parseChainInput('a, b, a'));
	}

	public function testEmptyInputs(): void
	{
		self::assertSame([], LlmController::parseChainInput([]));
		self::assertSame([], LlmController::parseChainInput(''));
	}
}
