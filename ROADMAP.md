# Roadmap / Backlog

Kurzliste geplanter Verbesserungen. Details jeweils im verlinkten Dokument unter [`docs/superpowers/`](docs/superpowers/).

## Offen

### Score-Batch-Größe als Admin-Setting
Die Anzahl Mails pro Scoring-Call (`scoring_batch_size`, Default 20) ist aktuell nur ein `config.php`-Wert (fix beim Container-Start). Als **Laufzeit-Setting** auffindbar machen: neues `system_settings.scoring.batch_size`, Admin-**Dropdown/Zahlenfeld** (z.B. „Aus (1) / 5 / 10 / 20 / 50"), `MailScoringService` liest es zur Laufzeit (Fallback = Config-Default). „Batching aus" = Größe 1.

- **Wichtig:** Batch-Größe ist an `max_tokens` des P-SCORE-Prompts gekoppelt — bei zu großem Batch wird das JSON abgeschnitten → ganzer Chunk failt auf `[]`. Validierung/Hinweis im UI. Der `results[]`-Output-Vertrag im Prompt bleibt (er ist anzahl-agnostisch).
- **Erfasst:** 2026-06-01

### Score-Modell/Effort über das Dropdown wirksam machen
Aktuell übergibt `MailScoringService::callViaRouter()` das Modell des aktiven **P-SCORE-Prompts** als `modelHint` (nicht leer) und reicht **kein** Effort durch → der `LlmRouter` löst Modell/Effort **nicht** aus `llm_models` auf. Das score-Dropdown in `admin/src/Views/llm/edit.php` steuert das Scoring daher nicht (Label am 2026-06-01 entsprechend korrigiert). Optionen: dauerhaft so dokumentieren **oder** `callViaRouter()` auf `modelHint=''` + Effort-Durchreichung umstellen, damit das Dropdown im `router`-Mode greift (Batching bleibt erhalten — der Chunk ist ein Request). Gut testen, da es die Modell-Quelle fürs Scoring ändert.

- **Erfasst:** 2026-06-01

## Erledigt

- **Routing-UI: Provider-Namen-Picker** — UUID-Textfeld durch geordnete Namens-Selects ersetzt; Banner-Text korrigiert + interner Hotfix-Kommentar entfernt + lesbare Routing-/Privacy-Labels. 2026-06-01.
- **score-Dropdown-Label korrigiert** (`admin/src/Views/llm/edit.php`) — „⚠ nur bei routing_mode=router" war irreführend; jetzt „ℹ steuert das Scoring derzeit nicht" (das Dropdown wirkt aktuell nicht aufs Scoring, siehe offenen Task oben). 2026-06-01.
- **Dynamische Multi-Provider-Modell- & Effort-Auswahl + Claude Opus 4.8** — live entdeckter Modellkatalog (alle Provider), Effort pro Rolle, Summary/Draft über den `LlmRouter` auf Opus 4.8. PR #1, deployed 2026-06-01. (Spec/Plan unter `docs/superpowers/`.)
