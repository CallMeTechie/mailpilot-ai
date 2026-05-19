-- Phase 9e Hotfix #3 (Marc 2026-05-19) — user_corrected_fields SET um
-- 'folder_segments' erweitern.
--
-- Bug: Migration 0023 definierte das SET als ('label','priority','action_owner',
-- 'sub_label'). Phase 9e schreibt aber 'folder_segments' in dieses Set, sobald
-- der User eine Topic-Korrektur macht. MariaDB truncated unbekannte SET-Werte
-- silent und gibt SQLSTATE 01000 (Warning) — PDO eskaliert das zu Exception,
-- correctScore liefert 500.
--
-- Beobachtet: 2026-05-19 19:09:36 UTC, „SQLSTATE[01000]: Warning: 1265 Data
-- truncated for column 'user_corrected_fields' at row 1" beim Topic-Test
-- „Familie/Jenny".
--
-- Additive Aenderung — alte Daten behalten ihre Werte.

ALTER TABLE mail_scores
	MODIFY user_corrected_fields
	SET('label','priority','action_owner','sub_label','folder_segments')
	DEFAULT NULL;
