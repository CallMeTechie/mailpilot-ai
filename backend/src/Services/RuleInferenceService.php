<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PromptRepository;
use MailPilot\Repositories\ScoreOverrideRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\UsageCounterRepository;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Sprint 6g — Auto-Rule-Inference aus Korrektur-Begründungen.
 *
 * Wird vom MailController::correctScore aufgerufen, sobald der User
 * eine Score-Korrektur MIT reasoning-Text einreicht. Extrahiert per
 * Haiku-Call (P-RULE-EXTRACT@1.0) ein AutoSort-Pattern und entscheidet:
 *
 *   - confidence < floor               → Pending
 *   - autosort_move_mode != 'auto'     → Pending
 *   - backfill_range == 'all'          → Pending (zu viele Mails, immer fragen)
 *   - matches > backfill_max           → Pending (Mass-Move-Schutz)
 *   - sonst                            → Regel anlegen, action=applied
 *
 * 2026-05-18 Marc-Bug-Fix: range=last_30_days fuehrte vorher auch zu Pending,
 * weil $autoApplyOnly zusaetzlich $range==='future_only' verlangt hat. Damit
 * landete praktisch jede Korrektur im Pending Tab, obwohl der User „Sofort
 * verschieben" gewaehlt hatte. Neue Logik: range != 'all' reicht — der
 * AutoSortService::backfillForMailbox-Worker holt die last_30_days-matches
 * im naechsten Sweep nach.
 *
 * Sync-Move wird bewusst NICHT gemacht — würde den HTTP-Request bei 30+
 * Mails über 30s halten. Pending-Approval triggert den existierenden
 * Bulk-Move-Pfad.
 *
 * Schutzschichten (DA-Runden 1 + 2):
 *   - Idempotenz via sha256(mailId+reasoning) in
 *     mail_score_corrections.rule_inference_hash → kein silent re-trigger.
 *   - Per-User-Quota via UsageCounterRepository::incrementOrFail.
 *   - PII-Redaction für subject (BUILTIN-Patterns), reasoning (BUILTIN +
 *     reasoning_pii_names) und from (Domain-only).
 *   - Sub-Label-Fuzzy-Merge gegen existierende Rules.
 */
final class RuleInferenceService
{
	public function __construct(
		private readonly PDO                     $db,
		private readonly \MailPilot\Llm\LlmRouter $router,
		private readonly RedactionService        $redactor,
		private readonly SettingsRepository      $settings,
		private readonly UsageCounterRepository  $usage,
		private readonly AutoSortRepository      $rules,
		private readonly PendingActionRepository $pending,
		private readonly PromptRepository        $prompts,
		private readonly LoggerInterface         $logger,
		// Phase 9b (Marc 2026-05-19): Score-Override-Inferenz. Optional,
		// damit aeltere Tests die nur Folder-Inferenz testen weiter laufen.
		private readonly ?ScoreOverrideRepository $scoreOverrides = null,
		// Task 8 (Spec 2): der frühere SenderResolver-Konstruktor-Parameter
		// (Phase 9h.2 Dedup-Check VOR dem Claude-Call) ist entfernt — der Skip
		// wurde durch Update-in-place (findUserDerivedSlot in den applyParsed-
		// Pfaden) ersetzt, der Resolver wird nicht mehr benötigt.
	) {}

	/**
	 * Haupteinstieg. Returnt eine struktierte Antwort die der Caller
	 * an's Add-in weiterleitet (für Toast + Status).
	 *
	 * @return array<string, mixed>
	 */
	public function infer(string $tenantId, string $userId, string $mailId, string $reasoning): array
	{
		if (!$this->settings->getBool('rule_inference_enabled', true)) {
			return ['action' => 'skipped', 'reason' => 'rule_inference_disabled'];
		}
		$reasoning = trim($reasoning);
		if ($reasoning === '') {
			return ['action' => 'skipped', 'reason' => 'empty_reasoning'];
		}

		// 1) Idempotenz — gleiche Korrektur ein zweites Mal → skipped.
		$hash = hash('sha256', $mailId . "\n" . $reasoning);
		if ($this->hashExists($tenantId, $hash)) {
			return ['action' => 'skipped', 'reason' => 'duplicate_submit', 'hash' => $hash];
		}

		// 2) Quota — wirft QuotaExceededException, vom Controller in 429 übersetzt.
		$dailyCap = $this->settings->getInt('rule_inference_max_per_user_per_day', 30);
		$this->usage->incrementOrFail($tenantId, $userId, 'rule_inference', $dailyCap);

		// 3) Mail-Kontext laden
		$ctx = $this->loadMailContext($tenantId, $mailId);
		if ($ctx === null) {
			return ['action' => 'skipped', 'reason' => 'mail_not_found'];
		}

		// 4) Redaction
		$nameList          = $this->getNameList();
		$redactedReasoning = $this->redactor->redactReasoning($reasoning, $nameList);
		$redactedSubject   = $this->redactor->redact((string)$ctx['subject']);
		$fromDomain        = $this->redactor->reduceFromToDomain((string)$ctx['from_email']);

		// 5) Claude-Call
		$parsed = $this->callClaude([
			'mail_label'       => (string)($ctx['label'] ?? 'auto'),
			'mail_sub_label'   => $ctx['sub_label'] !== null ? (string)$ctx['sub_label'] : 'null',
			'mail_from_domain' => $fromDomain,
			'mail_subject'     => $redactedSubject,
			'reasoning'        => $redactedReasoning,
		]);
		return $this->applyFolderRuleParsed($parsed, $tenantId, $userId, $mailId, $hash);
	}

	/**
	 * Phase 9h.3 — extrahiert aus infer(), damit inferAllFromCorrection()
	 * denselben Apply-Pfad nutzen kann (Fuzzy-Merge, Pending/Auto-Apply,
	 * Logging). $hash stammt aus dem Caller (Idempotenz-Stempel).
	 *
	 * @return array<string,mixed>
	 */
	private function applyFolderRuleParsed(?array $parsed, string $tenantId, string $userId, string $mailId, string $hash): array
	{
		if ($parsed === null) {
			return ['action' => 'skipped', 'reason' => 'claude_invalid_response'];
		}

		// Hash speichern, damit ein Doppelclick blockiert wird.
		$this->stampHash($tenantId, $mailId, $hash);

		if (!($parsed['create_rule'] ?? false)) {
			return [
				'action'  => 'none',
				'reason'  => (string)($parsed['reasoning_summary'] ?? 'no_pattern'),
			];
		}

		// Fuzzy-Merge gegen existing Rules.
		$label    = $this->normalizeLabel((string)($parsed['label'] ?? 'auto'));
		$subLabel = $this->normalizeSubLabel($parsed['sub_label'] ?? null);
		$folder   = trim((string)($parsed['folder_name'] ?? ''));
		if ($subLabel !== null) {
			$existing = $this->rules->findFuzzyMatchSubLabel($tenantId, $userId, $label, $subLabel);
			if ($existing !== null) {
				$this->logger->info('rule_inference.fuzzy_matched', [
					'proposed' => $parsed['sub_label'],
					'matched'  => $existing['sub_label'],
				]);
				$subLabel = (string)$existing['sub_label'];
				$folder   = (string)$existing['folder_name'];
			}
		}
		if ($folder === '') {
			// Phase 9m (Marc 2026-05-21): KEINE MailPilot/<Label>-Defaults mehr.
			// Wenn die KI keinen folder_name liefert und kein Fuzzy-Match
			// existiert, ist die Inferenz unvollstaendig — wir geben
			// reason="no_folder" zurueck statt zwanghaft MailPilot/Auto zu
			// erfinden (das hatte sich Marc explizit nicht gewuenscht).
			$this->logger->info('rule_inference.no_folder_in_parsed', [
				'label' => $label, 'sub_label' => $subLabel, 'mail_id' => $mailId,
			]);
			return [
				'action' => 'skipped',
				'reason' => 'no_folder_in_inference',
				'label'  => $label,
				'sub_label' => $subLabel,
			];
		}

		// Match-Suche + Decision (Auto-Apply vs. Pending).
		$signals     = is_array($parsed['match_signals'] ?? null) ? $parsed['match_signals'] : [];
		$range       = $this->settings->getString('rule_inference_backfill_range', 'last_30_days');
		$backfillCap = max(1, $this->settings->getInt('rule_inference_backfill_max', 100));
		$matches     = $this->findMatchingMails($tenantId, $userId, $signals, $range, $backfillCap + 1);

		$confidence      = (int)($parsed['confidence'] ?? 0);
		$confidenceFloor = $this->settings->getInt('rule_inference_confidence_floor', 80);
		$moveMode        = $this->settings->getString('autosort_move_mode', 'suggest');
		$autoApplyOnly   = $confidence >= $confidenceFloor
			&& $moveMode === 'auto'
			&& $range !== 'all'
			&& count($matches) <= $backfillCap;

		if (!$autoApplyOnly) {
			$pendingId = $this->pending->create(
				$tenantId, $userId, 'rule_suggestion',
				[
					'mail_id'           => $mailId,
					'label'             => $label,
					'sub_label'         => $subLabel,
					'folder_name'       => $folder,
					'match_signals'     => array_values(array_map('strval', $signals)),
					'affected_mail_ids' => array_column($matches, 'id'),
					'affected_subjects' => array_column($matches, 'subject'),
					'confidence'        => $confidence,
					'reasoning_summary' => (string)($parsed['reasoning_summary'] ?? ''),
				],
				$moveMode,
			);
			$this->logger->info('rule_inference.pending_created', [
				'pending_id'     => $pendingId,
				'confidence'     => $confidence,
				'affected_count' => count($matches),
				'force_reason'   => $this->describeForceReason($confidence, $confidenceFloor, $moveMode, $range, count($matches), $backfillCap),
			]);
			return [
				'action'         => 'pending',
				'pending_id'     => $pendingId,
				'label'          => $label,
				'sub_label'      => $subLabel,
				'folder_name'    => $folder,
				'affected_count' => count($matches),
				'confidence'     => $confidence,
			];
		}

		$this->rules->upsert($tenantId, $userId, $label, $subLabel, true, $folder);
		$this->logger->info('rule_inference.applied', [
			'label'      => $label,
			'sub_label'  => $subLabel,
			'folder'     => $folder,
			'confidence' => $confidence,
		]);
		return [
			'action'      => 'applied',
			'label'       => $label,
			'sub_label'   => $subLabel,
			'folder_name' => $folder,
			'confidence'  => $confidence,
		];
	}

	/** @return array<string,mixed>|null */
	private function loadMailContext(string $tenantId, string $mailId): ?array
	{
		$stmt = $this->db->prepare('SELECT m.subject, m.from_email, m.from_name,
				s.label, s.sub_label
			FROM mails m
			LEFT JOIN mail_scores s ON s.mail_id = m.id AND s.tenant_id = m.tenant_id
			WHERE m.id = :m AND m.tenant_id = :t AND m.deleted_at IS NULL
			LIMIT 1');
		$stmt->execute([':m' => $mailId, ':t' => $tenantId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : $row;
	}

	/**
	 * Task 8 (Spec 2) — löst die bestehende mail_score_corrections.id über den
	 * UNIQUE-Key uq_correction_per_mail (tenant_id, mail_id) auf. NICHT über
	 * rule_inference_hash (NULLABLE, von CorrectionRepository::record() nie
	 * befüllt) und NICHT über die von record() zurückgegebene UUID (bei
	 * ON DUPLICATE KEY UPDATE eine frische, weggeworfene id ≠ Row-id).
	 */
	private function resolveCorrectionId(string $tenantId, string $mailId): ?string
	{
		$stmt = $this->db->prepare('SELECT id FROM mail_score_corrections
			WHERE tenant_id = :t AND mail_id = :m LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':m' => $mailId]);
		$id = $stmt->fetchColumn();
		return $id === false ? null : (string)$id;
	}

	private function hashExists(string $tenantId, string $hash): bool
	{
		$stmt = $this->db->prepare('SELECT 1 FROM mail_score_corrections
			WHERE tenant_id = :t AND rule_inference_hash = :h LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':h' => $hash]);
		return (bool)$stmt->fetchColumn();
	}

	private function stampHash(string $tenantId, string $mailId, string $hash): void
	{
		// Setzt den Hash auf der Korrektur-Row, sofern existent. Falls
		// nicht: ignorieren (Caller hat noch nicht persistiert). Idempotenz
		// greift dann beim zweiten Submit über den hashExists-Check.
		$stmt = $this->db->prepare('UPDATE mail_score_corrections
			SET rule_inference_hash = :h
			WHERE tenant_id = :t AND mail_id = :m
			  AND rule_inference_hash IS NULL');
		try {
			$stmt->execute([':t' => $tenantId, ':m' => $mailId, ':h' => $hash]);
		} catch (\PDOException $e) {
			// uq_correction_rule_hash collision = race auf identische
			// Hash-Anlage. Idempotenz-Garantie bleibt erhalten.
			if ($e->getCode() !== '23000') {
				throw $e;
			}
		}
	}

	/** @return array<string,mixed>|null */
	private function callClaude(array $vars, string $promptKey = 'P-RULE-EXTRACT'): ?array
	{
		$payload = $this->buildClaudePayload($vars, $promptKey);
		try {
			$response = $this->routeInferencePayload($payload);
		} catch (\Throwable $e) {
			$this->logger->warning('rule_inference.llm_failed', ['err' => $e->getMessage()]);
			return null;
		}
		return $this->parseClaudeResponse($response);
	}

	/**
	 * Schickt eine buildClaudePayload()-Payload über den LlmRouter (Rolle
	 * 'inference', modelHint='' → Modell/Effort aus llm_models). Liefert das
	 * Anthropic-Response-Shape zurück, das parseClaudeResponse() erwartet.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function routeInferencePayload(array $payload): array
	{
		$resp = $this->router->complete($this->payloadToNormalizedRequest($payload), 'inference');
		return ['content' => [['type' => 'text', 'text' => $resp->content]]];
	}

	/**
	 * Baut aus einer buildClaudePayload()-Array einen NormalizedRequest
	 * für die inference-Rolle (modelHint='' → Modell/Effort aus llm_models).
	 *
	 * @param array<string,mixed> $payload
	 */
	private function payloadToNormalizedRequest(array $payload): \MailPilot\Llm\NormalizedRequest
	{
		return new \MailPilot\Llm\NormalizedRequest(
			systemPrompt:   (string)($payload['system'] ?? ''),
			messages:       $payload['messages'] ?? [],
			maxTokens:      (int)($payload['max_tokens'] ?? 1024),
			temperature:    (float)($payload['temperature'] ?? 0.1),
			modelHint:      '',
			responseFormat: 'json_object',
			cacheSegments:  [],
		);
	}

	/**
	 * Phase 9h.3 (Marc 2026-05-21) — baut die Anthropic-Payload separat,
	 * sodass callClaudeBatch() mehrere parallel feuern kann.
	 *
	 * @param array<string,mixed> $vars
	 * @return array<string,mixed>
	 */
	private function buildClaudePayload(array $vars, string $promptKey): array
	{
		$active = $this->prompts->getActive($promptKey);
		$user   = $active['user_template'];
		foreach ($vars as $k => $v) {
			$user = str_replace('{{' . $k . '}}', (string)$v, $user);
		}
		return [
			'model'       => $active['model'],
			'max_tokens'  => $active['max_tokens'],
			'temperature' => $active['temperature'],
			'system'      => $active['system_prompt'],
			'messages'    => [['role' => 'user', 'content' => $user]],
		];
	}

	/** @return array<string,mixed>|null */
	private function parseClaudeResponse(mixed $response): ?array
	{
		if (!is_array($response)) {
			return null;
		}
		$text = ClaudeClient::extractText($response);
		// Defensiv: Markdown-Fences strippen, falls Claude doch eine
		// einbaut trotz System-Prompt-Anweisung.
		$text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text)) ?? $text;
		try {
			$parsed = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			$this->logger->warning('rule_inference.invalid_json', [
				'excerpt' => substr($text, 0, 200),
			]);
			return null;
		}
		return is_array($parsed) ? $parsed : null;
	}

	/**
	 * Phase 9h.3 — Batch-Variante via LlmRouter::completeBatch (Rolle
	 * 'inference', modelHint='' → Modell/Effort aus llm_models). Sendet alle
	 * Requests über die Inference-Chain; returnt ein Array gleicher
	 * Reihenfolge mit jeweils parsed Response oder null bei Fehler.
	 *
	 * @param list<array{vars:array<string,mixed>, promptKey:string}> $specs
	 * @return list<array<string,mixed>|null>
	 */
	private function callClaudeBatch(array $specs): array
	{
		if ($specs === []) {
			return [];
		}
		$requests = [];
		foreach ($specs as $spec) {
			$p = $this->buildClaudePayload($spec['vars'], $spec['promptKey']);
			$requests[] = $this->payloadToNormalizedRequest($p);
		}
		$responses = $this->router->completeBatch($requests, 'inference');
		$results = [];
		foreach ($responses as $resp) {
			$results[] = $resp === null
				? null
				: $this->parseClaudeResponse(['content' => [['type' => 'text', 'text' => $resp->content]]]);
		}
		return $results;
	}

	/**
	 * Sucht Mails die zu den match_signals der extrahierten Regel passen.
	 * Signal-Format: "from_domain:example.com", "subject_contains:wort",
	 * "sender_email:foo@bar.de". Mehrere Signale werden via AND verknüpft —
	 * eine Mail muss ALLE Signale erfüllen. (2026-05-16-Fix: vorher OR,
	 * was bei [from_domain:github.com, subject_contains:gatecontrol] alle
	 * Github-Mails matched, nicht nur die für GateControl.)
	 *
	 * Range schränkt nach received_at ein (last_30_days/all/future_only).
	 * future_only liefert immer leere Liste (kein Backfill nötig).
	 *
	 * @param list<mixed> $signals
	 * @return list<array{id:string, subject:string}>
	 */
	private function findMatchingMails(string $tenantId, string $userId, array $signals, string $range, int $limit): array
	{
		if ($range === 'future_only' || $signals === []) {
			return [];
		}

		$where     = ['m.tenant_id = :t', 'mb.user_id = :u', 'm.deleted_at IS NULL'];
		$params    = [':t' => $tenantId, ':u' => $userId];
		$signalAnd = [];
		$idx       = 0;
		foreach ($signals as $s) {
			if (!is_string($s) || !str_contains($s, ':')) continue;
			[$kind, $value] = explode(':', $s, 2);
			$kind  = trim($kind);
			$value = trim($value);
			if ($value === '') continue;
			$ph = ':sig' . $idx++;
			switch ($kind) {
				case 'from_domain':
					$signalAnd[] = "m.from_email LIKE {$ph}";
					$params[$ph] = '%@' . $value;
					break;
				case 'subject_contains':
					$signalAnd[] = "m.subject LIKE {$ph}";
					$params[$ph] = '%' . $value . '%';
					break;
				case 'sender_email':
					$signalAnd[] = "m.from_email = {$ph}";
					$params[$ph] = $value;
					break;
				default:
					$idx--;
			}
		}
		if ($signalAnd === []) {
			return [];
		}
		// AND-Verknüpfung: Mail muss ALLE Signale erfüllen.
		foreach ($signalAnd as $clause) {
			$where[] = $clause;
		}

		if ($range === 'last_30_days') {
			$where[] = 'm.received_at >= (UTC_TIMESTAMP(3) - INTERVAL 30 DAY)';
		}

		$sql = 'SELECT m.id, m.subject FROM mails m
			INNER JOIN mailboxes mb ON mb.id = m.mailbox_id
			WHERE ' . implode(' AND ', $where) . '
			ORDER BY m.received_at DESC
			LIMIT :lim';
		$stmt = $this->db->prepare($sql);
		foreach ($params as $k => $v) $stmt->bindValue($k, $v);
		$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/** @return list<string> */
	private function getNameList(): array
	{
		$raw = $this->settings->getString('reasoning_pii_names', '[]');
		try {
			$decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}
		if (!is_array($decoded)) return [];
		$out = [];
		foreach ($decoded as $v) {
			if (is_string($v) && trim($v) !== '') $out[] = $v;
		}
		return $out;
	}

	private function normalizeLabel(string $label): string
	{
		$label = strtolower(trim($label));
		return in_array($label, AutoSortRepository::LABELS, true) ? $label : 'auto';
	}

	private function normalizeSubLabel(mixed $raw): ?string
	{
		if (!is_string($raw)) return null;
		$v = trim($raw);
		if ($v === '' || strtolower($v) === 'null') return null;
		$v = preg_replace('/[^\w\-äöüÄÖÜß ]+/u', '', $v) ?? $v;
		return trim($v) !== '' ? trim($v) : null;
	}

	private function describeForceReason(
		int $confidence, int $floor, string $moveMode, string $range, int $matchCount, int $cap,
	): string {
		$reasons = [];
		if ($confidence < $floor) $reasons[] = "confidence<{$floor}";
		if ($moveMode !== 'auto') $reasons[] = "mode={$moveMode}";
		if ($range === 'all')     $reasons[] = "range=all";
		if ($matchCount > $cap)   $reasons[] = "matches>{$cap}";
		return implode(',', $reasons);
	}

	// ========================================================================
	// Phase 9b — Score-Override-Inferenz (parallel zu infer() Folder-Inferenz)
	// ========================================================================

	/**
	 * Aus einer Score-Korrektur eine generelle Klassifikations-Regel ableiten.
	 *
	 * Wird vom MailController::correctScore aufgerufen, wenn der User eine
	 * reasoning-Begruendung mitgegeben hat. Schreibt die abgeleitete Regel
	 * mit source='ki_inferred' + enabled=false an — der User muss sie im
	 * Settings-Subtab explizit aktivieren, kein silent-apply.
	 *
	 * @return array<string,mixed>  shape: {action:'created'|'skipped'|'error', rule_id?:string, reason?:string, confidence?:int}
	 */
	public function inferScoreRule(
		string $tenantId,
		string $userId,
		string $mailId,
		array $correctedScore,
		array $originalScore,
		string $reasoning,
	): array {
		if ($this->scoreOverrides === null) {
			return ['action' => 'skipped', 'reason' => 'no_repo_injected'];
		}
		if (!$this->settings->getBool('rule_inference_enabled', true)) {
			return ['action' => 'skipped', 'reason' => 'rule_inference_disabled'];
		}
		$reasoning = trim($reasoning);
		if ($reasoning === '') {
			return ['action' => 'skipped', 'reason' => 'empty_reasoning'];
		}

		// Mail-Kontext laden — wir brauchen from_email + subject fuer den Prompt.
		$ctx = $this->loadMailContext($tenantId, $mailId);
		if ($ctx === null) {
			return ['action' => 'skipped', 'reason' => 'mail_not_found'];
		}
		$fromDomain = $this->redactor->reduceFromToDomain((string)$ctx['from_email']);
		$subject    = $this->redactor->redact((string)$ctx['subject']);
		$reasoningR = $this->redactor->redactReasoning($reasoning, $this->getNameList());

		// Task 8 (Spec 2): der frühere hasSimilarPriorityRule-Dedup-Skip ist
		// ENTFERNT — applyScoreRuleParsed macht jetzt Slot-Lookup + Update-in-
		// place, sodass eine wiederholte Korrektur die bestehende user-derived
		// Regel aktualisiert (Confidence/Breite) statt geskippt zu werden.

		// Quota wie bei infer() — gemeinsamer rule_inference-Counter.
		$dailyCap = $this->settings->getInt('rule_inference_max_per_user_per_day', 30);
		$this->usage->incrementOrFail($tenantId, $userId, 'rule_inference', $dailyCap);

		$parsed = $this->callClaude([
			'from_domain'        => $fromDomain,
			'subject'            => $subject,
			'original_label'    => (string)($originalScore['label'] ?? '?'),
			'original_priority'  => (string)($originalScore['priority'] ?? '?'),
			'original_action'    => !empty($originalScore['action_required']) ? 'true' : 'false',
			'corrected_label'    => (string)($correctedScore['label'] ?? '?'),
			'corrected_priority' => (string)($correctedScore['priority'] ?? '?'),
			'corrected_action'   => !empty($correctedScore['action_required']) ? 'true' : 'false',
			'reasoning'          => $reasoningR,
		], 'P-SCORE-RULE-EXTRACT');

		return $this->applyScoreRuleParsed($parsed, $tenantId, $userId, $mailId);
	}

	/**
	 * Phase 9h.3 — extrahiert aus inferScoreRule, damit
	 * inferAllFromCorrection() denselben Apply-Pfad nutzen kann.
	 *
	 * @return array<string,mixed>
	 */
	private function applyScoreRuleParsed(?array $parsed, string $tenantId, string $userId, string $mailId): array
	{
		if ($this->scoreOverrides === null) {
			return ['action' => 'skipped', 'reason' => 'no_repo_injected'];
		}
		if ($parsed === null) {
			return ['action' => 'error', 'reason' => 'claude_invalid_response'];
		}
		if (!($parsed['create_rule'] ?? false)) {
			return [
				'action'     => 'skipped',
				'reason'     => (string)($parsed['reasoning_summary'] ?? 'no_pattern'),
				'confidence' => (int)($parsed['confidence'] ?? 0),
			];
		}

		$confidence    = (int)($parsed['confidence'] ?? 0);
		$autoThreshold = $this->settings->getInt('score_rule_auto_enable_threshold', 85);
		$autoEnabled   = $confidence >= $autoThreshold;

		// Task 8 (Spec 2): Confidence → Match-Breite. Hohe Confidence → breit
		// (nur match_sender_key, Domain-weit). Niedrige Confidence → eng
		// (zusätzlich match_from_local), damit eine unsichere Regel weniger
		// Mails überschreibt. Wir nutzen score_rule_auto_enable_threshold als
		// Trennlinie — dieselbe Schwelle, die auch über auto-enable entscheidet.
		$matchFromLocal = ($confidence < $autoThreshold)
			? ($parsed['match_from_local'] ?? null)
			: null;

		// Set-Feld bestimmen (für Slot-Lookup): priority hat Vorrang, dann
		// label/action_required. Wir leiten EINE user-derived Regel pro
		// (sender_key, Set-Feld) ab — Update-in-place statt Sibling-Anlage.
		$senderKey = $this->normalizedSenderKey($parsed['match_sender_key'] ?? null);
		$slotField = $this->scoreSetField($parsed);

		// Bestehende mail_score_corrections.id als Herkunfts-Marker.
		$correctionId = $this->resolveCorrectionId($tenantId, $mailId);

		// Slot-Lookup: existiert schon eine user-derived Regel für
		// (sender_key, Set-Feld)? Dann UPDATE statt CREATE.
		$slot = ($senderKey !== null && $slotField !== null)
			? $this->scoreOverrides->findUserDerivedSlot($tenantId, $userId, $senderKey, $slotField)
			: null;

		if ($slot !== null) {
			$updateFields = [
				'set_priority'        => $parsed['set_priority']        ?? null,
				'set_action_required' => $parsed['set_action_required'] ?? null,
				'set_label'           => $parsed['set_label']           ?? null,
				'match_subject_regex' => $parsed['match_subject_regex'] ?? null,
				'match_from_local'    => $matchFromLocal,
				'enabled'             => $autoEnabled ? 1 : ($slot['enabled'] ?? 1),
			];
			// Nur tatsächlich vorhandene Set-/Match-Werte überschreiben — null
			// in updateFields würde sonst ein gesetztes Feld leeren.
			$updateFields = array_filter($updateFields, static fn($v) => $v !== null);
			try {
				$this->scoreOverrides->updateFields($tenantId, (string)$slot['id'], $updateFields);
			} catch (\InvalidArgumentException $e) {
				$this->logger->info('rule_inference.score_rule_invalid', [
					'reason' => $e->getMessage(), 'parsed' => $parsed,
				]);
				return ['action' => 'error', 'reason' => $e->getMessage()];
			}
			$this->logger->info('rule_inference.score_rule_updated', [
				'rule_id' => (string)$slot['id'], 'confidence' => $confidence, 'mail_id' => $mailId,
			]);
			return [
				'action'            => 'updated',
				'rule_id'           => (string)$slot['id'],
				'confidence'        => $confidence,
				'auto_enabled'      => $autoEnabled,
				'reasoning_summary' => (string)($parsed['reasoning_summary'] ?? ''),
			];
		}

		try {
			$ruleId = $this->scoreOverrides->create($tenantId, $userId, [
				'match_sender_key'    => $parsed['match_sender_key']    ?? null,
				'match_subject_regex' => $parsed['match_subject_regex'] ?? null,
				'match_from_local'    => $matchFromLocal,
				'match_label'         => $parsed['match_label']         ?? null,
				'match_priority_min'  => $parsed['match_priority_min']  ?? null,
				'set_priority'        => $parsed['set_priority']        ?? null,
				'set_action_required' => $parsed['set_action_required'] ?? null,
				'set_label'           => $parsed['set_label']           ?? null,
				'enabled'             => $autoEnabled,
				'source'              => 'ki_inferred',
				'origin_correction_id' => $correctionId,
			]);
		} catch (\InvalidArgumentException $e) {
			$this->logger->info('rule_inference.score_rule_invalid', [
				'reason' => $e->getMessage(),
				'parsed' => $parsed,
			]);
			return ['action' => 'error', 'reason' => $e->getMessage()];
		}

		$this->enforceSoftCap($tenantId, $userId);

		$this->logger->info('rule_inference.score_rule_created', [
			'rule_id'    => $ruleId,
			'confidence' => $confidence,
			'auto_enabled' => $autoEnabled,
			'mail_id'    => $mailId,
		]);
		return [
			'action'            => 'created',
			'rule_id'           => $ruleId,
			'confidence'        => $confidence,
			'auto_enabled'      => $autoEnabled,
			'reasoning_summary' => (string)($parsed['reasoning_summary'] ?? ''),
		];
	}

	/**
	 * Task 8 — bestimmt das primäre Set-Feld einer Score-Regel für den
	 * Slot-Lookup (eine user-derived Regel pro sender_key + Set-Feld).
	 *
	 * @param array<string,mixed> $parsed
	 */
	private function scoreSetField(array $parsed): ?string
	{
		if (($parsed['set_priority'] ?? null) !== null)        { return 'priority'; }
		if (($parsed['set_action_required'] ?? null) !== null) { return 'action_required'; }
		if (($parsed['set_label'] ?? null) !== null)           { return 'label'; }
		return null;
	}

	private function normalizedSenderKey(mixed $raw): ?string
	{
		if (!is_string($raw)) { return null; }
		$s = strtolower(trim($raw));
		return $s === '' ? null : substr($s, 0, 64);
	}

	/**
	 * Task 8 (b) — Soft-Cap: über learning.score_rules_soft_cap aktive
	 * user-derived Regeln → die am längsten nicht angewandte deaktivieren
	 * (kein Löschen). Best-effort; Fehler dürfen den Inferenz-Pfad nicht killen.
	 */
	private function enforceSoftCap(string $tenantId, string $userId): void
	{
		if ($this->scoreOverrides === null) { return; }
		try {
			$cap = $this->settings->getInt('learning.score_rules_soft_cap', 200);
			if ($cap < 1) { return; }
			if ($this->scoreOverrides->countUserDerived($tenantId, $userId) > $cap) {
				$disabledId = $this->scoreOverrides->disableLeastRecentlyUsed($tenantId, $userId);
				if ($disabledId !== null) {
					$this->logger->info('score_override.lru_disabled', [
						'rule_id' => $disabledId, 'soft_cap' => $cap,
					]);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('score_override.soft_cap_failed', ['err' => $e->getMessage()]);
		}
	}

	// ========================================================================
	// Phase 9e — Topic-Regel-Inferenz (Marc 2026-05-19)
	// ========================================================================

	/**
	 * Aus einer Topic-Korrektur (User hat Folder-Segments explizit gesetzt)
	 * eine generelle Regel ableiten: „Wenn Subject zu diesem Sender so aussieht,
	 * dann nach <topic>". Wird immer ausgefuehrt, auch ohne reasoning — bei
	 * fehlendem reasoning erwartet der Prompt eine niedrigere Confidence.
	 *
	 * @param list<string> $correctedSegments z.B. ["Ebay","Bewertung"]
	 * @return array<string,mixed> {action, rule_id?, confidence?, auto_enabled?, reasoning_summary?}
	 */
	public function inferTopicRule(
		string $tenantId,
		string $userId,
		string $mailId,
		array $correctedSegments,
		string $reasoning,
	): array {
		if ($this->scoreOverrides === null) {
			return ['action' => 'skipped', 'reason' => 'no_repo_injected'];
		}
		if (!$this->settings->getBool('rule_inference_enabled', true)) {
			return ['action' => 'skipped', 'reason' => 'rule_inference_disabled'];
		}
		if ($correctedSegments === []) {
			return ['action' => 'skipped', 'reason' => 'empty_segments'];
		}

		$ctx = $this->loadMailContext($tenantId, $mailId);
		if ($ctx === null) {
			return ['action' => 'skipped', 'reason' => 'mail_not_found'];
		}
		$fromDomain     = $this->redactor->reduceFromToDomain((string)$ctx['from_email']);
		$subject        = $this->redactor->redact((string)$ctx['subject']);
		$reasoningClean = trim($reasoning);
		$reasoningR     = $reasoningClean === ''
			? '(keine Begruendung)'
			: $this->redactor->redactReasoning($reasoningClean, $this->getNameList());
		$topic          = (string)end($correctedSegments);

		// Task 8 (Spec 2): der frühere hasSimilarTopicRule-Dedup-Skip ist
		// ENTFERNT — applyTopicRuleParsed macht Slot-Lookup + Update-in-place.

		$dailyCap = $this->settings->getInt('rule_inference_max_per_user_per_day', 30);
		$this->usage->incrementOrFail($tenantId, $userId, 'rule_inference', $dailyCap);

		$parsed = $this->callClaude([
			'from_domain'         => $fromDomain,
			'subject'             => $subject,
			'corrected_topic'     => $topic,
			'corrected_segments'  => json_encode($correctedSegments, JSON_UNESCAPED_UNICODE),
			'reasoning'           => $reasoningR,
		], 'P-TOPIC-RULE-EXTRACT');

		return $this->applyTopicRuleParsed($parsed, $tenantId, $userId, $mailId, $correctedSegments, $topic);
	}

	/**
	 * Phase 9h.3 — extrahiert aus inferTopicRule, damit
	 * inferAllFromCorrection() denselben Apply-Pfad nutzen kann.
	 *
	 * @param list<string> $correctedSegments
	 * @return array<string,mixed>
	 */
	private function applyTopicRuleParsed(?array $parsed, string $tenantId, string $userId, string $mailId, array $correctedSegments, string $topic): array
	{
		if ($this->scoreOverrides === null) {
			return ['action' => 'skipped', 'reason' => 'no_repo_injected'];
		}
		if ($parsed === null) {
			return ['action' => 'error', 'reason' => 'claude_invalid_response'];
		}
		if (!($parsed['create_rule'] ?? false)) {
			return [
				'action'     => 'skipped',
				'reason'     => (string)($parsed['reasoning_summary'] ?? 'no_pattern'),
				'confidence' => (int)($parsed['confidence'] ?? 0),
			];
		}

		$confidence    = (int)($parsed['confidence'] ?? 0);
		$autoThreshold = $this->settings->getInt('score_rule_auto_enable_threshold', 85);
		$autoEnabled   = $confidence >= $autoThreshold;

		// Task 8: Confidence → Match-Breite (niedrig → match_from_local verengt).
		$matchFromLocal = ($confidence < $autoThreshold)
			? ($parsed['match_from_local'] ?? null)
			: null;

		$senderKey    = $this->normalizedSenderKey($parsed['match_sender_key'] ?? null);
		$correctionId = $this->resolveCorrectionId($tenantId, $mailId);

		// Task 8: Slot-Lookup für (sender_key, folder_segments) — Update-in-place
		// statt Sibling. Ersetzt den früheren hasSimilarTopicRule-Skip.
		$slot = ($senderKey !== null)
			? $this->scoreOverrides->findUserDerivedSlot($tenantId, $userId, $senderKey, 'folder_segments')
			: null;

		if ($slot !== null) {
			$updateFields = [
				'set_folder_segments' => $correctedSegments,
				'match_subject_regex' => $parsed['match_subject_regex'] ?? null,
				'match_from_local'    => $matchFromLocal,
				'enabled'             => $autoEnabled ? 1 : ($slot['enabled'] ?? 1),
			];
			$updateFields = array_filter($updateFields, static fn($v) => $v !== null);
			try {
				$this->scoreOverrides->updateFields($tenantId, (string)$slot['id'], $updateFields);
			} catch (\InvalidArgumentException $e) {
				$this->logger->info('rule_inference.topic_rule_invalid', [
					'reason' => $e->getMessage(), 'parsed' => $parsed,
				]);
				return ['action' => 'error', 'reason' => $e->getMessage()];
			}
			$this->logger->info('rule_inference.topic_rule_updated', [
				'rule_id' => (string)$slot['id'], 'confidence' => $confidence,
				'mail_id' => $mailId, 'topic' => $topic,
			]);
			return [
				'action'            => 'updated',
				'rule_id'           => (string)$slot['id'],
				'confidence'        => $confidence,
				'auto_enabled'      => $autoEnabled,
				'reasoning_summary' => (string)($parsed['reasoning_summary'] ?? ''),
			];
		}

		try {
			$ruleId = $this->scoreOverrides->create($tenantId, $userId, [
				'match_sender_key'    => $parsed['match_sender_key']    ?? null,
				'match_subject_regex' => $parsed['match_subject_regex'] ?? null,
				'match_from_local'    => $matchFromLocal,
				'match_label'         => $parsed['match_label']         ?? null,
				'set_folder_segments' => $correctedSegments,
				'enabled'             => $autoEnabled,
				'source'              => 'ki_inferred',
				'origin_correction_id' => $correctionId,
			]);
		} catch (\InvalidArgumentException $e) {
			$this->logger->info('rule_inference.topic_rule_invalid', [
				'reason' => $e->getMessage(),
				'parsed' => $parsed,
			]);
			return ['action' => 'error', 'reason' => $e->getMessage()];
		}

		$this->enforceSoftCap($tenantId, $userId);

		$this->logger->info('rule_inference.topic_rule_created', [
			'rule_id'      => $ruleId,
			'confidence'   => $confidence,
			'auto_enabled' => $autoEnabled,
			'mail_id'      => $mailId,
			'topic'        => $topic,
		]);
		return [
			'action'            => 'created',
			'rule_id'           => $ruleId,
			'confidence'        => $confidence,
			'auto_enabled'      => $autoEnabled,
			'reasoning_summary' => (string)($parsed['reasoning_summary'] ?? ''),
		];
	}

	// ========================================================================
	// Phase 9k — KI-Merge fuer kollidierende Regeln (Marc 2026-05-20)
	// ========================================================================

	/**
	 * Versucht zwei kollidierende Override-Regeln zu mergen. Konservativ:
	 * wenn die KI keinen sicheren Merge findet, return can_merge=false.
	 * Schreibt NICHTS in die DB — Caller (Controller acceptMerge) entscheidet.
	 *
	 * @return array<string,mixed>  {can_merge, confidence, reasoning_summary, merged?, reason?}
	 */
	public function mergeRules(string $tenantId, string $userId, string $ruleAId, string $ruleBId): array
	{
		if ($this->scoreOverrides === null) {
			return ['can_merge' => false, 'reason' => 'no_repo_injected'];
		}
		$all = $this->scoreOverrides->listForUser($tenantId, $userId);
		$a = null; $b = null;
		foreach ($all as $r) {
			if ($r['id'] === $ruleAId) $a = $r;
			if ($r['id'] === $ruleBId) $b = $r;
		}
		if ($a === null || $b === null) {
			return ['can_merge' => false, 'reason' => 'rule_not_found'];
		}

		$dailyCap = $this->settings->getInt('rule_inference_max_per_user_per_day', 30);
		$this->usage->incrementOrFail($tenantId, $userId, 'rule_inference', $dailyCap);

		$conflicting = [];
		foreach (['set_priority' => 'priority', 'set_action_required' => 'action_required',
		          'set_label' => 'label', 'set_folder_segments' => 'folder_segments'] as $field => $short) {
			$av = $a[$field] ?? null;
			$bv = $b[$field] ?? null;
			if ($av !== null && $bv !== null && $av !== $bv) $conflicting[] = $short;
		}

		$parsed = $this->callClaude([
			'rule_a_json'         => json_encode($a, JSON_UNESCAPED_UNICODE),
			'rule_b_json'         => json_encode($b, JSON_UNESCAPED_UNICODE),
			'conflicting_fields'  => implode(',', $conflicting),
		], 'P-RULE-MERGE');

		if ($parsed === null) {
			return ['can_merge' => false, 'reason' => 'claude_invalid_response'];
		}
		$canMerge   = (bool)($parsed['can_merge'] ?? false);
		$confidence = (int)($parsed['confidence'] ?? 0);
		$summary    = (string)($parsed['reasoning_summary'] ?? '');
		$merged     = is_array($parsed['merged'] ?? null) ? $parsed['merged'] : null;

		$this->logger->info('rule_inference.merge_evaluated', [
			'rule_a' => $ruleAId, 'rule_b' => $ruleBId,
			'can_merge' => $canMerge, 'confidence' => $confidence,
		]);

		return [
			'can_merge'         => $canMerge,
			'confidence'        => $confidence,
			'reasoning_summary' => $summary,
			'merged'            => $canMerge ? $merged : null,
		];
	}

	// ========================================================================
	// Phase 9h.3 — parallele Inferenz aller drei Regel-Typen (Marc 2026-05-21)
	// ========================================================================

	/**
	 * Aus einer correctScore-Korrektur ALLE drei Regel-Typen (Folder /
	 * ScoreOverride / Topic) ableiten. Ersetzt drei sequenzielle infer*-
	 * Aufrufe (~7.5s total) durch EIN curl_multi (~2.5s total).
	 *
	 * Returnt ein Map mit den drei Result-Arrays:
	 *   { folder: array|null, score_rule: array|null, topic_rule: array|null }
	 * Slots koennen null sein wenn die Inferenz nicht relevant war (z.B.
	 * topic_rule wenn topicSegments leer, oder folder wenn reasoning leer).
	 *
	 * Caller (MailController::correctScore) hat schon entschieden ob es
	 * reasoning + topic gibt — wir bauen dynamisch 0..3 Payloads.
	 *
	 * @param array<string,mixed>      $correctedScore  {label,priority,action_required}
	 * @param array<string,mixed>      $originalScore   {label,priority,action_required}
	 * @param list<string>             $topicSegments
	 * @return array{folder:?array<string,mixed>, score_rule:?array<string,mixed>, topic_rule:?array<string,mixed>}
	 */
	public function inferAllFromCorrection(
		string $tenantId,
		string $userId,
		string $mailId,
		array $correctedScore,
		array $originalScore,
		string $reasoning,
		array $topicSegments,
	): array {
		$result = ['folder' => null, 'score_rule' => null, 'topic_rule' => null];

		if (!$this->settings->getBool('rule_inference_enabled', true)) {
			return $result;
		}
		$reasoning = trim($reasoning);
		$hasReasoning = $reasoning !== '';
		$hasTopic     = $topicSegments !== [];

		// Wenn weder reasoning noch topic da ist, gibt's nichts zu inferieren.
		if (!$hasReasoning && !$hasTopic) {
			return $result;
		}

		// Mail-Context 1× laden — alle drei Inferenzen brauchen from_email/subject.
		$ctx = $this->loadMailContext($tenantId, $mailId);
		if ($ctx === null) {
			$err = ['action' => 'skipped', 'reason' => 'mail_not_found'];
			if ($hasReasoning) {
				$result['folder']     = $err;
				$result['score_rule'] = $err;
			}
			if ($hasTopic) {
				$result['topic_rule'] = $err;
			}
			return $result;
		}

		$fromDomain = $this->redactor->reduceFromToDomain((string)$ctx['from_email']);
		$subject    = $this->redactor->redact((string)$ctx['subject']);
		$nameList   = $this->getNameList();

		// Quota — gemeinsamer Counter. Wir koennten 3× incrementOrFail rufen,
		// aber gemeinsamer Increment ist semantisch korrekter: eine User-
		// Korrektur = ein Inferenz-Event.
		$dailyCap = $this->settings->getInt('rule_inference_max_per_user_per_day', 30);
		try {
			$this->usage->incrementOrFail($tenantId, $userId, 'rule_inference', $dailyCap);
		} catch (QuotaExceededException $e) {
			$throw = ['action' => 'skipped', 'reason' => 'quota_exceeded'];
			if ($hasReasoning) {
				$result['folder']     = $throw;
				$result['score_rule'] = $throw;
			}
			if ($hasTopic) {
				$result['topic_rule'] = $throw;
			}
			throw $e; // Caller (Controller) übersetzt in 429.
		}

		// Pre-Apply-Hashes / Dedup-Checks.
		$folderHash = null;
		$folderSkipReason = null;
		if ($hasReasoning) {
			$folderHash = hash('sha256', $mailId . "\n" . $reasoning);
			if ($this->hashExists($tenantId, $folderHash)) {
				$folderSkipReason = ['action' => 'skipped', 'reason' => 'duplicate_submit', 'hash' => $folderHash];
			}
		}

		// Task 8 (Spec 2): die früheren hasSimilarPriorityRule/hasSimilarTopicRule-
		// Dedup-Skips (Phase 9h.2) sind ENTFERNT. Statt eine vorhandene Regel mit
		// 'duplicate_rule_exists' zu überspringen, läuft die Inferenz jetzt durch
		// und applyScoreRuleParsed/applyTopicRuleParsed machen einen Slot-Lookup
		// (findUserDerivedSlot) + Update-in-place. So bekommt EINE user-derived
		// Regel pro (sender_key, Set-Feld) einen Confidence-/Breite-Bump statt
		// einer zweiten, redundanten Zeile.

		// Payloads bauen — eine pro Slot der ausgefuehrt wird.
		// $specs ist [{vars, promptKey, slot:'folder'|'score_rule'|'topic_rule'}, ...]
		$specs = [];

		if ($hasReasoning && $folderSkipReason === null) {
			$redactedReasoning = $this->redactor->redactReasoning($reasoning, $nameList);
			$specs[] = [
				'slot'      => 'folder',
				'promptKey' => 'P-RULE-EXTRACT',
				'vars'      => [
					'mail_label'       => (string)($ctx['label'] ?? 'auto'),
					'mail_sub_label'   => $ctx['sub_label'] !== null ? (string)$ctx['sub_label'] : 'null',
					'mail_from_domain' => $fromDomain,
					'mail_subject'     => $subject,
					'reasoning'        => $redactedReasoning,
				],
			];
		}

		if ($hasReasoning && $this->scoreOverrides !== null) {
			$reasoningR = $this->redactor->redactReasoning($reasoning, $nameList);
			$specs[] = [
				'slot'      => 'score_rule',
				'promptKey' => 'P-SCORE-RULE-EXTRACT',
				'vars'      => [
					'from_domain'        => $fromDomain,
					'subject'            => $subject,
					'original_label'     => (string)($originalScore['label'] ?? '?'),
					'original_priority'  => (string)($originalScore['priority'] ?? '?'),
					'original_action'    => !empty($originalScore['action_required']) ? 'true' : 'false',
					'corrected_label'    => (string)($correctedScore['label'] ?? '?'),
					'corrected_priority' => (string)($correctedScore['priority'] ?? '?'),
					'corrected_action'   => !empty($correctedScore['action_required']) ? 'true' : 'false',
					'reasoning'          => $reasoningR,
				],
			];
		}

		if ($hasTopic && $this->scoreOverrides !== null) {
			$reasoningClean = trim($reasoning);
			$reasoningR = $reasoningClean === ''
				? '(keine Begruendung)'
				: $this->redactor->redactReasoning($reasoningClean, $nameList);
			$topicLast = (string)end($topicSegments);
			$specs[] = [
				'slot'      => 'topic_rule',
				'promptKey' => 'P-TOPIC-RULE-EXTRACT',
				'vars'      => [
					'from_domain'         => $fromDomain,
					'subject'             => $subject,
					'corrected_topic'     => $topicLast,
					'corrected_segments'  => json_encode($topicSegments, JSON_UNESCAPED_UNICODE),
					'reasoning'           => $reasoningR,
				],
			];
		}

		// Pre-skip-Antworten direkt eintragen (nur noch der folder-Idempotenz-Hash;
		// die score/topic-Dedup-Skips wurden durch Update-in-place ersetzt).
		if ($folderSkipReason !== null)  { $result['folder']     = $folderSkipReason; }

		// Wenn keine Specs uebrig sind (alles dedup-skipped), kein Claude-Call.
		if ($specs === []) {
			return $result;
		}

		// Parallel-Call — DAS ist der eigentliche 9h.3-Gewinn.
		$batchSpecs = array_map(fn($s) => ['vars' => $s['vars'], 'promptKey' => $s['promptKey']], $specs);
		$parsedList = $this->callClaudeBatch($batchSpecs);

		// Apply pro Slot.
		foreach ($specs as $i => $spec) {
			$parsed = $parsedList[$i] ?? null;
			switch ($spec['slot']) {
				case 'folder':
					$result['folder'] = $this->applyFolderRuleParsed($parsed, $tenantId, $userId, $mailId, $folderHash ?? '');
					break;
				case 'score_rule':
					$result['score_rule'] = $this->applyScoreRuleParsed($parsed, $tenantId, $userId, $mailId);
					break;
				case 'topic_rule':
					$topicLast = (string)end($topicSegments);
					$result['topic_rule'] = $this->applyTopicRuleParsed($parsed, $tenantId, $userId, $mailId, $topicSegments, $topicLast);
					break;
			}
		}

		return $result;
	}
}
