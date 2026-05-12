-- 0006_auto_sort_rules.sql
--
-- Per-user rules that route scored mails into Outlook folders.
-- One row per (tenant, user, label) — six labels, all opt-in
-- (enabled defaults to 0). folder_name is user-editable;
-- folder_id is the cached Microsoft Graph folder id resolved
-- on first use; last_error keeps the most recent Graph failure
-- so the add-in can surface "create failed: …" without trawling
-- the app log.

CREATE TABLE IF NOT EXISTS auto_sort_rules (
	id           CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id    CHAR(36) NOT NULL,
	user_id      CHAR(36) NOT NULL,
	label        ENUM('direct','action','cc','newsletter','auto','noise') NOT NULL,
	enabled      TINYINT(1) NOT NULL DEFAULT 0,
	folder_name  VARCHAR(200) NOT NULL,
	folder_id    VARCHAR(200) DEFAULT NULL,
	last_error   VARCHAR(500) DEFAULT NULL,
	created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	UNIQUE KEY uq_user_label (tenant_id, user_id, label),
	INDEX idx_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
