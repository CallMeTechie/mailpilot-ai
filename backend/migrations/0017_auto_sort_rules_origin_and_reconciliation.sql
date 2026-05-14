-- 0017_auto_sort_rules_origin_and_reconciliation.sql
--
-- Sprint 6b — Autonome Topic-Discovery + Vorbereitung für 6d Folder-Reconciliation.
--
-- created_by      — markiert KI-vorgeschlagene Rules (default 'user').
--                   KI-Discovery in MailScoringService legt Rules mit
--                   created_by='ki' + enabled=0 an. UI zeigt sie als
--                   „KI-Vorschlag" und lässt den User per Toggle aktivieren.
--
-- parent_folder_id + last_reconciled_at — Schema-Vorbereitung für Sprint
--                   6d (Folder-Rename-Reconciliation). In 6b nicht aktiv
--                   genutzt, aber jetzt anlegen damit Spalten existieren
--                   sobald 6d landet. parent_folder_id kommt aus dem
--                   ms-graph mailFolder.parentFolderId — verglichen wird
--                   in 6d gegen displayName-Drift.

ALTER TABLE auto_sort_rules
	ADD COLUMN created_by ENUM('user','ki') NOT NULL DEFAULT 'user' AFTER folder_name,
	ADD COLUMN parent_folder_id VARCHAR(255) NULL DEFAULT NULL AFTER folder_id,
	ADD COLUMN last_reconciled_at DATETIME(3) NULL DEFAULT NULL AFTER last_error;

-- Sprint 6b UI-Query: „zeige alle KI-Vorschläge in der Liste".
-- Index spart Full-Scan beim Settings-Load.
ALTER TABLE auto_sort_rules
	ADD KEY idx_origin (tenant_id, user_id, created_by);
