-- Phase-H5 — Brute-Force-Schutz fuer Admin-Login.
--
-- Speichert jeden Login-Versuch (success + fail) mit IP + Username.
-- AuthController prueft vor jedem Versuch ob die IP innerhalb des
-- Sliding-Windows zu viele Fehlversuche hat → 429-Lockout.
--
-- Index auf (ip, attempted_at) fuer den Sliding-Window-Count.
-- Alte Eintraege koennen via AdminLoginAttemptRepository::cleanup
-- (30-Tage-Cutoff) geloescht werden, z.B. per Daily-Cron.

CREATE TABLE admin_login_attempts (
	id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
	ip           VARCHAR(45) NOT NULL,                    -- IPv6-faehig
	username     VARCHAR(120) NULL,                       -- bei leerem Login NULL
	success      TINYINT(1) NOT NULL,
	attempted_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_admin_login_ip_time   (ip, attempted_at),
	KEY idx_admin_login_user_time (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
