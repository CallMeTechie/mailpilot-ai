-- 0009_sub_labels.sql
--
-- Adds a finer-grained "sub label" axis on top of the six hardcoded
-- primary labels. Primaries stay (direct/action/cc/newsletter/auto/
-- noise) so Briefing, Hard-Safety and code-level features keep
-- working. Sub labels are per-user, free-form, optional — Claude
-- gets the user's list as context and either picks one or returns
-- NULL.
--
-- AutoSort gets an extra column too so a user can route
--   (auto, GitHub CI) → MailPilot/Entwicklung/CI
--   (auto, Bestellung)→ MailPilot/Shopping/Bestellung
--   (auto, NULL)      → MailPilot/Auto                  ← catch-all
-- The UNIQUE key on auto_sort_rules grows to include sub_label so
-- multiple rules per primary become possible.

CREATE TABLE IF NOT EXISTS user_sublabels (
	id          CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id   CHAR(36) NOT NULL,
	user_id     CHAR(36) NOT NULL,
	parent      ENUM('direct','action','cc','newsletter','auto','noise') NOT NULL,
	name        VARCHAR(50) NOT NULL,
	description VARCHAR(500) DEFAULT NULL,
	color       VARCHAR(7)  DEFAULT NULL,
	created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	UNIQUE KEY uq_user_sub (tenant_id, user_id, parent, name),
	INDEX idx_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE mail_scores
	ADD COLUMN sub_label VARCHAR(50) DEFAULT NULL AFTER label,
	ADD INDEX idx_score_sub (tenant_id, sub_label);

ALTER TABLE auto_sort_rules
	ADD COLUMN sub_label VARCHAR(50) DEFAULT NULL AFTER label,
	DROP INDEX uq_user_label,
	ADD UNIQUE KEY uq_user_label_sub (tenant_id, user_id, label, sub_label);
