<?php
declare(strict_types=1);

/**
 * Quick smoke test — run after deploy to verify core wiring.
 *
 *   php bin/smoke.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use MailPilot\Claude\ClaudeClient;
use MailPilot\Http\Kernel;
use MailPilot\Services\RedactionService;

$config = require __DIR__ . '/../config/config.php';
$kernel = new Kernel($config);

$fail = 0;
$pass = 0;

function check(string $label, bool $ok, string $detail = ''): void {
	global $pass, $fail;
	if ($ok) {
		echo "  \e[32m✓\e[0m {$label}\n";
		$pass++;
	} else {
		echo "  \e[31m✗\e[0m {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
		$fail++;
	}
}

echo "\n\033[1mMailPilot smoke test\033[0m\n\n";

// --- 1. Config ---
echo "Config\n";
check('jwt_secret set',    $config['app']['jwt_secret']   !== '');
check('encrypt_key set',   $config['app']['encrypt_key']  !== '' && strlen($config['app']['encrypt_key']) === 64);
check('claude api_key set',$config['claude']['api_key']   !== '');
check('graph client_id',   $config['graph']['client_id']  !== '');

// --- 2. DB ---
echo "\nDatabase\n";
try {
	$pdo = $kernel->get(PDO::class);
	$row = $pdo->query('SELECT VERSION() AS v')->fetch();
	check('PDO connect', true, "MariaDB {$row['v']}");

	$tables = ['tenants','users','mailboxes','mails','mail_scores','claude_cache','oauth_states','sync_jobs'];
	foreach ($tables as $t) {
		$ok = (bool)$pdo->query("SHOW TABLES LIKE '{$t}'")->fetch();
		check("table: {$t}", $ok);
	}
} catch (\Throwable $e) {
	check('PDO connect', false, $e->getMessage());
}

// --- 3. Redaction ---
echo "\nRedaction\n";
$red = new RedactionService();
check('IBAN redacted', str_contains($red->redact('Meine IBAN DE89 3704 0044 0532 0130 00 bitte.'), '[IBAN-REDACTED]'));
check('CC redacted',   str_contains($red->redact('Karte 4111 1111 1111 1111'), '[CC-REDACTED]'));

// --- 4. Claude API reachable ---
echo "\nClaude API\n";
try {
	$claude = $kernel->get(ClaudeClient::class);
	$resp = $claude->messages([
		'model'      => $config['claude']['model_scoring'],
		'max_tokens' => 20,
		'messages'   => [['role' => 'user', 'content' => 'Say "ok" only.']],
	]);
	$text = ClaudeClient::extractText($resp);
	check('Claude responds', $text !== '', substr($text, 0, 80));
} catch (\Throwable $e) {
	check('Claude responds', false, $e->getMessage());
}

echo "\n\033[1mResult: {$pass} passed, {$fail} failed\033[0m\n\n";
exit($fail > 0 ? 1 : 0);
