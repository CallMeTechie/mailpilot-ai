-- Phase 9q-B (Marc 2026-05-22) — OpenAI-Provider als erster Cloud-Fallback.
--
-- enabled=0 → der Provider liegt in der Tabelle, ist aber inaktiv bis Marc
-- im Admin-UI (Phase 9q-F) oder manuell per UPDATE den OPENAI_API_KEY
-- einrichtet und enabled=1 setzt. Damit ist keine Aenderung am Verhalten
-- bis bewusst aktiviert.
--
-- Fallback-Chain wird mit nur Anthropic initialisiert. Marc setzt sie nach
-- OpenAI-Aktivierung auf JSON-Array mit beiden UUIDs.

INSERT INTO llm_providers
	(id, name, kind, base_url, api_key_env_fallback, is_local, enabled, priority)
VALUES (
	'00000000-0000-4000-8000-000000000051',
	'OpenAI',
	'openai',
	'https://api.openai.com',
	'OPENAI_API_KEY',
	0, 0, 20
);

INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('llm.score.fallback_chain',
	 '["00000000-0000-4000-8000-000000000050"]',
	 'json',
	 'Phase 9q-B: JSON-Array von llm_providers.id in Reihenfolge der Failover-Chain fuer Score-Calls. Initial nur Anthropic. Sobald OpenAI aktiviert ist, sollte Marc die Chain auf [anthropic-uuid, openai-uuid] setzen.'),
	('llm.summary.fallback_chain',
	 '["00000000-0000-4000-8000-000000000050"]',
	 'json',
	 'Phase 9q-B: analog fuer Summary-Calls (Opus-Klasse).'),
	('llm.routing_mode',
	 'direct',
	 'string',
	 'Phase 9q-B: direct | router. „direct" = MailScoringService nutzt AnthropicClient direkt (zero behavior change). „router" = LlmRouter mit Failover-Chain. Marc switcht via Admin-UI oder DB-Edit.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
