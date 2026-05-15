<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Claude\ClaudeClient;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\PromptRepository;
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
 *   - backfill_range != 'future_only'  → Pending (Bulk-Approve regelt Move)
 *   - matches > backfill_max           → Pending
 *   - sonst                            → Regel anlegen, action=applied
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
		private readonly ClaudeClient            $claude,
		private readonly RedactionService        $redactor,
		private readonly SettingsRepository      $settings,
		private readonly UsageCounterRepository  $usage,
		private readonly AutoSortRepository      $rules,
		private readonly PendingActionRepository $pending,
		private readonly PromptRepository        $prompts,
		private readonly LoggerInterface         $logger,
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
		if ($parsed === null) {
			return ['action' => 'skipped', 'reason' => 'claude_invalid_response'];
		}

		// 6) Hash speichern, damit ein Doppelclick blockiert wird
		$this->stampHash($tenantId, $mailId, $hash);

		if (!($parsed['create_rule'] ?? false)) {
			return [
				'action'  => 'none',
				'reason'  => (string)($parsed['reasoning_summary'] ?? 'no_pattern'),
			];
		}

		// 7) Fuzzy-Merge gegen existing Rules
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
			$folder = $subLabel !== null
				? 'MailPilot/' . ucfirst($label) . '/' . $subLabel
				: 'MailPilot/' . ucfirst($label);
		}

		// 8) Match-Suche
		$signals     = is_array($parsed['match_signals'] ?? null) ? $parsed['match_signals'] : [];
		$range       = $this->settings->getString('rule_inference_backfill_range', 'last_30_days');
		$backfillCap = max(1, $this->settings->getInt('rule_inference_backfill_max', 100));
		$matches     = $this->findMatchingMails($tenantId, $userId, $signals, $range, $backfillCap + 1);

		// 9) Decision
		$confidence      = (int)($parsed['confidence'] ?? 0);
		$confidenceFloor = $this->settings->getInt('rule_inference_confidence_floor', 80);
		$moveMode        = $this->settings->getString('autosort_move_mode', 'suggest');
		$autoApplyOnly   = $confidence >= $confidenceFloor
			&& $moveMode === 'auto'
			&& $range === 'future_only';

		// Pending sobald irgendeine Schutzbedingung greift. DA-R1 Crit 2:
		// range=all immer Pending. DA-R2 Med 3: ein einziges Pending mit
		// affected_mail_ids[]-Array, nicht N Einzel-Items.
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
					'affected_subjects' => array_slice(array_column($matches, 'subject'), 0, 10),
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

		// 10) Auto-Apply (range=future_only, kein Backfill nötig)
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
	private function callClaude(array $vars): ?array
	{
		$active = $this->prompts->getActive('P-RULE-EXTRACT');
		$user   = $active['user_template'];
		foreach ($vars as $k => $v) {
			$user = str_replace('{{' . $k . '}}', (string)$v, $user);
		}
		try {
			$response = $this->claude->messages([
				'model'       => $active['model'],
				'max_tokens'  => $active['max_tokens'],
				'temperature' => $active['temperature'],
				'system'      => $active['system_prompt'],
				'messages'    => [['role' => 'user', 'content' => $user]],
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('rule_inference.claude_failed', ['err' => $e->getMessage()]);
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
	 * Sucht Mails die zu den match_signals der extrahierten Regel passen.
	 * Signal-Format: "from_domain:example.com", "subject_contains:wort",
	 * "sender_email:foo@bar.de". Mehrere Signale werden via OR verknüpft —
	 * der Approval-Schritt im Pending-Tab gibt User die finale Kontrolle.
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

		$where    = ['m.tenant_id = :t', 'mb.user_id = :u', 'm.deleted_at IS NULL'];
		$params   = [':t' => $tenantId, ':u' => $userId];
		$signalOr = [];
		$idx      = 0;
		foreach ($signals as $s) {
			if (!is_string($s) || !str_contains($s, ':')) continue;
			[$kind, $value] = explode(':', $s, 2);
			$kind  = trim($kind);
			$value = trim($value);
			if ($value === '') continue;
			$ph = ':sig' . $idx++;
			switch ($kind) {
				case 'from_domain':
					$signalOr[]  = "m.from_email LIKE {$ph}";
					$params[$ph] = '%@' . $value;
					break;
				case 'subject_contains':
					$signalOr[]  = "m.subject LIKE {$ph}";
					$params[$ph] = '%' . $value . '%';
					break;
				case 'sender_email':
					$signalOr[]  = "m.from_email = {$ph}";
					$params[$ph] = $value;
					break;
				default:
					$idx--;
			}
		}
		if ($signalOr === []) {
			return [];
		}
		$where[] = '(' . implode(' OR ', $signalOr) . ')';

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
		if ($confidence < $floor)     $reasons[] = "confidence<{$floor}";
		if ($moveMode !== 'auto')     $reasons[] = "mode={$moveMode}";
		if ($range !== 'future_only') $reasons[] = "range={$range}";
		if ($matchCount > $cap)       $reasons[] = "matches>{$cap}";
		return implode(',', $reasons);
	}
}
