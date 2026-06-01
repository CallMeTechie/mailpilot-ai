# Roadmap / Backlog

Kurzliste geplanter Verbesserungen. Details jeweils im verlinkten Dokument unter [`docs/superpowers/`](docs/superpowers/).

## Offen

### Routing-UI: Provider-Namen-Picker statt UUID-Textfeld
Die „Fallback-Chain pro Rolle" in der Admin-LLM-Routing-Ansicht (`admin/src/Views/llm/routing.php`) nutzt aktuell ein rohes, komma-getrenntes **Provider-UUID-Textfeld**. Ersetzen durch eine **namensbasierte, geordnete Auswahl** (Slots Primary/Fallback-1/… als `<select>` mit `Name (kind) ☁/🏠`). **Gespeichert werden weiterhin Provider-UUIDs** (rename-sicher, eindeutig, FK-korrekt; `LlmController::saveRouting()` ggf. an die Slot-Form anpassen). Reines Vanilla-JS, server-rendered, kein Datenmodell-Change.

- **Grund:** UUIDs als Speicher sind richtig, aber für den Admin unleserlich.
- **Kontext/Design:** [`docs/superpowers/specs/2026-06-01-dynamic-model-effort-selection-design.md`](docs/superpowers/specs/2026-06-01-dynamic-model-effort-selection-design.md) · [`docs/superpowers/plans/2026-06-01-dynamic-model-effort-selection.md`](docs/superpowers/plans/2026-06-01-dynamic-model-effort-selection.md)
- **Erfasst:** 2026-06-01

## Erledigt

- **Dynamische Multi-Provider-Modell- & Effort-Auswahl + Claude Opus 4.8** — live entdeckter Modellkatalog (alle Provider), Effort pro Rolle, Summary/Draft über den `LlmRouter` auf Opus 4.8. PR #1, deployed 2026-06-01. (Spec/Plan unter `docs/superpowers/`.)
