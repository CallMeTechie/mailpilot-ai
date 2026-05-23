-- Phase 9q B5-Fix (Marc 2026-05-23): USD→EUR Wechselkurs als Setting.
--
-- Wird in LlmController::showUsage() gelesen, um den USD-Aggregat aus
-- llm_call_log in EUR umzurechnen (konsistent zu /admin/usage und
-- /admin/settings/budgets, die durchgängig in EUR rechnen).
--
-- Default 0.92 entspricht ungefähr dem EZB-Mittelwert Q2/2026. Marc kann
-- den Wert via INSERT/UPDATE auf system_settings nachschärfen, falls sich
-- der Kurs spürbar verschiebt. Idempotent — bestehender Wert bleibt.

INSERT INTO system_settings (`key`, `value`, `type`, description)
VALUES (
	'pricing.usd_to_eur_rate',
	'0.92',
	'float',
	'EUR-pro-USD-Kurs für LLM-Cost-Konvertierung im Admin-Dashboard. Default 0.92 (Q2/2026, EZB-Referenz). Nachschärfen bei Kurssprüngen.'
)
ON DUPLICATE KEY UPDATE
	`key` = `key`;
