<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\AutoSortRepository;

/**
 * Routes a freshly-scored mail into the user-configured Outlook
 * folder, if a matching rule is enabled. Safety net: mails labelled
 * "direct" or "action" with priority >= 4 are NEVER moved even if
 * the rule is on — the user must see them in the inbox.
 *
 * Folder resolution is lazy: on the first hit per (user, label)
 * we ensureFolderPath() against Graph (creates "MailPilot/<Label>"
 * if missing), then cache the id in auto_sort_rules.folder_id so
 * subsequent moves are a single API call.
 *
 * Per-call failures are logged + persisted as last_error on the
 * rule row; they never block the rest of the sync.
 */
final class AutoSortService
{
	public function __construct(
		private readonly GraphClient $graph,
		private readonly AutoSortRepository $rules,
		private readonly \Psr\Log\LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $mail   row from mails (needs ms_message_id)
	 * @param array<string, mixed> $score  row from mail_scores (needs label + priority)
	 *
	 * @return array{moved:bool, reason?:string, folder?:string}
	 */
	public function applyToScoredMail(
		string $accessToken,
		string $tenantId,
		string $userId,
		array $mail,
		array $score,
	): array {
		$label    = (string)($score['label']    ?? '');
		$priority = (int)   ($score['priority'] ?? 0);

		if (in_array($label, ['direct', 'action'], true) && $priority >= 4) {
			return ['moved' => false, 'reason' => 'high_priority_protected'];
		}

		$rule = $this->rules->findRule($tenantId, $userId, $label);
		if ($rule === null || !$rule['enabled']) {
			return ['moved' => false, 'reason' => 'rule_disabled'];
		}

		$msMessageId = (string)($mail['ms_message_id'] ?? '');
		if ($msMessageId === '') {
			return ['moved' => false, 'reason' => 'missing_message_id'];
		}

		try {
			$folderId = $rule['folder_id'];
			if ($folderId === null || $folderId === '') {
				$folderId = $this->graph->ensureFolderPath($accessToken, $rule['folder_name']);
				$this->rules->rememberFolderId($tenantId, $userId, $label, $folderId);
			}
			$this->graph->moveToFolder($accessToken, $msMessageId, $folderId);
			$this->logger->info('autosort.moved', [
				'user'   => $userId,
				'label'  => $label,
				'folder' => $rule['folder_name'],
			]);
			return ['moved' => true, 'folder' => $rule['folder_name']];
		} catch (\Throwable $e) {
			$this->logger->warning('autosort.failed', [
				'user'   => $userId,
				'label'  => $label,
				'err'    => $e->getMessage(),
			]);
			$this->rules->rememberError($tenantId, $userId, $label, $e->getMessage());

			// Stale cached id (folder deleted by user) → drop it so the
			// next round re-resolves.
			if (preg_match('/\b(404|410)\b/', $e->getMessage())) {
				$this->rules->rememberFolderId($tenantId, $userId, $label, '');
			}
			return ['moved' => false, 'reason' => 'graph_error'];
		}
	}
}
