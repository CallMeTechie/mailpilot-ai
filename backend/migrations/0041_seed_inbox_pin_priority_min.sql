-- Phase 9f (Marc 2026-05-19) — Pin-Logik auf Priority umstellen.
--
-- Bisher: inbox_pin_threshold (0-100) gegen mail_scores.inbox_score.
-- Neu:    inbox_pin_priority_min (1-5) gegen mail_scores.priority.
--
-- Marc-Diagnose: „Priority hat keine Relevanz mehr, inbox_score sieht der
-- User nirgends. Inbox_score hat fuer die KI Relevanz, aber der User kann
-- damit nichts anfangen." → 2-Achsen-Modell raus, eine Achse die der User
-- direkt korrigieren kann.
--
-- Default 4: Prio 4 und 5 bleiben in der Inbox bis User-Done. Prio 1-3
-- werden auto-sortiert.
--
-- inbox_pin_threshold bleibt vorerst in system_settings (kein Drop), wird
-- aber nicht mehr gelesen. inbox_score in mail_scores bleibt fuer
-- historische Daten / spaetere Re-Auswertung.

INSERT INTO system_settings (`key`, value, type) VALUES
	('inbox_pin_priority_min', '4', 'int')
ON DUPLICATE KEY UPDATE `key` = `key`;
