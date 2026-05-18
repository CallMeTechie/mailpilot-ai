-- Sort-Refactor Phase 1 — additive Spalten auf bestehenden Tabellen
-- und neue System-Settings. ALLES ist additiv und NULLable; bestehender
-- Code wird durch diese Migration NICHT beeinflusst (kein Repository
-- referenziert die neuen Felder bis Phase 3/4).
--
-- folder_segments: JSON-Array das die KI in Phase 3 pro Mail liefert
--   z.B. ["GitHub","GateControl","Security"]
--   AutoSortService nutzt diese ab Phase 4 statt der Label-Default-Pfade.
--
-- inbox_score: 0-100. Schwelle aus system_settings.inbox_pin_threshold.
--   >= Schwelle → Mail bleibt in Inbox, kein Auto-Move bis User-„Erledigt".
--   < Schwelle  → Auto-Move erlaubt (sofern Sender/Topic-Regel existiert).
--
-- spoof_suspect: True wenn der LookalikeDetector aus Phase 2 die Mail
--   einem Spoof-Verdacht zugeordnet hat. UI in Phase 5 zeigt Toast+Badge+
--   Briefing-Banner.
--
-- mails.user_cleared_at: Zeitpunkt des User-Klicks „Erledigt — verschieben"
--   im Briefing/DieseMail-Tab. Trennt sich bewusst von mail_scores.cleared_at
--   (gesetzt beim erfolgreichen Graph-Move) und mail_scores.auto_sorted_at.
--   Use-Case: gepinnte Action-Mail darf erst nach user_cleared_at IS NOT NULL
--   den AutoSort-Pfad betreten.

ALTER TABLE mail_scores
	ADD COLUMN inbox_score     TINYINT UNSIGNED NULL AFTER priority,
	ADD COLUMN spoof_suspect   TINYINT(1) NOT NULL DEFAULT 0 AFTER inbox_score,
	ADD COLUMN folder_segments JSON NULL AFTER sub_label;

ALTER TABLE mails
	ADD COLUMN user_cleared_at DATETIME(3) NULL AFTER deleted_at;

-- KI-Pfad-Lookups laufen oft pro mailbox: „alle gepinnten Mails dieses
-- Postfachs". Index auf (mailbox_id, user_cleared_at) macht das O(log n).
CREATE INDEX idx_mails_mailbox_cleared ON mails (mailbox_id, user_cleared_at);

-- Briefing-Top-Priority-Sortierung nutzt kuenftig inbox_score statt priority.
-- Index auf (tenant_id, inbox_score) fuer ORDER BY inbox_score DESC LIMIT N.
CREATE INDEX idx_mail_scores_inbox ON mail_scores (tenant_id, inbox_score);

-- System-Settings-Seeds. system_settings.key ist PRIMARY KEY (Migration 0005).
-- ON DUPLICATE KEY UPDATE value=value macht den INSERT idempotent: wenn
-- der Admin den Wert schon gesetzt hat, lassen wir ihn in Ruhe.
INSERT INTO system_settings (`key`, `value`, `type`) VALUES
	('inbox_pin_threshold', '70', 'int'),
	('sort_root',           '',   'string')
ON DUPLICATE KEY UPDATE `value` = `value`;
