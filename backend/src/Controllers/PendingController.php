<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Services\TokenService;
use MailPilot\Graph\GraphClient;
use PDO;

/**
 * Sprint 6c — Pending-Action-Endpoints (PRD §3.1, §6c).
 *
 * Approve führt die Action AKTUAL aus (move via Graph, create_topic +
 * folder via Graph). Bei partiellem Failure bleibt die Action pending
 * mit last_error gesetzt (DA-Finding 2: Best-Effort statt all-or-nothing).
 */
final class PendingController extends BaseController
{
	public function list(array $params, array $body): void
	{
		$ctx  = $this->requireAuth();
		$repo = $this->kernel->get(PendingActionRepository::class);

		$kind    = isset($_GET['kind'])     ? (string)$_GET['kind']     : null;
		$afterId = isset($_GET['after_id']) ? (string)$_GET['after_id'] : null;
		$limit   = max(1, min(200, (int)($_GET['limit'] ?? 50)));

		$items  = $repo->listPendingForUser($ctx['tenant_id'], $ctx['user_id'], $kind, $afterId, $limit);
		$counts = $repo->countByKind($ctx['tenant_id'], $ctx['user_id']);

		// DA-Impl-Finding 2: für create_topic-Items die Children-Anzahl
		// mitgeben, damit das Add-in vor Approve ein „X Mails werden
		// verschoben"-Confirm zeigen kann.
		$topicIds = [];
		foreach ($items as $it) {
			if ($it['kind'] === 'create_topic') $topicIds[] = $it['id'];
		}
		$childCounts = $topicIds !== []
			? $repo->countChildrenForParents($ctx['tenant_id'], $ctx['user_id'], $topicIds)
			: [];
		foreach ($items as $i => $it) {
			$items[$i]['children_count'] = $childCounts[$it['id']] ?? 0;
		}

		$settings = $this->kernel->get(SettingsRepository::class);
		$banner   = $this->resolveBannerLevel($counts['total'], $settings);

		Response::json([
			'items'       => $items,
			'next_cursor' => $items !== [] ? $items[count($items) - 1]['id'] : null,
			'counts'      => $counts,
			'banner'      => $banner,
		]);
	}

	public function approve(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$id  = (string)($params['id'] ?? '');
		$repo = $this->kernel->get(PendingActionRepository::class);
		$action = $repo->findById($ctx['tenant_id'], $ctx['user_id'], $id);
		if ($action === null) {
			throw HttpException::notFound('PENDING_NOT_FOUND', 'Action nicht gefunden');
		}
		if ($action['status'] !== 'pending') {
			throw HttpException::preconditionFailed('PENDING_NOT_PENDING', 'Action ist bereits entschieden');
		}
		$result = $this->executeAction($ctx['tenant_id'], $ctx['user_id'], $action);
		Response::json(['ok' => true, 'result' => $result]);
	}

	public function reject(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$id  = (string)($params['id'] ?? '');
		$ok  = $this->kernel->get(PendingActionRepository::class)
			->setStatus($ctx['tenant_id'], $ctx['user_id'], $id, 'rejected');
		if (!$ok) {
			throw HttpException::notFound('PENDING_NOT_FOUND', 'Action nicht gefunden oder bereits entschieden');
		}
		Response::json(['ok' => true]);
	}

	/**
	 * Bulk-Approve mit Cursor-Pagination (DA-Finding 4). UI loopt bis
	 * next_cursor=null. Body: { kind?, after_id?, limit? }
	 */
	public function bulkApprove(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$kind    = isset($body['kind'])     ? (string)$body['kind']     : null;
		$afterId = isset($body['after_id']) ? (string)$body['after_id'] : null;
		$limit   = max(1, min(50, (int)($body['limit'] ?? 25)));

		$repo  = $this->kernel->get(PendingActionRepository::class);
		$items = $repo->listPendingForUser($ctx['tenant_id'], $ctx['user_id'], $kind, $afterId, $limit);

		$succeeded = 0;
		$failed    = 0;
		foreach ($items as $a) {
			try {
				$res = $this->executeAction($ctx['tenant_id'], $ctx['user_id'], $a);
				if (!empty($res['ok'])) $succeeded++;
				else                    $failed++;
			} catch (\Throwable $e) {
				$repo->rememberError($ctx['tenant_id'], $ctx['user_id'], $a['id'], $e->getMessage());
				$failed++;
			}
		}

		Response::json([
			'processed'   => count($items),
			'succeeded'   => $succeeded,
			'failed'      => $failed,
			'next_cursor' => $items !== [] ? $items[count($items) - 1]['id'] : null,
		]);
	}

	/**
	 * Type-Dispatcher für Action-Ausführung.
	 *
	 * @param array<string,mixed> $action
	 * @return array<string,mixed>
	 */
	private function executeAction(string $tenantId, string $userId, array $action): array
	{
		$repo = $this->kernel->get(PendingActionRepository::class);
		$kind = (string)$action['kind'];
		$pid  = (string)$action['id'];
		$pld  = $action['payload'];

		try {
			switch ($kind) {
				case 'move':
				case 'move_to_pending_topic':
					$this->executeMove($tenantId, $userId, $pld);
					$repo->setStatus($tenantId, $userId, $pid, 'approved');
					return ['ok' => true, 'kind' => $kind];

				case 'create_topic':
					$summary = $this->executeCreateTopic($tenantId, $userId, $pid, $pld);
					$repo->setStatus($tenantId, $userId, $pid, 'approved');
					return ['ok' => true, 'kind' => 'create_topic'] + $summary;

				case 'reply_draft':
					// Sprint 6f-Pfad. In 6c nur Status-Übergang.
					$repo->setStatus($tenantId, $userId, $pid, 'approved');
					return ['ok' => true, 'kind' => 'reply_draft', 'note' => 'reply_draft execution kommt in Sprint 6f'];

				default:
					throw new \RuntimeException("Unknown action kind: {$kind}");
			}
		} catch (\Throwable $e) {
			$repo->rememberError($tenantId, $userId, $pid, $e->getMessage());
			return ['ok' => false, 'kind' => $kind, 'error' => $e->getMessage()];
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function executeMove(string $tenantId, string $userId, array $payload): void
	{
		$msMessageId = (string)($payload['ms_message_id'] ?? '');
		$folderPath  = (string)($payload['target_folder'] ?? '');
		if ($msMessageId === '' || $folderPath === '') {
			throw new \RuntimeException('move payload incomplete');
		}

		$mailboxes = $this->kernel->get(MailboxRepository::class)
			->findByUser($tenantId, $userId);
		if ($mailboxes === []) {
			throw new \RuntimeException('no mailbox connected');
		}
		// Erste verbundene Mailbox — Multi-Mailbox-Routing ist nicht
		// Sprint-6c-Scope (kommt mit Sprint 7).
		$accessToken = $this->kernel->get(TokenService::class)
			->ensureFreshAccessToken($mailboxes[0]);

		$graph    = $this->kernel->get(GraphClient::class);
		$folderId = $graph->ensureFolderPath($accessToken, $folderPath);
		$graph->moveToFolder($accessToken, $msMessageId, $folderId);

		$mailId = (string)($payload['mail_id'] ?? '');
		if ($mailId !== '') {
			$this->kernel->get(PDO::class)
				->prepare('UPDATE mail_scores SET auto_sorted_at = UTC_TIMESTAMP(3)
					WHERE mail_id = :m AND tenant_id = :t')
				->execute([':m' => $mailId, ':t' => $tenantId]);
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{moves_done:int, moves_failed:int}
	 */
	private function executeCreateTopic(string $tenantId, string $userId, string $parentPid, array $payload): array
	{
		$primary    = (string)($payload['primary']     ?? '');
		$subLabel   = (string)($payload['sub_label']   ?? '');
		$folderPath = (string)($payload['folder_path'] ?? '');
		if ($primary === '' || $subLabel === '' || $folderPath === '') {
			throw new \RuntimeException('create_topic payload incomplete');
		}

		// Rule aktivieren (legt sie an wenn sie noch nicht existiert).
		$this->kernel->get(AutoSortRepository::class)
			->upsert($tenantId, $userId, $primary, $subLabel, true, $folderPath);

		// Children abarbeiten — Best-Effort (DA-Finding 2)
		$repo = $this->kernel->get(PendingActionRepository::class);
		$children = $repo->findChildrenOfTopic($tenantId, $userId, $parentPid);
		$done = 0; $failed = 0;
		foreach ($children as $c) {
			try {
				$this->executeMove($tenantId, $userId, $c['payload']);
				$repo->setStatus($tenantId, $userId, $c['id'], 'approved');
				$done++;
			} catch (\Throwable $e) {
				$repo->rememberError($tenantId, $userId, $c['id'], $e->getMessage());
				$failed++;
			}
		}
		return ['moves_done' => $done, 'moves_failed' => $failed];
	}

	/**
	 * Stufenweise Banner-Klassifikation (DA-Finding 4). Schwellen aus
	 * system_settings, ohne Code-Deploy anpassbar.
	 *
	 * @return array{level:string, threshold:int, total:int}
	 */
	private function resolveBannerLevel(int $total, SettingsRepository $settings): array
	{
		$block   = max(1, $settings->getInt('pending.banner_block',   500));
		$warning = max(1, $settings->getInt('pending.banner_warning', 250));
		$info    = max(1, $settings->getInt('pending.banner_info',    100));
		if ($total >= $block)   return ['level' => 'block',   'threshold' => $block,   'total' => $total];
		if ($total >= $warning) return ['level' => 'warning', 'threshold' => $warning, 'total' => $total];
		if ($total >= $info)    return ['level' => 'info',    'threshold' => $info,    'total' => $total];
		return ['level' => 'none', 'threshold' => 0, 'total' => $total];
	}
}
