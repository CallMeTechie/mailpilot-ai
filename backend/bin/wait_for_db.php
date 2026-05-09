<?php
declare(strict_types=1);

/**
 * Block until the application can connect to the DB as the app user.
 *
 * Designed to be `require_once`'d at the start of long-running processes
 * (worker.php) so they don't crash-loop when the DB is still coming up
 * or has the wrong host grant.
 *
 * Behaviour:
 *  - SQLSTATE 2002 (connection refused): DB is not up yet — wait + retry
 *  - SQLSTATE 1045 (access denied):       wrong password — wait + retry,
 *                                         maybe MariaDB still applying init
 *  - SQLSTATE 1130 (host not allowed):    user is bound to wrong host —
 *                                         if DB_ROOT_PASS is available,
 *                                         self-heal via ensure_db_user.php
 *
 * Total budget: WAIT_FOR_DB_TIMEOUT seconds (default 60). After that, throw
 * a RuntimeException so the supervising process surfaces a clear failure.
 *
 * Idempotent and safe to require multiple times.
 */

(static function (): void {
	if (defined('MAILPILOT_DB_READY')) {
		return;
	}

	$host = getenv('DB_HOST') ?: 'mariadb';
	$user = getenv('DB_USER') ?: 'mailpilot';
	$pass = getenv('DB_PASS') ?: '';
	$db   = getenv('DB_NAME') ?: 'mailpilot';
	$rootPass = getenv('DB_ROOT_PASS') ?: '';

	$timeout = (int)(getenv('WAIT_FOR_DB_TIMEOUT') ?: 60);
	$dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";

	$start = time();
	$triedEnsure = false;
	$attempt = 0;

	while (true) {
		$attempt++;
		try {
			new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
			define('MAILPILOT_DB_READY', true);
			fwrite(STDOUT, "wait_for_db: OK after {$attempt} attempt(s)\n");
			return;
		} catch (\PDOException $e) {
			$msg = $e->getMessage();

			// 1130 = host not allowed; 1045 = access denied (user exists with
			// different password / wrong host). Both are self-healable when
			// DB_ROOT_PASS is reachable: ensure_db_user.php will (re)create
			// the user with the right host + password.
			$healable = str_contains($msg, '1130') || str_contains($msg, '1045');
			if ($healable && !$triedEnsure && $rootPass !== '') {
				fwrite(STDOUT, "wait_for_db: heal-able error detected, running ensure_db_user.php\n");
				$ensureScript = __DIR__ . '/ensure_db_user.php';
				if (is_file($ensureScript)) {
					try {
						(static fn(string $p) => require $p)($ensureScript);
					} catch (\Throwable $t) {
						fwrite(STDERR, "wait_for_db: ensure_db_user failed: {$t->getMessage()}\n");
					}
				}
				$triedEnsure = true;
				continue;
			}

			$elapsed = time() - $start;
			if ($elapsed >= $timeout) {
				throw new \RuntimeException(
					"wait_for_db: gave up after {$elapsed}s, last error: {$msg}",
				);
			}
			fwrite(STDOUT, "wait_for_db: not ready ({$msg}), retry in 2s\n");
			sleep(2);
		}
	}
})();
