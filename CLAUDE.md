# CLAUDE.md — MailPilot AI

**Project:** MailPilot AI — Outlook Add-in for AI-powered inbox triage
**Owner:** Marc (S-TechSMD)
**Stack:** Office.js Add-in + PHP 8.4 Backend + MariaDB + Redis + Claude API
**Status:** MVP scaffolding

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
                                   │  - Claude orchestrator   │
                                   │  - Graph API client      │
                                   └──────┬──────┬────────────┘
                                          │      │
                         ┌────────────────┘      └──────────────┐
                         ▼                                      ▼
                 ┌────────────────┐                    ┌─────────────────┐
                 │  MS Graph API  │                    │  Claude API     │
                 │  (OAuth2, mail │                    │  Haiku 4.5 →    │
                 │   read, cat.)  │                    │  scoring        │
                 └────────────────┘                    │  Opus 4.7 →     │
                                                       │  summary/reply  │
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
- **UI:** Light-mode primary. S-TechSMD design system (Inter font, Slate-50 base, JetBrains Mono for code). Dark mode only as opt-in secondary.
- **Dates:** Always store UTC in DB, format to user TZ on output.

## 4. Directory layout

```
mailpilot-ai/
├── CLAUDE.md                    # this file
├── README.md
├── docs/
│   ├── PRD.md                   # full product spec
│   ├── PROMPTS.md               # Claude prompt library (versioned)
│   ├── API.md                   # backend REST contract
│   └── DSGVO.md                 # compliance notes
├── addin/                       # Outlook Web Add-in
│   ├── manifest.xml             # Office.js manifest
│   ├── src/
│   │   ├── taskpane.html
│   │   ├── taskpane.js
│   │   ├── taskpane.css
│   │   ├── api.js               # backend client
│   │   └── i18n.js
│   └── assets/                  # icons (16/32/64/80/128)
├── backend/
│   ├── public/
│   │   ├── index.php            # front controller, only entry
│   │   └── .htaccess
│   ├── config/
│   │   ├── config.php           # env-driven, no secrets in git
│   │   └── config.example.php
│   ├── migrations/              # numbered SQL migrations
│   ├── src/
│   │   ├── Controllers/         # thin HTTP layer
│   │   ├── Services/            # business logic
│   │   ├── Claude/              # Claude API client + prompt templates
│   │   ├── Graph/               # MS Graph API client
│   │   ├── Repositories/        # PDO data access
│   │   └── Models/              # plain DTOs
│   └── composer.json
├── sql/
│   └── schema.sql               # full schema snapshot
└── docker/
    ├── Dockerfile
    └── docker-compose.yml       # for Synology deployment
```

## 5. Claude API usage rules

- **Scoring model:** `claude-haiku-4-5-20251001` — batches of up to 20 mails per call
- **Summary/Reply model:** `claude-opus-4-7` — one mail at a time, only if score ≥ 60
- **Always** set `max_tokens` explicitly. Scoring: 2000. Summary: 400. Reply draft: 800.
- **Caching:** Hash `(from, subject, body_first_2kb)` → SHA-256. If cached score exists in last 30 days, reuse.
- **Pre-filter before Claude:** Discard mails where `List-Unsubscribe` header is set AND sender not in user's VIP list → auto-score `newsletter`.
- **PII redaction:** Before sending to Claude, redact IBANs, credit card numbers, and strings matching user's configured redaction patterns.

## 6. Multi-tenancy

- Every table has `tenant_id` (UUID).
- Every query MUST filter by `tenant_id`. Repositories enforce this; no raw queries in controllers.
- User ↔ Tenant is many-to-many via `tenant_user` (role: owner/admin/member).
- One user can connect multiple M365 accounts (one mailbox per row in `mailboxes`).

## 7. Testing & deployment

- PHPUnit for backend services (`tests/Unit`, `tests/Integration`).
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
