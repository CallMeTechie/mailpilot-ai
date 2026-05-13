-- 0011_user_sublabels_created_by.sql
--
-- Phase 6b: Autonome Topic-Discovery. Wenn die KI im Score-Prompt
-- einen neuen Topic-Namen vorschlägt, weil keiner der bestehenden
-- User-Sub-Labels passt, wird er hier mit created_by='ki' abgelegt.
--
-- Der Wert ist wichtig für zukünftige UI-Workflows:
--   * 5d-Settings-UI kann „KI vorgeschlagen"-Topics visuell markieren
--   * 6c-Modus-Schalter kann KI-vorgeschlagene Topics als Pending
--     behandeln (Suggest-Modus) statt direkt zu aktivieren
--   * Rename/Merge-Aktionen können KI-Vorschläge bevorzugt
--     konsolidieren (Topic-Drift Mitigation aus PRD §9)
--
-- Default 'user' für alle bisher angelegten Sub-Labels — die wurden
-- alle vom User per Settings-UI angelegt.

ALTER TABLE user_sublabels
	ADD COLUMN created_by ENUM('user','ki') NOT NULL DEFAULT 'user' AFTER color;
