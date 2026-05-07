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

### 1. Azure App Registration

1. https://entra.microsoft.com → App registrations → New
2. Redirect URI: `https://mailpilot.your-domain.de/api/v1/auth/oauth/callback`
3. API permissions (delegated):
   - `Mail.Read`
   - `Mail.ReadWrite`
   - `MailboxSettings.Read`
   - `User.Read`
   - `offline_access`
4. Client secret erzeugen, notieren

### 2. Secrets generieren

```bash
# JWT secret (32 bytes)
openssl rand -hex 32

# Encryption key für Token-Verschlüsselung (32 bytes)
openssl rand -hex 32
```

### 3. `.env` erstellen (im `docker/` Ordner)

```env
JWT_SECRET=<aus Schritt 2>
ENCRYPT_KEY=<aus Schritt 2>
DB_PASS=<sicheres Passwort>
DB_ROOT_PASS=<sicheres Passwort>
CLAUDE_API_KEY=sk-ant-...
MS_CLIENT_ID=<aus Azure>
MS_CLIENT_SECRET=<aus Azure>
```

### 4. Deploy

```bash
cd docker/
docker compose up -d
docker compose logs -f backend
```

Die DB wird beim ersten Start aus `sql/schema.sql` initialisiert.

### 5. Add-in sideloaden

In Outlook: Datei → Add-Ins verwalten → Meine Add-Ins → Benutzerdefiniertes
Add-In hinzufügen → aus Datei → `addin/manifest.xml` auswählen.

Für Office 365 Admin Center (Team-Rollout): Zentrale Bereitstellung über
Integrated Apps.

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
