<?php
declare(strict_types=1);

/**
 * Einmaliger Backlog-Cleanup: markiert Mails die in Outlook gelöscht
 * wurden, aber vor dem Tombstone-Fix (commit 9eae45e) in MailPilot
 * sichtbar geblieben sind, mit deleted_at.
 *
 * Funktionsweise: iteriert alle aktiven Mailboxen, holt pro Mail in der
 * DB einen Graph GET /me/messages/{id}. Liefert Graph 404, ist die Mail
 * in Outlook entfernt — wir markieren sie soft-gelöscht.
 *
 * Aufruf:
 *   docker exec mailpilot-worker php /app/bin/cleanup_orphans.php
 *   docker exec mailpilot-worker php /app/bin/cleanup_orphans.php --dry-run
 *   docker exec mailpilot-worker php /app/bin/cleanup_orphans.php --mailbox=<uuid>
 *
 * Sicher mehrfach ausführbar — markDeletedByMsId ist idempotent.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wait_for_db.php';

use MailPilot\Graph\GraphClient;
use MailPilot\Http\Kernel;
use MailPilot\Repositories\MailRepository;
use MailPilot\Services\TokenService;

$config = require __DIR__ . '/../config/config.php';
$kernel = new Kernel($config);
$pdo    = $kernel->get(\PDO::class);
$graph  = $kernel->get(GraphClient::class);
$tokens = $kernel->get(TokenService::class);
$mails  = $kernel->get(MailRepository::class);

$args        = $argv ?? [];
$dryRun      = in_array('--dry-run', $args, true);
$mailboxFlag = null;
foreach ($args as $a) {
	if (str_starts_with($a, '--mailbox=')) {
		$mailboxFlag = substr($a, strlen('--mailbox='));
	}
}

$mbSql = 'SELECT * FROM mailboxes WHERE sync_enabled = 1 AND deleted_at IS NULL';
if ($mailboxFlag !== null) {
	$mbSql .= ' AND id = :id';
	$stmt = $pdo->prepare($mbSql);
	$stmt->execute([':id' => $mailboxFlag]);
} else {
	$stmt = $pdo->query($mbSql);
}
$mailboxes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if ($mailboxes === []) {
	fwrite(STDERR, "Keine aktiven Mailboxen gefunden.\n");
	exit(1);
}

echo "Cleanup-Lauf — " . count($mailboxes) . " Mailbox(en)";
echo $dryRun ? " [DRY-RUN]\n" : "\n";

$totalScanned = 0;
$totalDeleted = 0;
$totalErrors  = 0;

foreach ($mailboxes as $mb) {
	echo "\nMailbox {$mb['email']} ({$mb['id']}):\n";

	try {
		$accessToken = $tokens->ensureFreshAccessToken($mb);
	} catch (\Throwable $e) {
		echo "  ! Token-Refresh fehlgeschlagen: " . $e->getMessage() . "\n";
		$totalErrors++;
		continue;
	}

	$rowStmt = $pdo->prepare('SELECT id, ms_message_id, subject FROM mails
		WHERE mailbox_id = :m AND tenant_id = :t AND deleted_at IS NULL
		ORDER BY received_at ASC');
	$rowStmt->execute([':m' => $mb['id'], ':t' => $mb['tenant_id']]);

	$scanned = 0;
	$deleted = 0;
	while ($row = $rowStmt->fetch(\PDO::FETCH_ASSOC)) {
		$scanned++;
		$msId = (string)$row['ms_message_id'];
		if ($msId === '') continue;

		try {
			$found = $graph->fetchMessage($accessToken, $msId);
		} catch (\Throwable $e) {
			// Anderer Fehler als 404 (z.B. 5xx, throttle nach Backoff) —
			// nicht löschen, nicht raten.
			echo "  ? {$row['id']} — Graph-Fehler: " . $e->getMessage() . "\n";
			$totalErrors++;
			continue;
		}

		if ($found === null) {
			if ($dryRun) {
				echo "  - [dry] would delete: {$row['subject']}\n";
			} else {
				$mails->markDeletedByMsId((string)$mb['tenant_id'], (string)$mb['id'], $msId);
				echo "  - deleted: {$row['subject']}\n";
			}
			$deleted++;
		}

		// Sanfte Drossel — Graph mag 100ms zwischen Calls.
		usleep(80_000);
	}

	echo "  -> {$scanned} geprüft, {$deleted} als gelöscht markiert\n";
	$totalScanned += $scanned;
	$totalDeleted += $deleted;
}

echo "\nSumme: {$totalScanned} geprüft, {$totalDeleted} gelöscht, {$totalErrors} Fehler\n";
exit($totalErrors > 0 ? 2 : 0);
