<?php
declare(strict_types=1);

/**
 * MailPilot sync worker.
 *
 * Runs continuously, processes sync_jobs from DB (polling every 5 s) and from
 * Redis queue (blocking pop for low latency). Also runs daily housekeeping:
 * purges old mail bodies, expired oauth_states, expired jwt_blacklist rows.
 *
 * Race-condition safety:
 *   - DB job pickup uses SELECT ... FOR UPDATE SKIP LOCKED inside a tx, so
 *     two workers can never grab the same job.
 *   - Redis-popped jobs go through the same DB transition (queued → running)
 *     before SyncService runs — duplicate Redis events are absorbed.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Block until DB is reachable as the app user. Self-heals 1130 errors
// (wrong host grant) when DB_ROOT_PASS is provided to this container.
require_once __DIR__ . '/wait_for_db.php';

use MailPilot\Http\Kernel;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\UsageRepository;
use MailPilot\Services\JwtService;
use MailPilot\Services\SyncService;

$config = require __DIR__ . '/../config/config.php';
$kernel = new Kernel($config);
$log    = $kernel->get(\Monolog\Logger::class);
$pdo    = $kernel->get(\PDO::class);

$log->info('worker.start');

$redis = null;
try {
	$redis = new \Redis();
	$redis->connect($config['redis']['host'], (int)$config['redis']['port'], 2.0);
} catch (\Throwable $e) {
	$log->warning('worker.redis_unavailable', ['err' => $e->getMessage()]);
}

$lastHousekeepingDay = null;

while (true) {
	try {
		$jobHint = null;
		if ($redis !== null) {
			$popped = $redis->brPop(['mailpilot:sync'], 2);
			if (is_array($popped) && isset($popped[1])) {
				$decoded = json_decode((string)$popped[1], true);
				if (is_array($decoded) && isset($decoded['job_id'])) {
					$jobHint = (string)$decoded['job_id'];
				}
			}
		} else {
			sleep(2);
		}

		$job = pickJob($pdo, $jobHint);

		if ($job !== null) {
			runSyncJob($kernel, $log, $pdo, $job);
		}

		$today = gmdate('Y-m-d');
		if ($lastHousekeepingDay !== $today) {
			$mailRepo = $kernel->get(MailRepository::class);
			$purgedBodies = $mailRepo->purgeOldBodies((int)$config['limits']['body_retention_days']);

			$purgedStates = $pdo->exec('DELETE FROM oauth_states
				WHERE created_at < (UTC_TIMESTAMP(3) - INTERVAL 1 HOUR)');

			$purgedBlacklist = $kernel->get(JwtService::class)->purgeExpiredBlacklist();

			$purgedUsage = $kernel->get(UsageRepository::class)->purgeOlderThan(30);

			$log->info('worker.housekeeping', [
				'bodies'        => $purgedBodies,
				'oauth_states'  => $purgedStates,
				'jwt_blacklist' => $purgedBlacklist,
				'api_usage'     => $purgedUsage,
			]);
			$lastHousekeepingDay = $today;
		}
	} catch (\Throwable $e) {
		$log->error('worker.loop_error', ['err' => $e->getMessage()]);
		sleep(3);
	}
}

/**
 * Atomically claim a queued job. Returns null if none.
 *
 * @return array{job_id:string, tenant_id:string, mailbox_id:string}|null
 */
function pickJob(\PDO $pdo, ?string $hintId): ?array
{
	$pdo->beginTransaction();
	try {
		if ($hintId !== null) {
			$stmt = $pdo->prepare('SELECT id, tenant_id, mailbox_id FROM sync_jobs
				WHERE id = :id AND status = "queued"
				FOR UPDATE SKIP LOCKED');
			$stmt->execute([':id' => $hintId]);
		} else {
			$stmt = $pdo->query('SELECT id, tenant_id, mailbox_id FROM sync_jobs
				WHERE status = "queued"
				ORDER BY created_at ASC
				LIMIT 1
				FOR UPDATE SKIP LOCKED');
		}
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		if ($row === false) {
			$pdo->rollBack();
			return null;
		}

		$pdo->prepare('UPDATE sync_jobs
			SET status = "running", started_at = UTC_TIMESTAMP(3)
			WHERE id = :id')
			->execute([':id' => $row['id']]);

		$pdo->commit();

		return [
			'job_id'     => (string)$row['id'],
			'tenant_id'  => (string)$row['tenant_id'],
			'mailbox_id' => (string)$row['mailbox_id'],
		];
	} catch (\Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		throw $e;
	}
}

/**
 * @param array{job_id:string, tenant_id:string, mailbox_id:string} $job
 */
function runSyncJob(Kernel $kernel, \Monolog\Logger $log, \PDO $pdo, array $job): void
{
	try {
		$mailboxRepo = $kernel->get(MailboxRepository::class);
		$mailbox = $mailboxRepo->findById($job['tenant_id'], $job['mailbox_id']);
		if ($mailbox === null) {
			throw new \RuntimeException('mailbox_not_found');
		}

		$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
		$userStmt->execute([':id' => $mailbox['user_id']]);
		$user = $userStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

		$vipStmt = $pdo->prepare('SELECT email FROM vip_senders
			WHERE user_id = :u AND deleted_at IS NULL');
		$vipStmt->execute([':u' => $mailbox['user_id']]);

		$kwStmt = $pdo->prepare('SELECT keyword FROM project_keywords
			WHERE user_id = :u AND deleted_at IS NULL');
		$kwStmt->execute([':u' => $mailbox['user_id']]);

		$profile = [
			'tenant_id'        => (string)$mailbox['tenant_id'],
			'user_id'          => (string)$mailbox['user_id'],
			'email'            => (string)($user['email'] ?? ''),
			'language'         => (string)($user['language'] ?? 'de'),
			'vip_senders'      => array_column($vipStmt->fetchAll(\PDO::FETCH_ASSOC), 'email'),
			'project_keywords' => array_column($kwStmt->fetchAll(\PDO::FETCH_ASSOC), 'keyword'),
			'user_role'        => '',
		];

		$sync = $kernel->get(SyncService::class);
		$result = $sync->run($job['tenant_id'], $job['mailbox_id'], $profile);

		$pdo->prepare('UPDATE sync_jobs
			SET status = "done",
				finished_at = UTC_TIMESTAMP(3),
				total = :t, processed = :p
			WHERE id = :id')
			->execute([
				':id' => $job['job_id'],
				':t'  => $result['processed'],
				':p'  => $result['scored'],
			]);

		$log->info('worker.job_done', $job + $result);
	} catch (\Throwable $e) {
		$log->error('worker.job_error', ['job' => $job['job_id'], 'err' => $e->getMessage()]);
		$pdo->prepare('UPDATE sync_jobs
			SET status = "error", finished_at = UTC_TIMESTAMP(3), error_text = :err
			WHERE id = :id')
			->execute([':id' => $job['job_id'], ':err' => substr($e->getMessage(), 0, 500)]);
	}
}
