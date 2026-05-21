<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\RescoreJobRepository;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Phase 9l (Marc 2026-05-21) — Async Bulk-Rescore Worker-Logik.
 *
 * Ausgelagert aus MailController::rescoreFolder, damit der Worker dieselbe
 * Code-Pfad wie ein hypothetischer Sync-Aufruf nutzen kann. Wichtige
 * Unterschiede zur alten Sync-Variante:
 *   - Cap auf 200 statt 50 (Worker hat kein nginx-Timeout).
 *   - Progress wird live in rescore_jobs.processed gestrichen (UI-Polling).
 *   - Fehler in einem Chunk killen den Job nicht (best-effort), wandern aber
 *     in error_text wenn der gesamte Job platzt.
 */
final class RescoreJobService
{
	private const MAX_MAILS_PER_JOB = 200;
	private const SCORE_CHUNK_SIZE  = 20;

	public function __construct(
		private readonly PDO $db,
		private readonly RescoreJobRepository $jobs,
		private readonly MailboxRepository $mailboxes,
		private readonly MailRepository $mails,
		private readonly GraphClient $graph,
		private readonly TokenService $tokens,
		private readonly MailScoringService $scoring,
		private readonly LoggerInterface $log,
	) {
	}

	/**
	 * Verarbeitet einen einzelnen Job (vom Worker aufgerufen, nachdem
	 * RescoreJobRepository::claimNextQueued() ihn auf running gesetzt hat).
	 *
	 * @param array{id:string, tenant_id:string, user_id:string, mailbox_id:?string, folder_id:string} $job
	 */
	public function run(array $job): void
	{
		$jobId    = $job['id'];
		$tenantId = $job['tenant_id'];
		$userId   = $job['user_id'];
		$folderId = $job['folder_id'];

		try {
			$userProfile = $this->buildUserProfile($tenantId, $userId);
			$mailboxes = $this->mailboxes->findByUser($tenantId, $userId);
			if ($mailboxes === []) {
				$this->jobs->markFailed($jobId, 'MAILBOX_NOT_CONNECTED');
				return;
			}

			// Folder gehoert genau einer Mailbox an — Graph liefert leere
			// Antwort fuer die anderen. Erste Mailbox mit Treffern gewinnt.
			$graphMessages  = [];
			$matchedMailbox = null;
			foreach ($mailboxes as $mb) {
				try {
					$token = $this->tokens->ensureFreshAccessToken($mb);
					$msgs  = $this->graph->listFolderMessages($token, $folderId, self::MAX_MAILS_PER_JOB);
				} catch (\Throwable $e) {
					$this->log->info('rescore_job.graph_list_failed', [
						'job_id'     => $jobId,
						'mailbox_id' => (string)$mb['id'],
						'err'        => $e->getMessage(),
					]);
					$msgs = [];
				}
				if ($msgs !== []) {
					$graphMessages  = $msgs;
					$matchedMailbox = $mb;
					break;
				}
			}

			if ($graphMessages === [] || $matchedMailbox === null) {
				// Folder ist leer oder nicht erreichbar — kein Fehler, kein Drama.
				$this->jobs->markDone($jobId, 0, 0, false);
				return;
			}

			$capped = count($graphMessages) >= self::MAX_MAILS_PER_JOB;

			// Upsert ALLE Mails zuerst (heilt parent_folder_id), Mail-Rows sammeln.
			$lookup = $this->db->prepare(
				'SELECT * FROM mails WHERE ms_message_id = :ms AND tenant_id = :t LIMIT 1'
			);
			$mailRows = [];
			foreach ($graphMessages as $gm) {
				$msId = (string)($gm['id'] ?? '');
				if ($msId === '') {
					continue;
				}
				try {
					$this->mails->upsertFromGraph($tenantId, (string)$matchedMailbox['id'], $gm);
				} catch (\Throwable $e) {
					$this->log->info('rescore_job.upsert_failed', [
						'job_id' => $jobId, 'ms_id' => $msId, 'err' => $e->getMessage(),
					]);
					continue;
				}
				$lookup->execute([':ms' => $msId, ':t' => $tenantId]);
				$row = $lookup->fetch(PDO::FETCH_ASSOC);
				if ($row !== false) {
					$mailRows[] = $row;
				}
			}

			$total = count($mailRows);
			if ($total === 0) {
				$this->jobs->markDone($jobId, 0, 0, $capped);
				return;
			}

			// Set total upfront so UI sees the denominator immediately.
			$this->jobs->updateProgress($jobId, 0, $total);

			$processed = 0;
			foreach (array_chunk($mailRows, self::SCORE_CHUNK_SIZE) as $chunk) {
				$chunkSize = count($chunk);
				try {
					$this->scoring->scoreBatch($tenantId, $userProfile, $chunk);
				} catch (BudgetExceededException $e) {
					$this->jobs->markFailed($jobId, 'BUDGET_EXCEEDED: ' . $e->getMessage());
					return;
				} catch (\Throwable $e) {
					// Chunk-Fehler ist kein Job-Killer — log, weiter mit naechstem.
					$this->log->warning('rescore_job.chunk_failed', [
						'job_id'    => $jobId,
						'chunk_len' => $chunkSize,
						'err'       => $e->getMessage(),
					]);
				}
				$processed += $chunkSize;
				$this->jobs->updateProgress($jobId, $processed, $total);
			}

			$this->jobs->markDone($jobId, $processed, $total, $capped);
			$this->log->info('rescore_job.done', [
				'job_id' => $jobId, 'total' => $total, 'capped' => $capped,
			]);
		} catch (\Throwable $e) {
			$this->log->error('rescore_job.failed', [
				'job_id' => $jobId, 'err' => $e->getMessage(),
			]);
			try {
				$this->jobs->markFailed($jobId, $e->getMessage());
			} catch (\Throwable) {
				// markFailed nicht reachable → DB ist hin, nichts wir koennen tun.
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildUserProfile(string $tenantId, string $userId): array
	{
		$userStmt = $this->db->prepare('SELECT email, language FROM users WHERE id = :id LIMIT 1');
		$userStmt->execute([':id' => $userId]);
		$user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

		$vipStmt = $this->db->prepare(
			'SELECT email FROM vip_senders WHERE user_id = :u AND deleted_at IS NULL'
		);
		$vipStmt->execute([':u' => $userId]);
		$vips = array_column($vipStmt->fetchAll(PDO::FETCH_ASSOC), 'email');

		$kwStmt = $this->db->prepare(
			'SELECT keyword FROM project_keywords WHERE user_id = :u AND deleted_at IS NULL'
		);
		$kwStmt->execute([':u' => $userId]);
		$kws = array_column($kwStmt->fetchAll(PDO::FETCH_ASSOC), 'keyword');

		return [
			'tenant_id'        => $tenantId,
			'user_id'          => $userId,
			'email'            => (string)($user['email'] ?? ''),
			'language'         => (string)($user['language'] ?? 'de'),
			'vip_senders'      => $vips,
			'project_keywords' => $kws,
			'user_role'        => '',
		];
	}
}
