<?php
declare(strict_types=1);

namespace MailPilot\Tests\Unit\Scoring;

use MailPilot\Services\Scoring\MatchScorer;
use PHPUnit\Framework\TestCase;

final class MatchScorerTest extends TestCase
{
	public function testExactSenderScoresHighAndBandsResolve(): void
	{
		$scorer = new MatchScorer(autoThreshold: 80, suggestThreshold: 50);
		$rule = ['match_sender_key' => 'sk:acme', 'match_from_local' => null, 'match_subject_regex' => null];
		$mail = ['sender_key' => 'sk:acme', 'from_local' => 'billing', 'subject' => 'Rechnung 5'];

		$score = $scorer->score($rule, $mail);
		self::assertGreaterThanOrEqual(80, $score);
		self::assertSame('auto', $scorer->band($score));
		self::assertSame('ignore', $scorer->band(10));
		self::assertSame('suggest', $scorer->band(60));
	}

	public function testNoSignalScoresZero(): void
	{
		$scorer = new MatchScorer(80, 50);
		$rule = ['match_sender_key' => 'sk:other', 'match_from_local' => null, 'match_subject_regex' => null];
		$mail = ['sender_key' => 'sk:acme', 'from_local' => 'x', 'subject' => 'y'];
		self::assertSame(0, $scorer->score($rule, $mail));
	}
}
