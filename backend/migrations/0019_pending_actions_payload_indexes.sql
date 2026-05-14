-- 0019_pending_actions_payload_indexes.sql
--
-- Sprint 6c DA-Implementation-Finding 4: AutoSortService.applyToScoredMail
-- ruft findPendingTopicId(tenant, user, primary, sub_label) bei jedem
-- suggest-Move. Die Original-Query nutzte JSON_EXTRACT(payload, "$.primary")
-- — kein Index möglich, MariaDB scannt linear. Bei 2-5k offenen Pending
-- Actions × 10k-Mail Initial-Sync sind das 10^7+ JSON-Parses.
--
-- Generated-Columns + Composite-Index machen die Lookup-Query O(log n).
-- STORED, damit der Index auf die materialisierte Spalte greift. Kein
-- Daten-Backfill nötig, weil GENERATED ALWAYS retro auf alle vorhandenen
-- Rows angewendet wird.

ALTER TABLE pending_actions
	ADD COLUMN payload_primary VARCHAR(64)
		GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.primary'))) STORED,
	ADD COLUMN payload_sub_label VARCHAR(64)
		GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(payload, '$.sub_label'))) STORED;

-- Hot-Path-Index für findPendingTopicId: filter auf
-- (tenant, user, kind='create_topic', status='pending', primary, sub_label).
ALTER TABLE pending_actions
	ADD INDEX idx_pending_topic_lookup (tenant_id, user_id, kind, status, payload_primary, payload_sub_label);
