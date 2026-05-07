# PRD — MailPilot AI

## 1. Problem

Knowledge workers receive 80–200 mails/day. Most are noise (newsletters, CC info, automated). User wastes 1–2h/day scanning, and critical mails that require action get buried. Existing Outlook "Focused Inbox" uses heuristics, no content understanding. No visibility into _what Outlook's ML thinks_ and no override.

## 2. Target users

- **Primary:** Marc (self-hosted, heavy PHP/dev workflow, many project mails)
- **Secondary:** S-TechSMD team members
- **Tertiary:** SMB teams who install MailPilot on their own infra (white-label potential)

## 3. Core user stories

### US-01: Morning briefing
> As a user, when I open Outlook in the morning, I want to see a one-glance summary: how many mails overnight, how many need my action today, what the top 3 items are.

### US-02: Per-mail triage score
> As a user, for every mail in my inbox, I want to see a relevance badge (Direct/CC/Action/Newsletter/Auto/Noise) and a short 1–2 sentence summary without opening the mail.

### US-03: Action-required flag
> As a user, I want mails that the sender expects a response or decision from me to be flagged red ("Action required") and surfaced to the top of my briefing.

### US-04: Reply draft
> As a user, when a mail needs a reply, I want MailPilot to generate a draft in my tone (DE/EN, formal/casual depending on thread history). I review and send.

### US-05: Auto-categorization
> As a user, I want MailPilot to set Outlook categories on mails automatically so I can use native Outlook filters/search.

### US-06: VIP list
> As a user, I want to mark senders as VIP. VIP mails always score ≥ Direct, never get categorized as Noise.

### US-07: Redaction
> As a user (and especially as admin for team), I want to configure patterns (regex) that get redacted before mail content goes to Claude. Banking data, customer IDs, etc.

## 4. Non-functional requirements

| Area | Target |
|------|--------|
| Scoring latency | ≤ 8s for batch of 20 mails |
| Summary latency | ≤ 4s per mail |
| API cost | ≤ 0.02 € per mail triaged end-to-end |
| Availability | 99% (homelab-grade) |
| Data residency | EU (if using Bedrock); otherwise document Anthropic US transfer in AVV |
| Mail retention in our DB | 30 days for scores, 7 days for bodies (then purge) |

## 5. Classification taxonomy

| Label | Meaning | Color | Outlook Category |
|-------|---------|-------|------------------|
| `direct` | Addressed directly to user, personal content | red | MP-Direct |
| `action` | Requires decision/reply/task from user | orange | MP-Action |
| `cc` | User in CC, informational | blue | MP-CC |
| `newsletter` | Marketing/subscription, List-Unsubscribe set | gray | MP-Newsletter |
| `auto` | Automated (CI, monitoring, receipts) | slate | MP-Auto |
| `noise` | Spam-like or irrelevant | light gray | MP-Noise |

A mail can have `direct` AND `action`. Labels are not mutually exclusive; primary + flags.

## 6. Scoring prompt (high-level — full version in PROMPTS.md)

Inputs per mail: `from`, `to`, `cc`, `subject`, `body_text` (first 2 KB), `has_attachment`, `is_reply`, `list_unsubscribe`.

User profile injected: `user_email`, `user_role`, `vip_senders`, `project_keywords`, `language`.

Output: structured JSON per mail with `{label, action_required, priority (1-5), summary_de (max 160 chars), reasoning}`.

## 7. Data model (see sql/schema.sql)

Key tables: `tenants`, `users`, `tenant_user`, `mailboxes`, `mails`, `mail_scores`, `claude_cache`, `vip_senders`, `redaction_rules`, `prompt_versions`, `audit_log`.

## 8. API surface (see docs/API.md)

- `POST /api/v1/auth/login` — JWT via M365 OAuth redirect
- `GET /api/v1/briefing/today` — morning summary
- `GET /api/v1/mails?since=…&limit=…` — triaged mail list
- `POST /api/v1/mails/{id}/summarize`
- `POST /api/v1/mails/{id}/draft-reply`
- `POST /api/v1/sync` — manually trigger sync
- `GET/POST /api/v1/settings/vip`
- `GET/POST /api/v1/settings/redaction`

## 9. UI (Task Pane)

Three tabs:
1. **Briefing** — today's overview, top-priority mails, counters
2. **Inbox** — triaged list with score badges, search/filter
3. **Settings** — VIP, redaction, language, prompt tuning

Design: S-TechSMD light mode. Inter 14px body, Inter 18/22 headings. Slate-50 bg, white cards with 1px slate-200 border, 8px radius, subtle shadow. No glassmorphism.

## 10. Roadmap after MVP

- Thread-level analysis (not just latest mail)
- Calendar awareness ("meeting in 30 min, this mail is from attendee")
- Weekly digest mail
- Slack/Teams mirror of briefing
- On-device Haiku via Bedrock for EU-sovereign mode
