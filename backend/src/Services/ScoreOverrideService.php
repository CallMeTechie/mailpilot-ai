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

		// Phase 9j (Marc 2026-05-20): User-corrected sticky-Felder schuetzen.
		// Wenn z.B. der User folder_segments korrigiert hat, darf eine spaetere
		// Override-Regel diese Korrektur NICHT mehr ueberschreiben. Caller
		// reicht user_corrected_fields als String („label,priority,…") oder
		// Array durch.
		$stickyRaw = $score['user_corrected_fields'] ?? null;
		$sticky    = [];
		if (is_string($stickyRaw) && $stickyRaw !== '') {
			$sticky = array_map('trim', explode(',', $stickyRaw));
		} elseif (is_array($stickyRaw)) {
			$sticky = array_map('strval', $stickyRaw);
		}

		// Phase 9g (Marc 2026-05-20): orthogonale Regel-Anwendung. Statt
		// first-match-wins iterieren wir alle Regeln in created_at-Reihenfolge
		// und nehmen pro Set-Feld die ERSTE matchende Regel mit nicht-NULL-
		// Wert fuer dieses Feld. So koennen z.B. eine Folder-Override-Regel
		// UND eine Priority-Override-Regel gleichzeitig greifen, statt dass
		// die fruehere die spaetere blockt.
		$allChanges    = [];
		$appliedRules  = [];
		$fieldsSetByRule = [
			'priority'         => null,
			'action_required'  => null,
			'label'            => null,
			'folder_segments'  => null,
		];

		foreach ($rules as $rule) {
			if (!$this->matches($rule, $senderKey, $subject, $fromLocal, $label, $priority)) {
				continue;
			}
			$thisChanges = $this->applySetFieldsOrthogonal($rule, $score, $fieldsSetByRule, $sticky);
			if ($thisChanges !== []) {
				$ruleId = (string)$rule['id'];
				$this->rules->recordApply($tenantId, $ruleId);
				$this->logger->info('score_override.applied', [
					'rule_id' => $ruleId,
					'mail_id' => (string)($mail['id'] ?? ''),
					'changes' => $thisChanges,
				]);
				$allChanges    = array_merge($allChanges, $thisChanges);
				$appliedRules[] = $ruleId;
			}
		}

		if ($allChanges === []) {
			return ['matched' => false];
		}
		return [
			'matched'   => true,
			'rule_id'   => $appliedRules[0],          // backward-compat: erste applied rule
			'rule_ids'  => $appliedRules,
			'changes'   => $allChanges,
		];
	}

	/**
	 * Phase 9g — orthogonale Variante von applySetFields: setzt pro Feld nur,
	 * wenn eine FRUEHERE Regel dieses Feld noch nicht gesetzt hat.
	 * $fieldsSetByRule wird per-reference mitgefuehrt; gibt nur die in
	 * DIESEM Call tatsaechlich geaenderten Felder zurueck.
	 *
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $score             mutiert in-place
	 * @param array<string,?string> $fieldsSetByRule mutiert in-place; key=Feldname, value=ruleId
	 * @return array<string,mixed> Changes nur fuer Felder die diese Regel zuerst setzt.
	 */
	/**
	 * @param list<string> $sticky  user_corrected_fields-Marker, z.B. ['priority','folder_segments'].
	 *                              Diese Felder werden vom Override NICHT angefasst.
	 */
	private function applySetFieldsOrthogonal(array $rule, array &$score, array &$fieldsSetByRule, array $sticky = []): array
	{
		$changes = [];
		$ruleId  = (string)$rule['id'];

		if ($rule['set_priority'] !== null && $fieldsSetByRule['priority'] === null && !in_array('priority', $sticky, true)) {
			$old = (int)($score['priority'] ?? 0);
			$new = (int)$rule['set_priority'];
			if ($old !== $new) {
				$score['priority'] = $new;
				$changes['priority'] = ['from' => $old, 'to' => $new];
			}
			$fieldsSetByRule['priority'] = $ruleId;
		}
		if ($rule['set_action_required'] !== null && $fieldsSetByRule['action_required'] === null && !in_array('action_required', $sticky, true)) {
			$old = (int)(bool)($score['action_required'] ?? 0);
			$new = (int)(bool)$rule['set_action_required'];
			if ($old !== $new) {
				$score['action_required'] = $new;
				$changes['action_required'] = ['from' => $old, 'to' => $new];
			}
			$fieldsSetByRule['action_required'] = $ruleId;
		}
		if ($rule['set_label'] !== null && $fieldsSetByRule['label'] === null && !in_array('label', $sticky, true)) {
			$old = (string)($score['label'] ?? '');
			$new = (string)$rule['set_label'];
			if ($old !== $new) {
				$score['label'] = $new;
				$changes['label'] = ['from' => $old, 'to' => $new];
			}
			$fieldsSetByRule['label'] = $ruleId;
		}
		if (isset($rule['set_folder_segments']) && is_array($rule['set_folder_segments']) && $rule['set_folder_segments'] !== []
			&& $fieldsSetByRule['folder_segments'] === null
			&& !in_array('folder_segments', $sticky, true)) {
			$old = $score['folder_segments'] ?? null;
			$new = array_values($rule['set_folder_segments']);
			if ($old !== $new) {
				$score['folder_segments']   = $new;
				$changes['folder_segments'] = ['from' => $old, 'to' => $new];
			}
			$fieldsSetByRule['folder_segments'] = $ruleId;
		}
		return $changes;
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

	// Phase 9g (Marc 2026-05-20): Vorgaengermethode applySetFields() entfernt —
	// durch applySetFieldsOrthogonal() oben ersetzt, die per-Feld first-rule-wins
	// statt per-Regel first-match-wins implementiert.
}
