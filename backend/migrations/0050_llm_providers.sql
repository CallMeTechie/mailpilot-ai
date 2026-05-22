-- Phase 9q-A (Marc 2026-05-22) — Multi-Provider-LLM-Schicht.
--
-- llm_providers haelt Cloud + lokale Inferenz-Server. api_key_encrypted ist
-- mit libsodium-secretbox verschluesselt (Master-Key im /run/secrets/-Pfad,
-- Klasse SecretBox). api_key_env_fallback nennt den Namen der env-Var, die
-- als Failback genutzt wird, falls die DB-Spalte NULL ist — wichtig fuer
-- den Anthropic-Migrations-Pfad (env existiert weiter, DB wird erst beim
-- naechsten Boot gefuellt).
--
-- kind:
--   anthropic           - /v1/messages, Anthropic prompt-caching
--   openai              - /v1/chat/completions, response_format json_object
--   gemini              - /v1beta/models/{m}:generateContent, Context Cache
--   mistral             - OpenAI-kompatibel auf api.mistral.ai
--   openai_compatible   - Ollama / LM Studio / llama.cpp / vLLM / Qwen via
--                         DashScope-OpenAI-Endpoint — alle exponieren
--                         /v1/chat/completions, nur base_url unterscheidet sich

CREATE TABLE llm_providers (
	id                    CHAR(36) NOT NULL PRIMARY KEY,
	name                  VARCHAR(80)  NOT NULL,
	kind                  ENUM('anthropic','openai','gemini','mistral','openai_compatible') NOT NULL,
	base_url              VARCHAR(255) NOT NULL,
	api_key_encrypted     LONGTEXT     NULL,
	api_key_env_fallback  VARCHAR(64)  NULL,
	is_local              TINYINT(1)   NOT NULL DEFAULT 0,
	enabled               TINYINT(1)   NOT NULL DEFAULT 1,
	priority              INT          NOT NULL DEFAULT 100,
	health                JSON         NULL,
	created_at            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at            DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	deleted_at            DATETIME(3)  NULL,
	KEY idx_llm_providers_enabled (enabled, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: Anthropic-Provider-Row mit NULL api_key_encrypted — wird beim
-- naechsten Backend-Boot via env→DB-Migration gefuellt (bin/migrate-secrets.php
-- oder Bootstrap-Step in Kernel.php; Phase 9q-A.5).
INSERT INTO llm_providers
	(id, name, kind, base_url, api_key_env_fallback, is_local, enabled, priority)
VALUES (
	'00000000-0000-4000-8000-000000000050',
	'Anthropic',
	'anthropic',
	'https://api.anthropic.com',
	'ANTHROPIC_API_KEY',
	0, 1, 10
);

-- Phase 9q-A: Defaults fuer Privacy-Mode + primaere Provider-Auswahl.
-- (llm_models-Tabelle + fallback_chain kommen in Phase 9q-C.)
INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('llm.privacy_mode', 'cloud_allowed', 'string',
	 'Phase 9q-A: cloud_allowed | local_preferred | local_only — steuert ob Cloud-Provider in Fallback-Chain auftauchen duerfen.'),
	('llm.primary_provider_id', '00000000-0000-4000-8000-000000000050', 'string',
	 'Phase 9q-A: primaerer Provider — anfangs Anthropic. Multi-Provider-Routing kommt in 9q-B.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
