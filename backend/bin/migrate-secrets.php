<?php
declare(strict_types=1);

/**
 * MailPilot AI — Secret-Migration (Phase 9q-A.5, Marc 2026-05-22).
 *
 * Ueberfuehrt API-Keys aus env-Vars in die llm_providers.api_key_encrypted-
 * Spalte. Idempotent: wenn der Key in der DB schon liegt, no-op. Der env-
 * Fallback (via api_key_env_fallback) bleibt bestehen, damit ein Worker
 * der den DB-Master-Key nicht laden kann trotzdem klassifizieren kann.
 *
 * Wird vom Docker-Entrypoint nach bin/migrate.php ausgefuehrt. Sicher
 * mehrmals aufrufbar (z.B. nach Container-Restart).
 *
 * Aktuell migriert nur ANTHROPIC_API_KEY → Anthropic-Provider-Row. Ab
 * Phase 9q-B (OpenAI-Provider) kommen OPENAI_API_KEY, GEMINI_API_KEY etc.
 * dazu — gleiche Mechanik.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wait_for_db.php';

use MailPilot\Repositories\LlmProviderRepository;
use MailPilot\Security\SecretBox;

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

try {
	$box = new SecretBox();
} catch (\Throwable $e) {
	// Kein Master-Key → wir koennen nicht migrieren. Backend laeuft via
	// env-Fallback weiter, daher kein fatal. Log + exit 0.
	fwrite(STDERR, "[migrate-secrets] kein Master-Key — skip ({$e->getMessage()})\n");
	exit(0);
}

$repo = new LlmProviderRepository($pdo);
$migrated = 0;
$skipped  = 0;

foreach ($repo->listAll(includeDisabled: true) as $provider) {
	if (($provider['api_key_encrypted'] ?? null) !== null && $provider['api_key_encrypted'] !== '') {
		$skipped++;
		continue;
	}
	$envName = (string)($provider['api_key_env_fallback'] ?? '');
	if ($envName === '') {
		$skipped++;
		continue;
	}
	$envVal = (string)(getenv($envName) ?: '');
	if ($envVal === '') {
		echo "[migrate-secrets] {$provider['name']}: env-Var {$envName} nicht gesetzt, skip\n";
		$skipped++;
		continue;
	}
	try {
		$encrypted = $box->encrypt($envVal);
		$repo->updateEncryptedKey((string)$provider['id'], $encrypted);
		echo "[migrate-secrets] {$provider['name']}: {$envName} → llm_providers.api_key_encrypted\n";
		$migrated++;
	} catch (\Throwable $e) {
		fwrite(STDERR, "[migrate-secrets] {$provider['name']}: fail ({$e->getMessage()})\n");
	}
}

echo "[migrate-secrets] fertig — {$migrated} migriert, {$skipped} skipped\n";
