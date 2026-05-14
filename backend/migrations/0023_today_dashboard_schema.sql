-- 0023_today_dashboard_schema.sql
--
-- Sprint 6e — „MailPilot Heute"-Dashboard + Lern-Loop-Erweiterung.
--
-- DA-Pre-Impl-Findings 1+3 eingearbeitet:
--   #1 (HIGH): cleared_at als single source of truth für „weg aus der
--     Inbox". MoveDetectionService setzt sie bei User-Move zusätzlich
--     zur Korrektur. AutoSortService setzt sie nach erfolgreichem
--     Auto-Move. TodayController filtert die Done-Sektion danach.
--   #3 (MEDIUM): user_corrected_fields als SET, damit Owner-Korrektur
--     nur action_owner sticky macht und label/priority weiter von KI
--     überschrieben werden können. user_corrected_at bleibt als
--     Zeitstempel des letzten User-Eingriffs (backwards-compat zu 3e).
--
-- ENUM action_owner_source erweitert um 'user_corrected' — additive
-- Änderung, alte Daten behalten ihre Werte (MariaDB-backwards-compat).

ALTER TABLE mail_scores
	ADD COLUMN cleared_at DATETIME(3) NULL DEFAULT NULL AFTER auto_sorted_at,
	ADD COLUMN user_corrected_fields
		SET('label','priority','action_owner','sub_label')
		DEFAULT NULL
		AFTER user_corrected_at;

-- ENUM erweitern um den neuen Source-Wert.
ALTER TABLE mail_scores
	MODIFY action_owner_source ENUM('ki','fallback','user_corrected') NULL DEFAULT NULL;

-- Dashboard-Query-Indizes (TodayController filtert pro Sektion):
--   important: WHERE action_owner='user' AND action_required=1 AND cleared_at IS NULL
--   unclear:   WHERE action_owner='unsure' AND cleared_at IS NULL
--   done:      WHERE cleared_at IS NOT NULL AND action_required=0
-- Existing idx_scores_owner (tenant, action_owner, priority) + idx_scores_label
-- decken important/unclear ab. Done braucht eigenen Index auf cleared_at.
ALTER TABLE mail_scores
	ADD INDEX idx_scores_cleared (tenant_id, cleared_at, action_required);

-- Sprint 6e Settings: Few-Shot-Header für den combined Corrections-Block
-- + Limit pro Block (DA-Finding 2: stable ORDER BY + LIMIT damit der
-- Prompt-Cache-Segment-Hash nicht jeden Call rotiert).
INSERT IGNORE INTO system_settings (`key`, `value`, `type`, description) VALUES
	('prompt.autosort_corrections_header',
		'PRIOR_AUTOSORT_CORRECTIONS (der User hat KI-sortierte Mails manuell umverteilt — gleiches Muster vermeiden):',
		'string',
		'Header für den autosort-Korrekturen-Few-Shot-Block im Score-Prompt (Sprint 6e).'),
	('learning.score_corrections_limit', '10', 'int',
		'Max Anzahl Score-Korrekturen (PRIOR_USER_CORRECTIONS) die im Prompt-Cache-Segment landen.'),
	('learning.autosort_corrections_limit', '10', 'int',
		'Max Anzahl stabilisierter Move-Korrekturen die im Prompt-Cache-Segment landen.');
