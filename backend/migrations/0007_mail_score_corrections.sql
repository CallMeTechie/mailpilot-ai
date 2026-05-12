-- 0007_mail_score_corrections.sql
--
-- User-driven score corrections. Each row is one "user says the KI was
-- wrong" event — fed back into the scoring prompt as few-shot examples
-- so future classifications improve.
--
-- mail_scores grows a user_corrected_at flag. If set, scoreBatch
-- treats the score as locked (no overwrite by Claude) and the value
-- shown to the user is whatever the correction set, not Claude's.

CREATE TABLE IF NOT EXISTS mail_score_corrections (
	id                  CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id           CHAR(36) NOT NULL,
	user_id             CHAR(36) NOT NULL,
	mail_id             CHAR(36) NOT NULL,
	original_label      VARCHAR(20) DEFAULT NULL,
	original_priority   TINYINT UNSIGNED DEFAULT NULL,
	original_action     TINYINT(1) DEFAULT NULL,
	corrected_label     ENUM('direct','action','cc','newsletter','auto','noise') NOT NULL,
	corrected_priority  TINYINT UNSIGNED NOT NULL,
	corrected_action    TINYINT(1) NOT NULL DEFAULT 0,
	reasoning           VARCHAR(500) DEFAULT NULL,
	created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	UNIQUE KEY uq_correction_per_mail (tenant_id, mail_id),
	INDEX idx_user_recent (tenant_id, user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mail_scores
	ADD COLUMN user_corrected_at DATETIME(3) DEFAULT NULL AFTER scored_at;
