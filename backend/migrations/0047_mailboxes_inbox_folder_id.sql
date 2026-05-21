-- Phase 9n (Marc 2026-05-21) — Cache fuer die Outlook-Inbox-Folder-ID.
--
-- Marc-Beschwerde: Der Briefing-Tab zeigt Mails als „in deiner Inbox" obwohl
-- sie laengst manuell verschoben wurden. Ursache: BriefingController.
-- buildPinnedList filtert nur auf auto_sorted_at IS NULL — wenn der User
-- selbst (in Outlook) verschiebt, weiss MailPilot davon nichts.
--
-- Fix: parent_folder_id der Mail mit der Inbox-Folder-ID der Mailbox
-- vergleichen. Wenn ungleich → Mail nicht mehr in Inbox → aus Pin-Liste raus.
--
-- Cache ist optional: BriefingController resolved den Wert lazy via
-- GraphClient.resolveWellKnownFolder('inbox') und persistiert hier.
-- Analog zu Phase 9i sent_folder_id (Migration 0042).

ALTER TABLE mailboxes
	ADD COLUMN inbox_folder_id VARCHAR(255) NULL AFTER sent_folder_id;
