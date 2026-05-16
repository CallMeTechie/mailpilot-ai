<?php
declare(strict_types=1);

namespace MailPilot\Services\Scoring;

use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\PendingActionRepository;
use MailPilot\Repositories\SettingsRepository;
use MailPilot\Repositories\SubLabelRepository;
use Psr\Log\LoggerInterface;

/**
 * Topic-Discovery + Fuzzy-Merge fuer Sub-Labels (Phase 6b/6c).
 *
 * Drei Ausgaenge fuer resolveOrDiscover():
 *   - Pool-Hit: Claude hat existierenden Bucket gewaehlt → as-is
 *   - Discovery: sub_label_is_new=true + Format-OK + Fuzzy-No-Match →
 *     Anlegen in user_sublabels + AutoSort-Rule (enabled in 'auto'-Mode,
 *     disabled + pending_action(create_topic) in 'suggest'-Mode)
 *   - NULL: Halluzination, Mode='off', Format-invalid, oder Race-Loser
 */
final class SubLabelDiscoverer
{
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly SubLabelRepository $subLabels,
		private readonly AutoSortRepository $autoSortRules,
		private readonly LoggerInterface $logger,
		private readonly ?PendingActionRepository $pendingActions = null,
	) {
	}

	/**
	 * Per parent label eine list von {name, description}. Description ist
	 * was der User in Settings getippt hat — geht in den Prompt, damit
	 * Claude weiss WIESO ein Topic existiert.
	 *
	 * @return array<string, list<array{name:string, description:?string}>>
	 */
	public function loadMap(string $tenantId, string $userId): array
	{
		$map = [];
		foreach ($this->subLabels->listForUser($tenantId, $userId) as $row) {
			$map[$row['parent']][] = [
				'name'        => $row['name'],
				'description' => $row['description'] ?? null,
			];
		}
		return $map;
	}

	/**
	 * Whitelist + Topic-Discovery (Phase 6b).
	 *
	 * $subLabelMap wird by-ref geupdatet, damit weitere Mails im selben
	 * Batch den frisch entdeckten Topic ohne neuen DB-Round-Trip sehen.
	 *
	 * @param array<string, list<array{name:string, description:?string}>> $subLabelMap
	 */
	public function resolveOrDiscover(
		string $tenantId,
		string $userId,
		string $primary,
		mixed $candidate,
		bool $isNew,
		array &$subLabelMap,
	): ?string {
		if (!is_string($candidate)) return null;
		$candidate = trim($candidate);
		if ($candidate === '') return null;

		$pool  = $subLabelMap[$primary] ?? [];
		$names = array_column($pool, 'name');

		// 1) Exact match — Claude hat existing topic gewaehlt
		if (in_array($candidate, $names, true)) {
			return $candidate;
		}

		// 2) Discovery-Pfad
		if ($isNew && $userId !== '') {
			$createMode = $this->settings->getString('autosort_create_topic_mode', 'suggest');
			if (!in_array($createMode, ['suggest', 'auto'], true)) {
				$this->logger->info('topic.discovery_blocked_by_mode', [
					'primary' => $primary, 'mode' => $createMode,
				]);
				return null;
			}

			// 2a) Format-Sanity: max 30 chars, only letters/digits/-/_/space/slash
			if (mb_strlen($candidate) > 30 || !preg_match('/^[\p{L}\p{N}\s\-_\/]+$/u', $candidate)) {
				$this->logger->info('topic.discovery_rejected', [
					'name' => $candidate, 'primary' => $primary, 'reason' => 'format',
				]);
				return null;
			}

			// 2b) Fuzzy-Merge gegen existing names (lowercase)
			$mergeMax = max(0, $this->settings->getInt('topics.fuzzy_merge_levenshtein_max', 3));
			foreach ($names as $existing) {
				if (levenshtein(strtolower($candidate), strtolower($existing)) <= $mergeMax) {
					$this->logger->info('topic.merged_to_existing', [
						'proposed' => $candidate, 'matched' => $existing, 'primary' => $primary,
					]);
					return $existing;
				}
			}

			// 2c) Anlegen: user_sublabels (created_by='ki') + AutoSort-Rule.
			try {
				$this->subLabels->create($tenantId, $userId, $primary, $candidate, null, null, 'ki');
				$folderDefault = $this->settings->getString('folder_default.' . $primary, 'MailPilot/' . ucfirst($primary));
				$folderPath    = $folderDefault . '/' . $candidate;

				if ($createMode === 'auto') {
					$this->autoSortRules->upsert($tenantId, $userId, $primary, $candidate, true, $folderPath);
				} else {
					if ($this->pendingActions === null) {
						throw new \RuntimeException('SubLabelDiscoverer: suggest-mode benötigt PendingActionRepository');
					}
					$this->autoSortRules->suggestKiRule($tenantId, $userId, $primary, $candidate, '');
					$this->pendingActions->create($tenantId, $userId, 'create_topic', [
						'primary'     => $primary,
						'sub_label'   => $candidate,
						'folder_path' => $folderPath,
						'reason'      => 'auto-discovery',
					], 'suggest');
				}

				$this->logger->info('topic.discovered', [
					'name' => $candidate, 'primary' => $primary, 'mode' => $createMode,
				]);
				$subLabelMap[$primary][] = ['name' => $candidate, 'description' => null];
				return $candidate;
			} catch (\Throwable $e) {
				$this->logger->warning('topic.discovery_race_or_failed', [
					'name' => $candidate, 'primary' => $primary, 'err' => $e->getMessage(),
				]);
				foreach ($this->subLabels->listForUser($tenantId, $userId) as $row) {
					if ($row['parent'] === $primary
						&& strtolower($row['name']) === strtolower($candidate)) {
						$subLabelMap[$primary][] = ['name' => $candidate, 'description' => null];
						return $candidate;
					}
				}
				return null;
			}
		}

		// 3) KI hat halluziniert (Name nicht im Pool, isNew=false)
		return null;
	}
}
