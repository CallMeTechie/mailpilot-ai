-- Phase 9i (Marc 2026-05-20) — Cache fuer die Outlook-Sent-Folder-ID.
--
-- Marc-Wunsch: „Gesendete Emails duerfen nicht in die normalen Verzeichnisse
-- verschoben werden". Heutiger AutoSort-Pfad behandelt jede Mail gleich,
-- inkl. Sent-Mails. Fix-Strategie: parent_folder_id der Mail mit der
-- Sent-Folder-ID der Mailbox vergleichen — wenn gleich, Move skippen.
--
-- Cache ist optional: AutoSortService resolved den Wert lazy via
-- GraphClient.resolveWellKnownFolder('sentitems') und persistiert hier.

ALTER TABLE mailboxes
	ADD COLUMN sent_folder_id VARCHAR(255) NULL AFTER delta_token;
