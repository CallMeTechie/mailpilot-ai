<?php
declare(strict_types=1);

/**
 * MailPilot AI — config.example.php
 * Copy to config.php and fill in. Never commit config.php.
 */

return [
	'app' => [
		'env'          => getenv('APP_ENV') ?: 'prod',
		'debug'        => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL),
		// APP_BASE_URL must be set per deployment — there is no sensible default.
		'base_url'     => getenv('APP_BASE_URL') ?: '',
		'jwt_secret'   => getenv('JWT_SECRET') ?: '',
		// JWT-TTL: 1h default is short for the bulk operations the add-in
		// runs (rescore-all + apply-now can take 10+ minutes). The add-in
		// has a 401-retry-with-refresh fallback (Sprint 0.1), but a
		// generous TTL keeps the refresh path off the hot path.
		'jwt_ttl'      => (int)(getenv('JWT_TTL') ?: 28800),
		'jwt_issuer'   => getenv('JWT_ISSUER')   ?: 'mailpilot.ai',
		'jwt_audience' => getenv('JWT_AUDIENCE') ?: 'mailpilot-addin',
		'encrypt_key'  => getenv('ENCRYPT_KEY') ?: '', // 32 bytes hex for AES-256-GCM
	],

	'cors' => [
		// Comma-separated list of allowed origins.
		// Outlook Web Add-in uses outlook.office.com / outlook.office365.com.
		'allowed_origins' => array_values(array_filter(array_map('trim',
			explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: 'https://outlook.office.com,https://outlook.office365.com')
		))),
	],

	'db' => [
		'host'     => getenv('DB_HOST') ?: 'mariadb',
		'port'     => (int)(getenv('DB_PORT') ?: 3306),
		'name'     => getenv('DB_NAME') ?: 'mailpilot',
		'user'     => getenv('DB_USER') ?: 'mailpilot',
		'pass'     => getenv('DB_PASS') ?: '',
		'charset'  => 'utf8mb4',
	],

	'redis' => [
		'host' => getenv('REDIS_HOST') ?: 'redis',
		'port' => (int)(getenv('REDIS_PORT') ?: 6379),
	],

	'claude' => [
		// 'anthropic' (default) or 'bedrock'
		'provider'      => getenv('CLAUDE_PROVIDER') ?: 'anthropic',

		// Direct Anthropic API (provider = 'anthropic')
		'api_key'       => getenv('CLAUDE_API_KEY') ?: '',
		'base_url'      => 'https://api.anthropic.com/v1',
		'anthropic_version' => '2023-06-01',

		// AWS Bedrock (provider = 'bedrock')
		'bedrock' => [
			'access_key'    => getenv('AWS_ACCESS_KEY_ID')     ?: '',
			'secret_key'    => getenv('AWS_SECRET_ACCESS_KEY') ?: '',
			'session_token' => getenv('AWS_SESSION_TOKEN')     ?: null,
			'region'        => getenv('AWS_REGION')            ?: 'eu-central-1',
			'model_map'     => [
				// Anthropic logical name → Bedrock model ID.
				// Cross-region inference profile IDs (prefix "eu.") are required
				// for most Anthropic models in EU regions as of 2025.
				'claude-haiku-4-5-20251001' => 'eu.anthropic.claude-haiku-4-5-v1:0',
				'claude-opus-4-7'           => 'eu.anthropic.claude-opus-4-7-v1:0',
			],
		],

		'model_scoring' => 'claude-haiku-4-5-20251001',
		'model_summary' => 'claude-opus-4-7',
		'model_reply'   => 'claude-opus-4-7',
		'timeout'       => 30,
	],

	'graph' => [
		'client_id'     => getenv('MS_CLIENT_ID') ?: '',
		'client_secret' => getenv('MS_CLIENT_SECRET') ?: '',
		'redirect_uri'  => getenv('MS_REDIRECT_URI') ?: '',
		'tenant'        => 'common',
		'scopes'        => 'offline_access Mail.Read Mail.ReadWrite MailboxSettings.Read User.Read',
	],

	'limits' => [
		'scoring_batch_size'  => 20,
		'max_body_bytes'      => 2048,
		'cache_ttl_days'      => 30,
		'body_retention_days' => 7,
	],

	'log' => [
		'path'  => __DIR__ . '/../../var/log/app.log',
		'level' => 'info',
	],
];
