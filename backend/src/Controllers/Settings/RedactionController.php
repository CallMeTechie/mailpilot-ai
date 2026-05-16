<?php
declare(strict_types=1);

namespace MailPilot\Controllers\Settings;

use MailPilot\Controllers\BaseController;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\RedactionRepository;

/**
 * /api/v1/settings/redaction — list/add Redaction-Pattern.
 * Ausgegliedert aus SettingsController (Phase 2 split).
 */
final class RedactionController extends BaseController
{
	public function listRedaction(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$items = $this->kernel->get(RedactionRepository::class)->listForUser($ctx['tenant_id'], $ctx['user_id']);
		Response::json(['items' => $items]);
	}

	public function addRedaction(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pattern = (string)$this->requireField($body, 'pattern');
		if (@preg_match('#' . str_replace('#', '\#', $pattern) . '#', '') === false) {
			throw HttpException::badRequest('INVALID_REGEX', 'Ungültiges Regex-Muster');
		}
		$desc = isset($body['description']) ? (string)$body['description'] : null;
		$id = $this->kernel->get(RedactionRepository::class)->add($ctx['tenant_id'], $ctx['user_id'], $pattern, $desc);
		Response::json(['id' => $id, 'pattern' => $pattern, 'description' => $desc], 201);
	}
}
