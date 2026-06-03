<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Repositories\SettingsRepository;
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
	/**
	 * Spec 2 (Marc 2026-06-03) — Per-Batch-LLM-Match-Budget. Wird vom
	 * MailScoringService zu Beginn eines scoreBatch-Runs via
	 * resetMatchBudget() initialisiert und pro ruleMatch->scoreMatch()-Call
	 * dekrementiert. null = noch nicht initialisiert (dann lazy aus Settings).
	 */
	private ?int $matchBudgetLeft = null;

	public function __construct(
		private readonly ScoreOverrideRepository $rules,
		private readonly LoggerInterface $logger,
		private readonly ?\MailPilot\Services\Scoring\MatchScorer $matchScorer = null,
		private readonly ?RuleMatchService $ruleMatch = null,
		private readonly ?SettingsRepository $settings = null,
		private readonly ?\MailPilot\Repositories\PendingActionRepository $pending = null,
	) {
	}

	/**
	 * Spec 2 — setzt das Per-Batch-LLM-Match-Budget zurück. Vom
	 * MailScoringService am Anfang jedes scoreBatch()-Runs aufgerufen, damit
	 * der LLM-Verfeinerungs-Pfad pro Batch gedeckelt ist (kein Kosten-Run-away
	 * bei grossen Inboxen). Click-Time-Aufrufer rufen es NICHT — dort ist
	 * $wasCacheHit=true, der LLM-Pfad also ohnehin aus.
	 */
	public function resetMatchBudget(): void
	{
		$this->matchBudgetLeft = $this->settings !== null
			? max(0, $this->settings->getInt('learning.match_per_batch_budget', 5))
			: 0;
	}

	/**
	 * @param array<string,mixed>     $mail          mit at least subject + from_email
	 * @param array<string,mixed>     $score         mutiert in-place
	 * @param array<string,mixed>|null $senderBucket optional, fuer sender_key-Match
	 * @return array{matched:bool, rule_id?:string, rule_ids?:list<string>, changes?:array<string,mixed>, suggested?:list<string>}
	 *
	 * Hinweis zum Rueckgabe-Shape:
	 *   matched  — true NUR wenn der Score tatsaechlich (Auto-Band) veraendert wurde.
	 *              Kann false sein, waehrend suggested nicht leer ist: das ist gewollt
	 *              und der dokumentierte Vertrag — Aufrufer duerfen sich darauf verlassen.
	 *   suggested — Liste der Regel-IDs, die einen Vorschlag (suggest-Band) erzeugt
	 *               haben — unabhaengig von matched. Nur gesetzt wenn nicht leer.
	 */
	public function apply(string $tenantId, string $userId, array $mail, array &$score, ?array $senderBucket = null, bool $wasCacheHit = false): array
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

		// Spec 2 (Marc 2026-06-03): Match-Score + Bänder. NEUER Zweig — laeuft
		// NUR wenn der MatchScorer injiziert ist (Lern-Loop verdrahtet). Alle
		// Alt-Aufrufer (matchScorer===null) fallen unveraendert in den binaeren
		// Legacy-Pfad unten. Der Legacy-Pfad bleibt byte-fuer-byte erhalten,
		// weil der bestehende ScoreOverrideServiceTest match_label/
		// match_priority_min/first-match pinnt.
		if ($this->matchScorer !== null) {
			return $this->applyWithBands(
				$tenantId, $userId, $mail, $score, $rules,
				$senderKey, $subject, $fromLocal, $label, $priority, $sticky, $wasCacheHit,
			);
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
	 * Spec 2 (Marc 2026-06-03) — Match-Score + Bänder. Pro enabled Regel:
	 *   1. HARTE Gates (NUR match_label + match_priority_min) — match_sender_key/
	 *      match_from_local/match_subject_regex sind hier KEINE Gates, sondern
	 *      gehen ausschliesslich als gewichtete Features in den MatchScorer
	 *      (sonst wuerde der exakte sender_key-Vergleich die Domain-/Fuzzy-Logik
	 *      des Scorers vorab wegfiltern).
	 *   2. score = MatchScorer->score(rule, mailFeatures), band = MatchScorer->band.
	 *   3. match_mode in {llm,hybrid} && !cacheHit && band==suggest && Budget übrig
	 *      → ruleMatch->scoreMatch() als Verfeinerung (null → deterministisch behalten).
	 *   4. auto    → applySetFieldsOrthogonal() (Sticky-Schutz unveraendert), Score-Write.
	 *      suggest → pending_actions-Vorschlag (kind=score_suggestion), KEIN Score-Write.
	 *      ignore  → nichts.
	 *
	 * @param list<array<string,mixed>> $rules
	 * @param array<string,mixed> $mail
	 * @param array<string,mixed> $score mutiert in-place (nur im auto-Band)
	 * @param list<string> $sticky
	 * @return array{matched:bool, rule_id?:string, rule_ids?:list<string>, changes?:array<string,mixed>, suggested?:list<string>}
	 *
	 * Hinweis zum Rueckgabe-Shape:
	 *   matched  — true NUR wenn der Score tatsaechlich (Auto-Band) veraendert wurde.
	 *              Kann false sein, waehrend suggested nicht leer ist: das ist gewollt
	 *              und der dokumentierte Vertrag — Aufrufer duerfen sich darauf verlassen.
	 *   suggested — Liste der Regel-IDs, die einen Vorschlag (suggest-Band) erzeugt
	 *               haben — unabhaengig von matched. Nur gesetzt wenn nicht leer.
	 */
	private function applyWithBands(
		string $tenantId,
		string $userId,
		array $mail,
		array &$score,
		array $rules,
		string $senderKey,
		string $subject,
		string $fromLocal,
		string $label,
		int $priority,
		array $sticky,
		bool $wasCacheHit,
	): array {
		$mode = $this->settings !== null
			? $this->settings->getString('learning.match_mode', 'deterministic')
			: 'deterministic';

		// MatchScorer liest sender_key/from_local/subject aus dem mailFeatures-Array.
		$mailFeatures = [
			'sender_key' => $senderKey,
			'from_local' => $fromLocal,
			'subject'    => $subject,
		];

		$allChanges      = [];
		$appliedRules    = [];
		$suggestedRules  = [];
		$fieldsSetByRule = [
			'priority'         => null,
			'action_required'  => null,
			'label'            => null,
			'folder_segments'  => null,
		];

		foreach ($rules as $rule) {
			// Harte Gates: NUR match_label + match_priority_min (siehe Methoden-Doc).
			if (!$this->matchesHardGates($rule, $label, $priority)) {
				continue;
			}

			$mScore = $this->matchScorer->score($rule, $mailFeatures);
			$band   = $this->matchScorer->band($mScore);

			// LLM-Verfeinerung NUR im llm/hybrid-Modus, bei Cache-Miss, im
			// suggest-Band und solange Per-Batch-Budget uebrig ist.
			if (($mode === 'llm' || $mode === 'hybrid')
				&& !$wasCacheHit
				&& $band === 'suggest'
				&& $this->ruleMatch !== null
				&& $this->consumeMatchBudget()) {
				$llmScore = $this->ruleMatch->scoreMatch($rule, $mail);
				if ($llmScore !== null) {
					$mScore = $llmScore;
					$band   = $this->matchScorer->band($mScore);
				}
				// null → deterministischen Score/Band behalten.
				// TODO(Task 10): rule_match.budget_exceeded_fallback-Log-Marker.
			}

			if ($band === 'auto') {
				$thisChanges = $this->applySetFieldsOrthogonal($rule, $score, $fieldsSetByRule, $sticky);
				if ($thisChanges !== []) {
					$ruleId = (string)$rule['id'];
					$this->rules->recordApply($tenantId, $ruleId);
					$this->logger->info('score_override.applied', [
						'rule_id' => $ruleId,
						'mail_id' => (string)($mail['id'] ?? ''),
						'score'   => $mScore,
						'changes' => $thisChanges,
					]);
					$allChanges    = array_merge($allChanges, $thisChanges);
					$appliedRules[] = $ruleId;
				}
			} elseif ($band === 'suggest') {
				$suggestionId = $this->createSuggestion($tenantId, $userId, $rule, $mail, $mScore, $mode);
				if ($suggestionId !== null) {
					$suggestedRules[] = (string)$rule['id'];
				}
			}
			// ignore → nichts.
		}

		if ($allChanges === [] && $suggestedRules === []) {
			return ['matched' => false];
		}
		$result = ['matched' => $allChanges !== []];
		if ($appliedRules !== []) {
			$result['rule_id']  = $appliedRules[0];
			$result['rule_ids'] = $appliedRules;
			$result['changes']  = $allChanges;
		}
		if ($suggestedRules !== []) {
			$result['suggested'] = $suggestedRules;
		}
		return $result;
	}

	/**
	 * Spec 2 — HARTE Gates fuer den Banden-Pfad. BEWUSST nur match_label +
	 * match_priority_min (nicht das alte matches(), das auch sender_key/
	 * from_local/subject_regex hart gated). Diese drei Felder gehen
	 * ausschliesslich als gewichtete Features in den MatchScorer.
	 *
	 * @param array<string,mixed> $rule
	 */
	private function matchesHardGates(array $rule, string $label, int $priority): bool
	{
		if ($rule['match_label'] !== null && $label !== $rule['match_label']) {
			return false;
		}
		if ($rule['match_priority_min'] !== null && $priority < (int)$rule['match_priority_min']) {
			return false;
		}
		return true;
	}

	/**
	 * Spec 2 — dekrementiert das Per-Batch-LLM-Match-Budget. Lazy-Init aus
	 * Settings, falls resetMatchBudget() (noch) nicht gerufen wurde — so
	 * funktioniert das Budget auch fuer Einzel-apply()-Aufrufe.
	 * @return bool true wenn ein Budget-Slot verbraucht werden konnte.
	 */
	private function consumeMatchBudget(): bool
	{
		if ($this->matchBudgetLeft === null) {
			$this->resetMatchBudget();
		}
		if (($this->matchBudgetLeft ?? 0) <= 0) {
			return false;
		}
		$this->matchBudgetLeft--;
		return true;
	}

	/**
	 * Spec 2 — legt einen score_suggestion-Vorschlag in pending_actions an.
	 * Best-effort: ohne PendingActionRepository (Alt-Aufrufer) No-op → null.
	 *
	 * @param array<string,mixed> $rule
	 * @param array<string,mixed> $mail
	 * @return string|null pending-action-id oder null
	 */
	private function createSuggestion(string $tenantId, string $userId, array $rule, array $mail, int $matchScore, string $mode): ?string
	{
		if ($this->pending === null) {
			return null;
		}
		$proposed = [];
		if ($rule['set_priority'] !== null)        { $proposed['priority']         = (int)$rule['set_priority']; }
		if ($rule['set_action_required'] !== null) { $proposed['action_required']  = (int)(bool)$rule['set_action_required']; }
		if ($rule['set_label'] !== null)           { $proposed['label']            = (string)$rule['set_label']; }
		if (isset($rule['set_folder_segments']) && is_array($rule['set_folder_segments']) && $rule['set_folder_segments'] !== []) {
			$proposed['folder_segments'] = array_values($rule['set_folder_segments']);
		}
		if ($proposed === []) {
			return null;  // nichts vorzuschlagen — keine leere pending-Row erzeugen
		}
		// Dedup-Guard (Spec 2, D4): kein zweiter Vorschlag fuer dieselbe (mail, rule).
		// Verhindert Duplikate bei Re-Scoring und Click-Time (wasCacheHit=true).
		$mailId = (string)($mail['id'] ?? '');
		$ruleId = (string)$rule['id'];
		if ($this->pending->hasOpenSuggestionFor($tenantId, $userId, $mailId, $ruleId)) {
			return null; // bereits ein offener Vorschlag fuer (mail, rule) — kein Duplikat
		}

		// created_under_mode muss ein gueltiger pending_actions-MODE sein
		// (off|suggest|auto) — der match_mode (deterministic|llm|hybrid) ist es
		// NICHT. Score-Suggestions entstehen konzeptionell im suggest-Modus.
		try {
			return $this->pending->create(
				$tenantId,
				$userId,
				'score_suggestion',
				[
					'rule_id'     => $ruleId,
					'mail_id'     => $mailId,
					'match_score' => $matchScore,
					'match_mode'  => $mode,
					'proposed'    => $proposed,
				],
				'suggest',
			);
		} catch (\Throwable $e) {
			$this->logger->warning('score_override.suggestion_failed', [
				'rule_id' => $ruleId,
				'mail_id' => $mailId,
				'err'     => $e->getMessage(),
			]);
			return null;
		}
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
