<?php
declare(strict_types=1);

namespace MailPilot\Services;

use MailPilot\Repositories\SettingsRepository;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Phase 9p (Marc 2026-05-22) — Auto-Cleanup fuer Score-Override-Regeln.
 *
 * Marc-Beschwerde 2026-05-22: ueber die Zeit haeufen sich (KI-inferred)
 * Override-Regeln an, die entweder deaktiviert daliegen oder nie auf
 * eine Mail gegriffen haben. Dieser Service raumt sie auf — soft-delete
 * via deleted_at, damit nichts unwiderruflich verloren ist.
 *
 * Drei Settings steuern das Verhalten (Migration 0049):
 *   - score_rule_autoclean.enabled (bool, default 1)
 *   - score_rule_autoclean.delete_disabled (bool, default 1)
 *   - score_rule_autoclean.delete_unused_after_days (int, default 7)
 *
 * Worker ruft ::tick() einmal pro Tag (worker.php). Manueller Trigger
 * via POST /api/v1/settings/score-overrides/cleanup gibt den gleichen
 * cleanup() zurueck, ignoriert aber die enabled-Schwelle (User hat
 * explizit geklickt).
 */
final class ScoreOverrideCleanupService
{
	public function __construct(
		private readonly PDO $db,
		private readonly SettingsRepository $settings,
		private readonly LoggerInterface $log,
	) {
	}

	/**
	 * Worker-Tick. Liest enabled-Flag; wenn aus, no-op.
	 *
	 * @return array{ran:bool, deleted:int}
	 */
	public function tick(): array
	{
		if (!$this->settings->getBool('score_rule_autoclean.enabled', true)) {
			return ['ran' => false, 'deleted' => 0];
		}
		$deleted = $this->cleanup();
		$this->log->info('score_rule_autoclean.tick', ['deleted' => $deleted]);
		return ['ran' => true, 'deleted' => $deleted];
	}

	/**
	 * Fuehrt den Cleanup-Pass aus. Soft-Delete via deleted_at-Stempel.
	 *
	 * Heuristik:
	 *   - enabled=0  → wird geloescht, wenn settings.delete_disabled = true
	 *   - applies_count=0 AND created_at < UTC - INTERVAL N DAY → loeschen
	 *
	 * Returnt die Anzahl der affected rows.
	 */
	public function cleanup(): int
	{
		$deleteDisabled = $this->settings->getBool('score_rule_autoclean.delete_disabled', true);
		$unusedAfterDays = max(1, $this->settings->getInt('score_rule_autoclean.delete_unused_after_days', 7));

		$conditions = [];
		if ($deleteDisabled) {
			$conditions[] = 'enabled = 0';
		}
		$conditions[] = '(applies_count = 0 AND created_at < (UTC_TIMESTAMP(3) - INTERVAL :d DAY))';

		// Wenn nur applies_count-Bedingung uebrig bleibt, ist conditions[] nicht
		// leer — der OR-Build funktioniert trotzdem. Kein Edge-Case zu fangen.
		$sql = 'UPDATE score_override_rules
				SET deleted_at = UTC_TIMESTAMP(3)
				WHERE deleted_at IS NULL
				  AND (' . implode(' OR ', $conditions) . ')';

		$stmt = $this->db->prepare($sql);
		$stmt->bindValue(':d', $unusedAfterDays, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}

	/**
	 * Liefert die aktuelle Config zurueck — Read-Side fuer GET-Endpoint.
	 *
	 * @return array{enabled:bool, delete_disabled:bool, delete_unused_after_days:int}
	 */
	public function readConfig(): array
	{
		return [
			'enabled'                  => $this->settings->getBool('score_rule_autoclean.enabled', true),
			'delete_disabled'          => $this->settings->getBool('score_rule_autoclean.delete_disabled', true),
			'delete_unused_after_days' => max(1, $this->settings->getInt('score_rule_autoclean.delete_unused_after_days', 7)),
		];
	}

	/**
	 * Schreibt eine partielle Config zurueck. Nur Keys die im Patch
	 * vorhanden sind werden persistiert — partial-PATCH-Semantik.
	 *
	 * @param array{enabled?:bool, delete_disabled?:bool, delete_unused_after_days?:int} $patch
	 */
	public function writeConfig(array $patch): void
	{
		if (array_key_exists('enabled', $patch)) {
			$this->settings->set('score_rule_autoclean.enabled', $patch['enabled'] ? '1' : '0');
		}
		if (array_key_exists('delete_disabled', $patch)) {
			$this->settings->set('score_rule_autoclean.delete_disabled', $patch['delete_disabled'] ? '1' : '0');
		}
		if (array_key_exists('delete_unused_after_days', $patch)) {
			$days = max(1, min(365, (int)$patch['delete_unused_after_days']));
			$this->settings->set('score_rule_autoclean.delete_unused_after_days', (string)$days);
		}
	}
}
