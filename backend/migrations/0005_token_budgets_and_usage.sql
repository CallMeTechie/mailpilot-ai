-- 0005_token_budgets_and_usage.sql
--
-- Token-Budget enforcement + Usage tracking.
--
-- system_settings  → free-form key/value store for budgets and feature flags
-- model_pricing    → per-model EUR pricing (admin-maintained, no FX logic)
-- api_usage        → per-call record (~30d retention, see worker housekeeping)
-- usage_daily      → daily aggregate (long retention, drives admin charts)

CREATE TABLE IF NOT EXISTS system_settings (
	`key`        VARCHAR(100) NOT NULL PRIMARY KEY,
	`value`      TEXT NOT NULL,
	`type`       ENUM('int','float','string','bool','json') NOT NULL DEFAULT 'string',
	description  VARCHAR(500) DEFAULT NULL,
	updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (`key`, `value`, `type`, description) VALUES
	('budget.global.daily_tokens', '5000000', 'int',    'System-wide daily output-token ceiling across all tenants'),
	('budget.tenant.daily_tokens', '2000000', 'int',    'Default daily output-token ceiling per tenant'),
	('budget.user.daily_tokens',   '100000',  'int',    'Default daily output-token ceiling per user'),
	('budget.enforcement_mode',    'enforce', 'string', 'enforce | log_only — log_only never blocks (for soft rollout)');

CREATE TABLE IF NOT EXISTS model_pricing (
	model                      VARCHAR(100) NOT NULL PRIMARY KEY,
	input_eur_per_1m           DECIMAL(10,4) NOT NULL,
	output_eur_per_1m          DECIMAL(10,4) NOT NULL,
	cache_read_eur_per_1m      DECIMAL(10,4) DEFAULT NULL,
	cache_creation_eur_per_1m  DECIMAL(10,4) DEFAULT NULL,
	effective_from             DATE NOT NULL DEFAULT (CURRENT_DATE),
	updated_at                 DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed prices: Anthropic list prices converted at ~0.92 EUR/USD (May 2026).
-- Admin pflegt die Werte über das Settings-UI nach.
INSERT IGNORE INTO model_pricing (model, input_eur_per_1m, output_eur_per_1m, cache_read_eur_per_1m, cache_creation_eur_per_1m) VALUES
	('claude-haiku-4-5-20251001', 0.9200,  4.6000,  0.0920,  1.1500),
	('claude-opus-4-7',          13.8000, 69.0000,  1.3800, 17.2500),
	('claude-sonnet-4-6',         2.7600, 13.8000,  0.2760,  3.4500);

CREATE TABLE IF NOT EXISTS api_usage (
	id                     CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id              CHAR(36) NOT NULL,
	user_id                CHAR(36) DEFAULT NULL,
	mailbox_id             CHAR(36) DEFAULT NULL,
	mail_id                CHAR(36) DEFAULT NULL,
	prompt_version         VARCHAR(50) NOT NULL,
	model                  VARCHAR(100) NOT NULL,
	input_tokens           INT UNSIGNED NOT NULL DEFAULT 0,
	output_tokens          INT UNSIGNED NOT NULL DEFAULT 0,
	cache_read_tokens      INT UNSIGNED NOT NULL DEFAULT 0,
	cache_creation_tokens  INT UNSIGNED NOT NULL DEFAULT 0,
	cost_eur               DECIMAL(10,6) NOT NULL DEFAULT 0,
	duration_ms            INT UNSIGNED NOT NULL DEFAULT 0,
	status                 ENUM('success','error','blocked') NOT NULL,
	error_text             VARCHAR(500) DEFAULT NULL,
	created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	INDEX idx_tenant_created (tenant_id, created_at),
	INDEX idx_user_created   (user_id, created_at),
	INDEX idx_created        (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_daily (
	`date`                 DATE NOT NULL,
	tenant_id              CHAR(36) NOT NULL,
	user_id                CHAR(36) NOT NULL DEFAULT '',
	prompt_version         VARCHAR(50) NOT NULL,
	model                  VARCHAR(100) NOT NULL,
	calls                  INT UNSIGNED NOT NULL DEFAULT 0,
	input_tokens           BIGINT UNSIGNED NOT NULL DEFAULT 0,
	output_tokens          BIGINT UNSIGNED NOT NULL DEFAULT 0,
	cache_read_tokens      BIGINT UNSIGNED NOT NULL DEFAULT 0,
	cache_creation_tokens  BIGINT UNSIGNED NOT NULL DEFAULT 0,
	cost_eur               DECIMAL(12,4) NOT NULL DEFAULT 0,
	blocked_count          INT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (`date`, tenant_id, user_id, prompt_version, model),
	INDEX idx_date         (`date`),
	INDEX idx_tenant_date  (tenant_id, `date`),
	INDEX idx_user_date    (user_id, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
