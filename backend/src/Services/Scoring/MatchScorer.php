<?php
declare(strict_types=1);

namespace MailPilot\Services\Scoring;

/**
 * Spec 2 — deterministischer Per-Mail-Match einer Regel. Gewichtete Features
 * → Score 0..100 (gekappt). Billig + erklärbar; kein LLM. Gewichte sind
 * Defaults (YAGNI: erst global, später tunebar).
 */
final class MatchScorer
{
	private const W_SENDER_EXACT  = 80;
	private const W_DOMAIN        = 35;
	private const W_FROM_LOCAL    = 25;
	private const W_SUBJECT_REGEX = 30;

	public function __construct(
		private readonly int $autoThreshold = 80,
		private readonly int $suggestThreshold = 50,
	) {
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $mail  mit sender_key, from_local, subject
	 */
	public function score(array $rule, array $mail): int
	{
		$ruleSender = $rule['match_sender_key'] !== null ? (string)$rule['match_sender_key'] : '';
		$mailSender = (string)($mail['sender_key'] ?? '');
		$points = 0;

		if ($ruleSender !== '' && $mailSender !== '') {
			if ($ruleSender === $mailSender) {
				$points += self::W_SENDER_EXACT;
			} elseif ($this->sameDomain($ruleSender, $mailSender)) {
				$points += self::W_DOMAIN;
			}
		}
		if ($rule['match_from_local'] !== null
			&& (string)$rule['match_from_local'] === strtolower((string)($mail['from_local'] ?? ''))) {
			$points += self::W_FROM_LOCAL;
		}
		if ($rule['match_subject_regex'] !== null) {
			$pattern = (string)$rule['match_subject_regex'];
			set_error_handler(static fn(): bool => true);
			try {
				$hit = @preg_match($pattern, (string)($mail['subject'] ?? ''));
			} finally {
				restore_error_handler();
			}
			if ($hit === 1) {
				$points += self::W_SUBJECT_REGEX;
			}
		}
		return min(100, $points);
	}

	/** @return 'auto'|'suggest'|'ignore' */
	public function band(int $score): string
	{
		if ($score >= $this->autoThreshold) { return 'auto'; }
		if ($score >= $this->suggestThreshold) { return 'suggest'; }
		return 'ignore';
	}

	private function sameDomain(string $a, string $b): bool
	{
		$da = strstr($a, '@') ?: $a;
		$db = strstr($b, '@') ?: $b;
		return $da !== '' && $da === $db;
	}
}
