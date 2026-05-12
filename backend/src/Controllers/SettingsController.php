<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\RedactionRepository;
use MailPilot\Repositories\UserRepository;
use MailPilot\Repositories\VipRepository;

final class SettingsController extends BaseController
{
	public function getUser(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$stmt = $this->kernel->get(\PDO::class)->prepare(
			'SELECT id, email, display_name, language, timezone, briefing_hour
			 FROM users WHERE id = :id LIMIT 1'
		);
		$stmt->execute([':id' => $ctx['user_id']]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		Response::json($row === false ? [] : $row);
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
		// Validate regex by testing a compile
		if (@preg_match('#' . str_replace('#', '\#', $pattern) . '#', '') === false) {
			throw HttpException::badRequest('INVALID_REGEX', 'Ungültiges Regex-Muster');
		}
		$desc = isset($body['description']) ? (string)$body['description'] : null;
		$id = $this->kernel->get(RedactionRepository::class)->add($ctx['tenant_id'], $ctx['user_id'], $pattern, $desc);
		Response::json(['id' => $id, 'pattern' => $pattern, 'description' => $desc], 201);
	}

	public function listAutoSort(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$rules = $this->kernel->get(AutoSortRepository::class)
			->listForUser($ctx['tenant_id'], $ctx['user_id']);
		Response::json(['rules' => $rules]);
	}

	/**
	 * Body: {"rules": [{"label": "newsletter", "enabled": true, "folder_name": "MailPilot/Newsletter"}, …]}
	 * Accepts any subset; missing labels stay as-is.
	 */
	public function updateAutoSort(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$rules = $body['rules'] ?? null;
		if (!is_array($rules)) {
			throw HttpException::badRequest('VALIDATION', 'rules array fehlt');
		}
		$repo = $this->kernel->get(AutoSortRepository::class);
		$count = 0;
		foreach ($rules as $r) {
			if (!is_array($r) || !isset($r['label'])) continue;
			$label = (string)$r['label'];
			if (!in_array($label, AutoSortRepository::LABELS, true)) {
				throw HttpException::badRequest('VALIDATION', "Unbekanntes Label: {$label}");
			}
			$repo->upsert(
				$ctx['tenant_id'],
				$ctx['user_id'],
				$label,
				(bool)($r['enabled'] ?? false),
				(string)($r['folder_name'] ?? ''),
			);
			$count++;
		}
		Response::json([
			'updated' => $count,
			'rules'   => $repo->listForUser($ctx['tenant_id'], $ctx['user_id']),
		]);
	}
}
