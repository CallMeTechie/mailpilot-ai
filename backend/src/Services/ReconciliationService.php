<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Graph\GraphClient;
use MailPilot\Repositories\MailboxRepository;
use MailPilot\Repositories\SettingsRepository;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Sprint 6d — Folder-Rename-Reconciliation (PRD-Phase-6 §9).
 *
 * Vergleicht periodisch die `folder_name`/`parent_folder_id` der
 * enabled AutoSort-Rules mit dem aktuellen Graph-Stand
 * (`displayName` + `parentFolderId`). Bei Drift wird die DB
 * angepasst — aber NUR wenn die DB-folder_name dem zuletzt bekannten
 * Graph-displayName entspricht (User hat es nicht selbst überschrieben).
 *
 * DA-Pre-Impl-Findings:
 *   #2 First-Touch: bei NULL-parent_folder_id wird der Graph-Stand
 *      einmalig ohne Drift-Logging eingelesen.
 *   #3 last_known_display_name-Tracker: Vergleich gegen DB-folder_name
 *      stellt sicher, dass wir User-Renamings nicht zurücksetzen.
 */
final class ReconciliationService
{
	public function __construct(
		private readonly PDO $db,
		private readonly GraphClient $graph,
		private readonly TokenService $tokens,
		private readonly MailboxRepository $mailboxes,
		private readonly SettingsRepository $settings,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Daily-Run: alle Rules mit folder_id≠null und last_reconciled_at
	 * älter als configured interval. Iteriert pro User × Mailbox, da
	 * jeder eigene Access-Tokens braucht.
	 *
	 * @return array{processed:int, drift:int, gone:int, errors:int, first_touch:int, unchanged:int}
	 */
	public function reconcileAll(): array
	{
		$intervalHours = max(1, $this->settings->getInt('autosort.reconciliation_interval_hours', 24));

		$rules = $this->db->prepare('SELECT id, tenant_id, user_id, folder_id, folder_name,
				last_known_display_name, parent_folder_id, label, sub_label
			FROM auto_sort_rules
			WHERE folder_id IS NOT NULL AND folder_id <> ""
			  AND (last_reconciled_at IS NULL
			    OR last_reconciled_at < (UTC_TIMESTAMP(3) - INTERVAL :h HOUR))
			ORDER BY tenant_id, user_id, id');
		$rules->bindValue(':h', $intervalHours, PDO::PARAM_INT);
		$rules->execute();
		$rows = $rules->fetchAll(PDO::FETCH_ASSOC);

		$counts = ['processed' => 0, 'drift' => 0, 'gone' => 0, 'errors' => 0, 'first_touch' => 0, 'unchanged' => 0];
		$accessTokenByUser = []; // user_id → access_token cache pro Run

		foreach ($rows as $r) {
			$counts['processed']++;
			$userId   = (string)$r['user_id'];
			$tenantId = (string)$r['tenant_id'];

			try {
				if (!array_key_exists($userId, $accessTokenByUser)) {
					$mbs = $this->mailboxes->findByUser($tenantId, $userId);
					$accessTokenByUser[$userId] = $mbs === []
						? null
						: $this->tokens->ensureFreshAccessToken($mbs[0]);
				}
				$accessToken = $accessTokenByUser[$userId];
				if ($accessToken === null) {
					$this->logger->info('reconciliation.no_mailbox', ['user' => $userId]);
					continue;
				}

				$status = $this->reconcileOne($r, $accessToken);
				$counts[$status] = ($counts[$status] ?? 0) + 1;
			} catch (\Throwable $e) {
				$counts['errors']++;
				$this->logger->warning('reconciliation.failed', [
					'rule' => $r['id'], 'err' => $e->getMessage(),
				]);
			}
		}

		return $counts;
	}

	/**
	 * @param array<string,mixed> $rule
	 * @return string 'gone'|'drift'|'first_touch'|'unchanged'
	 */
	private function reconcileOne(array $rule, string $accessToken): string
	{
		$folderId = (string)$rule['folder_id'];
		$folder   = $this->graph->getFolder($accessToken, $folderId);

		if ($folder === null) {
			// 404 Folder gone: clear folder_id, disable rule, stamp error.
			$this->db->prepare('UPDATE auto_sort_rules
				SET folder_id = NULL, enabled = 0,
				    last_error = "folder_gone",
				    last_reconciled_at = UTC_TIMESTAMP(3)
				WHERE id = :id')
				->execute([':id' => $rule['id']]);
			$this->logger->info('reconciliation.folder_gone', ['rule' => $rule['id']]);
			return 'gone';
		}

		$graphDisplay = $folder['displayName'];
		$graphParent  = $folder['parentFolderId'];

		// First-Touch (DA-Finding 2): wenn weder parent_folder_id noch
		// last_known_display_name gesetzt sind, ist das der erste je
		// Reconciliation-Lauf für diese Rule. Wir füllen die Tracker,
		// loggen aber KEINEN Drift.
		if ($rule['parent_folder_id'] === null && $rule['last_known_display_name'] === null) {
			$this->db->prepare('UPDATE auto_sort_rules
				SET parent_folder_id = :pf,
				    last_known_display_name = :n,
				    last_reconciled_at = UTC_TIMESTAMP(3)
				WHERE id = :id')
				->execute([':id' => $rule['id'], ':pf' => $graphParent, ':n' => $graphDisplay]);
			return 'first_touch';
		}

		$displayDrift = $rule['last_known_display_name'] !== $graphDisplay;
		$parentDrift  = $rule['parent_folder_id']        !== $graphParent
			&& $rule['parent_folder_id'] !== null;

		if (!$displayDrift && !$parentDrift) {
			$this->db->prepare('UPDATE auto_sort_rules
				SET last_reconciled_at = UTC_TIMESTAMP(3) WHERE id = :id')
				->execute([':id' => $rule['id']]);
			return 'unchanged';
		}

		// User-Customization-Schutz (DA-Finding 3): folder_name wird nur
		// dann angepasst, wenn der User es nicht selbst überschrieben hat.
		$followGraph = ((string)$rule['folder_name'] === (string)$rule['last_known_display_name']);

		if ($followGraph) {
			// PDO mit emulate_prepares=false akzeptiert keinen Named-Param
			// mehrfach in derselben Query — separate :n1/:n2 nutzen.
			$this->db->prepare('UPDATE auto_sort_rules
				SET folder_name = :n1,
				    last_known_display_name = :n2,
				    parent_folder_id = :pf,
				    last_reconciled_at = UTC_TIMESTAMP(3)
				WHERE id = :id')
				->execute([
					':id' => $rule['id'], ':n1' => $graphDisplay, ':n2' => $graphDisplay, ':pf' => $graphParent,
				]);
			$this->logger->info('reconciliation.followed_graph', [
				'rule' => $rule['id'], 'new' => $graphDisplay,
			]);
		} else {
			$this->db->prepare('UPDATE auto_sort_rules
				SET last_known_display_name = :n,
				    parent_folder_id = :pf,
				    last_reconciled_at = UTC_TIMESTAMP(3)
				WHERE id = :id')
				->execute([':id' => $rule['id'], ':n' => $graphDisplay, ':pf' => $graphParent]);
			$this->logger->info('reconciliation.user_override_respected', [
				'rule' => $rule['id'],
				'user_folder_name' => $rule['folder_name'],
				'graph_display' => $graphDisplay,
			]);
		}
		return 'drift';
	}
}
