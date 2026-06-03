-- Spec 1 (Marc 2026-06-02) — routing_mode wird reiner Failover-Schalter.
--
-- Mit Spec 1 verliert `direct` das Failover für ALLE Rollen. Heute laufen
-- summary/draft immer über die volle Chain (haben also Failover). Damit beim
-- Upgrade kein stiller Failover-Verlust entsteht, wird der Bestand auf
-- `router` gesetzt. Ein einmaliges Notice-Flag triggert einen Admin-Hinweis.
INSERT INTO system_settings (`key`, `value`, `type`, description)
	VALUES ('llm.routing_mode', 'router', 'string',
	        'Spec 1: direct = Primary-only (kein Failover), router = volle Chain. Beim Upgrade auf router gesetzt.')
ON DUPLICATE KEY UPDATE `value` = 'router';

INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('llm.inference.fallback_chain',
	 '["00000000-0000-4000-8000-000000000050"]',
	 'json',
	 'Spec 1: Failover-Chain fuer inference-Calls (Regel-Extraktion). Initial nur Anthropic.'),
	('llm.draft.fallback_chain',
	 '["00000000-0000-4000-8000-000000000050"]',
	 'json',
	 'Spec 1: Failover-Chain fuer draft-Calls. Initial nur Anthropic.'),
	-- routing_mode_upgrade_notice: vom Admin-Routing-Banner (Task 10) gelesen und nach Anzeige auf '0' gesetzt.
	('llm.routing_mode_upgrade_notice',
	 '1',
	 'string',
	 'Spec 1: einmaliges Flag → Admin-Hinweis „routing_mode beim Upgrade auf router gesetzt". Vom Admin-Banner auf 0 gesetzt nach Anzeige.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
