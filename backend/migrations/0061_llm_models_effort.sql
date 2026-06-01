-- 0061_llm_models_effort.sql
--
-- Effort pro (Provider, Modell, Rolle). NULL = kein output_config/reasoning_effort
-- (Provider-Default high). Erlaubte Werte App-seitig gegen den Katalog validiert.

ALTER TABLE llm_models ADD COLUMN effort VARCHAR(12) NULL AFTER max_context;
