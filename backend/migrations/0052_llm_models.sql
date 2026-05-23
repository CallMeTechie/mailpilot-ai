-- Phase 9q-C (Marc 2026-05-23) — Pro Provider verfuegbare Modelle.
--
-- Trennung Provider vs. Model:
--   - llm_providers haelt die HTTP-Endpoint-Definition (api.openai.com,
--     api.anthropic.com, http://nas3:11434).
--   - llm_models haelt PRO PROVIDER die nutzbaren Modelle und ihre
--     Rolle (score/summary/draft/inference).
--
-- Rollen:
--   - score      — MailScoringService::scoreBatch (Haiku-Klasse, billig+schnell)
--   - summary    — MailSummaryService            (Opus-Klasse, hoeher quality)
--   - draft      — ReplyDraftService             (Opus-Klasse)
--   - inference  — RuleInferenceService          (Haiku-Klasse reicht meist)
--
-- Pricing-Felder pro Mtok (Stand 2026-Q2). Wenn NULL, kein Cost-Tracking.

CREATE TABLE llm_models (
	id                 CHAR(36) NOT NULL PRIMARY KEY,
	provider_id        CHAR(36) NOT NULL,
	model_id           VARCHAR(120) NOT NULL,
	role               ENUM('score','summary','draft','inference') NOT NULL,
	cost_per_mtok_in   DECIMAL(10,4) NULL,
	cost_per_mtok_out  DECIMAL(10,4) NULL,
	supports_caching   TINYINT(1) NOT NULL DEFAULT 0,
	max_context        INT NOT NULL DEFAULT 200000,
	enabled            TINYINT(1) NOT NULL DEFAULT 1,
	priority           INT NOT NULL DEFAULT 100,
	created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	deleted_at         DATETIME(3) NULL,
	UNIQUE KEY uq_provider_model_role (provider_id, model_id, role),
	KEY idx_llm_models_role (role, enabled, priority),
	CONSTRAINT fk_llm_models_provider FOREIGN KEY (provider_id)
		REFERENCES llm_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed Anthropic (Provider-UUID aus Migration 0050).
INSERT INTO llm_models
	(id, provider_id, model_id, role, cost_per_mtok_in, cost_per_mtok_out, supports_caching, max_context, enabled, priority)
VALUES
	('00000000-0000-4000-8001-000000000050',
	 '00000000-0000-4000-8000-000000000050',
	 'claude-haiku-4-5-20251001', 'score',     0.80, 4.00, 1, 200000, 1, 10),
	('00000000-0000-4000-8001-000000000051',
	 '00000000-0000-4000-8000-000000000050',
	 'claude-haiku-4-5-20251001', 'inference', 0.80, 4.00, 1, 200000, 1, 10),
	('00000000-0000-4000-8001-000000000052',
	 '00000000-0000-4000-8000-000000000050',
	 'claude-opus-4-7',           'summary',   15.00, 75.00, 1, 200000, 1, 10),
	('00000000-0000-4000-8001-000000000053',
	 '00000000-0000-4000-8000-000000000050',
	 'claude-opus-4-7',           'draft',     15.00, 75.00, 1, 200000, 1, 10);

-- Seed OpenAI (Provider-UUID aus Migration 0051).
INSERT INTO llm_models
	(id, provider_id, model_id, role, cost_per_mtok_in, cost_per_mtok_out, supports_caching, max_context, enabled, priority)
VALUES
	('00000000-0000-4000-8001-000000000060',
	 '00000000-0000-4000-8000-000000000051',
	 'gpt-4o-mini', 'score',     0.15, 0.60, 1, 128000, 1, 20),
	('00000000-0000-4000-8001-000000000061',
	 '00000000-0000-4000-8000-000000000051',
	 'gpt-4o-mini', 'inference', 0.15, 0.60, 1, 128000, 1, 20),
	('00000000-0000-4000-8001-000000000062',
	 '00000000-0000-4000-8000-000000000051',
	 'gpt-4o',      'summary',   2.50, 10.00, 1, 128000, 1, 20),
	('00000000-0000-4000-8001-000000000063',
	 '00000000-0000-4000-8000-000000000051',
	 'gpt-4o',      'draft',     2.50, 10.00, 1, 128000, 1, 20);

-- Privacy-Mode-Setting wurde bereits in 0050 angelegt. Description verfeinern.
INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('llm.privacy_mode', 'cloud_allowed', 'string',
	 'Phase 9q-C: cloud_allowed | local_preferred | local_only. „local_only" verbietet jede Cloud-Inferenz — wenn alle lokalen Modelle down sind, schlaegt die Klassifizierung mit LlmAllProvidersDownException fehl statt Cloud zu nutzen.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
