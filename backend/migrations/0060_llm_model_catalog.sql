-- 0060_llm_model_catalog.sql
--
-- Discovery-Katalog: pro Provider live entdeckte Modelle (speist nur die
-- Admin-Dropdowns; NICHT die Quelle der Wahrheit fuer Routing/Pricing —
-- das bleibt llm_models). available=0 = beim letzten erfolgreichen Refresh
-- nicht mehr gesehen.

CREATE TABLE llm_model_catalog (
	id                 CHAR(36) NOT NULL PRIMARY KEY,
	provider_id        CHAR(36) NOT NULL,
	model_id           VARCHAR(160) NOT NULL,
	display_name       VARCHAR(190) NOT NULL,
	effort_levels      JSON NULL,
	max_output_tokens  INT NULL,
	max_context_tokens INT NULL,
	released_at        DATETIME NULL,
	available          TINYINT(1) NOT NULL DEFAULT 1,
	last_seen_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	discovered_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
	UNIQUE KEY uq_catalog_provider_model (provider_id, model_id),
	KEY idx_catalog_provider_avail (provider_id, available),
	CONSTRAINT fk_catalog_provider FOREIGN KEY (provider_id)
		REFERENCES llm_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
