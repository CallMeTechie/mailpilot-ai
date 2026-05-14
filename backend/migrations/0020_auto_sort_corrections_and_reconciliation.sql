-- 0020_auto_sort_corrections_and_reconciliation.sql
--
-- Sprint 6d — Move-Lern-Loop + Folder-Rename-Reconciliation
-- (PRD-Phase-6 §4, §6c Sprint-6d-Zeile, §7, §9, §10.1).
--
-- DA-Pre-Impl-Findings eingearbeitet:
--   Finding 1 (HIGH): stabilized_at DATETIME(3) NULL für Quiet-Window-
--                     Promotion. Korrekturen werden erst nach 60min im
--                     Ziel-Ordner als "stabilisiert" markiert; Schwellwert
--                     COUNT(DISTINCT mail_id) WHERE stabilized_at IS NOT NULL.
--   Finding 2 (HIGH): kein Schema-Bedarf — ReconciliationService
--                     implementiert First-Touch-Logic auf NULL-parent_folder_id.
--   Finding 3 (MEDIUM): auto_sort_rules.last_known_display_name als Tracker
--                       für User-Rename-Detection. Wenn DB-folder_name !=
--                       last_known_display_name, hat der User es selbst
--                       geändert → Reconciliation respektiert das.
--   Finding 4 (MEDIUM): kein Schema-Bedarf — Privacy-Disclaimer reused
--                       aus Sprint 6a via users.privacy_acknowledged_at.

CREATE TABLE auto_sort_corrections (
	id                      CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id               CHAR(36) NOT NULL,
	user_id                 CHAR(36) NOT NULL,
	mail_id                 CHAR(36) NULL,
	original_folder_path    VARCHAR(255) NOT NULL,
	corrected_folder_path   VARCHAR(255) NOT NULL,
	original_sub_label      VARCHAR(50) NULL,
	suggested_sub_label     VARCHAR(50) NULL,
	user_reason             VARCHAR(500) NULL,
	-- DA-Finding 1: Korrektur ist erst nach 60min Quiet-Window verlässlich.
	-- Worker-Promotion-Job setzt stabilized_at, sobald die Mail nicht
	-- innerhalb von 60min weiter verschoben wurde. Schwellwert-Query
	-- ignoriert stabilized_at IS NULL.
	stabilized_at           DATETIME(3) NULL,
	created_at              DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	deleted_at              DATETIME(3) NULL,
	-- mail_id ist absichtlich KEIN FK: Mail-Retention-Purge (7d Bodies,
	-- 30d Header) würde sonst Korrekturen wegcasten, die Lern-Signal sind.
	-- Nach Mail-Delete wird mail_id einfach stale, kein Constraint-Fail.
	INDEX idx_user_created      (tenant_id, user_id, created_at),
	INDEX idx_pair_corrections  (tenant_id, user_id, original_sub_label, suggested_sub_label, stabilized_at),
	INDEX idx_purge             (deleted_at, created_at),
	CONSTRAINT fk_asc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_asc_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Finding 3: Tracker für „was wussten wir zuletzt aus Graph?".
-- Reconciliation aktualisiert folder_name nur wenn DB-folder_name
-- noch dem last_known_display_name entspricht (User hat es nicht
-- selbst überschrieben). Sonst nur Tracker-Update.
ALTER TABLE auto_sort_rules
	ADD COLUMN last_known_display_name VARCHAR(200) NULL DEFAULT NULL AFTER folder_name;

-- mails braucht parent_folder_id-Snapshot für Move-Detection:
-- Sync-Tick vergleicht aktuellen Graph-Wert gegen den letzten DB-Wert.
-- Bei Drift = User hat die Mail manuell verschoben → Korrektur-Kandidat.
ALTER TABLE mails
	ADD COLUMN parent_folder_id VARCHAR(255) NULL DEFAULT NULL AFTER mailbox_id,
	ADD INDEX idx_mails_parent_folder (mailbox_id, parent_folder_id);

-- System-Settings für 6d-Tuning (alle admin-editierbar).
INSERT IGNORE INTO system_settings (`key`, `value`, `type`, description) VALUES
	('autosort.correction_quiet_window_minutes', '60', 'int', 'Sprint 6d DA-Finding 1: Korrekturen werden erst nach N Minuten Stabilität im Ziel-Ordner als verlässliches Lern-Signal markiert (stabilized_at gesetzt). Vorher: nur Logging, kein Few-Shot.'),
	('autosort.correction_threshold_count',     '3',  'int', 'PRD §4 Single-Correction-Schutz: ab N gleichartigen Korrekturen in correction_threshold_days wird ein Verhaltenswechsel daraus.'),
	('autosort.correction_threshold_days',      '30', 'int', 'PRD §4 Single-Correction-Schutz: Zeitfenster für die Schwelle.'),
	('autosort.correction_retention_days',      '90', 'int', 'PRD §6c Sprint 6d: nightly-Purge von Korrekturen älter als N Tage (Soft-Delete via deleted_at).'),
	('autosort.reconciliation_interval_hours',  '24', 'int', 'PRD §9: Mindestabstand zwischen zwei Reconciliation-Runs für dieselbe Rule.');
