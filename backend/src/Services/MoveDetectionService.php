<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Repositories\AutoSortCorrectionRepository;
use MailPilot\Repositories\AutoSortRepository;
use MailPilot\Repositories\MailRepository;
use MailPilot\Repositories\ScoreRepository;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Sprint 6d — Move-Detection (PRD-Phase-6 §4).
 *
 * Wird vom SyncService VOR jedem upsertFromGraph() aufgerufen. Vergleicht
 * den frischen parentFolderId aus Graph mit dem letzten DB-Wert. Wenn die
 * Mail nach KI-Auto-Sort in einen anderen Folder gerutscht ist, legen wir
 * eine Korrektur an — pending, mit stabilized_at=NULL.
 *
 * DA-Pre-Impl-Findings:
 *   #1 Quiet-Window: Korrektur startet im pending-Zustand. Worker
 *      promoteStable() setzt stabilized_at nach 60min.
 *   #1.2 Target-Klassifikation: Korrektur wird NUR geloggt, wenn der
 *      Ziel-Ordner zu einer bekannten enabled AutoSortRule gehört
 *      — Move in „Archiv" / „Gelöscht" ist kein Lern-Signal.
 */
final class MoveDetectionService
{
	public function __construct(
		private readonly MailRepository $mails,
		private readonly ScoreRepository $scores,
		private readonly AutoSortRepository $rules,
		private readonly AutoSortCorrectionRepository $corrections,
		private readonly PDO $db,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Wertet eine einzelne Graph-Mail vor dem Upsert aus.
	 *
	 * @param array<string,mixed> $msg Graph-Message (mit parentFolderId)
	 */
	public function evaluate(string $tenantId, string $mailboxId, string $userId, array $msg): void
	{
		$msId = (string)($msg['id'] ?? '');
		$newParent = (string)($msg['parentFolderId'] ?? '');
		if ($msId === '' || $newParent === '') return;

		$prev = $this->mails->findIdAndParentByMsId($tenantId, $mailboxId, $msId);
		// Mail neu (Erst-Sync) ODER parent_folder_id unverändert → kein Move.
		if ($prev === null) return;
		if ($prev['parent_folder_id'] === null) return;
		if ($prev['parent_folder_id'] === $newParent) return;

		$mailId = $prev['id'];

		// Score-Spalte: nur Mails, die wir SELBST sortiert haben, zählen
		// als Korrektur (auto_sorted_at IS NOT NULL). Ein User-Move einer
		// nie-gesortierten Mail ist kein Lern-Signal — wir sehen ihn als
		// Aufbewahrungs-Aktion.
		$stmt = $this->db->prepare('SELECT s.sub_label, s.auto_sorted_at, s.label,
				r.folder_name, r.sub_label AS rule_sub
			FROM mail_scores s
			LEFT JOIN auto_sort_rules r
				ON r.tenant_id = s.tenant_id
			   AND r.label = s.label
			   AND (r.sub_label = s.sub_label OR (r.sub_label IS NULL AND s.sub_label IS NULL))
			WHERE s.tenant_id = :t AND s.mail_id = :m LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':m' => $mailId]);
		$score = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($score === false) return;
		if ($score['auto_sorted_at'] === null) return;

		// Target-Klassifikation: Ziel-Folder zu einer bekannten enabled
		// Rule? Wenn nicht (Archiv, Privat, ...) → kein Lern-Signal.
		$target = $this->resolveTargetSubLabel($tenantId, $userId, $newParent);
		if ($target === null) {
			$this->logger->info('move_detection.target_unknown', [
				'mail' => $mailId, 'parent' => $newParent,
			]);
			return;
		}

		$this->corrections->create(
			$tenantId,
			$userId,
			$mailId,
			(string)($score['folder_name'] ?? ''),
			(string)$target['folder_name'],
			$score['sub_label'] !== null ? (string)$score['sub_label'] : null,
			$target['sub_label'],
		);
		$this->logger->info('move_detection.correction_logged', [
			'mail' => $mailId,
			'from' => $score['folder_name'],
			'to'   => $target['folder_name'],
		]);
	}

	/**
	 * Findet die enabled AutoSortRule, deren folder_id dem Graph-
	 * parentFolderId entspricht. Wenn keine → return null (kein
	 * Lern-relevanter Ziel-Ordner).
	 *
	 * @return array{folder_name:string, sub_label:?string}|null
	 */
	private function resolveTargetSubLabel(string $tenantId, string $userId, string $parentFolderId): ?array
	{
		$stmt = $this->db->prepare('SELECT sub_label, folder_name FROM auto_sort_rules
			WHERE tenant_id = :t AND user_id = :u AND folder_id = :pf AND enabled = 1
			LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':pf' => $parentFolderId]);
		$r = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($r === false) return null;
		return [
			'folder_name' => (string)$r['folder_name'],
			'sub_label'   => $r['sub_label'] !== null ? (string)$r['sub_label'] : null,
		];
	}
}
