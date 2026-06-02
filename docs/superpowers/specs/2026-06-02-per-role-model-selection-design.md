# Spec 1 — Modell-/Effort-/Provider-Auswahl pro Rolle (Design)

**Datum:** 2026-06-02
**Status:** Entwurf zur Review
**Autor:** MailPilot AI (Brainstorming mit Marc)
**Folge-Spec:** [Spec 2 — Lern-Loop](2026-06-02-correction-learning-loop-design.md) (baut hierauf auf)

---

## Ziel

Pro LLM-Rolle (`score`, `summary`, `draft`, `inference`) frei **Provider + Modell + Effort** wählbar — **immer**, unabhängig vom `routing_mode`. `llm_models` wird die **einzige Wahrheit** für die Modellauflösung. `routing_mode` wird zum **reinen Failover-Schalter**. Das Modell-Feld verschwindet aus dem Prompt-UI. Der Mechanismus wird rollen-erweiterbar gebaut, damit Spec 2 die 5. Rolle `match` ohne Streuung ergänzen kann.

Beispiel-Endzustand (alles über Dropdowns, keine Hardcodierung): Drafts = Claude Opus 4.8 / Effort `xhigh`, Summary = OpenAI GPT-5.3, Scoring = Claude Sonnet 4.6 / `max`, Inference = Haiku-Klasse.

## Architektur (Kurz)

Alle vier Rollen rufen `LlmRouter->complete($req, $role)` mit `modelHint=''` auf; der Router resolved die Provider-Reihenfolge aus `llm.{role}.fallback_chain` und Modell+Effort pro `(provider_id, role)` aus `llm_models`. `routing_mode` steuert nur noch, ob die ganze Chain (`router`) oder nur der Primary (`direct`) probiert wird. Jeder Call wird vom Router selbst ins `llm_call_log` gespiegelt.

## Problem / Ist-Zustand

| Rolle | LLM-Weg heute | Modell/Effort wählbar? |
|---|---|---|
| summary | **immer** `router->complete(...,'summary')`, `modelHint=''` → `llm_models` | ✅ in beiden Modi |
| draft | **immer** `router->complete(...,'draft')`, `modelHint=''` → `llm_models` | ✅ in beiden Modi |
| score | `router`-Mode → `llm_models`; `direct`-Mode → **Prompt-Modell**, direkt `AnthropicClient`, kein Effort, kein Failover | ⚠️ nur im router-Mode |
| inference | **immer** direkt `ClaudeClient` (`messages`/`messagesBatch`), Prompt-Modell | ❌ gar nicht; Anthropic hart verdrahtet |

Konsequenzen:
- `routing_mode` ist faktisch ein **score-only-Schalter** und vermischt zwei Dinge: Modellquelle **und** Failover.
- Es gibt **zwei** Modellquellen — `prompt_versions.model` vs. `llm_models` — was zur „welches gewinnt?"-Verwirrung führt.
- Der `/admin/llm/routing`-Banner behauptet, die Chain gelte auch für `inference` — **falsch**, `inference` läuft nie über den Router.

## Design

### D1 — `llm_models` als einzige Wahrheit
`llm_models(role, provider_id, model_id, effort, enabled, priority, …)` ist die einzige Quelle für Provider+Modell+Effort pro Rolle. Alle Roll-Aufrufe gehen über `LlmRouter->complete($req, $role)` mit `modelHint=''`; der Router resolved Modell+Effort via `LlmModelRepository::findForProviderAndRole($providerId, $role)` (existiert bereits).

### D2 — `routing_mode` = Failover-Schalter (einheitlich für alle Rollen)
Neue Semantik in `LlmRouter` (`complete()`/`resolveChain()` liest `llm.routing_mode`):
- **`direct`** → Chain auf das **erste (Primary-)Element** kürzen. Kein Ausweichen. Primary down → `LlmAllProvidersDownException` (hartes Fail, wie das heutige direct-Verhalten).
- **`router`** → ganze Chain (heutiges Verhalten, inkl. Privacy-Mode-Filter).

Gilt einheitlich für **alle** Rollen. Bewusste Folge: `summary`/`draft` haben im `direct`-Mode künftig **auch** kein Failover (heute immer Router) — gewollte Vereinheitlichung der Semantik.

### D3 — `score` immer über den Router
`MailScoringService::callClaude()`: das `useRouter`-Gate (`routing_mode === 'router'`) entfällt — **immer** `callViaRouter()`. Der direkte `AnthropicClient`-Pfad und `mirrorDirectCallToLlmLog()` entfallen (der Router loggt sich selbst). Budget-Preflight (`recordCall`/`BudgetExceededException`) bleibt unverändert. Das Score-Batching (Prompt-Level, `scoring.batch_size`) bleibt unberührt.

### D4 — `inference` über den Router
`RuleInferenceService`: die `ClaudeClient`-Aufrufe (`messages`, `messagesBatch`) werden über `LlmRouter` (Rolle `inference`, `modelHint=''`) geführt. `buildClaudePayload()` baut künftig einen `NormalizedRequest`; `callClaude()` → `LlmRouter::complete()`; `callClaudeBatch()` → neuer `LlmRouter::completeBatch()`. JSON-Parsing/Fehlerbehandlung wie bisher (best-effort, kein Job-Killer).

**Verifiziert — entschärft DA-Fund #4:** Der **einzige** gebündelte LLM-Pfad ist die **Regel-Extraktion** in `inferAllFromCorrection()` — sie bündelt **maximal 3** Payloads (`P-RULE-EXTRACT` / `P-SCORE-RULE-EXTRACT` / `P-TOPIC-RULE-EXTRACT`, Zeilen 957/972/995). Der Backfill (`findMatchingMails`, bis `backfill_max=100`) wendet die extrahierte Regel **deterministisch** an — **kein LLM pro Mail**. Den „100 sequenzielle Calls"-Pfad gibt es also nicht.

**`completeBatch()`-Semantik:** läuft **sequenziell** (≤ 3 Items) — jedes Item über das normale `complete()` mit **per-Item-Failover** über die Chain; ein gescheitertes Item → **`null`-Slot**, **kein** Job-Kill (identisch zum heutigen `messagesBatch`-Verhalten). Native Provider-Batch-Parallelität (Anthropic `messagesBatch`) ist eine **optionale spätere Optimierung** (YAGNI — bei ≤ 3 Items irrelevant).

### D5 — Prompt-`model`-Feld raus aus dem UI
- Prompt-Edit-Seite (`admin/src/Views/prompts/*`) + zugehöriger Controller: **`model`-Eingabe entfernen**. `max_tokens` und `temperature` bleiben (prompt-spezifisch).
- Services lesen `$activePrompt['model']` **nicht mehr** für das Routing. Wo ein konkreter Modell-String nachgelagert nötig ist (Cache-`model`-Spalte, `recordCall`-Logging), wird der vom Router zurückgegebene `response.modelId` verwendet.
- **`modelId`-Garantie (DA-Fund #5):** `response.modelId` ist nicht von jedem Provider garantiert gefüllt (OpenAI-kompatible/lokale Endpoints liefern oft leer/abweichend). Da der Cost-Pfad (`api_usage`, `llm_call_log`, `PricingRepository`, `BudgetService::costEur`, `GoldenSetRunner`) auf dem Modell-String keyt, würde ein leerer `modelId` das Cost-Dashboard **still** vergiften. Der **Router garantiert** daher einen nicht-leeren `modelId`: liefert der Provider leer, fällt er auf das resolvte `llm_models.model_id` zurück (das der Router ohnehin kennt). Pro Provider ein Test, dass `modelId` nicht leer ist.
- `prompt_versions.model`-Spalte bleibt vorerst **in der DB (nullable, dormant)** — kein riskanter Daten-Migrations-Drop. Optionaler Cleanup später.
- Legacy-Direct-Fallback in `MailSummaryService`/`ReplyDraftService` (`claudeFallback`): der Modell-String wird aus dem **Primary der Rolle** (`llm_models`) abgeleitet statt aus `prompt.model`. (Alternative: Fallback ganz entfernen, da der Router-Pfad maßgeblich ist — Entscheidung im Plan; Default: ableiten, Safety-Net erhalten.)

### D6 — `inference`-Zeile in `llm_models` sicherstellen
Migration: falls keine `llm_models`-Zeile mit `role='inference'` existiert, eine Default-Zeile seeden (Anthropic Haiku-Klasse, `enabled=1`, `priority`) und `llm.inference.fallback_chain` mit einem sinnvollen Default belegen. Damit der Router für `inference` immer ein Modell findet.

### D7 — Rollen-Erweiterbarkeit
Die Rollen-Liste wird an **einer** Stelle definiert (Konstante/Config, z. B. `LlmRouter::ROLES` oder eine zentrale Config). Admin-UI (Routing-Chain + Modell-Dropdowns) iteriert diese Liste. Spec 2 fügt dort nur `match` hinzu.

### D8 — Upgrade-Migration für `routing_mode` (DA-Fund #6)
Heute laufen `summary`/`draft` **immer** über die volle Chain — haben also **immer Failover**. Mit D2 verliert `direct` dieses Failover für alle Rollen. Prod-Default ist `direct`. Damit beim Deploy **kein stiller Failover-Verlust** entsteht:
- **Migration** setzt bestehende Installs auf `routing_mode='router'` (Failover für summary/draft bleibt erhalten). Neue klare Semantik, kein Regressionsrisiko.
- **Sichtbarer Hinweis (DA-Fund C4):** Da die Migration auch Installs umstellt, die `direct` bewusst gewählt haben (Kostenkontrolle/Single-Provider), wird beim ersten Admin-Login ein **einmaliger Hinweis** angezeigt („`routing_mode` beim Upgrade auf `router` gesetzt — prüfen unter `/admin/llm/routing`"). Kein stiller Wechsel.
- Admin behält die freie Wahl, danach wieder auf `direct` zu stellen (= bewusst kein Failover).
- Begleitend: der **stale `Kernel.php`-Kommentar** (~Zeile 199), der behauptet, der Router werde im direct-Mode nicht konstruiert, wird bereinigt — der Router wird bereits eager gebaut (Zeilen ~352/359/368).

## Betroffene Komponenten / Dateien

- `backend/src/Llm/LlmRouter.php` — `routing_mode` → Failover-Verkürzung der Chain; zentrale Rollen-Konstante; **`modelId`-Garantie** (#5); neues **`completeBatch()`** (#4).
- `backend/src/Services/MailScoringService.php` — `useRouter`-Gate raus; immer `callViaRouter`; Direct-Pfad + `mirrorDirectCallToLlmLog` entfernen.
- `backend/src/Services/RuleInferenceService.php` — LLM-Calls über den Router (`'inference'`); Backfill via `completeBatch()`/Worker-Parallelität (kein 100×-sequenziell).
- `backend/src/Services/MailSummaryService.php`, `ReplyDraftService.php` — Fallback-Modell aus `llm_models` statt `prompt.model`.
- `admin/src/Views/prompts/*` + Prompt-Controller — `model`-Feld entfernen.
- `admin/src/Views/llm/routing.php` — Banner/Labels: „Failover-Schalter", gilt für alle Rollen.
- `backend/migrations/00XX_inference_model_seed.sql` — `inference`-Zeile + Chain-Default.
- `backend/migrations/00YY_routing_mode_router_on_upgrade.sql` — Bestand auf `routing_mode='router'` (#6).
- `backend/src/Http/Kernel.php` — DI-Anpassung (LlmRouter in RuleInferenceService); stalen direct-Mode-Kommentar (~Z. 199) bereinigen.

## Fehlerbehandlung

- `direct` + Primary down → `LlmAllProvidersDownException` → bestehende Behandlung in `Http/Router.php` (503 `AI_OVERLOADED` + Retry-After).
- `inference` über Router: alle Provider down → Inferenz skippt best-effort (wie heute `claude_failed`), kein Job-Killer.

## Teststrategie (Projekt-Konvention: **kein Mocking**)

- **Unit** (`MailPilot\Tests\Unit`, reale Objekte/Reflection, anonyme `LlmProvider`): `LlmRouter` kürzt im `direct`-Mode die Chain auf den Primary (kein Failover); im `router`-Mode volle Chain mit Failover.
- **Integration** (`MailPilot\Tests\TestCase`, `$this->pdo()`, `truncateAll()`, anon `LlmProvider`/`FakeClaudeClient`): `score` nutzt im `direct`-Mode das `llm_models`-Modell **+ Effort** (nicht `prompt.model`); `inference` über den Router resolved das Modell aus der `llm_models`-`inference`-Zeile; `summary`/`draft` unverändert grün.
- **`modelId`-Garantie (#5):** anon `LlmProvider`, der leeren `modelId` zurückgibt → Router liefert das resolvte `llm_models.model_id`, `llm_call_log`/`api_usage` bekommen nie ein leeres Modell.
- **`completeBatch()` (#4):** mehrere Requests → korrekte Zuordnung der Antworten; Fehler einzelner Items killen den Batch nicht.
- Composer-Scripts: `cs-check`, `vendor/bin/phpstan analyse`, `test:unit`, `test:integration`.

## Out of Scope (→ Spec 2)

`match`-Rolle, Per-Mail-Match-Score, Match-Modi (deterministisch/LLM/hybrid), Korrektur-Lern-Loop, Cache-Invalidierung bei Korrektur, Autoclean-Politik.

## Entscheidungen (Review 2026-06-02, Marc)

1. **Legacy-Fallback in Summary/Draft:** Modell aus dem `llm_models`-Primary der Rolle **ableiten** (Safety-Net bleibt erhalten).
2. **`prompt_versions.model`-Spalte:** vorerst **dormant** in der DB lassen — kein Drop in diesem Spec.
3. **Upgrade-Verhalten (#6):** Migration setzt bestehende Installs auf `routing_mode='router'` (Failover-Erhalt) + einmaliger Admin-Hinweis (D8).

*DA-Runde 1 (2026-06-02) eingearbeitet:* #4 inference-Concurrency (D4), #5 `modelId`-Garantie (D5), #6 Upgrade-Migration (D8).
*DA-Runde 2 eingearbeitet:* C3 `completeBatch`-Semantik (D4), C4 Upgrade-Hinweis (D8).
