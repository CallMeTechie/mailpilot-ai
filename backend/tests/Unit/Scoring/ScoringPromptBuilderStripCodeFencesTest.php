<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Scoring;

use MailPilot\Services\Scoring\ScoringPromptBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Static-Funktion-Test fuer den ```json … ``` Stripper. Frueher als
 * MailScoringService::stripCodeFences (private) — jetzt public static.
 */
final class ScoringPromptBuilderStripCodeFencesTest extends TestCase
{
	public function testPlainJsonPassesThrough(): void
	{
		$input = '{"a":1}';
		$this->assertSame('{"a":1}', ScoringPromptBuilder::stripCodeFences($input));
	}

	public function testJsonFenceIsStripped(): void
	{
		$input = "```json\n{\"a\":1}\n```";
		$this->assertSame('{"a":1}', ScoringPromptBuilder::stripCodeFences($input));
	}

	public function testGenericFenceIsStripped(): void
	{
		$input = "```\n{\"a\":1}\n```";
		$this->assertSame('{"a":1}', ScoringPromptBuilder::stripCodeFences($input));
	}

	public function testLeadingTrailingWhitespaceTrimmed(): void
	{
		$input = "   \n  {\"a\":1}  \n  ";
		$this->assertSame('{"a":1}', ScoringPromptBuilder::stripCodeFences($input));
	}

	public function testFenceWithLanguageHintAndWhitespace(): void
	{
		$input = "  ```json\n   {\"results\": []}\n  ```  ";
		$out = ScoringPromptBuilder::stripCodeFences($input);
		$this->assertStringContainsString('{"results": []}', $out);
		$this->assertStringNotContainsString('```', $out);
	}
}
