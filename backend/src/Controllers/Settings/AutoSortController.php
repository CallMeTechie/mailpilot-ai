<?php
declare(strict_types=1);

namespace MailPilot\Controllers\Settings;

use MailPilot\Controllers\BaseController;
use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\CacheRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\AutoSortService;
use MailPilot\Services\TokenService;

/**
 * /api/v1/settings/auto-sort/* + rescore-all.
 * Ausgegliedert aus SettingsController (Phase 2 split).
 */
final class AutoSortController extends BaseController
{
	public function listAutoSort(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$rules = $this->kernel->get(AutoSortRepository::class)
			->listForUser($ctx['tenant_id'], $ctx['user_id']);
		Response::json(['rules' => $rules]);
	}

	/**
	 * Body: {"rules": [{label,sub_label?,enabled,folder_name}, ...]}
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

		$pdo = $this->kernel->get(\PDO::class);

		$retryCap = max(1, $this->kernel->get(SettingsRepository::class)
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
	 * 1) claude_cache fuer den Tenant wischen
	 * 2) non-user-corrected mail_scores als 'preset_deprecated' markieren
	 * 3) Der eigentliche Sync wird vom Add-in via POST /sync getriggert.
	 */
	public function rescoreAll(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$pdo = $this->kernel->get(\PDO::class);

		$pdo->beginTransaction();
		try {
			$cachePurged = $this->kernel->get(CacheRepository::class)
				->purgeForTenant($ctx['tenant_id']);

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
			'next_action'    => 'sync',
		]);
	}
}
