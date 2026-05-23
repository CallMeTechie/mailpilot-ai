-- Phase 9q B7 (Marc 2026-05-23): Soft-Delete fuer prompt_versions.
--
-- Bisher konnten verworfene Prompt-Versionen nur per SQL geloescht
-- werden. Soft-Delete ueber Admin-UI: deleted_at IS NOT NULL filtert
-- sie aus der Liste, history bleibt fuer audit_log-Trace.
--
-- Aktive Prompt-Version (active=1) kann nicht geloescht werden — der
-- Controller validiert.

ALTER TABLE prompt_versions
	ADD COLUMN deleted_at DATETIME(3) NULL DEFAULT NULL AFTER created_at;

CREATE INDEX ix_prompt_versions_deleted_at ON prompt_versions (deleted_at);
