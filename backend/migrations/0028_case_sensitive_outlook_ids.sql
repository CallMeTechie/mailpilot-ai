-- 0028_case_sensitive_outlook_ids.sql
--
-- 2026-05-15 KRITISCHER Bug-Fix: Outlook/Graph-IDs sind Base64-codiert
-- und damit case-sensitive — utf8mb4_unicode_ci (Default-Collation) ist
-- aber case-insensitive. Folge: zwei verschiedene Mails mit IDs die
-- sich nur im Case unterscheiden (z.B. ...HuAAAA vs ...HUAAAA) wurden
-- vom WHERE ms_message_id = ... als gleich behandelt → falsche Row
-- zurückgegeben, falsche uq_mail-Konflikte, falsche Tombstone-Treffer.
--
-- Marc-Reproduktion 2026-05-15: Klick auf Amazon-Mail mit ID
-- '...HuAAAA' lieferte Score+Summary einer momox-Mail mit '...HUAAAA'.
--
-- Fix: alle Graph-ID-Spalten auf utf8mb4_bin umstellen. Damit werden
-- Lookups byte-genau, UNIQUE-Constraints respektieren Case, FK-Bindungen
-- bleiben intakt (FK-Spalten matchen nach Charset+Collation).

ALTER TABLE mails
	MODIFY COLUMN ms_message_id   VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
	MODIFY COLUMN conversation_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
	MODIFY COLUMN internet_msg_id VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL;

ALTER TABLE reply_drafts
	MODIFY COLUMN conversation_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL;
