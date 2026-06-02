# Roadmap / Backlog

Kurzliste geplanter Verbesserungen. Details jeweils im verlinkten Dokument unter [`docs/superpowers/`](docs/superpowers/).

## Offen

_(derzeit keine offenen Punkte)_

## Erledigt

- **Score-Batch-Größe als Admin-Setting** — `system_settings.scoring.batch_size` (Migration 0063, Default 20), Zahlenfeld auf der Routing-Seite (1–100), `MailScoringService::scoreBatch()` liest es zur Laufzeit (Fallback = Config-Default). 1 = kein Batching. Hinweis: an P-SCORE-`max_tokens` gekoppelt. 2026-06-02.
- **Score-Modell/Effort übers Dropdown wirksam** — `callViaRouter()` übergibt jetzt `modelHint=''`, der `LlmRouter` resolved Modell **+ Effort** pro Rolle aus `llm_models` (greift im `router`-Mode; `direct`-Mode nutzt weiter das Modell des aktiven P-SCORE-Prompts). score-Dropdown-Label entsprechend korrigiert („ℹ wirkt bei routing_mode=router"). 2026-06-02.
- **Routing-UI: Provider-Namen-Picker** — UUID-Textfeld durch geordnete Namens-Selects ersetzt; Banner-Text korrigiert + interner Hotfix-Kommentar entfernt + lesbare Routing-/Privacy-Labels. 2026-06-01.
- **Dynamische Multi-Provider-Modell- & Effort-Auswahl + Claude Opus 4.8** — live entdeckter Modellkatalog (alle Provider), Effort pro Rolle, Summary/Draft über den `LlmRouter` auf Opus 4.8. PR #1, deployed 2026-06-01. (Spec/Plan unter `docs/superpowers/`.)
