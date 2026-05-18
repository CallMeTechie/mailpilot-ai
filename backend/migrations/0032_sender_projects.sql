-- Sort-Refactor Phase 1 — N Projekte pro Absender.
--
-- Marc-Beispiel (2026-05-18): GitHub-Sender hat mehrere Projekte
--   /GitHub/GateControl/...
--   /GitHub/MailPilot-AI/...
-- abgeleitet aus:
--   - To-Local-Part:  gatecontrol@noreply.github.com → „GateControl"
--   - Subject:        „[CallMeTechie/gatecontrol] CodeQL alert" → „GateControl"
--   - Body-Repo-URL:  „github.com/CallMeTechie/mailpilot-ai/pull/…" → „MailPilot-AI"
--
-- match_patterns ist JSON, weil pro Projekt eine variable Liste von Indikatoren
-- (To-Local-Strings, Subject-Regex-Snippets, Repo-Paths, …) zusammenpassen
-- muss. Phase 3 implementiert den FolderPathBuilder, der diese Patterns
-- gegen jede neue Mail laufen laesst und das passende Projekt zurueckgibt.
--
-- Wenn die KI in Phase 3 einen neuen Projektnamen erkennt, der nicht via
-- match_patterns matched, legt sie eine neue Zeile an (created_by='ki').

CREATE TABLE sender_projects (
	id              CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id       CHAR(36) NOT NULL,
	sender_id       CHAR(36) NOT NULL,
	project_key     VARCHAR(64) NOT NULL,                       -- normalisierter Stem, z.B. „gatecontrol"
	display_name    VARCHAR(120) NOT NULL,                      -- User-editierbar, Default = capitalize(project_key)
	match_patterns  JSON NULL,                                  -- {"to_local":["gatecontrol"],"subject_regex":"\\[CallMeTechie/gatecontrol\\]"}
	created_by      ENUM('user','ki') NOT NULL DEFAULT 'user',
	created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	deleted_at      DATETIME(3) NULL,
	UNIQUE KEY uq_sender_projects_sender_key (sender_id, project_key),
	KEY idx_sender_projects_tenant (tenant_id),
	CONSTRAINT fk_sender_projects_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_sender_projects_sender FOREIGN KEY (sender_id) REFERENCES senders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
