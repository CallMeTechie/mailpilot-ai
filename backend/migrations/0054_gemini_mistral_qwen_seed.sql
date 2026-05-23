-- Phase 9q-E (Marc 2026-05-23) — Gemini + Mistral + Qwen-Cloud-Seeds.
--
-- Alle enabled=0 — Marc aktiviert pro Provider was er nutzen will (API-Key
-- ueber Admin-UI oder docker .env hinterlegen, dann `enabled=1`).
--
-- Provider-Charakteristika:
--   Gemini (Google): sehr guenstig (Flash $0.075/Mtok), eigene API-Shape
--   Mistral (FR):    EU-Hosting, DSGVO-freundlich, OpenAI-kompatibel
--   Qwen via DashScope (CN/Alibaba): OpenAI-kompatibles Endpoint,
--                                     guenstig, asiatische Sprachen stark

INSERT INTO llm_providers
	(id, name, kind, base_url, api_key_env_fallback, is_local, enabled, priority)
VALUES
	('00000000-0000-4000-8000-000000000054',
	 'Google Gemini',  'gemini',
	 'https://generativelanguage.googleapis.com',
	 'GEMINI_API_KEY', 0, 0, 30),
	('00000000-0000-4000-8000-000000000055',
	 'Mistral (EU)',   'mistral',
	 'https://api.mistral.ai',
	 'MISTRAL_API_KEY', 0, 0, 40),
	('00000000-0000-4000-8000-000000000056',
	 'Qwen (DashScope)', 'openai_compatible',
	 'https://dashscope-intl.aliyuncs.com/compatible-mode',
	 'DASHSCOPE_API_KEY', 0, 0, 50);

-- Modelle pro Provider.
INSERT INTO llm_models
	(id, provider_id, model_id, role, cost_per_mtok_in, cost_per_mtok_out, supports_caching, max_context, enabled, priority)
VALUES
	-- Gemini
	('00000000-0000-4000-8001-000000000080',
	 '00000000-0000-4000-8000-000000000054',
	 'gemini-2.0-flash',  'score',     0.075, 0.30,  1, 1000000, 1, 30),
	('00000000-0000-4000-8001-000000000081',
	 '00000000-0000-4000-8000-000000000054',
	 'gemini-2.0-flash',  'inference', 0.075, 0.30,  1, 1000000, 1, 30),
	('00000000-0000-4000-8001-000000000082',
	 '00000000-0000-4000-8000-000000000054',
	 'gemini-2.5-pro',    'summary',   1.25,  10.00, 1, 2000000, 1, 30),
	('00000000-0000-4000-8001-000000000083',
	 '00000000-0000-4000-8000-000000000054',
	 'gemini-2.5-pro',    'draft',     1.25,  10.00, 1, 2000000, 1, 30),

	-- Mistral
	('00000000-0000-4000-8001-000000000090',
	 '00000000-0000-4000-8000-000000000055',
	 'mistral-small-latest', 'score',     0.20, 0.60, 0, 32768, 1, 40),
	('00000000-0000-4000-8001-000000000091',
	 '00000000-0000-4000-8000-000000000055',
	 'mistral-small-latest', 'inference', 0.20, 0.60, 0, 32768, 1, 40),
	('00000000-0000-4000-8001-000000000092',
	 '00000000-0000-4000-8000-000000000055',
	 'mistral-large-latest', 'summary',   2.00, 6.00, 0, 128000, 1, 40),
	('00000000-0000-4000-8001-000000000093',
	 '00000000-0000-4000-8000-000000000055',
	 'mistral-large-latest', 'draft',     2.00, 6.00, 0, 128000, 1, 40),

	-- Qwen via DashScope (alle Modelle ueber OpenAI-kompatibles Endpoint)
	('00000000-0000-4000-8001-0000000000a0',
	 '00000000-0000-4000-8000-000000000056',
	 'qwen-turbo',  'score',     0.05, 0.20, 0, 1000000, 1, 50),
	('00000000-0000-4000-8001-0000000000a1',
	 '00000000-0000-4000-8000-000000000056',
	 'qwen-turbo',  'inference', 0.05, 0.20, 0, 1000000, 1, 50),
	('00000000-0000-4000-8001-0000000000a2',
	 '00000000-0000-4000-8000-000000000056',
	 'qwen-plus',   'summary',   0.40, 1.20, 0, 131072, 1, 50),
	('00000000-0000-4000-8001-0000000000a3',
	 '00000000-0000-4000-8000-000000000056',
	 'qwen-plus',   'draft',     0.40, 1.20, 0, 131072, 1, 50);
