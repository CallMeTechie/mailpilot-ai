-- 0015_identity_and_action_owner.sql
--
-- Sprint 6a — Identity-Modell + action_owner als Post-Cache-Feld.
--
-- PRD-PHASE-6 §2 (Drei-Lagen-Identität) und §5.1 (action_owner ist
-- nicht cacheable, Mini-Call gebatched). Schema-Konsequenzen:
--
-- users:
--   aliases JSON               — Lage 2: Anrede-Varianten (KI-Vorschlag, User bestätigt)
--   privacy_acknowledged_at    — DSGVO-Disclaimer-Akzept (PRD §10.3)
--
-- mail_scores:
--   action_owner ENUM          — Lage 3: 'user'|'other'|'group'|'unsure'
--   action_owner_confidence    — 0-100; bei Fallback-Stufen 40/60/0
--   action_owner_source        — 'ki' oder 'fallback' (Observability für DA-Finding 1)
--
--   WICHTIG: Diese drei Spalten dürfen NIE im claude_cache landen.
--   Test CacheRepositoryTest::testActionOwnerFieldsAreNotCached pinnt das.
--
-- system_settings:
--   autoreply_max_per_day      — Sprint 6f Cost-Cap, schon in 6a seeden
--                                damit der Wert pre-existiert, wenn 6f greift.
--   prompt.user_identity_header / .recipients_header / .action_owner_rules
--                              — neue Prompt-Hilfsblöcke für Score-Prompt v1.3
--
-- Migration-Nummer: 0010 im PRD-Skelett war geplant, ist aber durch
-- mail_scores_auto_sort_attempts belegt (Sprint 0.2). 0015 ist die
-- nächste freie.

-- ============================================================
-- USER-IDENTITY (Lage 2 — lernbare Anrede-Aliase)
-- ============================================================
ALTER TABLE users
	ADD COLUMN aliases               JSON         NULL DEFAULT NULL AFTER display_name,
	ADD COLUMN privacy_acknowledged_at DATETIME(3) NULL DEFAULT NULL AFTER aliases;

-- ============================================================
-- ACTION-OWNER auf mail_scores (Lage 3 — Post-Cache)
-- ============================================================
ALTER TABLE mail_scores
	ADD COLUMN action_owner            ENUM('user','other','group','unsure') NOT NULL DEFAULT 'unsure' AFTER action_required,
	ADD COLUMN action_owner_confidence TINYINT UNSIGNED                       NULL DEFAULT NULL        AFTER action_owner,
	ADD COLUMN action_owner_source     ENUM('ki','fallback')                  NULL DEFAULT NULL        AFTER action_owner_confidence;

-- Dashboard-Query 6e filtert nach (tenant, action_owner, priority) —
-- Index spart Full-Scan auf der wachsenden Tabelle.
ALTER TABLE mail_scores
	ADD KEY idx_scores_owner (tenant_id, action_owner, priority);

-- ============================================================
-- SYSTEM-SETTINGS-Seeds für Sprint 6a/6f
-- ============================================================
INSERT IGNORE INTO system_settings (`key`, `value`, `type`, description) VALUES
	('autoreply_max_per_day', '15', 'int', 'Sprint 6f Cost-Cap: max Anzahl Auto-Reply-Drafts pro Tag pro User'),
	('prompt.user_identity_header', 'USER_IDENTITY (so heißt der Postfach-Inhaber — bei Anrede-Ambiguität immer action_owner=unsure):', 'string', 'Header über dem USER_IDENTITY-Block im Score-Prompt (Sprint 6a)'),
	('prompt.recipients_header',    'RECIPIENTS (alle Empfänger der konkreten Mail; USER-Marker zeigt den Postfach-Inhaber):', 'string', 'Header über dem Recipients-Block im Score-Prompt (Sprint 6a)'),
	('prompt.action_owner_rules',
		'ACTION_OWNER_RULES:\n- Wenn die Anrede im Body eindeutig auf USER zeigt (Alias-Match, kein anderer Empfänger mit gleichem Vornamen) → action_owner="user".\n- Wenn die Anrede auf einen anderen Empfänger zeigt → action_owner="other".\n- Wenn ambig (zwei Empfänger mit gleichem Vornamen) → IMMER action_owner="unsure", niemals raten.\n- Wenn Verteiler / unpersönliche Anrede → action_owner="group".\n- action_owner_confidence: 0-100. Bei klarem Alias-Match ≥ 80, bei reinem Kontext-Schluss ≤ 60.',
		'string',
		'ACTION_OWNER-Reasoning-Regeln für den Score-Prompt (Sprint 6a §2.1)');
