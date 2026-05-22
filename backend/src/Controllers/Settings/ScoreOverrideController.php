<?php
declare(strict_types=1);

namespace MailPilot\Controllers\Settings;

use MailPilot\Controllers\BaseController;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\ScoreOverrideRepository;
use PDO;

/**
 * Phase 9b — /api/v1/settings/score-overrides.
 *
 * Liest die Klassifikations-Override-Regeln + erlaubt Toggle, Delete,
 * Create. KI-inferred-Regeln werden mit enabled=false angelegt — User
 * aktiviert sie hier.
 */
final class ScoreOverrideController extends BaseController
{
	public function listRules(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$items = $this->kernel->get(ScoreOverrideRepository::class)
			->listForUser($ctx['tenant_id'], $ctx['user_id']);
		Response::json(['items' => $items]);
	}

	/**
	 * Manuell eine Regel anlegen. Body akzeptiert match_* + set_*-Felder
	 * wie das Repository sie definiert.
	 */
	public function createRule(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		try {
			$id = $this->kernel->get(ScoreOverrideRepository::class)->create(
				$ctx['tenant_id'],
				$ctx['user_id'],
				$body + ['source' => 'user_manual'],
			);
		} catch (\InvalidArgumentException $e) {
			throw HttpException::badRequest('VALIDATION', $e->getMessage());
		}
		Response::json(['ok' => true, 'id' => $id], 201);
	}

	/**
	 * Toggle des enabled-Flag (POST /score-overrides/{id}/toggle).
	 * Spezial-Endpoint statt PATCH weil das die häufigste UI-Aktion ist
	 * (KI-Vorschlag aktivieren / Bestands-Regel deaktivieren).
	 */
	public function toggleRule(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$id = (string)($params['id'] ?? '');
		if ($id === '') {
			throw HttpException::badRequest('VALIDATION', 'Regel-ID fehlt');
		}
		$pdo = $this->kernel->get(PDO::class);
		$stmt = $pdo->prepare('UPDATE score_override_rules
			SET enabled = 1 - enabled
			WHERE id = :id AND tenant_id = :t AND user_id = :u AND deleted_at IS NULL');
		$stmt->execute([':id' => $id, ':t' => $ctx['tenant_id'], ':u' => $ctx['user_id']]);
		if ($stmt->rowCount() === 0) {
			throw HttpException::notFound('NOT_FOUND', 'Regel nicht gefunden');
		}
		Response::json(['ok' => true]);
	}

	public function deleteRule(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$id = (string)($params['id'] ?? '');
		if ($id === '') {
			throw HttpException::badRequest('VALIDATION', 'Regel-ID fehlt');
		}
		$ok = $this->kernel->get(ScoreOverrideRepository::class)
			->softDelete($ctx['tenant_id'], $ctx['user_id'], $id);
		if (!$ok) {
			throw HttpException::notFound('NOT_FOUND', 'Regel nicht gefunden');
		}
		Response::json(['ok' => true]);
	}

	/**
	 * Phase 9k (Marc 2026-05-20) — Liste konkurrierender Regel-Paare.
	 * Frontend zeigt das als Banner + Konflikt-Karten im Subtab „Regeln".
	 */
	public function listConflicts(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$conflicts = $this->kernel->get(ScoreOverrideRepository::class)
			->findConflicts($ctx['tenant_id'], $ctx['user_id']);
		Response::json(['items' => $conflicts, 'count' => count($conflicts)]);
	}

	/**
	 * Phase 9k — KI-Merge-Vorschlag fuer zwei kollidierende Regeln.
	 * Body: { rule_a_id, rule_b_id }
	 * Liefert: { can_merge: bool, merged?: <rule-shape>, reason?: string,
	 *           confidence: int, reasoning_summary: string }
	 *
	 * Konservativ: wenn die KI keinen sicheren Merge findet → can_merge=false.
	 * Aktualisiert NICHTS in der DB — der User akzeptiert separat per
	 * acceptMerge.
	 */
	public function mergeRules(array $params, array $body): void
	{
		$ctx     = $this->requireAuth();
		$aId     = trim((string)($body['rule_a_id'] ?? ''));
		$bId     = trim((string)($body['rule_b_id'] ?? ''));
		if ($aId === '' || $bId === '') {
			throw HttpException::badRequest('VALIDATION', 'rule_a_id + rule_b_id erforderlich');
		}
		$result = $this->kernel->get(\MailPilot\Services\RuleInferenceService::class)
			->mergeRules($ctx['tenant_id'], $ctx['user_id'], $aId, $bId);
		Response::json($result);
	}

	/**
	 * Phase 9k — User hat den KI-Merge-Vorschlag akzeptiert. Loescht beide
	 * Quell-Regeln (soft) und legt die gemergte Regel an.
	 * Body: { rule_a_id, rule_b_id, merged: <rule-shape mit set_*+match_*> }
	 */
	public function acceptMerge(array $params, array $body): void
	{
		$ctx     = $this->requireAuth();
		$aId     = trim((string)($body['rule_a_id'] ?? ''));
		$bId     = trim((string)($body['rule_b_id'] ?? ''));
		$merged  = is_array($body['merged'] ?? null) ? $body['merged'] : [];
		if ($aId === '' || $bId === '' || $merged === []) {
			throw HttpException::badRequest('VALIDATION', 'rule_a_id, rule_b_id, merged erforderlich');
		}
		$repo = $this->kernel->get(ScoreOverrideRepository::class);
		try {
			$newId = $repo->create($ctx['tenant_id'], $ctx['user_id'],
				$merged + ['source' => 'ki_inferred', 'enabled' => true]);
		} catch (\InvalidArgumentException $e) {
			throw HttpException::badRequest('VALIDATION', 'Merged-Regel ungueltig: ' . $e->getMessage());
		}
		// Quell-Regeln nach erfolgreichem Create soft-deleten.
		$repo->softDelete($ctx['tenant_id'], $ctx['user_id'], $aId);
		$repo->softDelete($ctx['tenant_id'], $ctx['user_id'], $bId);
		Response::json(['ok' => true, 'merged_id' => $newId, 'deleted' => [$aId, $bId]]);
	}

	/**
	 * Phase 9p (Marc 2026-05-22) — Auto-Cleanup: manuell triggern.
	 * Soft-Delete fuer Regeln gemaess Settings-Heuristik. Liefert die
	 * Anzahl der affected rows zurueck.
	 */
	public function cleanup(array $params, array $body): void
	{
		$this->requireAuth();
		$deleted = $this->kernel
			->get(\MailPilot\Services\ScoreOverrideCleanupService::class)
			->cleanup();
		Response::json(['ok' => true, 'deleted' => $deleted]);
	}

	/**
	 * Phase 9p — aktuelle Auto-Cleanup-Settings auslesen.
	 */
	public function getCleanupConfig(array $params, array $body): void
	{
		$this->requireAuth();
		$cfg = $this->kernel
			->get(\MailPilot\Services\ScoreOverrideCleanupService::class)
			->readConfig();
		Response::json($cfg);
	}

	/**
	 * Phase 9p — Auto-Cleanup-Settings teilweise updaten.
	 * Body: { enabled?: bool, delete_disabled?: bool, delete_unused_after_days?: int }
	 */
	public function patchCleanupConfig(array $params, array $body): void
	{
		$this->requireAuth();
		$patch = [];
		if (array_key_exists('enabled', $body)) {
			$patch['enabled'] = (bool)$body['enabled'];
		}
		if (array_key_exists('delete_disabled', $body)) {
			$patch['delete_disabled'] = (bool)$body['delete_disabled'];
		}
		if (array_key_exists('delete_unused_after_days', $body)) {
			$days = (int)$body['delete_unused_after_days'];
			if ($days < 1 || $days > 365) {
				throw HttpException::badRequest('VALIDATION', 'delete_unused_after_days muss zwischen 1 und 365 liegen');
			}
			$patch['delete_unused_after_days'] = $days;
		}
		if ($patch === []) {
			throw HttpException::badRequest('VALIDATION', 'Keine bekannten Patch-Felder');
		}
		$svc = $this->kernel->get(\MailPilot\Services\ScoreOverrideCleanupService::class);
		$svc->writeConfig($patch);
		Response::json(['ok' => true, 'config' => $svc->readConfig()]);
	}
}
