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
	) {
	}

	/**
	 * @return array{processed:int, scored:int}
	 */
	public function run(string $tenantId, string $mailboxId, array $userProfile): array
	{
		$mailbox = $this->mailboxes->findById($tenantId, $mailboxId);
		if ($mailbox === null) {
			throw new \RuntimeException('mailbox_not_found');
		}

		$accessToken = $this->tokens->ensureFreshAccessToken($mailbox);

		$deltaResult = $this->graph->syncInbox($accessToken, $mailbox['delta_token']);

		$processed = 0;
		foreach ($deltaResult['messages'] as $msg) {
			if ($this->isTombstone($msg)) {
				continue;
			}
			$this->mails->upsertFromGraph($tenantId, $mailboxId, $msg);
			$processed++;
		}

		$unscored = $this->mails->findUnscoredForMailbox($tenantId, $mailboxId, 200);
		$scored   = $this->scoring->scoreBatch($tenantId, $userProfile, $unscored);

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
		// that match a now-enabled rule. Cheap when nothing matches
		// (auto_sorted_at index → empty result), idempotent on rerun.
		if ($autosortUserId !== '') {
			$this->autoSort->backfillForMailbox($accessToken, $tenantId, $autosortUserId, $mailboxId, 50);
		}

		$this->mailboxes->updateDeltaAndSyncAt($mailboxId, $deltaResult['delta']);

		$this->logger->info('sync.done', [
			'mailbox'   => $mailboxId,
			'processed' => $processed,
			'scored'    => count($scored),
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
