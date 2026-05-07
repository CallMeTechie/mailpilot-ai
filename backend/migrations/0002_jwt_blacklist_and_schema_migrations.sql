-- Migration 0002 — JWT revocation list + migration tracking table.

CREATE TABLE IF NOT EXISTS schema_migrations (
	version    VARCHAR(64)  NOT NULL PRIMARY KEY,
	applied_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jwt_blacklist (
	jti        CHAR(36)    NOT NULL PRIMARY KEY,
	expires_at DATETIME(3) NOT NULL,
	revoked_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_jwt_bl_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
