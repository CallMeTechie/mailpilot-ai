<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\ScoreRepository;

/**
 * Orchestrates the sync pipeline:
 *  1. Refresh access token if needed
 *  2. Graph delta sync of Inbox
 *  3. Upsert mails into DB
 *  4. Score new mails via MailScoringService
 *  5. Push categories back to Graph
 *  6. Persist new delta token
 */
final class SyncService
{
	private const CATEGORY_MAP = [
		'direct'     => 'MP-Direct',
		'action'     => 'MP-Action',
		'cc'         => 'MP-CC',
		'newsletter' => 'MP-Newsletter',
		'auto'       => 'MP-Auto',
		'noise'      => 'MP-Noise',
	];

	public function __construct(
		private readonly GraphClient $graph,
		private readonly MailRepository $mails,
		private readonly MailboxRepository $mailboxes,
		private readonly ScoreRepository $scores,
		private readonly MailScoringService $scoring,
		private readonly TokenService $tokens,
		private readonly AutoSortService $autoSort,
		private readonly \Psr\Log\LoggerInterface $logger,
		// Sprint 6d: optional, damit Tests ohne Wiring laufen. Wenn null,
		// wird die Move-Detection still übersprungen.
		private readonly ?MoveDetectionService $moveDetection = null,
	) {
	}

	/**
	 * @param callable(int,int):void|null $onProgress
	 *   Fired with (processedCount, totalCount) at every meaningful
	 *   milestone — fetch done, after each scoring chunk, after
	 *   AutoSort. Worker wires this to UPDATE sync_jobs so the
	 *   add-in's progress bar actually moves during the run instead
	 *   of jumping from 0 to 100% at the end.
	 *
	 * @return array{processed:int, scored:int}
	 */
	public function run(string $tenantId, string $mailboxId, array $userProfile, ?callable $onProgress = null): array
	{
		$mailbox = $this->mailboxes->findById($tenantId, $mailboxId);
		if ($mailbox === null) {
			throw new \RuntimeException('mailbox_not_found');
		}

		$accessToken = $this->tokens->ensureFreshAccessToken($mailbox);

		// Pre-fetch: signal "we're alive, fetching delta". Total=1
		// keeps the bar a tiny sliver instead of 0/0 (which would
		// render as empty). Real total comes after delta returns.
		if ($onProgress) { $onProgress(0, 1); }

		$deltaResult = $this->graph->syncInbox($accessToken, $mailbox['delta_token']);

		$processed = 0;
		$deleted   = 0;
		foreach ($deltaResult['messages'] as $msg) {
			if ($this->isTombstone($msg)) {
				// Graph schickt @removed wenn der User die Mail in Outlook
				// (oder ein anderer Client) gelöscht hat. Vor diesem Fix
				// schluckten wir das schweigend — Mail blieb in MailPilot
				// sichtbar, „Öffnen" warf ErrorItemNotFound.
				$msMessageId = (string)($msg['id'] ?? '');
				if ($msMessageId !== ''
					&& $this->mails->markDeletedByMsId($tenantId, $mailboxId, $msMessageId)
				) {
					$deleted++;
				}
				continue;
			}
			// Sprint 6d: Move-Detection läuft VOR dem Upsert — sonst
			// wäre der "alte" DB-Wert schon überschrieben und der Vergleich
			// liefe leer. userId kommt aus dem $userProfile.
			$this->moveDetection?->evaluate(
				$tenantId, $mailboxId, (string)($userProfile['user_id'] ?? ''), $msg,
			);
			$this->mails->upsertFromGraph($tenantId, $mailboxId, $msg);
			$processed++;
		}

		$unscored = $this->mails->findUnscoredForMailbox($tenantId, $mailboxId, 200);
		$total    = count($unscored);
		if ($onProgress) { $onProgress(0, max(1, $total)); }

		$chunkCb = $onProgress !== null && $total > 0
			? function (int $done) use ($onProgress, $total): void { $onProgress($done, $total); }
			: null;
		$scored = $this->scoring->scoreBatch($tenantId, $userProfile, $unscored, $chunkCb);

		$this->pushCategories($accessToken, $scored);

		// Per-user auto-sort. Worker-side scoring carries the user_id
		// via $userProfile['user_id']; if it's missing (legacy callers)
		// we skip moves entirely.
		$autosortUserId = (string)($userProfile['user_id'] ?? '');
		$moved = 0;
		if ($autosortUserId !== '') {
			foreach ($scored as $scoreRow) {
				$mail = $this->mails->findById($tenantId, (string)$scoreRow['mail_id']);
				if ($mail === null) continue;
				$result = $this->autoSort->applyToScoredMail(
					$accessToken,
					$tenantId,
					$autosortUserId,
					$mail,
					$scoreRow,
				);
				if (!empty($result['moved'])) $moved++;
			}
		}

		// Backfill: retroactively move scored-but-not-yet-moved mails
		// that match a now-enabled rule. Cheap when nothing matches.
		if ($autosortUserId !== '') {
			$this->autoSort->backfillForMailbox($accessToken, $tenantId, $autosortUserId, $mailboxId, 50);
		}

		$this->mailboxes->updateDeltaAndSyncAt($mailboxId, $deltaResult['delta']);

		// Final progress beat → 100 % for the UI.
		if ($onProgress) { $onProgress(max($total, count($scored)), max(1, $total)); }

		$this->logger->info('sync.done', [
			'mailbox'   => $mailboxId,
			'processed' => $processed,
			'scored'    => count($scored),
			'deleted'   => $deleted,
		]);

		return ['processed' => $processed, 'scored' => count($scored)];
	}

	private function isTombstone(array $msg): bool
	{
		return isset($msg['@removed']);
	}

	/**
	 * @param list<array<string, mixed>> $scores
	 */
	private function pushCategories(string $accessToken, array $scores): void
	{
		foreach ($scores as $score) {
			$mail = $this->mails->findById($score['tenant_id'], $score['mail_id']);
			if ($mail === null || !isset($mail['ms_message_id'])) {
				continue;
			}
			$cat = self::CATEGORY_MAP[$score['label']] ?? null;
			if ($cat === null) {
				continue;
			}
			try {
				$this->graph->setCategories($accessToken, (string)$mail['ms_message_id'], [$cat]);
			} catch (\Throwable $e) {
				$this->logger->warning('sync.category.failed', [
					'mail_id' => $score['mail_id'],
					'err'     => $e->getMessage(),
				]);
			}
		}
	}
}
