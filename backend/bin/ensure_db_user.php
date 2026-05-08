<?php
declare(strict_types=1);

/**
 * Ensure the application DB user exists with a wildcard host grant.
 *
 * The mariadb image normally creates the MARIADB_USER with host '%' on
 * first startup, but in some volume states (e.g. when the DB was
 * initialised manually, or by an older image) the user only exists for
 * 'localhost'. The worker / backend / admin containers then connect from
 * other compose-network IPs and get SQLSTATE[HY000] [1130]:
 *   Host '172.x.y.z' is not allowed to connect to this MariaDB server
 *
 * This script is run by the 'migrate' service before bin/migrate.php so
 * the schema can be created against an account that's actually reachable.
 *
 * It uses the root credentials (DB_ROOT_PASS) only for this fix-up.
 * Idempotent: re-running it is a no-op.
 */

$host = getenv('DB_HOST') ?: 'mariadb';
$rootPass = getenv('DB_ROOT_PASS');
$user = getenv('DB_USER') ?: 'mailpilot';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'mailpilot';

if ($rootPass === false || $rootPass === '') {
	fwrite(STDERR, "DB_ROOT_PASS not set — cannot ensure DB user grants\n");
	exit(1);
}

try {
	$pdo = new PDO(
		"mysql:host={$host};dbname=mysql;charset=utf8mb4",
		'root',
		$rootPass,
		[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
	);
} catch (\PDOException $e) {
	fwrite(STDERR, "Cannot connect as root: {$e->getMessage()}\n");
	exit(2);
}

// User / database identifiers via backticks. Password through PDO::quote.
$userIdent = '`' . str_replace('`', '``', $user) . '`';
$dbIdent   = '`' . str_replace('`', '``', $db) . '`';
$passLit   = $pdo->quote($pass);

$pdo->exec("CREATE USER IF NOT EXISTS {$userIdent}@'%' IDENTIFIED BY {$passLit}");
$pdo->exec("ALTER USER {$userIdent}@'%' IDENTIFIED BY {$passLit}");
$pdo->exec("CREATE DATABASE IF NOT EXISTS {$dbIdent} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("GRANT ALL PRIVILEGES ON {$dbIdent}.* TO {$userIdent}@'%'");
$pdo->exec("FLUSH PRIVILEGES");

echo "DB user {$user}@'%' ensured for database {$db}.\n";
