<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\RedactionRepository;
use MailPilot\Repositories\SubLabelRepository;
use MailPilot\Repositories\UserRepository;
use MailPilot\Repositories\VipRepository;
use MailPilot\Services\AutoSortService;
use MailPilot\Services\TokenService;

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
	 * Body: {"rules": [
	 *   {"label": "newsletter", "enabled": true, "folder_name": "MailPilot/Newsletter"},
	 *   {"label": "auto", "sub_label": "GitHub CI", "enabled": true, "folder_name": "MailPilot/Auto/CI"},
	 *   …
	 * ]}
	 *
	 * Each rule is keyed by (label, sub_label). sub_label null/missing
	 * means the catch-all row. Accepts any subset; rules not in the
	 * payload stay as-is.
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
			$subLabel = isset($r['sub_label']) && $r['sub_label'] !== null && $r['sub_label'] !== ''
				? (string)$r['sub_label']
				: null;
			$repo->upsert(
				$ctx['tenant_id'],
				$ctx['user_id'],
				$label,
				$subLabel,
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

	/**
	 * Apply currently-enabled auto-sort rules to mails that are already in
	 * the DB (scored from a previous sync but not moved because the rule
	 * was disabled at the time, or because they predate Phase 3 entirely).
	 *
	 * Body: {"limit": 50}  // optional, default 50, max 200
	 *
	 * Synchronous and paginated. The add-in calls in a loop until
	 * has_more = false. Each chunk is one HTTP round-trip + N Graph
	 * moves so a 30 s page is plenty even on slow links.
	 */
	public function applyAutoSortNow(array $params, array $body): void
	{
		$ctx   = $this->requireAuth();
		$limit = max(1, min(200, (int)($body['limit'] ?? 50)));

		$rules = $this->kernel->get(AutoSortRepository::class)
			->listForUser($ctx['tenant_id'], $ctx['user_id']);
		$enabledRules = array_values(array_filter(
			$rules,
			static fn(array $r): bool => (bool)$r['enabled'],
		));
		if ($enabledRules === []) {
			Response::json(['processed' => 0, 'moved' => 0, 'protected' => 0, 'errors' => 0, 'has_more' => false]);
			return;
		}

		$mailboxes = $this->kernel->get(MailboxRepository::class)
			->findByUser($ctx['tenant_id'], $ctx['user_id']);
		if ($mailboxes === []) {
			throw HttpException::preconditionFailed('MAILBOX_NOT_CONNECTED', 'Kein Postfach verbunden');
		}

		// Candidate mails: scored, with at least one enabled rule that
		// could match. The EXISTS subquery does exact (label, sub_label)
		// OR catch-all (sub_label IS NULL) matching — same precedence as
		// AutoSortService::backfillForMailbox. high-priority direct/action
		// is filtered out here so the returned count == real workload.
		$pdo = $this->kernel->get(\PDO::class);
		$sql = "SELECT m.*, s.label AS score_label, s.sub_label AS score_sub_label,
				s.priority AS score_priority, s.action_required AS score_ar
			FROM mails m
			INNER JOIN mail_scores s ON s.mail_id = m.id
			WHERE m.tenant_id = ?
			  AND m.deleted_at IS NULL
			  AND NOT (s.label IN ('direct','action') AND s.priority >= 4)
			  AND EXISTS (
				SELECT 1 FROM auto_sort_rules r
				WHERE r.tenant_id = m.tenant_id
				  AND r.user_id   = ?
				  AND r.label     = s.label
				  AND r.enabled   = 1
				  AND (r.sub_label = s.sub_label OR r.sub_label IS NULL)
			  )
			ORDER BY m.received_at DESC
			LIMIT " . ($limit + 1);
		$stmt = $pdo->prepare($sql);
		$stmt->execute([$ctx['tenant_id'], $ctx['user_id']]);
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		$hasMore = count($rows) > $limit;
		$rows    = array_slice($rows, 0, $limit);

		// Per-mailbox token cache so we don't refresh on every iteration.
		$tokens = $this->kernel->get(TokenService::class);
		$autoSort = $this->kernel->get(AutoSortService::class);
		$tokenByMailbox = [];

		$moved = 0; $protected = 0; $errors = 0;
		foreach ($rows as $row) {
			$mailboxId = (string)$row['mailbox_id'];
			if (!isset($tokenByMailbox[$mailboxId])) {
				$mb = null;
				foreach ($mailboxes as $cand) {
					if ((string)$cand['id'] === $mailboxId) { $mb = $cand; break; }
				}
				if ($mb === null) { $errors++; continue; }
				$tokenByMailbox[$mailboxId] = $tokens->ensureFreshAccessToken($mb);
			}
			$score = [
				'label'           => $row['score_label'],
				'sub_label'       => $row['score_sub_label'] ?? null,
				'priority'        => $row['score_priority'],
				'action_required' => $row['score_ar'],
			];
			$res = $autoSort->applyToScoredMail(
				$tokenByMailbox[$mailboxId],
				$ctx['tenant_id'],
				$ctx['user_id'],
				$row,
				$score,
			);
			if (!empty($res['moved']))                          $moved++;
			elseif (($res['reason'] ?? '') === 'high_priority_protected') $protected++;
			elseif (($res['reason'] ?? '') === 'graph_error')   $errors++;
		}

		Response::json([
			'processed' => count($rows),
			'moved'     => $moved,
			'protected' => $protected,
			'errors'    => $errors,
			'has_more'  => $hasMore,
		]);
	}

	/**
	 * Drops a single sub-label rule. The catch-all (sub_label = NULL)
	 * rows are not deletable via this endpoint — disable them via
	 * updateAutoSort instead, listForUser then materialises a fresh
	 * disabled default in their place.
	 *
	 * URL: DELETE /api/v1/settings/auto-sort/sub/{label}/{name}
	 *      {name} is URL-encoded by the client.
	 */
	public function deleteAutoSortSub(array $params, array $body): void
	{
		$ctx   = $this->requireAuth();
		$label = (string)($params['label'] ?? '');
		$name  = trim(urldecode((string)($params['name'] ?? '')));

		if (!in_array($label, AutoSortRepository::LABELS, true)) {
			throw HttpException::badRequest('VALIDATION', "Unbekanntes Label: {$label}");
		}
		if ($name === '') {
			throw HttpException::badRequest('VALIDATION', 'Sub-Label-Name erforderlich');
		}

		$ok = $this->kernel->get(AutoSortRepository::class)
			->delete($ctx['tenant_id'], $ctx['user_id'], $label, $name);
		if (!$ok) {
			throw HttpException::notFound('NOT_FOUND', 'Sub-Regel nicht gefunden');
		}
		Response::json(['ok' => true]);
	}

	public function listSubLabels(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$items = $this->kernel->get(SubLabelRepository::class)
			->listForUser($ctx['tenant_id'], $ctx['user_id']);
		Response::json(['items' => $items]);
	}

	public function addSubLabel(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$parent = (string)($body['parent'] ?? '');
		$name   = (string)($body['name']   ?? '');
		if ($name === '' || $parent === '') {
			throw HttpException::badRequest('VALIDATION', 'parent + name required');
		}
		try {
			$id = $this->kernel->get(SubLabelRepository::class)->create(
				$ctx['tenant_id'],
				$ctx['user_id'],
				$parent,
				$name,
				isset($body['description']) ? (string)$body['description'] : null,
				isset($body['color']) ? (string)$body['color'] : null,
			);
		} catch (\InvalidArgumentException $e) {
			throw HttpException::badRequest('VALIDATION', $e->getMessage());
		}
		Response::json(['id' => $id, 'parent' => $parent, 'name' => $name], 201);
	}

	public function updateSubLabel(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$id   = (string)($params['id'] ?? '');
		$name = (string)($body['name'] ?? '');
		try {
			$ok = $this->kernel->get(SubLabelRepository::class)->update(
				$ctx['tenant_id'],
				$ctx['user_id'],
				$id,
				$name,
				isset($body['description']) ? (string)$body['description'] : null,
				isset($body['color']) ? (string)$body['color'] : null,
			);
		} catch (\InvalidArgumentException $e) {
			throw HttpException::badRequest('VALIDATION', $e->getMessage());
		}
		if (!$ok) {
			throw HttpException::notFound('NOT_FOUND', 'Sub-Label nicht gefunden');
		}
		Response::json(['ok' => true]);
	}

	public function deleteSubLabel(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$id  = (string)($params['id'] ?? '');
		$ok  = $this->kernel->get(SubLabelRepository::class)
			->delete($ctx['tenant_id'], $ctx['user_id'], $id);
		if (!$ok) {
			throw HttpException::notFound('NOT_FOUND', 'Sub-Label nicht gefunden');
		}
		Response::json(['ok' => true]);
	}
}
