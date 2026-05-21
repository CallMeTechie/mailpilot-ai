-- Phase 9m (Marc 2026-05-21) — Folder-Architektur-Refactor.
--
-- Auslöser: Marc beschwert sich dass (a) MailPilot/*-Folder zurückkommen
-- obwohl Phase 7 das beenden sollte, (b) Newsletter falsch sortiert werden
-- (Sender/Newsletter statt Newsletter/Sender), (c) Auto-Sort-Rules aus
-- Sprint 5-7 noch alle aktiv sind (30+ Stück mit folder_name='MailPilot/*').
--
-- Diese Migration setzt das Fundament:
--   1. Neues Setting mailpilot_root (Account-Root | Inbox | Custom-Pfad)
--   2. Legacy auto_sort_rules mit MailPilot/*-Pfad werden DEAKTIVIERT
--      (nicht hard-deleted — User kann sie im Auto-Sort-Tab inspizieren
--      und manuell loeschen wenn er sicher ist).
--   3. P-SCORE@1.5 wird in 9m-6 separat geseedet (eigene Migration), nicht
--      hier — Schema-/Daten-Trennung.

-- (1) mailpilot_root Setting — wo darf MailPilot Ordner anlegen?
INSERT INTO system_settings (`key`, `value`, `type`, description)
VALUES (
	'mailpilot_root',
	'account_root',
	'string',
	'Wurzel-Ordner unter dem MailPilot neue Ordner anlegen darf. Werte: "account_root" (parallel zu Inbox/Sent/Junk), "inbox" (unter Inbox), oder ein absoluter Pfad wie "Archiv" fuer einen eigenen Folder.'
)
ON DUPLICATE KEY UPDATE description = VALUES(description);

-- (2) Legacy auto_sort_rules deaktivieren — alle Pfade die mit "MailPilot/"
-- beginnen werden disabled. Rule-Tupel bleibt fuer Inspection in der DB.
-- Marc kann sie im Auto-Sort-Tab ueber den "Bulk-Delete"-Knopf hart loeschen.
UPDATE auto_sort_rules
SET enabled = 0
WHERE folder_name LIKE 'MailPilot/%';
