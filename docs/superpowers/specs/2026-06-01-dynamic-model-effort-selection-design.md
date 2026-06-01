# Design: Dynamische Multi-Provider-Modell- & Effort-Auswahl

**Datum:** 2026-06-01
**Autor:** Marc / CallMeTechie (Brainstorming mit Claude)
**Status:** Draft v3 — Devils-Advocate Runde 1+2 eingearbeitet (siehe §11/§12)
**Auslöser:** Migration auf Claude Opus 4.8 mit Effort-Steuerung; verallgemeinert zu einem
provider-übergreifenden, dynamisch entdeckten Modell-/Effort-Auswahlsystem.

---

## 1. Ziel & Leitprinzip

**Leitprinzip:** Keine hardcodierten Modell-IDs in UI oder Code. Jedes wählbare Modell stammt
aus einem **live entdeckten Katalog**. Effort-Level werden — wo die Provider-API es hergibt —
pro Modell automatisch erkannt. „Opus 4.8 nutzen" wird dadurch zur reinen Datenauswahl im
Dropdown statt zu einer Code-Änderung.

**Konkrete Anforderungen (von Marc):**
1. Modell wählbar per Dropdown — über **alle** Provider (Anthropic, OpenAI, Gemini, Mistral, lokal).
2. Effort-Level wählbar per Dropdown.
3. **Nichts hardcodiert.** Neue Modelle sollen **automatisch** auftauchen, ohne Code-Änderung.

**Bestätigte Entscheidungen aus dem Brainstorming:**
- Katalog-Speicherung: **DB-Tabelle `llm_model_catalog` + „Aktualisieren"-Button + täglicher Worker-Job.**
- Effort-Emission: **Anthropic** (voll auto-erkannt via `capabilities.effort`) **+ OpenAI** (`reasoning_effort`).
  Gemini/Mistral/lokal zeigen „—" und senden kein Effort.
- Effort-Default für Summary/Draft: **`medium`** (Startwert, live per Dropdown änderbar).
- **DA-1-Entscheidung (Variante a):** Summary/Draft laufen künftig **über den `LlmRouter`** (wie Score),
  damit Modell + Effort pro Rolle aus `llm_models` kommen und **echt multi-provider** wählbar sind.
  Konsequenz: Modell/Effort leben **ausschließlich in `llm_models`** (nicht in `prompt_versions`).
- **DA-2-Entscheidungen:** (a) Score-Rolle bleibt im `direct`-Mode beim gebatchten Direct-Pfad; ihr
  Modell/Effort-Dropdown wird sichtbar als „nur bei routing_mode=router wirksam" gekennzeichnet.
  (b) `draft` macht **keinen** stillen Provider-Failover (bei Overload → Toast); `summary` darf faillovern.
  (c) Summary/Draft cachen wie heute **nicht** (kein `cacheSegments`).

---

## 2. Verifizierte technische Grundlagen

- **Anthropic Models-API** (`GET /v1/models`) liefert pro Modell ein `capabilities.effort`-Objekt mit
  `supported` + pro-Level-Flags (`low/medium/high/xhigh/max`), dazu `id`, `display_name`,
  `created_at`, `max_tokens`, `max_input_tokens`. Paginiert über `has_more`/`last_id` (`after_id`).
  **Kein** explizites prompt-caching-Flag im `capabilities`-Objekt (nur batch/citations/code_execution/
  context_management/effort/thinking/image_input/pdf_input/structured_outputs).
  Quelle: <https://platform.claude.com/docs/en/api/models/list>
- **Effort-API:** Top-Level-Feld `output_config: { "effort": "<level>" }` im Messages-Body. Werte
  `low|medium|high|xhigh|max`, Default `high` (= Feld weglassen). **Kein Beta-Header**,
  `anthropic-version: 2023-06-01` bleibt. Opus 4.8 nutzt Adaptive Thinking; manuelles
  `budget_tokens` → 400. Quelle: <https://platform.claude.com/docs/en/build-with-claude/effort>
- **Opus 4.8:** API-ID `claude-opus-4-8`; Preis $5 in / $25 out pro MTok; Max-Output 128k; Context 1M.
- **OpenAI** `GET /v1/models` liefert nur IDs (keine Effort-/Chat-Capability) und enthält **alle**
  Modellfamilien (Embeddings, Audio, Bild, Moderation, Legacy). `reasoning_effort`
  (`low|medium|high`) gilt nur für Reasoning-Modelle.
- **Bestehende Modell-Wahl im Code:** `MailSummaryService`/`ReplyDraftService` lesen Modell +
  `max_tokens` aus `prompt_versions` (`getActive()`) und rufen `$this->claude->messages()` **direkt**
  (nicht über `LlmRouter`). Diese werden auf den Router umgestellt (§5.8). `MailScoringService`
  nutzt den Router bei `llm.routing_mode='router'` (Default `direct`) — **bleibt unverändert**.

---

## 3. Scope

### In Scope
- Discovery-Methode pro `LlmProvider` + Katalog-Tabelle + Refresh (Button + Worker, mit Bootstrap).
- Effort-Spalte in `llm_models`; Effort-Emission für Anthropic + OpenAI.
- **Summary/Draft auf den `LlmRouter` umstellen** (Variante a) — Modell+Effort pro Rolle aus `llm_models`.
- Dynamische Modell- + Effort-Dropdowns in der LLM-Admin-Ansicht (`llm/edit.php`) statt hardcodierter
  Optionen; Prompt-Editor verliert die Modell-Auswahl (verweist auf Routing).
- Opus-4.8-Datenmigration (llm_models, model_pricing, config.php, Doku).

### Out of Scope (Non-Goals)
- **Score-Pfad-Umbau.** `MailScoringService` behält seinen Dual-Pfad (Direct/Router) und Haiku.
- **Bedrock-Discovery.** `BedrockClient` (EU-souverän) hat eine andere API (`ListFoundationModels`)
  und ist **kein** `LlmProvider`. Modell-Swap + Effort gelten für Bedrock nur, soweit der Router-Pfad
  Bedrock unterstützt; Bedrock-Modelle werden **nicht** in den Katalog auto-entdeckt.
- **Gemini/Mistral Effort-Emission** (Thinking-Budgets). Modelle werden gelistet, Effort = leer.
- Kein Caching-Redesign. (Caching-Verhalten für Summary wird beim Router-Umbau bewusst erhalten, §5.8.)

> **Bewusste Verhaltensänderung:** Mit Variante a laufen Summary/Draft **immer** über den Router
> (auch bei `routing_mode='direct'`). Ohne konfigurierte Chain fällt der Router auf
> `llm.primary_provider_id` zurück — funktional identisch zum bisherigen Single-Provider-Direct-Call,
> aber Modell/Effort kommen jetzt aus `llm_models`.

---

## 4. Architektur

```
┌─ LlmProvider (+ 1 Methode) ─────────────────────────────────┐
│ listModels(): ModelDescriptor[]   (nur CHAT-fähige Modelle) │  live gegen /models-Endpoint,
│   Anthropic → capabilities.effort → effortLevels (voll)     │  eigener KURZER Timeout (10s),
│   Gemini    → Filter supportedGenerationMethods=generate... │  mit gespeichertem API-Key
│   Mistral   → Filter capabilities.completion_chat           │  (SecretBox)
│   OpenAI    → Familien-Heuristik (gpt-/o*/chatgpt-);        │
│               effortLevels = ['low','medium','high']        │
│   OpenAiCompatible/Ollama → alle (lokal, admin-kuratiert)   │
└───────────────┬─────────────────────────────────────────────┘
                ▼  (resilient pro Provider, Fehler isoliert)
┌─ ModelCatalogService ───────────────────────────────────────┐
│  refreshProvider(id): 1 Provider (Button)                    │  Trigger:
│  refreshAll(): Voll-Sweep (Worker)                           │   • Admin-Button → 1 Provider (POST+CSRF)
│  upsert; nur bei ERFOLG markStale(available=0)               │   • Worker: Bootstrap beim Start
│  (transiente Ausfälle leeren den Katalog NICHT)              │     + tägliche Kadenz
└───────────────┬─────────────────────────────────────────────┘
                ▼
┌─ Admin-Dropdowns (lesen aus Katalog ∪ aktuelle Auswahl) ────┐
│  llm/edit.php: pro llm_models-Rolle Modell-<select>          │  Vanilla JS, server-rendered
│  (optgroup je Provider, data-effort-levels) + Effort-<select>│
│  Prompt-Editor: KEINE Modellwahl mehr → Hinweis auf Routing  │
└───────────────┬─────────────────────────────────────────────┘
                ▼  Auswahl model_id + effort (validiert gegen Katalog)
            llm_models(+effort)
                ▼
   alle Inferenz-Rollen (score/summary/draft/inference) → LlmRouter
                ▼  NormalizedRequest.effort
   AnthropicProvider → output_config.effort  |  OpenAiProvider → reasoning_effort
```

**Trennung der Zuständigkeiten:**
- **Katalog** = was es gibt (entdeckt, alle Provider) — `llm_model_catalog`. Speist nur Dropdowns.
- **Auswahl** = was gewählt ist + Effort (kuratiert) — `llm_models`. Quelle der Wahrheit für Routing/Pricing.

---

## 5. Komponenten im Detail

### 5.1 `ModelDescriptor` (Value-Object, `src/Llm/`)
- Felder: `modelId:string`, `displayName:string`, `effortLevels:list<string>`,
  `maxOutputTokens:?int`, `maxContextTokens:?int`, `releasedAt:?string`.
- **Kein `supportsCaching`** — die Anthropic-API liefert dafür kein verlässliches Flag (DA-6).
- `null`/`0`-Token-Werte werden als „unbekannt" behandelt, nie als „0" gerendert.
- Reines DTO, `readonly`, `declare(strict_types=1)`.

### 5.2 `LlmProvider::listModels(): ModelDescriptor[]`
- Neue Interface-Methode. Wirft `LlmUnavailableException` bei Transport-/Auth-Fehlern (damit der
  Katalog-Refresh den Provider überspringen kann, ohne den Katalog zu beschädigen).
- **Eigener Discovery-Timeout (10s)**, NICHT der 60s-Inferenz-Timeout (DA-3).
- **Testbarkeit:** Parsing in eine **reine** Methode `parseModelsResponse(array $json): ModelDescriptor[]`
  je Provider auslagern. Unit-Tests fahren gegen Fixture-JSON; der HTTP-Call bleibt dünn.
- **Chat-Filter pro Provider** (DA-2 — Katalog nur mit nutzbaren Modellen):
  - **Anthropic:** `GET {baseUrl}/models?limit=1000`, paginiert. Liste ist sauber → alle. effortLevels
    aus `capabilities.effort` (`supported && level.supported`).
  - **Gemini:** Filter auf Modelle mit `supportedGenerationMethods` ⊇ `generateContent` (API-getrieben).
  - **Mistral:** Filter auf `capabilities.completion_chat == true` (API-getrieben).
  - **OpenAI:** Familien-Heuristik an **einer** Stelle (IDs `gpt-`/`o1`/`o3`/`o4`/`chatgpt-`; ausschließen
    von `embedding|whisper|tts|dall-e|moderation|audio|image|davinci|babbage`). Dokumentiert als
    wartbare API-Vertrags-Heuristik (keine versions-spezifische Hardcodierung).
    effortLevels = `['low','medium','high']`.
  - **OpenAiCompatible/Ollama:** alle (lokale Listen sind klein, Admin kuratiert via enable-Flag).
  - *(Gemini/Mistral-Filterfelder vor Implementierung an der jeweiligen API verifizieren.)*

### 5.3 Tabelle `llm_model_catalog` (neue Migration)
```sql
CREATE TABLE llm_model_catalog (
    id                 CHAR(36) NOT NULL PRIMARY KEY,
    provider_id        CHAR(36) NOT NULL,
    model_id           VARCHAR(160) NOT NULL,
    display_name       VARCHAR(190) NOT NULL,
    effort_levels      JSON NULL,            -- ["low","medium","high","xhigh","max"] oder []
    max_output_tokens  INT NULL,
    max_context_tokens INT NULL,
    released_at        DATETIME NULL,
    available          TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    discovered_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_catalog_provider_model (provider_id, model_id),
    KEY idx_catalog_provider_avail (provider_id, available),
    CONSTRAINT fk_catalog_provider FOREIGN KEY (provider_id)
        REFERENCES llm_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.4 `LlmModelCatalogRepository`
- `upsert(string $providerId, ModelDescriptor $d): void` (ON DUPLICATE KEY UPDATE, `last_seen_at`/`available=1`).
- `markStale(string $providerId, list<string> $seenModelIds): void` (setzt `available=0` für nicht gesehene).
- `listByProvider(string $providerId, bool $availableOnly = true): array`.
- `listAvailableGrouped(): array` (für Dropdowns, gruppiert nach enabled Provider).
- `find(string $providerId, string $modelId): ?array` (Effort-Validierung beim Save; eindeutig dank
  provider_id — DA-7). Alias↔kanonisch: beim Save die kanonische Katalog-ID übernehmen.

### 5.5 `ModelCatalogService`
- `refreshProvider(string $providerId): array` — **ein** Provider (Button). `listModels()` in try/catch;
  bei Erfolg upsert + `markStale`; bei Fehler loggen + Fehlertext **defensiv gekürzt** zurück (kein Key-Leak).
- `refreshAll(): array` — Voll-Sweep über aktive Provider (Worker), je Provider via `refreshProvider`.
  Rückgabe: Map `provider → {discovered:int, error:?string}`. Ein Provider-Fehler **leert den Katalog nie**.

### 5.6 Refresh-Trigger
- **Admin-Button** „Modelle aktualisieren" in `llm/edit.php` (pro Provider!): POST-Route
  `/admin/llm/{id}/models/refresh` (CSRF) → `refreshProvider($id)` → Flash mit Count/Fehler.
  Pro-Provider-Refresh hält den Request schnell und isoliert langsame/down Provider (DA-3).
- **Worker (DA-4 Bootstrap):** `refreshAll()` **einmal beim Worker-Start** + danach in der bestehenden
  **täglichen Housekeeping-Kadenz** in `bin/worker.php`. try/catch — Fehler dürfen den Loop nicht killen.

### 5.7 Effort-Spalte (neue Migration)
- `ALTER TABLE llm_models ADD COLUMN effort VARCHAR(12) NULL` (nach `max_context`).
- **`prompt_versions` bleibt unverändert** (Variante a: Modell/Effort leben in `llm_models`). `getActive()`
  unverändert.
- Erlaubte Werte App-seitig gegen die Katalog-`effort_levels` des gewählten Modells validiert (kein ENUM,
  weil provider-/modellabhängig). Leerer Katalog → Fallback auf `low|medium|high|xhigh|max` + Hinweis-Flash.

### 5.8 Request-Emission & Router-Umbau von Summary/Draft
- `NormalizedRequest` += `public readonly ?string $effort = null` (letzter Parameter, default `null`,
  backward-compatible).
- `LlmRouter` reicht beim Model-Resolve aus der `llm_models`-Zeile zusätzlich `effort` in den
  rekonstruierten `NormalizedRequest` durch.
- **AnthropicProvider.buildPayload():** `if ($request->effort !== null) $payload['output_config'] = ['effort' => $request->effort];`
- **OpenAiProvider.buildPayload():** `if ($request->effort !== null) $payload['reasoning_effort'] = $request->effort;`
- **Summary/Draft-Refactor (Variante a):**
  - `MailSummaryService` / `ReplyDraftService` injizieren künftig den `LlmRouter` statt `ClaudeProvider`.
  - Sie bauen einen `NormalizedRequest` (systemPrompt, messages, `maxTokens`+`temperature` weiter aus
    `prompt_versions`, `modelHint=''` → Router resolved Modell+Effort pro Rolle, **`cacheSegments=[]`**
    — kein Caching, exakt wie der heutige Direct-Pfad; DA-R2-2) und rufen
    `$router->complete($req, 'summary')` bzw. `'draft'`.
  - **Budget-Gate, Redaction und `recordUsage` bleiben in den Services.** `recordUsage` nutzt
    `NormalizedResponse.usage` + `response->modelId` (das tatsächlich gewählte Modell — genauer als bisher).
  - **Kernel-Wiring:** Der `LlmRouter` muss für Summary/Draft **immer** gebaut werden (heute wird er bei
    `routing_mode='direct'` nie konstruiert — `Kernel.php`). Anpassen.
  - **Draft-Failover-Policy (DA-R2-4):** `draft` hat per Default **keine** Multi-Provider-Chain (in 0051
    sind nur `score`+`summary` geseedet) → der Router nutzt allein `primary_provider_id` = **Single-Provider,
    kein Failover** auf ein schwächeres Modell. Bewusst so belassen. Bei Overload mappt der HTTP-Exception-
    Handler die Router-Ausnahme (Ursache `LlmOverloadedException`) auf das bestehende **503 + Retry-After**
    (Add-in-Toast), wie heute beim direkten Anthropic-Overload. `summary` behält seine Failover-Chain (0051).
  - **Safety-Net (DA-R2-5):** Ist die aufgelöste Chain für summary/draft leer (z.B. Anthropic-Provider
    deaktiviert), fällt der Service auf den Legacy-Direct-Anthropic-Call zurück statt hart mit
    `LlmAllProvidersDownException` zu scheitern. Post-Deploy-Smoke-Test (1 Summary + 1 Draft) in die
    Release-Checkliste.

### 5.9 Admin-UI (Vanilla JS, server-rendered — gemäß CLAUDE.md)
- `llm/edit.php`: pro `llm_models`-Zeile ein **Modell-`<select>`** (Optionen aus `listByProvider` des
  Providers, jede Option mit `data-effort-levels`) + **Effort-`<select>`** (Optionen aus den
  effortLevels des gewählten Modells; erste Option immer „— (Default)" = `null`; disabled wenn leer).
  Plus der „Aktualisieren"-Button. `LlmController::saveModel` um `model_id` + `effort` erweitert,
  inkl. Validierung (effort ∈ Katalog-effortLevels des Modells oder `null`).
- **Score-Rolle (DA-R2-1):** Die `score`-Zeile zeigt dieselben Dropdowns, ist aber sichtbar als
  „wirkt nur bei routing_mode=router" markiert (im `direct`-Mode liest der gebatchte Scoring-Pfad
  weiter das Prompt-Modell). summary/draft sind voll wirksam, da router-always.
- **Selektion erhalten (DA):** Option-Liste = `Katalog ∪ {aktuell gespeichertes model_id}` — eine
  gewählte ID, die (noch) nicht im Katalog ist, bleibt sichtbar/vorausgewählt.
- `prompt_edit.php`: hardcodierte Modell-`<option>` **entfernen**. Statt Modellwahl ein Hinweis
  „Modell & Effort werden pro Rolle in der LLM-Routing-Ansicht gesetzt" (Link). `prompt_versions.model`
  bleibt als NOT-NULL-Spalte bestehen (historisch), wird für Summary/Draft nicht mehr zur Ausführung
  gelesen; beim Anlegen neuer Versionen mit dem aktuellen Rollen-Modell vorbelegt.
- **Output-Escaping:** `display_name`/`model_id` aus externer API immer `$h()`; `data-effort-levels`
  als sauberes JSON encoden.

### 5.10 Opus-4.8-Datenmigration (reine Daten — jetzt nur `llm_models`/Pricing, kein Prompt-Touch)
- UPDATE `llm_models` summary/draft → `model_id='claude-opus-4-8'`, `effort='medium'`,
  `cost_per_mtok_in=5.00`, `cost_per_mtok_out=25.00` (USD, Einheit dieser Tabelle).
  Targeting über Rolle, nicht über alten Modell-Wert (DA-5).
- INSERT `model_pricing` `claude-opus-4-8` (EUR, ~0.92-Kurs): input 4.6000, output 23.0000,
  cache_read 0.4600, cache_creation 5.7500. Opus-4-7-Zeile **bleibt** (historische Kosten).
- `config.php` + `config.example.php`: `model_summary`/`model_reply` → `claude-opus-4-8`;
  Bedrock-Map += `claude-opus-4-8 => eu.anthropic.claude-opus-4-8-v1:0`
  (**TODO vor Commit:** exakte EU-Bedrock-ID gegen AWS-Doku verifizieren).
- **`prompt_versions` wird NICHT angefasst** (Modell kommt jetzt aus `llm_models`).
- Migrationen ab `0060`: Reihenfolge (1) `llm_model_catalog`, (2) `llm_models.effort`,
  (3) Opus-4.8-Daten/Pricing.

### 5.11 Doku
- `docs/PROMPTS.md` (Modell-Refs raus / „Modell pro Rolle in Routing"), `docs/BEDROCK.md` (Opus-4.8-ARNs),
  `CLAUDE.md §5` (Effort-Regel, dynamischer Katalog, Summary/Draft via Router), ggf. `README.md`.

---

## 6. Sicherheits-Überlegungen

- **Kein neuer SSRF-Vektor:** `listModels()` ruft dieselbe `base_url` wie `complete()` — admin-kuratiert.
- **API-Keys:** Discovery nutzt die bestehende `SecretBox`-Entschlüsselung; Keys verlassen den Server
  nicht und landen nicht im Katalog/Log. Provider-Fehlertexte vor Flash/Log defensiv kürzen.
- **CSRF:** Refresh-Button + alle Save-Actions über `verifyCsrf()`.
- **Output-Escaping:** externe `display_name`/`model_id` immer `$h()`; JSON-Attribute sauber encoden.
- **Multi-Tenancy:** `llm_models`/`llm_model_catalog`/`llm_providers` sind system-global (admin-verwaltet,
  kein `tenant_id`) — konsistent mit dem bestehenden Provider-Modell. Keine tenant-Leak-Fläche.

---

## 7. Teststrategie

**Unit (kein DB, `composer test:unit`):**
- `parseModelsResponse()` je Provider gegen Fixture-JSON: Anthropic-Effort-Extraktion (inkl.
  `supported=false` → `[]` für Haiku); OpenAI-Chat-Filter wirft Embeddings/Audio raus; Gemini/Mistral
  Chat-Filter; OpenAI effortLevels=`['low','medium','high']`.
- `AnthropicProvider.buildPayload()`: `output_config.effort` gesetzt bei `effort!==null`, sonst weg.
- `OpenAiProvider.buildPayload()`: `reasoning_effort` analog.
- `ModelCatalogService.refreshAll()` mit gemockten Providern: Upsert + Stale-Marking; Provider-Fehler
  → übersprungen, **kein** Stale-Marking (Katalog bleibt erhalten).
- `LlmRouter`: liefert `effort` aus `llm_models`-Zeile in den `NormalizedRequest` durch.
- Summary/Draft-Service: baut `NormalizedRequest` korrekt (modelHint leer, **cacheSegments=[]**), ruft
  Router mit Rolle 'summary'/'draft', `recordUsage` nutzt `response.modelId`; Budget-Gate bleibt aktiv.
- Draft-Overload: **kein** stiller Failover; Overload propagiert → 503+Retry-After-Mapping. Summary darf faillovern.
- Safety-Net: leere Chain für summary/draft → Legacy-Direct-Anthropic-Call statt Hard-Fail.

**Integration (MariaDB, `composer test:integration`):**
- `LlmModelCatalogRepository` Upsert/markStale/listAvailableGrouped/find.
- `llm_models.effort` Roundtrip.
- Admin-Save persistiert `model_id`+`effort` und lehnt ungültige Effort-Werte ab.

---

## 8. Implementierungs-Phasen

- **Phase A — Fundament:** `ModelDescriptor`, `listModels()` + `parseModelsResponse()` + Chat-Filter je
  Provider (10s-Timeout), `llm_model_catalog`-Migration, `LlmModelCatalogRepository`,
  `ModelCatalogService`, Refresh-Button (pro Provider) + Worker-Bootstrap/Daily. Tests A.
- **Phase B — Effort & Router-Umbau:** `llm_models.effort`-Migration, `NormalizedRequest.effort`,
  Emission (Anthropic+OpenAI), **Summary/Draft auf Router umstellen** (+ Kernel-Wiring), dynamische
  Dropdowns + Validierung in `llm/edit.php`, Prompt-Editor-Modellwahl entfernen. Tests B.
- **Phase C — Opus-4.8-Daten:** Daten-Migration (`llm_models`, `model_pricing`), `config.php`/
  `config.example.php`, Bedrock-ID-Verifikation, Doku.

---

## 9. Risiken & offene Punkte

- **OpenAI `reasoning_effort` auf Nicht-Reasoning-Modellen → 400.** Effort opt-in (Default `null`); im
  Router ist OpenAI nur Fallback — ein 400 propagiert (kein Failover). Doku-Hinweis.
- **Bedrock-Effort/Router-Pfad.** `output_config.effort` über die Bedrock-Messages-API ist unverifiziert;
  Bedrock-Discovery ist out of scope. Phase C: bei Bedrock-Installationen testen oder Effort dort weglassen.
- **`max_tokens` vs. `xhigh/max`.** Summary/Draft haben kleine `max_tokens` (400/800). Hohe Effort-Level
  brauchen laut Doku großzügige `max_tokens` — bei diesen kurzen Tasks bewusst gedeckelt.
- **Verhaltensänderung Summary/Draft → Router.** Muss in einem Release zusammen mit korrekt geseedeten
  `llm_models`-Zeilen + `primary_provider_id` deployen (sonst kein Provider in der Chain). Safety-Net
  (§5.8) + Smoke-Test (§Release-Checkliste) fangen Fehlkonfiguration ab. (`primary_provider_id` ist in
  0050 geseedet, `summary.fallback_chain` in 0051 — post-Deploy funktionsfähig.)
- **Cost-Reporting-Shift (DA-R2-3):** Summary/Draft erscheinen nach dem Umbau zusätzlich im
  `llm_call_log` (USD-Dashboard, gespeist via Router-`LlmCallLogger`), parallel zu `api_usage` (EUR-Budget
  via `recordUsage`). Verifizieren, dass **kein** einzelner Wert beide Tabellen für denselben Call summiert;
  die beiden Dashboards bleiben getrennt (Ops-Kosten USD vs. Budget EUR) — bewusst dokumentieren.
- **Gemini/Mistral-Chat-Filterfelder** vor Implementierung an den echten APIs verifizieren.
- **Exakte EU-Bedrock-ID** für Opus 4.8 noch zu verifizieren.

---

## 10. Definition of Done

- Admin wählt pro `llm_models`-Rolle (score/summary/draft/inference) Modell **und** Effort per Dropdown;
  Listen kommen ausschließlich aus dem Katalog (keine hardcodierten Optionen, kein Embeddings-Müll).
- „Modelle aktualisieren" (pro Provider) + Worker-Bootstrap/Daily füllen den Katalog; ein neu
  erschienenes Modell ist nach dem nächsten Refresh ohne Code-Änderung wählbar.
- Summary/Draft laufen über den Router; Anthropic-Calls senden `output_config.effort`, OpenAI
  `reasoning_effort`, wenn gesetzt.
- Summary/Draft laufen auf `claude-opus-4-8` mit `effort=medium`; Cost-Dashboard kennt den Preis;
  Budget-Gate + Usage-Logging weiter aktiv.
- `composer test:unit` + `composer test:integration` grün; CI grün; PHPStan/CS-Fixer sauber.

---

## 11. Devils-Advocate — Runde 1 (eingearbeitet)

| # | Concern | Severity | Auflösung |
|---|---------|----------|-----------|
| 1 | Prompt-Editor bot „alle Provider", Direct-Pfad kann nur Anthropic | Critical | **Variante a**: Summary/Draft über Router; Modell/Effort aus `llm_models` (§5.8). |
| 2 | OpenAI `/v1/models` flutet Katalog mit Nicht-Chat-Modellen | High | Chat-Filter pro Provider (API-getrieben wo möglich, OpenAI-Heuristik) (§5.2). |
| 3 | Synchroner Voll-Refresh blockiert Admin-Request → 504 | High | 10s-Discovery-Timeout; Button refresht **einen** Provider; Worker macht Voll-Sweep (§5.5/5.6). |
| 4 | Katalog nach Deploy leer bis Tages-Tick | High | Worker-**Bootstrap** beim Start + Daily (§5.6). |
| 5 | In-place-UPDATE der Prompts verletzt Versionskonvention | Medium | Modell wandert nach `llm_models` → Prompts unangetastet; `llm_models` per Rolle (nicht Modellwert) targeten (§5.10). |
| 6 | Nicht verifizierte API-Felder (`supports_caching`, `max_tokens=0`) | Medium | Nur bestätigte Felder; `supportsCaching` gestrichen; 0 = „unbekannt" (§5.1). |
| 7 | Effort-Validierung ohne provider_id im Prompt | Medium | Auswahl lebt in `llm_models` (hat provider_id) → eindeutiger Lookup; Alias→kanonisch beim Save (§5.4). |

## 12. Devils-Advocate — Runde 2 (fokussiert auf den Variante-a-Router-Umbau, eingearbeitet)

| # | Concern | Severity | Auflösung |
|---|---------|----------|-----------|
| R2-1 | `score`-Dropdown wirkt im `direct`-Mode nicht (Batching-Pfad) | High | Score-Zeile sichtbar „nur bei routing_mode=router" labeln; summary/draft voll dynamisch; Batching bleibt (§5.9). |
| R2-2 | `cacheSegments=[0]` „erhält" kein Caching — führt es auf pro-User-Prompt EIN | Medium | `cacheSegments=[]` — kein Caching, exakt wie heute (§5.8). |
| R2-3 | Summary/Draft nun zusätzlich im `llm_call_log` (USD) → Reporting-Shift | Medium | Verifizieren, dass kein Wert beide Tabellen summiert; bewusst dokumentiert (§9). |
| R2-4 | Stiller Qualitätsabfall bei Draft-Failover; Effort gilt nicht für Fallback | Medium | `draft` Single-Provider-Chain (kein Failover) + Overload→503-Toast; `summary` faillovert (§5.8). |
| R2-5 | Neue harte Abhängigkeit von valider Router-Chain für Summary/Draft | Medium | Safety-Net: leere Chain → Legacy-Direct-Anthropic-Call; Post-Deploy-Smoke-Test (§5.8/§9). |
