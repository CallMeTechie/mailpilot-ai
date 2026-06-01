-- 0062_opus_4_8_summary_draft.sql
--
-- Opus-4.8-Migration: summary/draft auf claude-opus-4-8 + effort=medium.
-- Targeting ueber Rolle (nicht ueber alten Modellwert). Pricing: model_pricing
-- in EUR (~0.92-Kurs), llm_models.cost_per_mtok in USD. Opus-4-7-Zeilen bleiben
-- fuer historische Kosten.

UPDATE llm_models
	SET model_id = 'claude-opus-4-8', effort = 'medium',
	    cost_per_mtok_in = 5.00, cost_per_mtok_out = 25.00
	WHERE role IN ('summary', 'draft')
	  AND provider_id = '00000000-0000-4000-8000-000000000050'
	  AND deleted_at IS NULL;

INSERT IGNORE INTO model_pricing
	(model, input_eur_per_1m, output_eur_per_1m, cache_read_eur_per_1m, cache_creation_eur_per_1m)
VALUES
	('claude-opus-4-8', 4.6000, 23.0000, 0.4600, 5.7500);

-- Safety-Net-Konsistenz: der Legacy-Direct-Fallback in MailSummary/ReplyDraftService
-- nutzt das Modell der aktiven Prompt-Version. Damit der Fallback dasselbe Modell wie
-- der Router faehrt, die aktiven P-SUMMARY/P-REPLY ebenfalls auf claude-opus-4-8 setzen.
-- (Score/P-SCORE bleibt unangetastet -> Haiku.)
UPDATE prompt_versions
	SET model = 'claude-opus-4-8'
	WHERE key_name IN ('P-SUMMARY', 'P-REPLY') AND active = 1;
