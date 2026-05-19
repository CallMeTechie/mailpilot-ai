-- Phase 9d (Marc 2026-05-19) — Score-Override-Regeln auto-aktivieren ab Confidence-Schwelle.
--
-- Hintergrund: 9b legt KI-abgeleitete Regeln initial mit enabled=false an,
-- weil der User sie im Settings-Subtab „Regeln" pruefen soll. In der Praxis
-- erwartet Marc aber, dass die KI „direkt lernt" — wenn die Begruendung
-- explizit ist und die KI mit hoher Konfidenz eine Regel ableitet, soll die
-- Regel sofort greifen. Schwelle in system_settings, damit der User sie
-- zurueckdrehen kann.
--
-- 85 ist der gleiche Schwellenwert, den der P-SCORE-RULE-EXTRACT-Prompt
-- selbst als „hoch" definiert (Migration 0036 Zeile 54).
--
-- Setze auf 101 = nie auto-aktivieren (Phase-9c-Original-Verhalten).
-- Setze auf 0   = immer auto-aktivieren (gefaehrlich, fuer Tests).

INSERT INTO system_settings (`key`, value, type) VALUES
	('score_rule_auto_enable_threshold', '85', 'int')
ON DUPLICATE KEY UPDATE `key` = `key`;
