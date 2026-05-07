<?php
declare(strict_types=1);

/**
 * MailPilot AI — migration runner.
 *
 * Applies all SQL files in migrations/ in lexicographic order. A row in
 * schema_migrations records each applied version. Uses GET_LOCK to prevent
 * concurrent runs (e.g. two containers booting simultaneously).
 *
 *   php bin/migrate.php          # apply pending migrations
 *   php bin/migrate.php --status # list applied/pending without changes
 */

require_once __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$db = $config['db'];

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
	$db['host'], $db['port'], $db['name'], $db['charset']);
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
	PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
	PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	PDO::ATTR_EMULATE_PREPARES   => true,
	PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', NAMES utf8mb4",
]);

$migrationsDir = __DIR__ . '/../migrations';
$files = glob($migrationsDir . '/[0-9]*.sql') ?: [];
sort($files, SORT_STRING);

$statusOnly = in_array('--status', $argv ?? [], true);

$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS schema_migrations (
	version    VARCHAR(64)  NOT NULL PRIMARY KEY,
	applied_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$pending = [];
foreach ($files as $file) {
	$version = basename($file, '.sql');
	if (!isset($applied[$version])) {
		$pending[] = ['version' => $version, 'file' => $file];
	}
}

if ($statusOnly) {
	echo "Applied:\n";
	foreach (array_keys($applied) as $v) {
		echo "  ✓ {$v}\n";
	}
	echo "\nPending:\n";
	if ($pending === []) {
		echo "  (none)\n";
	} else {
		foreach ($pending as $p) {
			echo "  ○ {$p['version']}\n";
		}
	}
	exit(0);
}

if ($pending === []) {
	echo "No pending migrations.\n";
	exit(0);
}

$lockName = "mailpilot_migrate_{$db['name']}";
$lockStmt = $pdo->prepare('SELECT GET_LOCK(:l, 30) AS got');
$lockStmt->execute([':l' => $lockName]);
$lockRow = $lockStmt->fetch();
if (!$lockRow || (int)$lockRow['got'] !== 1) {
	fwrite(STDERR, "Could not acquire migration lock — another run in progress?\n");
	exit(2);
}

try {
	foreach ($pending as $p) {
		echo "Applying {$p['version']} ...\n";
		$sql = file_get_contents($p['file']);
		if ($sql === false) {
			throw new RuntimeException("Cannot read {$p['file']}");
		}

		$pdo->exec($sql);
		$pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:v)')
			->execute([':v' => $p['version']]);

		echo "  ✓ {$p['version']}\n";
	}
	echo "\nAll migrations applied.\n";
} finally {
	$pdo->prepare('SELECT RELEASE_LOCK(:l)')->execute([':l' => $lockName]);
}
