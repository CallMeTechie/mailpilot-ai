<?php
declare(strict_types=1);

namespace MailPilot\Admin\Security;

use PDO;

/**
 * Phase-H5 — Brute-Force-Schutz fuer Admin-Login.
 *
 * Sliding-Window-Counter ueber `admin_login_attempts`-Tabelle.
 *
 * Threshold-Policy:
 *   - 5 Failed-Attempts pro IP in 15 Minuten → Lockout
 *   - Lockout-Dauer: implizit — sobald alte Failures aus dem Sliding-
 *     Window fallen, ist die IP wieder erlaubt. Effektiv gleicher Effekt
 *     wie exp-backoff, aber simpler.
 *
 * Success-Login loescht historische failed-attempts der IP (sonst koennte
 * ein User legitim ueber das Threshold rutschen wenn er 4× sich vertippt
 * + 1× richtig + 1× vertippt → wuerde locken).
 */
final class AdminLoginAttemptRepository
{
	public const MAX_FAILED_PER_WINDOW = 5;
	public const WINDOW_MINUTES        = 15;
	public const RETAIN_DAYS           = 30;

	public function __construct(
		private readonly PDO $db,
	) {
	}

	public function countRecentFailures(string $ip): int
	{
		$stmt = $this->db->prepare(
			'SELECT COUNT(*) FROM admin_login_attempts
			 WHERE ip = :ip
			   AND success = 0
			   AND attempted_at > (UTC_TIMESTAMP(3) - INTERVAL :win MINUTE)'
		);
		$stmt->bindValue(':ip',  $ip);
		$stmt->bindValue(':win', self::WINDOW_MINUTES, PDO::PARAM_INT);
		$stmt->execute();
		return (int)$stmt->fetchColumn();
	}

	public function isLocked(string $ip): bool
	{
		return $this->countRecentFailures($ip) >= self::MAX_FAILED_PER_WINDOW;
	}

	/**
	 * Loggt einen Versuch. Bei success=true werden historische failed-
	 * attempts der IP geloescht (Counter-Reset).
	 */
	public function record(string $ip, ?string $username, bool $success): void
	{
		$this->db->prepare(
			'INSERT INTO admin_login_attempts (ip, username, success)
			 VALUES (:ip, :u, :s)'
		)->execute([
			':ip' => $ip,
			':u'  => $username !== '' ? $username : null,
			':s'  => $success ? 1 : 0,
		]);

		if ($success) {
			$this->db->prepare(
				'DELETE FROM admin_login_attempts
				 WHERE ip = :ip AND success = 0'
			)->execute([':ip' => $ip]);
		}
	}

	/**
	 * Sekunden bis die IP wieder versuchen darf. 0 wenn nicht gelockt.
	 * Genutzt fuer Retry-After-Header.
	 */
	public function secondsUntilUnlock(string $ip): int
	{
		// PDO mit EMULATE_PREPARES=false bindet jeden Placeholder genau
		// einmal — :win waere im SELECT + WHERE doppelt referenziert. Wir
		// nutzen :win1/:win2 statt eines Aliases.
		$stmt = $this->db->prepare(
			'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(3),
			   (MIN(attempted_at) + INTERVAL :win1 MINUTE)) AS s
			 FROM admin_login_attempts
			 WHERE ip = :ip
			   AND success = 0
			   AND attempted_at > (UTC_TIMESTAMP(3) - INTERVAL :win2 MINUTE)'
		);
		$stmt->bindValue(':ip',   $ip);
		$stmt->bindValue(':win1', self::WINDOW_MINUTES, PDO::PARAM_INT);
		$stmt->bindValue(':win2', self::WINDOW_MINUTES, PDO::PARAM_INT);
		$stmt->execute();
		$s = (int)$stmt->fetchColumn();
		return max(0, $s);
	}

	/**
	 * Cron-Job-Helper: loescht Eintraege aelter als RETAIN_DAYS.
	 */
	public function cleanup(): int
	{
		$stmt = $this->db->prepare(
			'DELETE FROM admin_login_attempts
			 WHERE attempted_at < (UTC_TIMESTAMP(3) - INTERVAL :d DAY)'
		);
		$stmt->bindValue(':d', self::RETAIN_DAYS, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->rowCount();
	}
}
