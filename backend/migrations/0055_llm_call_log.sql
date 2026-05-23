-- Phase 9q-G (Marc 2026-05-23) — Per-Call Audit-Log fuer LLM-Inferenz.
--
-- Hintergrund: Marc will pro Provider sehen wie viele Calls / wie viele
-- Tokens / welche Kosten / wie lange Latency / wie viele Errors. Plus
-- Health-Status fuer das Admin-Dashboard.
--
-- WICHTIG: NIEMALS Mail-Inhalte loggen. Nur Metriken + Error-Message
-- (max 500 chars, ohne Mail-Body-Auszuege). Datenschutz-relevant —
-- die Tabelle ist DSGVO-frei weil keine Personenbeziehung.
--
-- model_id_str ist STRING (nicht FK) damit Logs ueberleben wenn ein
-- llm_models-Eintrag spaeter geloescht wird. Cost wird beim Insert
-- berechnet (input_tokens * cost_per_mtok_in / 1e6 + ...) und persistiert
-- — Pricing-Aenderungen wirken nur auf zukuenftige Calls.

CREATE TABLE llm_call_log (
	id                CHAR(36) NOT NULL PRIMARY KEY,
	provider_id       CHAR(36) NOT NULL,
	model_id_str      VARCHAR(120) NOT NULL,
	role              ENUM('score','summary','draft','inference') NOT NULL,
	input_tokens      INT UNSIGNED NOT NULL DEFAULT 0,
	output_tokens     INT UNSIGNED NOT NULL DEFAULT 0,
	cached_tokens     INT UNSIGNED NOT NULL DEFAULT 0,
	latency_ms        INT UNSIGNED NOT NULL DEFAULT 0,
	status            ENUM('ok','overloaded','unavailable','error') NOT NULL,
	error_msg         VARCHAR(500) NULL,
	cost_usd          DECIMAL(10, 6) NOT NULL DEFAULT 0,
	created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	KEY idx_llm_call_log_provider_time (provider_id, created_at),
	KEY idx_llm_call_log_time (created_at),
	CONSTRAINT fk_llm_call_log_provider FOREIGN KEY (provider_id)
		REFERENCES llm_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
