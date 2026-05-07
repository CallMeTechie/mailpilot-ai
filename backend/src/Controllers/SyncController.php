<?php
declare(strict_types=1);

namespace MailPilot\Controllers;

use MailPilot\Http\Exceptions\HttpException;
use MailPilot\Http\Response;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Util\Uuid;

final class SyncController extends BaseController
{
	public function trigger(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$mailboxId = isset($body['mailbox_id']) ? (string)$body['mailbox_id'] : null;

		$mailboxes = $this->kernel->get(MailboxRepository::class)
			->findByUser($ctx['tenant_id'], $ctx['user_id']);

		if ($mailboxes === []) {
			throw HttpException::preconditionFailed('MAILBOX_NOT_CONNECTED', 'Kein Postfach verbunden');
		}

		$jobIds = [];
		foreach ($mailboxes as $mb) {
			if ($mailboxId !== null && (string)$mb['id'] !== $mailboxId) {
				continue;
			}
			$jobIds[] = $this->enqueue($ctx['tenant_id'], (string)$mb['id'], $ctx['user_id']);
		}

		Response::json([
			'job_ids'  => $jobIds,
			'queued'   => count($jobIds),
		]);
	}

	public function status(array $params, array $body): void
	{
		$ctx = $this->requireAuth();
		$stmt = $this->kernel->get(\PDO::class)->prepare('SELECT id, status, total, processed, error_text
			FROM sync_jobs WHERE id = :id AND tenant_id = :t LIMIT 1');
		$stmt->execute([':id' => $params['id'], ':t' => $ctx['tenant_id']]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if ($row === false) {
			throw HttpException::notFound('NOT_FOUND', 'Job nicht gefunden');
		}
		Response::json($row);
	}

	/**
	 * Insert a sync job and notify the worker via Redis.
	 */
	private function enqueue(string $tenantId, string $mailboxId, string $userId): string
	{
		$id = Uuid::v4();
		$pdo = $this->kernel->get(\PDO::class);
		$pdo->prepare('INSERT INTO sync_jobs (id, tenant_id, mailbox_id, status)
			VALUES (:id, :t, :m, "queued")')
			->execute([':id' => $id, ':t' => $tenantId, ':m' => $mailboxId]);

		// Redis notification — best effort; worker also polls every 5s as fallback.
		try {
			$cfg = $this->kernel->config['redis'];
			$redis = new \Redis();
			$redis->connect($cfg['host'], (int)$cfg['port'], 1.0);
			$redis->lPush('mailpilot:sync', json_encode([
				'job_id'     => $id,
				'tenant_id'  => $tenantId,
				'mailbox_id' => $mailboxId,
				'user_id'    => $userId,
			], JSON_THROW_ON_ERROR));
			$redis->close();
		} catch (\Throwable) {
			// Polling fallback handles this.
		}

		return $id;
	}
}
