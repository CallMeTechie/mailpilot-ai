<?php
declare(strict_types=1);

/**
 * Admin UI config. Secrets via env.
 *
 * Admin users are defined in this file — intentionally not in DB.
 * Keeps admin access independent of regular user accounts.
 */

return [
	'admins' => [
		// username => password_hash (use password_hash('...', PASSWORD_ARGON2ID))
		getenv('ADMIN_USER') ?: 'admin' =>
			getenv('ADMIN_PASS_HASH') ?: '$argon2id$v=19$m=65536,t=4,p=1$REPLACE_ME',
	],

	'session_ttl' => 3600,  // 1 hour idle timeout
	'require_ip_allowlist' => filter_var(getenv('ADMIN_IP_RESTRICT') ?: 'false', FILTER_VALIDATE_BOOL),
	'allowed_ips' => array_filter(explode(',', (string)(getenv('ADMIN_ALLOWED_IPS') ?: ''))),
];
