-- Phase 9l (Marc 2026-05-21) — Async-Worker fuer Bulk-Rescore.
--
-- Auslöser: rescoreFolder lief bisher synchron mit Cap 50 und nginx-Timeout
-- 180s. Bei vollen Foldern (>50 Mails, oder grosse Mails mit langem KI-Call)
-- reicht das nicht — User bekommt 504. Loesung: Job-Queue analog sync_jobs.
-- Backend enqueued + 202, Worker arbeitet ab, Frontend pollt status.
--
-- Spalten:
--   folder_id     — Outlook-Graph parentFolderId (target des Bulk-Rescore)
--   mailbox_id    — auf welcher Mailbox laeuft der Folder
--   total         — Anzahl gefundener Mails (vom Worker gesetzt, nicht Caller)
--   processed     — bisher gescorte Mails (live progress)
--   capped        — TRUE wenn Worker den 9l Hard-Cap (200) erreicht hat
--   error_text    — Fehlertext bei status=failed

CREATE TABLE rescore_jobs (
	id             CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id      CHAR(36) NOT NULL,
	user_id        CHAR(36) NOT NULL,
	mailbox_id     CHAR(36) NULL,
	folder_id      VARCHAR(255) NOT NULL,
	status         ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
	total          INT UNSIGNED NOT NULL DEFAULT 0,
	processed      INT UNSIGNED NOT NULL DEFAULT 0,
	capped         TINYINT(1) NOT NULL DEFAULT 0,
	error_text     TEXT NULL,
	started_at     DATETIME(3) NULL,
	finished_at    DATETIME(3) NULL,
	created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_rj_status_created (status, created_at),
	KEY idx_rj_tenant_status (tenant_id, status),
	CONSTRAINT fk_rj_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_rj_user   FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
