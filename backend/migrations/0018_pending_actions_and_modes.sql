-- 0018_pending_actions_and_modes.sql
--
-- Sprint 6c — Modus-Schalter (3 Toggles × 3 Stufen) + Pending-Action-Queue.
--
-- Schema kombiniert PRD §7 (pending_actions Base) mit den vier DA-Pre-
-- Implementation-Findings:
--   Finding 1 (CRITICAL): created_under_mode friert den Modus zum Erstellungs-
--     zeitpunkt fest. Age-Out evaluiert auf created_under_mode statt aktuellem
--     System-Setting → kein Stille-Aktion-Risiko nach Toggle-Wechsel.
--   Finding 2 (HIGH): last_error + retry_count für Best-Effort-Approval, wenn
--     Topic-Approval mit 50 Moves teils failed. Failed Children bleiben pending.
--   Finding 3 (HIGH): Toggle-Hierarchie-Constraint wird im SettingsController
--     enforced, kein Schema-Constraint nötig (ENUMs haben keine Ordnung).
--   Finding 4 (MEDIUM): Banner-Schwellen als system_settings, nicht hardcoded.
--
-- PRD-DSGVO §10.1: pending_actions enthält Mail-Subjects + Recipients im
-- payload-JSON → MUSS in MeController::exportTableList und deleteTableList.
-- MeControllerCoverageTest kippt rot wenn vergessen.

CREATE TABLE pending_actions (
	id                   CHAR(36) NOT NULL PRIMARY KEY,
	tenant_id            CHAR(36) NOT NULL,
	user_id              CHAR(36) NOT NULL,
	kind                 ENUM('move','create_topic','move_to_pending_topic','reply_draft') NOT NULL,
	payload              JSON NOT NULL,
	parent_pending_id    CHAR(36) NULL,
	status               ENUM('pending','approved','rejected','aged_out') NOT NULL DEFAULT 'pending',
	created_under_mode   ENUM('off','suggest','auto') NOT NULL,
	last_error           VARCHAR(500) DEFAULT NULL,
	retry_count          TINYINT UNSIGNED NOT NULL DEFAULT 0,
	created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	decided_at           DATETIME(3) NULL,
	INDEX idx_user_status      (tenant_id, user_id, status, created_at),
	INDEX idx_user_kind_status (tenant_id, user_id, kind, status),
	INDEX idx_parent_pending   (parent_pending_id),
	INDEX idx_age_out          (status, created_at),
	CONSTRAINT fk_pa_tenant FOREIGN KEY (tenant_id)         REFERENCES tenants(id)        ON DELETE CASCADE,
	CONSTRAINT fk_pa_user   FOREIGN KEY (user_id)           REFERENCES users(id)          ON DELETE CASCADE,
	CONSTRAINT fk_pa_parent FOREIGN KEY (parent_pending_id) REFERENCES pending_actions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System-Settings-Seeds für die drei Modi + drei Banner-Schwellen.
-- Modi stehen unter PRD §3 — Default ist überall 'suggest' (PRD §3 Schluss).
INSERT IGNORE INTO system_settings (`key`, `value`, `type`, description) VALUES
	('autosort_move_mode',         'suggest', 'string', 'PRD §3 Toggle 1: Move-Aktion (off/suggest/auto). Default suggest.'),
	('autosort_create_topic_mode', 'suggest', 'string', 'PRD §3 Toggle 2: Topic-Anlage (off/suggest/auto). Default suggest. Muss <= autosort_move_mode sein (DA-Finding 3).'),
	('autosort_reply_mode',        'suggest', 'string', 'PRD §3 Toggle 3: Auto-Reply-Draft (off/suggest/auto). Default suggest. Unabhängig von den anderen.'),
	('pending.banner_info',        '100',     'int',    'DA-Finding 4: Ab N pending zeigt Add-in einen blauen Info-Hinweis.'),
	('pending.banner_warning',     '250',     'int',    'DA-Finding 4: Ab N pending zeigt Add-in einen gelben Warner.'),
	('pending.banner_block',       '500',     'int',    'DA-Finding 4: Ab N pending blockt Add-in das Öffnen weiterer Aktionen.'),
	('pending.retention_days',     '30',      'int',    'PRD §6c: Age-Out-Schwelle in Tagen. Pending älter als das werden vom Worker als aged_out markiert.');
