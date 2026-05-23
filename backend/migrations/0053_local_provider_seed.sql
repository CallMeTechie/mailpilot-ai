-- Phase 9q-D (Marc 2026-05-23) — Beispiel-Seed fuer lokalen Inferenz-Server.
--
-- Idee: Wenn Marc spaeter einen Ollama/LM-Studio im Netzwerk laufen hat,
-- aendert er nur base_url + enabled=1 im Admin-UI (Phase 9q-F). Die Provider-
-- Row ist hier schon vorgemerkt damit der Admin-UI-Flow „neuen Provider
-- anlegen" nicht zwingend noetig ist fuer den haeufigsten Use-Case.
--
-- Defaults:
--   - base_url = http://localhost:11434 (Ollama-Standard) — Marc passt
--     entweder auf nas3.local:11434 oder Mini-PC-IP an.
--   - is_local = 1 → wird bei Privacy-Mode local_only/local_preferred
--     bevorzugt.
--   - enabled = 0 → wird NICHT in die Fallback-Chain gewaehlt bis Marc
--     bewusst aktiviert.

INSERT INTO llm_providers
	(id, name, kind, base_url, api_key_env_fallback, is_local, enabled, priority)
VALUES (
	'00000000-0000-4000-8000-000000000053',
	'Ollama (lokal)',
	'openai_compatible',
	'http://localhost:11434',
	NULL,    -- lokale Server brauchen meist keinen API-Key
	1, 0, 5
);

-- Beispiel-Modelle. Disabled — Marc enabled die, fuer die er das Modell
-- via `ollama pull qwen3:7b` etc. installiert hat. Pricing NULL = keine
-- Cost-Tracking (lokal kostet Strom, nicht USD).
INSERT INTO llm_models
	(id, provider_id, model_id, role, supports_caching, max_context, enabled, priority)
VALUES
	('00000000-0000-4000-8001-000000000070',
	 '00000000-0000-4000-8000-000000000053',
	 'qwen3:7b',   'score',     0, 32768, 0, 50),
	('00000000-0000-4000-8001-000000000071',
	 '00000000-0000-4000-8000-000000000053',
	 'qwen3:7b',   'inference', 0, 32768, 0, 50),
	('00000000-0000-4000-8001-000000000072',
	 '00000000-0000-4000-8000-000000000053',
	 'qwen3:32b',  'summary',   0, 32768, 0, 50),
	('00000000-0000-4000-8001-000000000073',
	 '00000000-0000-4000-8000-000000000053',
	 'qwen3:32b',  'draft',     0, 32768, 0, 50);
