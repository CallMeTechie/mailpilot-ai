# MailPilot AI

KI-gestütztes Outlook Add-in für E-Mail-Triage. Klassifiziert eingehende Mails
nach Relevanz, generiert Kurz-Zusammenfassungen und Antwort-Vorschläge, setzt
Outlook-Kategorien automatisch.

## Features

- **Relevanz-Klassifizierung** — Direct / Action / CC / Newsletter / Auto / Noise
- **Tages-Briefing** — Zähler + Top-Priority-Liste beim Outlook-Start
- **Kurz-Zusammenfassung** — pro Mail, 1–2 Sätze
- **Antwort-Entwürfe** — in deinem Ton, Sprache aus Thread erkannt
- **Auto-Kategorisierung** — native Outlook-Kategorien per Graph API
- **Multi-Tenant** — für Teams, mit Rollen
- **Self-hosted** — auf deinem NAS, DSGVO-freundlich

## Architektur

```
Outlook Desktop/Web
    ↓ (Office.js Task Pane)
Backend (PHP 8.4 + MariaDB + Redis)  ← Synology NAS
    ↓
Microsoft Graph API  (Mails, Kategorien)
    ↓
Claude API (Haiku → Scoring, Opus → Summary/Reply)
```

## Tech Stack

| Layer | Tech |
|-------|------|
| Add-in | Office.js, vanilla JS, ES2022 modules |
| Backend | PHP 8.4, PSR-12 (tabs), PDO |
| Storage | MariaDB 11.4, Redis 7 |
| AI | Claude Haiku 4.5 + Opus 4.7 |
| Integration | MS Graph API (OAuth2 + PKCE) |
| Deploy | Docker Compose on Synology DSM 7.2 |

## Setup

Drei Schritte: Azure App Registration → Container-Stack hochfahren → Outlook
Add-in sideloaden. Der Stack läuft auf Synology DS218+/DS220+/DS920+ mit
DSM 7.2+ über den Container Manager — alternativ überall, wo `docker compose`
verfügbar ist.

### 1. Azure App Registration

1. https://entra.microsoft.com → **App registrations** → **New**
2. Redirect URI: `https://mailpilot.deine-domain.de/api/v1/auth/oauth/callback`
3. API permissions (delegated):
   - `Mail.Read` · `Mail.ReadWrite` · `MailboxSettings.Read` · `User.Read` · `offline_access`
4. **Certificates & secrets** → Client-Secret erzeugen, **Wert** notieren
5. Tenant-ID, Client-ID notieren

### 2. Container-Stack auf der NAS deployen

Detail-Anleitung mit DSM-spezifischen Hinweisen: **[docs/SYNOLOGY-INSTALL.md](docs/SYNOLOGY-INSTALL.md)**.

Kurz-Version:

1. **DSM File Station** → Ordner `/docker/mailpilot-ai/` anlegen
2. `.env` aus [`docker/.env.example`](docker/.env.example) ableiten und mit echten Werten
   füllen. Mindestens `JWT_SECRET`, `ENCRYPT_KEY` (je `openssl rand -hex 32`),
   `DB_PASS`, `DB_ROOT_PASS`, `CLAUDE_API_KEY`, `MS_CLIENT_ID/SECRET`,
   `APP_BASE_URL`, `MS_REDIRECT_URI`, `ADMIN_USER`, `ADMIN_PASS_HASH_B64`.
3. [`docker/docker-compose.synology.yml`](docker/docker-compose.synology.yml)
   nach `/docker/mailpilot-ai/docker-compose.yml` hochladen (rename auf
   `docker-compose.yml` — DSM erwartet diesen Namen).
4. **DSM Container Manager** → **Projekt** → **Erstellen** mit Namen `mailpilot-ai`,
   Pfad `/docker/mailpilot-ai`, Quelle „bestehende docker-compose.yml".
5. **DSM Login Portal** → **Reverse Proxy** für Backend (`:19080`) und Admin (`:19081`)
   auf deine Domain einrichten.

Beim ersten Start dauert es ~30–60 s, bis das Backend-Init Migrations
eingespielt hat und der Healthcheck grün wird. Danach starten Worker und
Admin automatisch.

Lokal (Linux/macOS, Dev-Maschine): `cd docker/ && docker compose up -d` mit
[`docker/docker-compose.yml`](docker/docker-compose.yml).

### 3. Add-in sideloaden

In Outlook: **Datei → Add-Ins verwalten → Meine Add-Ins → Benutzerdefiniertes
Add-In hinzufügen → aus Datei** → `addin/manifest.xml` auswählen.

Für Team-Rollout (Office 365 Admin Center): Zentrale Bereitstellung über
**Integrated Apps**.

## Development

### Backend

```bash
cd backend/
composer install
cp config/config.example.php config/config.php  # dann editieren
php -S localhost:8080 -t public/
composer test
composer cs-fix
```

### Add-in

```bash
cd addin/
# manifest.xml zeigt auf localhost:3000 in DEV — ggf. anpassen
npx http-server src/ -p 3000 --ssl
```

Office Add-ins erfordern HTTPS — für lokal entweder self-signed Cert oder
`npm install -g office-addin-dev-certs`.

## Dokumentation

- [`docs/PRD.md`](docs/PRD.md) — Produkt-Spezifikation
- [`docs/API.md`](docs/API.md) — REST API Contract
- [`docs/PROMPTS.md`](docs/PROMPTS.md) — Claude Prompt Library
- [`docs/DSGVO.md`](docs/DSGVO.md) — Datenschutz-Notizen
- [`CLAUDE.md`](CLAUDE.md) — Projekt-Standards (für Claude Code)

## Roadmap

### MVP (v0.1)
- [x] Projekt-Skelett
- [x] DB-Schema
- [x] Claude Client + Scoring Service
- [x] Graph Client + OAuth
- [x] Task Pane UI
- [ ] Auth-Controllers + JWT-Middleware
- [ ] Worker für asynchrones Scoring
- [ ] Sync-Controller + Job-Tracking
- [ ] Integration-Tests

### v0.2
- [ ] Thread-level Analyse (statt nur letzte Mail)
- [ ] Kalender-Awareness
- [ ] Weekly Digest per Mail

### v1.0
- [ ] Bedrock eu-central-1 für EU-Sovereign-Mode
- [ ] Admin-UI (Mandanten, Nutzer, Prompt-Tuning)
- [ ] Slack/Teams Mirror

## Lizenz

Proprietär — S-TechSMD. Nutzung durch Marc und lizenzierte Teammitglieder.
