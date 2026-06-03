-- Spec 2 (Marc 2026-06-02) — Lern-Loop: dauerhafte Regeln + Per-Mail-Match.

-- 1) Schutz-Markierung für aus User-Korrekturen abgeleitete Regeln.
ALTER TABLE score_override_rules
	ADD COLUMN origin_correction_id CHAR(36) NULL AFTER source,
	ADD KEY idx_sor_origin (origin_correction_id);

-- 2) llm_models.role um 'match' erweitern (Per-Mail-LLM-Bewertung).
ALTER TABLE llm_models
	MODIFY COLUMN role ENUM('score','summary','draft','inference','match') NOT NULL;

-- 2b) llm_call_log.role MUSS 'match' kennen, sonst wird das Audit-Logging der
--     match-Calls STILL verworfen (CLAUDE.md §5 / Spec-D8). PFLICHT — role ist
--     ein ENUM('score','summary','draft','inference') (Migration 0055).
ALTER TABLE llm_call_log
	MODIFY COLUMN role ENUM('score','summary','draft','inference','match') NOT NULL;

-- 2c) pending_actions.kind MUSS 'score_suggestion' kennen, sonst bricht der
--     Vorschlags-INSERT (data truncation). PFLICHT — kind ist ENUM (0018 + 0025).
ALTER TABLE pending_actions
	MODIFY COLUMN kind ENUM('move','create_topic','move_to_pending_topic','reply_draft','rule_suggestion','score_suggestion') NOT NULL;

-- 3) match-Modell-Row (Anthropic Haiku — billig/schnell; läuft pro Mail).
INSERT INTO llm_models
	(id, provider_id, model_id, role, cost_per_mtok_in, cost_per_mtok_out, supports_caching, max_context, enabled, priority)
VALUES
	('00000000-0000-4000-8001-000000000054',
	 '00000000-0000-4000-8000-000000000050',
	 'claude-haiku-4-5-20251001', 'match', 0.80, 4.00, 1, 200000, 1, 10);

-- 4) Settings: Match-Modus (Default deterministic) + Bänder + Budget + Soft-Cap + match-Chain.
INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('learning.match_mode', 'deterministic', 'string',
	 'Spec 2: deterministic | llm | hybrid. Wie eine Mail gegen gelernte Regeln gematcht wird. LLM/hybrid nur bei Cache-Miss.'),
	('learning.match_auto_threshold', '80', 'int', 'Spec 2: Score >= → Regel automatisch anwenden.'),
	('learning.match_suggest_threshold', '50', 'int', 'Spec 2: suggest <= Score < auto → Vorschlag. < suggest → ignorieren.'),
	('learning.match_per_batch_budget', '5', 'int', 'Spec 2: max LLM-Match-Calls pro Scoring-Batch; darüber deterministischer Fallback.'),
	('learning.score_rules_soft_cap', '200', 'int', 'Spec 2: max aktive user-derived Override-Regeln pro User; darüber LRU-Deaktivierung.'),
	('llm.match.fallback_chain', '["00000000-0000-4000-8000-000000000050"]', 'json',
	 'Spec 2: Failover-Chain für die match-Rolle. Initial nur Anthropic.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
