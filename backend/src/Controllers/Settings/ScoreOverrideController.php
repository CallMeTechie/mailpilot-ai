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
}
