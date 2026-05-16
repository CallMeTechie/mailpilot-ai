<?php
declare(strict_types=1);

namespace MailPilot\Controllers\Settings;

use MailPilot\Controllers\BaseController;
use MailPilot\Http\Response;
use MailPilot\Repositories\UserRepository;

/**
 * /api/v1/settings/user — getUser, updateUser.
 *
 * Ausgegliedert aus SettingsController (Phase 2 split). API-URLs sind
 * unveraendert, nur Router-Mapping zeigt jetzt auf diese Klasse.
 */
final class UserSettingsController extends BaseController
{
	public function getUser(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pdo = $this->kernel->get(\PDO::class);
		$stmt = $pdo->prepare(
			'SELECT id, email, display_name, language, timezone, briefing_hour
			 FROM users WHERE id = :id LIMIT 1'
		);
		$stmt->execute([':id' => $ctx['user_id']]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if ($row === false) {
			Response::json([]);
			return;
		}
		// project_keywords mitliefern fuer Settings-Load im Add-in
		$kw = $pdo->prepare('SELECT keyword FROM project_keywords
			WHERE user_id = :u AND deleted_at IS NULL ORDER BY keyword');
		$kw->execute([':u' => $ctx['user_id']]);
		$row['project_keywords'] = array_map('strval', $kw->fetchAll(\PDO::FETCH_COLUMN));
		Response::json($row);
	}

	public function updateUser(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$this->kernel->get(UserRepository::class)->updatePreferences(
			$ctx['user_id'],
			isset($body['language'])      ? (string)$body['language']       : null,
			isset($body['timezone'])      ? (string)$body['timezone']       : null,
			isset($body['briefing_hour']) ? (int)$body['briefing_hour']     : null,
		);

		if (isset($body['project_keywords']) && is_array($body['project_keywords'])) {
			$this->kernel->get(UserRepository::class)->replaceKeywords(
				$ctx['tenant_id'],
				$ctx['user_id'],
				array_map('strval', $body['project_keywords']),
			);
		}
		Response::noContent();
	}
}
