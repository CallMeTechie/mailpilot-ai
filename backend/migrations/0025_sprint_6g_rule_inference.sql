-- 0025_sprint_6g_rule_inference.sql
--
-- Sprint 6g — Auto-Rule-Inference aus Korrektur-Begründungen.
-- Schema-Vorarbeit für RuleInferenceService.
--
-- Drei Schema-Änderungen + Settings-Seeds:
--   1. usage_counters: per-User-per-Day Quota-Tabelle (6f benutzt sie mit)
--   2. pending_actions.kind ENUM erweitert um 'rule_suggestion'
--   3. mail_score_corrections.rule_inference_hash für Idempotenz-Check

-- ---------------------------------------------------------------------
-- 1) usage_counters (DA-R2 Finding 2 — Quota-Infrastruktur)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usage_counters (
	tenant_id   CHAR(36)    NOT NULL,
	user_id     CHAR(36)    NOT NULL,
	kind        VARCHAR(64) NOT NULL,
	`date`      DATE        NOT NULL,
	count       INT UNSIGNED NOT NULL DEFAULT 0,
	updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
		ON UPDATE CURRENT_TIMESTAMP(3),
	PRIMARY KEY (tenant_id, user_id, kind, `date`),
	KEY idx_uc_user_kind (user_id, kind, `date`),
	CONSTRAINT fk_uc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
	CONSTRAINT fk_uc_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) pending_actions.kind ENUM erweitert
-- ---------------------------------------------------------------------
ALTER TABLE pending_actions
	MODIFY COLUMN kind ENUM(
		'move',
		'create_topic',
		'move_to_pending_topic',
		'reply_draft',
		'rule_suggestion'
	) NOT NULL;

-- ---------------------------------------------------------------------
-- 3) mail_score_corrections: Idempotenz-Hash (DA-R1 Finding 4)
-- ---------------------------------------------------------------------
ALTER TABLE mail_score_corrections
	ADD COLUMN IF NOT EXISTS rule_inference_hash CHAR(64) NULL AFTER created_at,
	ADD UNIQUE KEY IF NOT EXISTS uq_correction_rule_hash (tenant_id, rule_inference_hash);

-- ---------------------------------------------------------------------
-- 4) Settings-Seeds (alle admin-editable per Mandate)
-- ---------------------------------------------------------------------
INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('rule_inference_enabled',             '1',           'bool',   'Sprint 6g: Master-Toggle für Auto-Rule-Inference aus Korrektur-Begründungen.'),
	('rule_inference_max_per_user_per_day','30',          'int',    'Sprint 6g: Daily Cap pro (tenant,user) für Rule-Extraction-Calls. Enforced via usage_counters.kind=rule_inference.'),
	('rule_inference_confidence_floor',    '80',          'int',    'Sprint 6g: Mindest-Konfidenz (0-100) für Auto-Apply ohne Pending. Unter Floor → immer Pending.'),
	('rule_inference_backfill_range',      'last_30_days','string', 'Sprint 6g: Backfill-Range für extrahierte Regeln. Werte: future_only | last_30_days | all.'),
	('rule_inference_backfill_max',        '100',         'int',    'Sprint 6g: Harter Cap für Auto-Apply. Übersteigt Match-Count diesen Wert → Force-Pending (DA-R1 Finding 2 Critical).'),
	('reasoning_pii_names',                '[]',          'json',   'Sprint 6g: JSON-Array von Eigennamen die in Korrektur-Begründungen vor Claude-Versand durch ###NAME### ersetzt werden.')
ON DUPLICATE KEY UPDATE
	`type` = VALUES(`type`),
	description = VALUES(description);
