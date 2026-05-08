<?php
declare(strict_types=1);

/**
 * Admin UI config. Secrets via env.
 *
 * Admin users are defined in this file — intentionally not in DB.
 * Keeps admin access independent of regular user accounts.
 */

// Resolve admin password hash. We support two sources because docker compose
// (and DSM Container Manager) mangles '$' inside .env values via variable
// substitution, which destroys raw bcrypt/argon2 hashes. Use the base64-
// encoded variant if your environment can't preserve raw hashes.
$adminHash = (function (): string {
	$b64 = getenv('ADMIN_PASS_HASH_B64');
	if (is_string($b64) && $b64 !== '') {
		$decoded = base64_decode($b64, true);
		if ($decoded !== false && $decoded !== '') {
			return $decoded;
		}
	}
	return (string)(getenv('ADMIN_PASS_HASH') ?: '');
})();

return [
	'admins' => [
		getenv('ADMIN_USER') ?: 'admin' => $adminHash,
	],

	'session_ttl' => 3600,  // 1 hour idle timeout
	'require_ip_allowlist' => filter_var(getenv('ADMIN_IP_RESTRICT') ?: 'false', FILTER_VALIDATE_BOOL),
	'allowed_ips' => array_filter(explode(',', (string)(getenv('ADMIN_ALLOWED_IPS') ?: ''))),
];
