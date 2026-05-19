-- Sort-Refactor Phase 9a — Deterministische Klassifikations-Overrides.
--
-- Marc-Frust (2026-05-19): „Ich habe mehrere fehlgeschlagene CI Runs die
-- Klassifikation geändert in Prio 2 und keine Aktion erforderlich, als
-- Begründung habe ich angegeben 'Prio 2 ist ausreichend für fehlgeschlagenen
-- CI Run' trotzdem ist bei den nachfolgenden Analysen die Prio wieder auf
-- 4 gesetzt und der Haken bei Aktion erforderlich gesetzt."
--
-- Loesung: 3. Lern-Ebene neben Pro-Mail-Sticky (Phase ~3e) und Few-Shot-
-- Prompt-Kontext. Hier landen ECHTE Regeln die JEDE Mail uebersteuern,
-- wenn alle Match-Kriterien greifen.
--
-- Beispiel-Eintrag fuer den CI-Run-Fall:
--   match_sender_key='github', match_subject_regex='/build.*fail|test.*fail/i',
--   match_label='action', match_priority_min=3,
--   set_priority=2, set_action_required=0
--
-- Match-Felder sind ALLE optional aber mindestens EINES MUSS gesetzt sein
-- (sonst wuerde die Regel jede Mail uebernehmen — wird im Repository
-- validiert, nicht hier per Constraint).
--
-- ENUM-Werte spiegeln die projektweiten Label-Werte aus dem KI-Schema.

CREATE TABLE score_override_rules (
	id                    CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id             CHAR(36) NOT NULL,
	user_id               CHAR(36) NOT NULL,

	-- Match-Felder (AND-verknuepft, alle optional, min. 1 muss != NULL sein)
	match_sender_key      VARCHAR(64)  NULL,   -- exakter PSL-Stem aus senders.sender_key
	match_subject_regex   VARCHAR(255) NULL,   -- PCRE inkl. Delimiter „/.../i", max 255 chars
	match_from_local      VARCHAR(120) NULL,   -- exakter Local-Part vor @, lowercase
	match_label           ENUM('direct','action','cc','newsletter','auto','noise') NULL,
	match_priority_min    TINYINT UNSIGNED NULL,  -- Regel greift nur wenn KI-Score >= dieser Schwelle

	-- Set-Felder (was die Regel ueberschreibt — auch alle optional)
	set_priority          TINYINT UNSIGNED NULL,
	set_action_required   TINYINT(1) NULL,
	set_label             ENUM('direct','action','cc','newsletter','auto','noise') NULL,

	enabled               TINYINT(1) NOT NULL DEFAULT 1,
	source                ENUM('user_manual','ki_inferred') NOT NULL DEFAULT 'user_manual',

	-- Audit / Diagnostik
	applies_count         INT UNSIGNED NOT NULL DEFAULT 0,
	last_applied_at       DATETIME(3) NULL,

	created_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at            DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	deleted_at            DATETIME(3) NULL,

	KEY idx_score_override_active (tenant_id, user_id, enabled),
	CONSTRAINT fk_score_override_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_score_override_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
