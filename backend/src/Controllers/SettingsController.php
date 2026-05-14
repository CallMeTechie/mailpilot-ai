<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\RedactionRepository;
use MailPilot\Repositories\SettingsRepository;
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
		// project_keywords mitliefern, damit das Add-in die Liste beim
		// Settings-Load anzeigen kann (Marc-Bug 2026-05-14: Add-in hatte
		// HTML-Button + Input ohne Lade-Pfad).
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
		$ctx     = $this->requireAuth();
		$limit   = max(1, min(200, (int)($body['limit'] ?? 50)));
		$afterId = isset($body['after_id']) && $body['after_id'] !== null && $body['after_id'] !== ''
			? (string)$body['after_id']
			: null;

		$rules = $this->kernel->get(AutoSortRepository::class)
			->listForUser($ctx['tenant_id'], $ctx['user_id']);
		$enabledRules = array_values(array_filter(
			$rules,
			static fn(array $r): bool => (bool)$r['enabled'],
		));
		if ($enabledRules === []) {
			Response::json([
				'processed' => 0, 'moved' => 0, 'protected' => 0, 'errors' => 0,
				'has_more' => false, 'next_after_id' => null,
				'total' => $afterId === null ? 0 : null,
			]);
			return;
		}

		$mailboxes = $this->kernel->get(MailboxRepository::class)
			->findByUser($ctx['tenant_id'], $ctx['user_id']);
		if ($mailboxes === []) {
			throw HttpException::preconditionFailed('MAILBOX_NOT_CONNECTED', 'Kein Postfach verbunden');
		}

		// Candidate mails: scored, with at least one enabled rule that
		// could match. Cursor-Pagination via m.id (UUID-stable order)
		// damit auch failed Moves bei jedem Loop-Schritt überschritten
		// werden. Retry-Cap (auto_sort_attempts < 3) hält Mails draußen,
		// die schon dreimal gescheitert sind — siehe AutoSortService.
		$pdo = $this->kernel->get(\PDO::class);

		$retryCap = max(1, $this->kernel->get(\MailPilot\Repositories\SettingsRepository::class)
			->getInt('autosort.retry_cap', 3));
		$where = "m.tenant_id = :t
			AND m.deleted_at IS NULL
			AND NOT (s.label IN ('direct','action') AND s.priority >= 4)
			AND s.auto_sorted_at IS NULL
			AND s.auto_sort_attempts < {$retryCap}
			AND EXISTS (
				SELECT 1 FROM auto_sort_rules r
				WHERE r.tenant_id = m.tenant_id
				  AND r.user_id   = :u
				  AND r.label     = s.label
				  AND r.enabled   = 1
				  AND (r.sub_label = s.sub_label OR r.sub_label IS NULL)
			)";
		$afterClause = $afterId !== null ? ' AND m.id > :after_id' : '';

		// Total nur beim ersten Call (after_id IS NULL) — Frontend braucht
		// das einmalig für die Progress-Bar, danach trackt es selbst.
		$total = null;
		if ($afterId === null) {
			$countStmt = $pdo->prepare("SELECT COUNT(*) FROM mails m
				INNER JOIN mail_scores s ON s.mail_id = m.id
				WHERE {$where}");
			$countStmt->execute([':t' => $ctx['tenant_id'], ':u' => $ctx['user_id']]);
			$total = (int)$countStmt->fetchColumn();
		}

		$sql = "SELECT m.*, s.label AS score_label, s.sub_label AS score_sub_label,
				s.priority AS score_priority, s.action_required AS score_ar
			FROM mails m
			INNER JOIN mail_scores s ON s.mail_id = m.id
			WHERE {$where}{$afterClause}
			ORDER BY m.id ASC
			LIMIT " . ($limit + 1);
		$stmt = $pdo->prepare($sql);
		$stmt->bindValue(':t', $ctx['tenant_id']);
		$stmt->bindValue(':u', $ctx['user_id']);
		if ($afterId !== null) {
			$stmt->bindValue(':after_id', $afterId);
		}
		$stmt->execute();
		$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

		$hasMore = count($rows) > $limit;
		$rows    = array_slice($rows, 0, $limit);
		$nextAfterId = $rows !== [] ? (string)end($rows)['id'] : $afterId;

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
			'processed'     => count($rows),
			'moved'         => $moved,
			'protected'     => $protected,
			'errors'        => $errors,
			'has_more'      => $hasMore,
			'next_after_id' => $nextAfterId,
			'total'         => $total,
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

	/**
	 * "Mails neu klassifizieren" — User-getriggertes Re-Score-All.
	 *
	 * Wenn der User merkt, dass Klassifizierungen veraltet sind (z. B.
	 * weil er gerade ein neues Sub-Label angelegt hat, das per
	 * Sub-Label-CRUD-Trigger zwar den claude_cache geleert, aber die
	 * bereits gespeicherten mail_scores nicht angefasst hat).
	 *
	 * Schritte:
	 *   1. claude_cache fuer den Tenant atomar wischen
	 *   2. Alle non-user-corrected mail_scores als "preset_deprecated"
	 *      markieren. findUnscoredForMailbox() greift dieses Flag und
	 *      schickt die Mails beim naechsten Sync neu durch Claude.
	 *   3. Benutzer-Korrekturen bleiben unangetastet (user_corrected_at IS NOT NULL).
	 *
	 * Der eigentliche Sync wird vom Add-in im Anschluss via
	 * POST /sync getriggert — so vermeiden wir Code-Duplikation
	 * mit SyncController::trigger.
	 */
	public function rescoreAll(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pdo = $this->kernel->get(\PDO::class);

		$pdo->beginTransaction();
		try {
			$cachePurged = $this->kernel->get(CacheRepository::class)
				->purgeForTenant($ctx['tenant_id']);

			// Mark non-user-corrected scores als veraltet damit der Worker
			// sie beim naechsten Sync neu durch Claude schickt. Plus:
			// auto_sorted_at + auto_sort_attempts zuruecksetzen, damit
			// frueher failed Moves (z.B. wegen Token-Rotation) wieder
			// eine Chance auf einen sauberen Move bekommen.
			$stmt = $pdo->prepare('UPDATE mail_scores
				SET model = "preset_deprecated",
				    auto_sorted_at = NULL,
				    auto_sort_attempts = 0
				WHERE tenant_id = :t AND user_corrected_at IS NULL');
			$stmt->execute([':t' => $ctx['tenant_id']]);
			$scoresMarked = $stmt->rowCount();

			$pdo->commit();
		} catch (\Throwable $e) {
			if ($pdo->inTransaction()) $pdo->rollBack();
			throw $e;
		}

		Response::json([
			'ok'             => true,
			'cache_purged'   => $cachePurged,
			'scores_marked'  => $scoresMarked,
			// Next step for the client: POST /api/v1/sync to actually
			// re-run the classifier on the marked mails.
			'next_action'    => 'sync',
		]);
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

		// Pool-Pool-Override-Fix: bisher gecachte Scores wurden ohne diesen
		// Sub-Label berechnet und liefern Cache-Hits weiterhin sub_label=NULL.
		// Atomar wischen, damit die naechsten Score-Calls Claude wieder fragen.
		$purged = $this->kernel->get(CacheRepository::class)
			->purgeForTenant($ctx['tenant_id']);

		Response::json(['id' => $id, 'parent' => $parent, 'name' => $name, 'cache_purged' => $purged], 201);
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

		// Name- oder Description-Aenderung beeinflusst Claudes Sub-Label-Wahl
		// → Cache wischen, sonst liefern Hits weiterhin den alten Wert.
		$purged = $this->kernel->get(CacheRepository::class)
			->purgeForTenant($ctx['tenant_id']);

		Response::json(['ok' => true, 'cache_purged' => $purged]);
	}

	/**
	 * Deletes a sub-label and atomically clears any AutoSort sub-rule
	 * that pointed at it. Without the cascade those rules would linger
	 * as zombies: their (label, sub_label) pair would never match a
	 * scored mail again because the sub-label is no longer in the
	 * user's pool that Claude sees.
	 *
	 * Returns the count of removed rules so the UI can show a
	 * "X + N rules deleted" toast.
	 */
	public function deleteSubLabel(array $params, array $body): void
	{
		$ctx  = $this->requireAuth();
		$id   = (string)($params['id'] ?? '');
		$subs = $this->kernel->get(SubLabelRepository::class);

		$row = $subs->findById($ctx['tenant_id'], $ctx['user_id'], $id);
		if ($row === null) {
			throw HttpException::notFound('NOT_FOUND', 'Sub-Label nicht gefunden');
		}

		$autoSort = $this->kernel->get(AutoSortRepository::class);
		$pdo      = $this->kernel->get(\PDO::class);

		// One transaction so a partial failure never leaves the rule
		// pointing at a removed sub-label and the cache full of stale
		// scores that reference the deleted sub-label.
		$alreadyInTx = $pdo->inTransaction();
		if (!$alreadyInTx) $pdo->beginTransaction();
		$cachePurged = 0;
		try {
			$ruleCount = $autoSort->countBySubLabel(
				$ctx['tenant_id'], $ctx['user_id'], $row['parent'], $row['name'],
			);
			if ($ruleCount > 0) {
				$autoSort->delete($ctx['tenant_id'], $ctx['user_id'], $row['parent'], $row['name']);
			}
			$subs->delete($ctx['tenant_id'], $ctx['user_id'], $id);
			$cachePurged = $this->kernel->get(CacheRepository::class)
				->purgeForTenant($ctx['tenant_id']);
			if (!$alreadyInTx) $pdo->commit();
		} catch (\Throwable $e) {
			if (!$alreadyInTx && $pdo->inTransaction()) $pdo->rollBack();
			throw $e;
		}

		Response::json([
			'ok'            => true,
			'deleted_rules' => $ruleCount,
			'cache_purged'  => $cachePurged,
		]);
	}

	/**
	 * Sprint 6c — GET /api/v1/settings/modes liefert die drei Toggle-Stufen.
	 * Defaults greifen wenn der system_settings-Seed (Migration 0018) noch
	 * nicht durchgelaufen ist (z.B. frischer Test-DB-Setup).
	 */
	public function getModes(array $params, array $body): void
	{
		$this->requireAuth();
		$s = $this->kernel->get(SettingsRepository::class);
		Response::json([
			'autosort_move_mode'         => $s->getString('autosort_move_mode',         'suggest'),
			'autosort_create_topic_mode' => $s->getString('autosort_create_topic_mode', 'suggest'),
			'autosort_reply_mode'        => $s->getString('autosort_reply_mode',        'suggest'),
		]);
	}

	/**
	 * POST /api/v1/settings/modes — speichert die drei Modi.
	 * Validiert die Toggle-Hierarchie (DA-Finding 3):
	 *   level(autosort_create_topic_mode) <= level(autosort_move_mode)
	 * sonst legt die KI Folder an, in die nichts hinkommt. Reply ist
	 * unabhängig (Drafts hängen an der Mail, egal wo sie liegt).
	 */
	public function saveModes(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$allowed = ['off', 'suggest', 'auto'];
		$level   = ['off' => 0, 'suggest' => 1, 'auto' => 2];

		$move   = isset($body['autosort_move_mode'])         ? (string)$body['autosort_move_mode']         : null;
		$topic  = isset($body['autosort_create_topic_mode']) ? (string)$body['autosort_create_topic_mode'] : null;
		$reply  = isset($body['autosort_reply_mode'])        ? (string)$body['autosort_reply_mode']        : null;

		foreach ([$move, $topic, $reply] as $v) {
			if ($v !== null && !in_array($v, $allowed, true)) {
				throw HttpException::badRequest('INVALID_MODE', 'Modus muss off/suggest/auto sein');
			}
		}

		// Bei Partial-Update fallen wir auf die aktuell gespeicherten Werte
		// zurück, damit die Hierarchie-Prüfung gegen den NEUEN Zielzustand
		// läuft, nicht gegen ein willkürliches Default.
		$s = $this->kernel->get(SettingsRepository::class);
		$effMove  = $move  ?? $s->getString('autosort_move_mode',         'suggest');
		$effTopic = $topic ?? $s->getString('autosort_create_topic_mode', 'suggest');

		if ($level[$effTopic] > $level[$effMove]) {
			throw HttpException::badRequest('TOGGLE_HIERARCHY',
				"autosort_create_topic_mode ({$effTopic}) darf nicht aggressiver sein als autosort_move_mode ({$effMove})");
		}

		if ($move  !== null) $s->set('autosort_move_mode',         $move);
		if ($topic !== null) $s->set('autosort_create_topic_mode', $topic);
		if ($reply !== null) $s->set('autosort_reply_mode',        $reply);

		// DA-Impl-Finding 3: wenn Modus auf 'auto' wechselt, sind Bestands-
		// Pending mit created_under_mode='suggest' nicht automatisch
		// betroffen (PRD-DA-Pre-Impl-Finding 1: kein silent-flip). Wir
		// liefern die Counts mit, damit das Add-in eine sichtbare Notice
		// rendern kann („Du hast noch N Vorschläge unter suggest").
		$pendingCounts = $this->kernel
			->get(\MailPilot\Repositories\PendingActionRepository::class)
			->countByKind($ctx['tenant_id'], $ctx['user_id']);

		Response::json([
			'autosort_move_mode'         => $effMove,
			'autosort_create_topic_mode' => $effTopic,
			'autosort_reply_mode'        => $reply ?? $s->getString('autosort_reply_mode', 'suggest'),
			'existing_pending'           => $pendingCounts,
		]);
	}
}
