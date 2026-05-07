# API.md — MailPilot Backend REST Contract

**Base URL:** `https://mailpilot.s-techsmd.de/api/v1`
**Auth:** Bearer JWT (obtained via M365 OAuth2 flow)
**Content-Type:** `application/json`

---

## Auth

### `POST /auth/oauth/start`
Returns M365 authorization URL.
```json
→ { "auth_url": "https://login.microsoftonline.com/..." }
```

### `GET /auth/oauth/callback?code=…&state=…`
Exchanges code for tokens, creates/updates user, returns JWT.
```json
→ { "token": "eyJ…", "user": { "id": "...", "email": "...", "tenant_id": "..." } }
```

### `POST /auth/refresh`
Body: `{ "token": "..." }` → new JWT.

---

## Briefing

### `GET /briefing/today`
```json
→ {
  "generated_at": "2026-04-16T07:00:00Z",
  "counters": {
    "total_new": 47,
    "direct": 6,
    "action": 4,
    "cc": 12,
    "newsletter": 18,
    "auto": 5,
    "noise": 2
  },
  "top_priority": [
    {
      "mail_id": "AAMk…",
      "from": "alice@example.com",
      "subject": "Re: Q2 Angebot",
      "summary": "Alice fragt nach Nachbesserung des Angebots, Deadline Freitag",
      "priority": 5,
      "label": "action"
    }
  ]
}
```

---

## Mails

### `GET /mails?since=2026-04-16T00:00:00Z&limit=50&label=action`
```json
→ {
  "items": [
    {
      "id": "AAMk…",
      "from": "alice@example.com",
      "from_name": "Alice Example",
      "subject": "...",
      "received_at": "...",
      "score": {
        "label": "action",
        "action_required": true,
        "priority": 5,
        "summary": "...",
        "scored_at": "..."
      }
    }
  ],
  "next_cursor": "..."
}
```

### `POST /mails/{id}/summarize`
Triggers deep summary via Opus. Cached 30 days.
```json
→ {
  "summary": "**Worum geht's:** ...\n**Was wird erwartet:** ...\n**Deadline:** ..."
}
```

### `POST /mails/{id}/draft-reply`
Body (optional): `{ "instruction": "Zusage mit Vorbehalt" }`
```json
→ { "draft": "Hi Alice,\n\n..." }
```

### `POST /mails/{id}/rescore`
Force re-classification, bypass cache.

---

## Sync

### `POST /sync`
Body (optional): `{ "mailbox_id": "...", "since": "..." }`
```json
→ { "job_id": "...", "queued": 47 }
```

### `GET /sync/status/{job_id}`
```json
→ { "status": "running|done|error", "processed": 30, "total": 47 }
```

---

## Settings

### `GET /settings/vip`
### `POST /settings/vip`
Body: `{ "email": "boss@example.com", "name": "The Boss" }`
### `DELETE /settings/vip/{id}`

### `GET /settings/redaction`
### `POST /settings/redaction`
Body: `{ "pattern": "DE\\d{2}[ \\d]{18,}", "description": "IBAN" }`

### `GET /settings/user`
### `PATCH /settings/user`
Body: `{ "language": "de", "briefing_hour": 7, "project_keywords": ["Ori:Dev", "SocialPilot"] }`

---

## Errors

Standard error body:
```json
{
  "error": {
    "code": "MAILBOX_NOT_CONNECTED",
    "message": "User-facing message (localized)",
    "details": { ... }
  }
}
```

HTTP codes: 400 validation, 401 auth, 403 tenant-mismatch, 404 missing, 429 rate limit, 500 server.
