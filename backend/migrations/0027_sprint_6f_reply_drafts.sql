-- 0027_sprint_6f_reply_drafts.sql
--
-- Sprint 6f — Auto-Reply-Drafts.
-- Erweiterung des bestehenden reply_drafts-Schemas (aus 0001) um die
-- Marker, die der Worker-Hintergrund-Job braucht: created_by zur
-- Unterscheidung User-on-demand vs. Auto, stale_at für Thread-Move-
-- detection, dismissed_at für User-Verwerfen, plus user_id und
-- conversation_id zum schnellen Filtern.
--
-- Settings: master toggle (Default 0 = opt-in), enabled_at-Marker für
-- Backlog-Schutz, priority-floor + only_owner_user-Filter, regex für
-- FYI-Skip.

-- ---------------------------------------------------------------------
-- 1) reply_drafts Schema-Erweiterung
-- ---------------------------------------------------------------------
ALTER TABLE reply_drafts
	ADD COLUMN IF NOT EXISTS created_by      ENUM('user', 'auto') NOT NULL DEFAULT 'user' AFTER model,
	ADD COLUMN IF NOT EXISTS user_id         CHAR(36)             NULL                    AFTER tenant_id,
	ADD COLUMN IF NOT EXISTS conversation_id VARCHAR(255)         NULL                    AFTER mail_id,
	ADD COLUMN IF NOT EXISTS stale_at        DATETIME(3)          NULL                    AFTER generated_at,
	ADD COLUMN IF NOT EXISTS dismissed_at    DATETIME(3)          NULL                    AFTER stale_at;

-- Index für die häufigste Worker-Query: „existiert eine aktive
-- (non-dismissed, non-stale) Draft für diese Mail?"
ALTER TABLE reply_drafts
	ADD INDEX IF NOT EXISTS idx_draft_mail_active   (mail_id, dismissed_at, stale_at),
	ADD INDEX IF NOT EXISTS idx_draft_conversation  (conversation_id, dismissed_at);

-- ---------------------------------------------------------------------
-- 2) Settings-Seeds (alle admin-editable)
-- ---------------------------------------------------------------------
-- Hinweis: autoreply_max_per_day existiert schon seit Migration 0015
-- (Seed war damals 15) — wir lassen den Wert wie er ist.
INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('autoreply_enabled',              '0',   'bool',   'Sprint 6f Master-Toggle. Default 0 = opt-in. User aktiviert im Auto-Sort-Tab.'),
	('autoreply_enabled_at',           '',    'string', 'Sprint 6f Cold-Start-Schutz (DA-R1 Finding 2). UTC-ISO-Zeitstempel; nur Mails mit received_at >= enabled_at werden für Auto-Draft erwogen. Leer = aktuell deaktiviert.'),
	('autoreply_priority_floor',       '4',   'int',    'Sprint 6f Mindest-Priority für Auto-Draft (1-5). Default 4.'),
	('autoreply_only_owner_user',      '1',   'bool',   'Sprint 6f: nur Mails mit action_owner=user. Wenn 0, würden auch other/group/unsure-Mails Drafts kriegen (selten gewollt).'),
	('autoreply_skip_subject_regex',   '/best[äa]tigung|confirmation|receipt|order #/i', 'string', 'Sprint 6f Pre-Filter (DA-R1 Finding 4). Skip-Regex auf subject. PCRE-Format inkl. Delimiter und Modifier.'),
	('autoreply_skip_body_min_chars',  '200', 'int',    'Sprint 6f Pre-Filter. Wenn body_text unter dieser Länge: skip (vermutlich FYI/Bestätigung).')
ON DUPLICATE KEY UPDATE
	`type`      = VALUES(`type`),
	description = VALUES(description);
