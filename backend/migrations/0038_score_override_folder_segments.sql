-- Phase 9e (Marc 2026-05-19) — Topic-Override fuer score_override_rules.
--
-- Marc-Frust: „eBay-Mails landen konsequent in /eBay/Sicherheit/ auch wenn
-- es eine Bewertungsaufforderung ist". Phase 9a-d kann nur priority /
-- action_required / label setzen — nicht den Folder-Pfad. Daher hier eine
-- neue Set-Spalte `set_folder_segments` (JSON-Array), die der ScoreOverride-
-- Service direkt in mail_scores.folder_segments persistiert.
--
-- Beispiel: Marc korrigiert eine Bewertungsaufforderung in der „Klassifikation
-- korrigieren"-Form mit Topic="Bewertung". KI inferiert eine Regel:
--   match_sender_key='ebay',
--   match_subject_regex='/(bewertung|feedback|rating)/i',
--   set_folder_segments='["Ebay","Bewertung"]',
--   confidence=88 → enabled=true (Phase-9d Auto-Enable greift).
--
-- Validation in PHP (ScoreOverrideRepository::create):
--   - JSON-Array von max 3 Strings (FolderPathBuilder::MAX_DEPTH)
--   - Strings je max 64 Zeichen, kein „/" oder „\" (Outlook-Pfad-Separator)

ALTER TABLE score_override_rules
	ADD COLUMN set_folder_segments JSON NULL AFTER set_label;
