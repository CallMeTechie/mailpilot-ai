<?php
declare(strict_types=1);

namespace MailPilot\Repositories;

use MailPilot\Services\QuotaExceededException;
use PDO;

/**
 * Per-user, per-day action counter. Eine Row pro
 * (tenant_id, user_id, kind, date). Sprint 6g baut diese Infrastruktur
 * erstmals (DA-R2 Finding 2); Sprint 6f wird sie für autoreply_max_per_day
 * mitbenutzen.
 *
 * Kein Cron-Reset nötig — der Schlüssel enthält `date`, gestrige Zähler
 * bleiben einfach liegen und werden bei Bedarf von Housekeeping
 * purged (siehe purgeOlderThan).
 */
final class UsageCounterRepository
{
	public function __construct(private readonly PDO $db)
	{
	}

	/**
	 * Atomar inkrementiert den Zähler für (tenant, user, kind, heute).
	 * Wirft QuotaExceededException wenn der RESULTIERENDE Wert > $cap.
	 *
	 * Die Reihenfolge ist bewusst: erst inkrementieren, dann prüfen.
	 * Race-frei via INSERT … ON DUPLICATE KEY UPDATE + LAST_INSERT_ID-
	 * Trick zum Auslesen des neuen counts in derselben Operation.
	 */
	public function incrementOrFail(
		string $tenantId,
		string $userId,
		string $kind,
		int    $cap,
		?\DateTimeImmutable $now = null,
	): int {
		$date = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');

		// MariaDB-Trick: LAST_INSERT_ID(expr) als value-of-expr-Helper
		// im ON DUPLICATE KEY UPDATE — liefert nach EXECUTE den neuen
		// count via lastInsertId(). Beim reinen INSERT fällt das auf 0
		// zurück, daher der explizite Read-Back-Fallback unten.
		$stmt = $this->db->prepare('INSERT INTO usage_counters
			(tenant_id, user_id, kind, `date`, count)
			VALUES (:t, :u, :k, :d, 1)
			ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(count + 1)');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':k' => $kind, ':d' => $date]);

		$newCount = (int)$this->db->lastInsertId();
		if ($newCount === 0) {
			// Frische INSERT-Zeile: count steht garantiert auf 1.
			$newCount = 1;
		}

		if ($newCount > $cap) {
			throw new QuotaExceededException($kind, $cap, $newCount);
		}
		return $newCount;
	}

	/**
	 * Aktueller Zählerstand für (tenant, user, kind, $date).
	 * Default $date = heute UTC. Returnt 0 wenn keine Row.
	 */
	public function getCount(
		string $tenantId,
		string $userId,
		string $kind,
		?\DateTimeImmutable $date = null,
	): int {
		$dateStr = ($date ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
		$stmt = $this->db->prepare('SELECT count FROM usage_counters
			WHERE tenant_id = :t AND user_id = :u AND kind = :k AND `date` = :d
			LIMIT 1');
		$stmt->execute([':t' => $tenantId, ':u' => $userId, ':k' => $kind, ':d' => $dateStr]);
		$v = $stmt->fetchColumn();
		return $v === false ? 0 : (int)$v;
	}

	/**
	 * Housekeeping: Counter älter als $days Tage löschen.
	 * Wird im worker.php daily-Tick aufgerufen. Returnt Anzahl gelöschter Rows.
	 */
	public function purgeOlderThan(int $days): int
	{
		$days = max(1, $days);
		$stmt = $this->db->prepare('DELETE FROM usage_counters
			WHERE `date` < (CURRENT_DATE - INTERVAL :d DAY)');
		$stmt->bindValue(':d', $days, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}
}
