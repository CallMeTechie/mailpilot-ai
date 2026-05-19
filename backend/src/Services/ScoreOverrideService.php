<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Repositories\ScoreOverrideRepository;
use Psr\Log\LoggerInterface;

/**
 * Sort-Refactor Phase 9a (Marc 2026-05-19) — Klassifikations-Overrides.
 *
 * Wird vom MailScoringService NACH der KI-Klassifikation aber VOR dem
 * Persistieren aufgerufen. Iteriert die enabled Regeln des Users in
 * deterministischer Reihenfolge (created_at ASC) und wendet die ERSTE
 * matchende Regel an. Mutiert $score in-place.
 *
 * Match-Logik (alle gesetzten Felder werden AND-verknuepft geprueft):
 *   match_sender_key      → exakter Vergleich gegen $senderBucket['sender_key']
 *   match_subject_regex   → preg_match gegen $mail['subject']
 *   match_from_local      → exakter Vergleich gegen local-part vor @
 *   match_label           → exakter Vergleich gegen $score['label']
 *   match_priority_min    → KI-Score muss >= dieser Schwelle sein
 *
 * Set-Felder (alle optional, nicht-null overschreibt):
 *   set_priority, set_action_required, set_label
 *
 * Was wir NICHT machen:
 *   - Mehrere Regeln kombinieren (erste Match gewinnt, deterministisch)
 *   - user_corrected-Mails antasten — der MailScoringService persistiert
 *     ohnehin nur fuer User-uncorrected-Felder
 */
final class ScoreOverrideService
{
	public function __construct(
		private readonly ScoreOverrideRepository $rules,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string,mixed>     $mail          mit at least subject + from_email
	 * @param array<string,mixed>     $score         mutiert in-place
	 * @param array<string,mixed>|null $senderBucket optional, fuer sender_key-Match
	 * @return array{matched:bool, rule_id?:string, changes?:array<string,mixed>}
	 */
	public function apply(string $tenantId, string $userId, array $mail, array &$score, ?array $senderBucket = null): array
	{
		if ($userId === '') {
			return ['matched' => false];  // KI-Mini-Calls ohne User-Kontext
		}
		$rules = $this->rules->listEnabledForMatching($tenantId, $userId);
		if ($rules === []) {
			return ['matched' => false];
		}

		$senderKey = $senderBucket !== null ? (string)($senderBucket['sender_key'] ?? '') : '';
		$subject   = (string)($mail['subject'] ?? '');
		$from      = (string)($mail['from_email'] ?? '');
		$at        = strrpos($from, '@');
		$fromLocal = $at !== false ? strtolower(substr($from, 0, $at)) : '';
		$label     = (string)($score['label'] ?? '');
		$priority  = (int)($score['priority'] ?? 0);

		foreach ($rules as $rule) {
			if (!$this->matches($rule, $senderKey, $subject, $fromLocal, $label, $priority)) {
				continue;
			}
			$changes = $this->applySetFields($rule, $score);
			if ($changes !== []) {
				$this->rules->recordApply($tenantId, (string)$rule['id']);
				$this->logger->info('score_override.applied', [
					'rule_id' => (string)$rule['id'],
					'mail_id' => (string)($mail['id'] ?? ''),
					'changes' => $changes,
				]);
				return ['matched' => true, 'rule_id' => (string)$rule['id'], 'changes' => $changes];
			}
			// Regel matched aber set_-Felder waren alle null → wirkungslos, weiter.
		}
		return ['matched' => false];
	}

	/**
	 * @param array<string,mixed> $rule
	 */
	private function matches(array $rule, string $senderKey, string $subject, string $fromLocal, string $label, int $priority): bool
	{
		if ($rule['match_sender_key'] !== null && $senderKey !== $rule['match_sender_key']) {
			return false;
		}
		if ($rule['match_from_local'] !== null && $fromLocal !== $rule['match_from_local']) {
			return false;
		}
		if ($rule['match_label'] !== null && $label !== $rule['match_label']) {
			return false;
		}
		if ($rule['match_priority_min'] !== null && $priority < (int)$rule['match_priority_min']) {
			return false;
		}
		if ($rule['match_subject_regex'] !== null) {
			$pattern = (string)$rule['match_subject_regex'];
			set_error_handler(static fn() => true);  // suppress warnings on bad pattern
			try {
				$m = @preg_match($pattern, $subject);
			} finally {
				restore_error_handler();
			}
			if ($m === false || $m === 0) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $score  mutiert in-place
	 * @return array<string,mixed> tatsaechlich geaenderte Felder fuer Audit-Log
	 */
	private function applySetFields(array $rule, array &$score): array
	{
		$changes = [];
		if ($rule['set_priority'] !== null) {
			$old = (int)($score['priority'] ?? 0);
			$new = (int)$rule['set_priority'];
			if ($old !== $new) {
				$score['priority'] = $new;
				$changes['priority'] = ['from' => $old, 'to' => $new];
			}
		}
		if ($rule['set_action_required'] !== null) {
			$old = (int)(bool)($score['action_required'] ?? 0);
			$new = (int)(bool)$rule['set_action_required'];
			if ($old !== $new) {
				$score['action_required'] = $new;
				$changes['action_required'] = ['from' => $old, 'to' => $new];
			}
		}
		if ($rule['set_label'] !== null) {
			$old = (string)($score['label'] ?? '');
			$new = (string)$rule['set_label'];
			if ($old !== $new) {
				$score['label'] = $new;
				$changes['label'] = ['from' => $old, 'to' => $new];
			}
		}
		return $changes;
	}
}
