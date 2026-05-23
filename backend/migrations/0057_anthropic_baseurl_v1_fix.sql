-- Phase 9q-Hotfix (Marc 2026-05-23) — Anthropic-Provider base_url-Korrektur.
--
-- Bug: Migration 0050 hat 'https://api.anthropic.com' (ohne /v1) gesetzt.
-- AnthropicClient::messages haengt nur '/messages' an → 404 statt
-- /v1/messages. Golden-Set-Run zeigt 0.0% Label-Accuracy weil ALLE
-- Calls mit status=404 fehlschlagen.
--
-- Der AnthropicProvider normalisiert das jetzt zwar im Code (defensives
-- /v1-Ergaenzen), aber wir korrigieren auch die DB damit das base_url-
-- Feld direkt korrekt ist und die Admin-UI den richtigen Wert zeigt.
--
-- Idempotent — UPDATE nur wenn aktueller Wert kein /v1 enthaelt.

UPDATE llm_providers
SET base_url = 'https://api.anthropic.com/v1'
WHERE kind = 'anthropic'
  AND base_url NOT LIKE '%/v1%'
  AND base_url NOT LIKE '%/v2%';
