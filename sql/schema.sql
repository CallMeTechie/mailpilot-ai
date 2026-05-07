-- MailPilot AI — Schema v1
-- MariaDB 10.11+
-- Convention: UUIDs as CHAR(36), timestamps as DATETIME(3) UTC, soft-delete via deleted_at

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- TENANTS & USERS
-- ============================================================

CREATE TABLE tenants (
	id           CHAR(36) NOT NULL PRIMARY KEY,
	name         VARCHAR(200) NOT NULL,
	plan         ENUM('free','pro','team','enterprise') NOT NULL DEFAULT 'free',
	created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	deleted_at   DATETIME(3) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
	id            CHAR(36) NOT NULL PRIMARY KEY,
	email         VARCHAR(320) NOT NULL,
	display_name  VARCHAR(200) NULL,
	language      CHAR(2) NOT NULL DEFAULT 'de',
	timezone      VARCHAR(64) NOT NULL DEFAULT 'Europe/Berlin',
	briefing_hour TINYINT UNSIGNED NOT NULL DEFAULT 7,
	last_login_at DATETIME(3) NULL,
	created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	deleted_at    DATETIME(3) NULL,
	UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tenant_user (
	tenant_id   CHAR(36) NOT NULL,
	user_id     CHAR(36) NOT NULL,
	role        ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
	created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	PRIMARY KEY (tenant_id, user_id),
	KEY idx_tu_user (user_id),
	CONSTRAINT fk_tu_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_tu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MAILBOXES (M365 accounts connected by users)
-- ============================================================

CREATE TABLE mailboxes (
	id                    CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id             CHAR(36) NOT NULL,
	user_id               CHAR(36) NOT NULL,
	email                 VARCHAR(320) NOT NULL,
	display_name          VARCHAR(200) NULL,
	ms_tenant_id          VARCHAR(64) NULL,
	ms_user_id            VARCHAR(128) NULL,
	refresh_token_enc     BLOB NOT NULL,          -- AES-256-GCM encrypted
	access_token_enc      BLOB NULL,
	access_token_expires  DATETIME(3) NULL,
	scopes                VARCHAR(500) NOT NULL,
	last_sync_at          DATETIME(3) NULL,
	delta_token           TEXT NULL,              -- Graph delta query token
	sync_enabled          TINYINT(1) NOT NULL DEFAULT 1,
	created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	deleted_at            DATETIME(3) NULL,
	UNIQUE KEY uq_mailbox (tenant_id, email),
	KEY idx_mb_user (user_id),
	CONSTRAINT fk_mb_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_mb_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MAILS & SCORES
-- ============================================================

CREATE TABLE mails (
	id                CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id         CHAR(36) NOT NULL,
	mailbox_id        CHAR(36) NOT NULL,
	ms_message_id     VARCHAR(255) NOT NULL,
	conversation_id   VARCHAR(255) NULL,
	internet_msg_id   VARCHAR(500) NULL,
	from_email        VARCHAR(320) NOT NULL,
	from_name         VARCHAR(200) NULL,
	to_json           JSON NOT NULL,
	cc_json           JSON NULL,
	subject           VARCHAR(500) NOT NULL,
	body_preview      VARCHAR(500) NULL,
	body_text         MEDIUMTEXT NULL,            -- purged after 7 days
	body_purged_at    DATETIME(3) NULL,
	has_attachment    TINYINT(1) NOT NULL DEFAULT 0,
	is_reply          TINYINT(1) NOT NULL DEFAULT 0,
	list_unsubscribe  TINYINT(1) NOT NULL DEFAULT 0,
	received_at       DATETIME(3) NOT NULL,
	created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	deleted_at        DATETIME(3) NULL,
	UNIQUE KEY uq_mail (tenant_id, mailbox_id, ms_message_id),
	KEY idx_mails_received (tenant_id, mailbox_id, received_at),
	KEY idx_mails_conv (conversation_id),
	CONSTRAINT fk_mail_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_mail_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mail_scores (
	id                CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id         CHAR(36) NOT NULL,
	mail_id           CHAR(36) NOT NULL,
	label             ENUM('direct','action','cc','newsletter','auto','noise') NOT NULL,
	action_required   TINYINT(1) NOT NULL DEFAULT 0,
	priority          TINYINT UNSIGNED NOT NULL,
	summary           VARCHAR(200) NOT NULL,
	reasoning         VARCHAR(200) NULL,
	prompt_version    VARCHAR(32) NOT NULL,
	model             VARCHAR(64) NOT NULL,
	cached            TINYINT(1) NOT NULL DEFAULT 0,
	scored_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	UNIQUE KEY uq_score_mail (mail_id),
	KEY idx_scores_label (tenant_id, label, priority),
	CONSTRAINT fk_score_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_score_mail FOREIGN KEY (mail_id) REFERENCES mails(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mail_summaries (
	id              CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id       CHAR(36) NOT NULL,
	mail_id         CHAR(36) NOT NULL,
	summary_text    TEXT NOT NULL,
	prompt_version  VARCHAR(32) NOT NULL,
	model           VARCHAR(64) NOT NULL,
	generated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	UNIQUE KEY uq_summary_mail (mail_id),
	CONSTRAINT fk_sum_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_sum_mail FOREIGN KEY (mail_id) REFERENCES mails(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reply_drafts (
	id              CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id       CHAR(36) NOT NULL,
	mail_id         CHAR(36) NOT NULL,
	draft_text      TEXT NOT NULL,
	user_instruction VARCHAR(500) NULL,
	prompt_version  VARCHAR(32) NOT NULL,
	model           VARCHAR(64) NOT NULL,
	generated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_draft_mail (mail_id),
	CONSTRAINT fk_drft_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_drft_mail FOREIGN KEY (mail_id) REFERENCES mails(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- CLAUDE CACHE (de-dup same-content scoring)
-- ============================================================

CREATE TABLE claude_cache (
	content_hash    CHAR(64) NOT NULL PRIMARY KEY,   -- SHA-256 hex
	tenant_id       CHAR(36) NOT NULL,
	result_json     JSON NOT NULL,
	prompt_version  VARCHAR(32) NOT NULL,
	model           VARCHAR(64) NOT NULL,
	hits            INT UNSIGNED NOT NULL DEFAULT 1,
	created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	last_hit_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_cache_tenant (tenant_id),
	KEY idx_cache_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- USER SETTINGS
-- ============================================================

CREATE TABLE vip_senders (
	id           CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id    CHAR(36) NOT NULL,
	user_id      CHAR(36) NOT NULL,
	email        VARCHAR(320) NOT NULL,
	display_name VARCHAR(200) NULL,
	created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	deleted_at   DATETIME(3) NULL,
	UNIQUE KEY uq_vip (user_id, email),
	CONSTRAINT fk_vip_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_vip_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE redaction_rules (
	id           CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id    CHAR(36) NOT NULL,
	user_id      CHAR(36) NULL,                    -- NULL = tenant-wide
	pattern      VARCHAR(500) NOT NULL,
	description  VARCHAR(200) NULL,
	enabled      TINYINT(1) NOT NULL DEFAULT 1,
	created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	deleted_at   DATETIME(3) NULL,
	KEY idx_red_tenant (tenant_id, enabled),
	CONSTRAINT fk_red_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_red_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_keywords (
	id          CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id   CHAR(36) NOT NULL,
	user_id     CHAR(36) NOT NULL,
	keyword     VARCHAR(200) NOT NULL,
	created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	deleted_at  DATETIME(3) NULL,
	UNIQUE KEY uq_kw (user_id, keyword),
	CONSTRAINT fk_kw_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_kw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PROMPTS & AUDIT
-- ============================================================

CREATE TABLE prompt_versions (
	id             CHAR(36) NOT NULL PRIMARY KEY,
	key_name       VARCHAR(50) NOT NULL,     -- P-SCORE, P-SUMMARY, P-REPLY
	version        VARCHAR(32) NOT NULL,     -- v1.0
	system_prompt  TEXT NOT NULL,
	user_template  TEXT NOT NULL,
	model          VARCHAR(64) NOT NULL,
	max_tokens     INT UNSIGNED NOT NULL,
	temperature    DECIMAL(3,2) NOT NULL,
	active         TINYINT(1) NOT NULL DEFAULT 0,
	created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	UNIQUE KEY uq_prompt (key_name, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
	id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	tenant_id   CHAR(36) NULL,
	user_id     CHAR(36) NULL,
	event       VARCHAR(100) NOT NULL,
	entity      VARCHAR(100) NULL,
	entity_id   VARCHAR(64) NULL,
	meta_json   JSON NULL,
	ip          VARCHAR(45) NULL,
	created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_audit_tenant_time (tenant_id, created_at),
	KEY idx_audit_event (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sync_jobs (
	id             CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id      CHAR(36) NOT NULL,
	mailbox_id     CHAR(36) NOT NULL,
	status         ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
	total          INT UNSIGNED NOT NULL DEFAULT 0,
	processed      INT UNSIGNED NOT NULL DEFAULT 0,
	error_text     TEXT NULL,
	started_at     DATETIME(3) NULL,
	finished_at    DATETIME(3) NULL,
	created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_sj_status (status),
	CONSTRAINT fk_sj_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_sj_mailbox FOREIGN KEY (mailbox_id) REFERENCES mailboxes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- OAUTH STATE (short-lived, for PKCE flow)
-- ============================================================

CREATE TABLE oauth_states (
	state          CHAR(32) NOT NULL PRIMARY KEY,
	code_verifier  VARCHAR(128) NOT NULL,
	created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_oas_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
