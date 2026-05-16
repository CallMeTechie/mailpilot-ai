<?php
declare(strict_types=1);

namespace MailPilot\Controllers\Settings;

use MailPilot\Controllers\BaseController;
use MailPilot\Http\Response;
use MailPilot\Repositories\VipRepository;

/**
 * /api/v1/settings/vip — list/add/delete VIP-Sender.
 * Ausgegliedert aus SettingsController (Phase 2 split).
 */
final class VipController extends BaseController
{
	public function listVip(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$items = $this->kernel->get(VipRepository::class)->listForUser($ctx['tenant_id'], $ctx['user_id']);
		Response::json(['items' => $items]);
	}

	public function addVip(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$email = (string)$this->requireField($body, 'email');
		$name  = isset($body['name']) ? (string)$body['name'] : null;
		$id = $this->kernel->get(VipRepository::class)->add($ctx['tenant_id'], $ctx['user_id'], $email, $name);
		Response::json(['id' => $id, 'email' => $email, 'name' => $name], 201);
	}

	public function deleteVip(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$this->kernel->get(VipRepository::class)->softDelete($ctx['tenant_id'], $ctx['user_id'], (string)$params['id']);
		Response::noContent();
	}
}
