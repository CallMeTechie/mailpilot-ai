# CLAUDE.md — MailPilot AI

**Project:** MailPilot AI — Outlook Add-in for AI-powered inbox triage
**Owner:** CallMeTechie (CallMeTechie.de)
**Stack:** Office.js Add-in + PHP 8.4 Backend + MariaDB + Redis + multi-provider LLM layer
**Status:** Production — self-hosted v1.0 line (multi-tenant, multi-provider LLM, separate admin panel). v0.2 thread-analysis features in progress; 59 migrations; backend + admin + add-in deployed via Docker Compose on Synology.

---

## 1. Mission

Reduce email overload by classifying every incoming mail for relevance, generating short summaries, suggesting replies, and auto-categorizing in Outlook. User never has to read all mails — MailPilot surfaces only what matters.

## 2. Architecture at a glance

```
┌─────────────────────────┐        ┌──────────────────────────┐
│  Outlook Desktop/Web    │        │  Backend (PHP 8.4)       │
│  Task Pane Add-in       │◄──────►│  /api/v1/*               │
│  (Office.js, vanilla JS)│  HTTPS │  - Auth (JWT)            │
└─────────────────────────┘        │  - Sync orchestrator     │
                                   │  - LLM orchestrator      │
                                   │  - Graph API client      │
                                   └──────┬──────┬────────────┘
                                          │      │
                         ┌────────────────┘      └──────────────┐
                         ▼                                      ▼
                 ┌────────────────┐                    ┌─────────────────┐
                 │  MS Graph API  │                    │  LLM Router     │
                 │  (OAuth2, mail │                    │  score → Haiku  │
                 │   read, cat.)  │                    │  summary→ Opus  │
                 └────────────────┘                    │  +OpenAI/Gemini │
                                                       │  /Mistral/local │
                                                       └─────────────────┘
                         ▲
                         │
                 ┌───────┴────────┐
                 │ MariaDB        │
                 │ Redis (cache/  │
                 │  queue)        │
                 └────────────────┘
```

**Why backend-mediated (not direct Claude from Add-in):**
- API key never leaves server
- Multi-tenant rate limiting & cost control
- Prompt versioning & A/B
- Caching (same mail ≠ rescored)
- Audit log / DSGVO compliance

## 3. Non-negotiable standards (inherited from global CLAUDE.md)

- **PHP:** 8.4+, `declare(strict_types=1);` in every file, PSR-12 with **tabs** (not spaces)
- **DB:** PDO prepared statements only. No query builder. Soft deletes (`deleted_at`) everywhere.
- **JS:** Vanilla JS in Add-in. No frameworks. ES2022 modules.
- **Commits:** Conventional Commits (feat:, fix:, chore:, docs:, refactor:)
- **i18n:** DE primary, EN secondary. All user-facing strings through `L::t('key')`.
- **Security-first layout:** `/public` is the only web root. Everything else above it.
- **UI:** Light-mode primary. CallMeTechie.de design system (Inter font, Slate-50 base, JetBrains Mono for code). Dark mode only as opt-in secondary.
- **Dates:** Always store UTC in DB, format to user TZ on output.

## 4. Directory layout

```
mailpilot-ai/
├── CLAUDE.md                    # this file
├── README.md
├── docs/
│   ├── PRD.md / PRD-PHASE-6.md  # product spec
│   ├── PROMPTS.md               # LLM prompt library (versioned)
│   ├── API.md                   # backend REST contract
│   ├── BEDROCK.md               # EU-sovereign provider notes
│   ├── SYNOLOGY-INSTALL.md      # NAS deployment guide
│   └── DSGVO.md                 # compliance notes
├── addin/                       # Outlook Web Add-in
│   ├── manifest.xml             # generated; source: manifest.template.xml
│   ├── build-bundle.sh          # cat src/scripts/*.js > src/taskpane.js
│   ├── build-manifest.sh
│   ├── src/
│   │   ├── scripts/             # 01-state.js … 16-confirm-modal.js (SOURCE OF TRUTH)
│   │   ├── styles/              # 01-tokens.css … 13-pin-list.css
│   │   ├── taskpane.js          # GENERATED bundle — never edit directly
│   │   ├── taskpane.html / taskpane.css
│   │   └── api.js               # backend client
│   ├── tests-js/ , tests-css/   # bundle-integrity (drift) tests
│   └── assets/                  # icons (16/32/64/80/128)
├── backend/
│   ├── public/index.php         # front controller, only entry
│   ├── config/                  # config.php (env-driven) + config.example.php
│   ├── migrations/              # 59 numbered SQL migrations
│   ├── bin/                     # worker.php, migrate.php, smoke.php, …
│   ├── src/
│   │   ├── Controllers/         # thin HTTP layer (+ Settings/)
│   │   ├── Services/            # business logic (+ Sender/, Scoring/)
│   │   ├── Llm/                 # multi-provider layer: LlmRouter + Providers/
│   │   │                        #   (Anthropic, OpenAI, Gemini, Mistral, OpenAI-compat),
│   │   │                        #   LlmCallLogger, GoldenSetRunner
│   │   ├── Claude/              # Anthropic/Bedrock clients + ProviderFactory
│   │   ├── Graph/               # MS Graph API client
│   │   ├── Repositories/        # PDO data access (tenant_id enforced)
│   │   ├── Http/                # Kernel, routing, Exceptions
│   │   ├── Security/ , Util/
│   │   └── Models/              # plain DTOs
│   ├── tests/                   # Unit/ + Integration/ (PHPUnit)
│   └── composer.json
├── admin/                       # separate admin panel (own public/, Kernel,
│                                #   Controllers, server-rendered Views: tenants,
│                                #   prompts, LLM, budget, usage, audit, cache)
├── sql/
│   └── schema.sql               # full schema snapshot
└── docker/                      # Dockerfile(+.admin), nginx, supervisord,
    ├── docker-compose.yml       #   compose for local dev …
    └── docker-compose.synology.yml  # … and Synology deployment
```

## 5. LLM usage rules (multi-provider)

- **Never call a provider SDK directly.** All inference goes through `Llm\LlmRouter`, which resolves a model from the `llm_models` registry by **role** (`score` / `summary` / `draft` / `inference`) and walks a per-role **failover chain** across configured providers. Default provider: Anthropic; fallbacks: OpenAI, Gemini, Mistral, Qwen, and any OpenAI-compatible local endpoint (privacy mode). Every call is mirrored to `llm_call_log` for the cost dashboard.
- **Default models per role:** `score` → `claude-haiku-4-5-20251001` (batches of up to 20 mails per call), `summary` / `draft` → `claude-opus-4-8` (one mail at a time, only if score ≥ 60), `inference` → Haiku-class. Per-role models are overridable in the admin panel — don't hard-code model IDs in services.
- **Dynamic model catalog + effort:** Modelle werden pro Provider live entdeckt (`LlmProvider::listModels()` → `llm_model_catalog`, befüllt per Admin-Button + täglichem Worker-Refresh) — **keine hardcodierten Modell-IDs** in Services/UI. Auswahl + Effort pro Rolle leben in `llm_models`. Effort wird emittiert als Anthropic `output_config.effort` (pro Modell auto-erkannt aus der Models-API) bzw. OpenAI `reasoning_effort`. Summary/Draft laufen über den `LlmRouter` (mit Legacy-Direct-Anthropic-Safety-Net). Cost-Tracking bleibt getrennt: `api_usage` (EUR-Budget) vs. `llm_call_log` (USD-Ops) — kein Wert summiert beide für denselben Call.
- **Always** set `max_tokens` explicitly. Scoring: 2000. Summary: 400. Reply draft: 800.
- **Caching:** Hash `(from, subject, body_first_2kb)` → SHA-256. If cached score exists in last 30 days, reuse.
- **Pre-filter before the LLM:** Discard mails where `List-Unsubscribe` header is set AND sender not in user's VIP list → auto-score `newsletter`.
- **PII redaction:** Before sending to any provider, redact IBANs, credit card numbers, and strings matching user's configured redaction patterns (`RedactionService`).

## 6. Multi-tenancy

- Every table has `tenant_id` (UUID).
- Every query MUST filter by `tenant_id`. Repositories enforce this; no raw queries in controllers.
- User ↔ Tenant is many-to-many via `tenant_user` (role: owner/admin/member).
- One user can connect multiple M365 accounts (one mailbox per row in `mailboxes`).

## 7. Testing & deployment

- PHPUnit for backend services (`tests/Unit`, `tests/Integration`).
  - `composer test:unit` — no DB, fast. `composer test:integration` / `composer test:all`
    spin up a secured MariaDB 11.4 via `bin/test-db-up.sh` (127.0.0.1, random pass,
    auto-migrations), source its creds, then run the suite. Teardown:
    `bash bin/test-db-up.sh --down`. Requires Docker.
  - **No SQLite fallback by design.** The schema is MariaDB-specific (ENUM, utf8mb4,
    JSON, FK CASCADE), so tests run against the real engine to stay faithful — a
    translated SQLite schema would drift and give false confidence.
- Add-in: manual smoke tests via Office Add-in sideloading.
- Deployment: Docker Compose on Synology DS218+. nginx-proxy + Let's Encrypt via existing stack.

## 8. When building features, always:

1. Read `docs/PRD.md` for feature context first.
2. Check `docs/PROMPTS.md` — don't invent new Claude prompts, extend versioned ones.
3. Write migration BEFORE code that uses the new column.
4. Add the endpoint to `docs/API.md` BEFORE implementing the controller.
5. Never log full email bodies. Log mail IDs and score only.

## 9. Known decisions / ADRs

- **No Exchange on-prem support in MVP.** M365/Graph API only. EWS is deprecated.
- **No attachment analysis in MVP.** Too expensive, too risky for DSGVO.
- **No auto-reply-sending.** Replies are always drafts the user approves.
- **Categories are Outlook-native.** We create: `MP-Direct`, `MP-CC`, `MP-Action`, `MP-Newsletter`, `MP-Auto`, `MP-Noise`. User can rename in settings; we sync via Graph.

## 10. Out of scope for MVP

- Calendar integration
- Teams/chat triage
- Mobile-specific UI (Outlook Mobile uses same task pane)
- On-device (local) inference
