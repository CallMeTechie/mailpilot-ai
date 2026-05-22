-- Phase 9p (Marc 2026-05-22) — Auto-Cleanup fuer Score-Override-Regeln.
--
-- Marc-Wunsch: ueberfluessige Regeln (deaktiviert oder lange ungenutzt)
-- sollen automatisch verschwinden. Drei Settings — User toggelt das in
-- Settings -> Regeln -> "Auto-Cleanup".
--
-- Default-Verhalten: enabled=true, delete_disabled=true,
-- delete_unused_after_days=7. Heisst: alle deaktivierten Regeln + alle
-- nie applies'ten Regeln aelter als 7 Tage werden vom Worker einmal
-- pro Tag soft-geloescht (deleted_at gestempelt). Restore moeglich via
-- DB-UPDATE — die Soft-Delete-Semantik aller anderen Repositories.

INSERT INTO system_settings (`key`, `value`, `type`, description) VALUES
	('score_rule_autoclean.enabled', '1', 'bool',
	 'Phase 9p: Score-Override-Regeln automatisch aufraeumen (per Worker-Tick).'),
	('score_rule_autoclean.delete_disabled', '1', 'bool',
	 'Phase 9p: Deaktivierte Regeln (enabled=0) sollen vom Cleanup mitgenommen werden.'),
	('score_rule_autoclean.delete_unused_after_days', '7', 'int',
	 'Phase 9p: Regeln mit applies_count=0, die mindestens N Tage alt sind, werden aufgeraeumt.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
