<?php
declare(strict_types=1);

/**
 * Bestands-Recovery für Phantom-Deletes aus dem Sprint-6f-Tombstone-Bug
 * (2026-05-15 Diagnose).
 *
 * Problem: SyncService::run hat bei @removed-Delta-Events blind
 * markDeletedByMsId gerufen. Aber @removed im Inbox-Folder-Delta heißt
 * NICHT zwingend „Mail gelöscht" — es heißt auch „Mail aus Inbox in
 * anderen Folder verschoben". Konsequenz: jede AutoSort-Move-Aktion
 * hinterließ ihre Mail als deleted_at markiert.
 *
 * Dieses Script kehrt das um: für jede deleted_at-Mail der letzten N
 * Tage prüfen wir via Graph, ob sie noch existiert. Wenn ja → clearen.
 *
 * Aufruf:
 *   docker exec mailpilot-worker php /app/bin/recover_phantoms.php --dry-run
 *   docker exec mailpilot-worker php /app/bin/recover_phantoms.php
 *   docker exec mailpilot-worker php /app/bin/recover_phantoms.php --since-days=14
 *
 * Idempotent — Mails die echt weg sind, werden in Ruhe gelassen.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wait_for_db.php';

use MailPilot\Graph\GraphClient;
use MailPilot\Http\Kernel;
use MailPilot\Services\TokenService;

$config = require __DIR__ . '/../config/config.php';
$kernel = new Kernel($config);
$pdo    = $kernel->get(\PDO::class);
$graph  = $kernel->get(GraphClient::class);
$tokens = $kernel->get(TokenService::class);

$args      = $argv ?? [];
$dryRun    = in_array('--dry-run', $args, true);
$sinceDays = 7;
foreach ($args as $a) {
	if (str_starts_with($a, '--since-days=')) {
		$sinceDays = max(1, (int)substr($a, strlen('--since-days=')));
	}
}

$mailboxes = $pdo->query('SELECT * FROM mailboxes
	WHERE sync_enabled = 1 AND deleted_at IS NULL')->fetchAll(\PDO::FETCH_ASSOC);

if ($mailboxes === []) {
	fwrite(STDERR, "Keine aktiven Mailboxen gefunden.\n");
	exit(1);
}

echo "Recovery-Lauf — " . count($mailboxes) . " Mailbox(en), "
	. "deleted_at >= jetzt - {$sinceDays} Tage";
echo $dryRun ? " [DRY-RUN]\n" : "\n";

$totalScanned   = 0;
$totalRecovered = 0;
$totalConfirmed = 0;
$totalErrors    = 0;

foreach ($mailboxes as $mb) {
	echo "\nMailbox {$mb['email']}:\n";

	try {
		$accessToken = $tokens->ensureFreshAccessToken($mb);
	} catch (\Throwable $e) {
		echo "  ! Token-Refresh fehlgeschlagen: " . $e->getMessage() . "\n";
		$totalErrors++;
		continue;
	}

	$stmt = $pdo->prepare('SELECT id, ms_message_id, subject, deleted_at
		FROM mails
		WHERE mailbox_id = :m AND tenant_id = :t
		  AND deleted_at IS NOT NULL
		  AND deleted_at >= (UTC_TIMESTAMP(3) - INTERVAL :d DAY)
		ORDER BY deleted_at DESC');
	$stmt->bindValue(':m', $mb['id']);
	$stmt->bindValue(':t', $mb['tenant_id']);
	$stmt->bindValue(':d', $sinceDays, \PDO::PARAM_INT);
	$stmt->execute();

	$undelete = $pdo->prepare('UPDATE mails SET deleted_at = NULL
		WHERE id = :id AND tenant_id = :t');

	$scanned = 0;
	$recovered = 0;
	$confirmed = 0;
	while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
		$scanned++;
		$msId = (string)$row['ms_message_id'];
		if ($msId === '') {
			continue;
		}

		try {
			$found = $graph->fetchMessage($accessToken, $msId);
		} catch (\Throwable $e) {
			echo "  ? Graph-Fehler für {$row['id']}: " . $e->getMessage() . "\n";
			$totalErrors++;
			continue;
		}

		if ($found !== null) {
			// Mail lebt noch — false-positive-Delete vom Tombstone-Bug.
			if ($dryRun) {
				echo "  + [dry] would recover: {$row['subject']}\n";
			} else {
				$undelete->execute([':id' => $row['id'], ':t' => $mb['tenant_id']]);
				echo "  + recovered: {$row['subject']}\n";
			}
			$recovered++;
		} else {
			// Echt gelöscht — deleted_at war korrekt, nichts zu tun.
			$confirmed++;
		}

		usleep(80_000);
	}

	echo "  -> {$scanned} geprüft, {$recovered} recovered, {$confirmed} bestätigt gelöscht\n";
	$totalScanned   += $scanned;
	$totalRecovered += $recovered;
	$totalConfirmed += $confirmed;
}

echo "\nSumme: {$totalScanned} geprüft, {$totalRecovered} recovered, "
	. "{$totalConfirmed} bestätigt gelöscht, {$totalErrors} Fehler\n";
exit($totalErrors > 0 ? 2 : 0);
