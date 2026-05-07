# DEPLOY.md — Deployment-Checkliste

Step-by-step um MailPilot auf dem Synology DS218+ zum Laufen zu bringen.

---

## Phase 1 — Azure / Microsoft Entra

- [ ] https://entra.microsoft.com öffnen → App registrations → **New registration**
- [ ] Name: `MailPilot AI` · Accounts: "Multitenant + Personal" (für Gmail/Outlook.com Kompatibilität)
- [ ] Redirect URI (Web): `https://mailpilot.s-techsmd.de/api/v1/auth/oauth/callback`
- [ ] Notieren: `Application (client) ID`
- [ ] **Certificates & secrets** → New client secret → 24 Monate → Notieren (nur einmal sichtbar!)
- [ ] **API permissions** → Add permission → Microsoft Graph → Delegated:
	- [ ] `Mail.Read`
	- [ ] `Mail.ReadWrite`
	- [ ] `MailboxSettings.Read`
	- [ ] `User.Read`
	- [ ] `offline_access`
- [ ] Grant admin consent (nur für Single-Tenant; Multi-Tenant machen User selbst beim OAuth)
- [ ] **Authentication** → Advanced settings → Allow public client flows = **No** (wir nutzen client secret)

## Phase 2 — DNS + Reverse Proxy

- [ ] DNS-Record: `mailpilot.s-techsmd.de` → NAS-IP (A-Record oder CNAME auf deine dynamische DNS)
- [ ] Synology Control Panel → Login Portal → Advanced → Reverse Proxy → Neu:
	- Source: `https://mailpilot.s-techsmd.de:443`
	- Destination: `http://localhost:<mapped-port>` (z.B. 18080)
	- Custom Header → WebSocket: Upgrade + Connection
- [ ] Let's Encrypt Zertifikat über DSM Control Panel → Security → Certificate (ggf. DNS-Challenge, wenn Port 80 nicht offen)

## Phase 3 — Secrets

```bash
# Auf deinem NAS, via SSH:
cd /volume1/docker/mailpilot-ai/docker

# Secrets generieren
JWT=$(openssl rand -hex 32)
KEY=$(openssl rand -hex 32)
DBP=$(openssl rand -base64 24 | tr -d '=+/' | head -c 24)
DBRP=$(openssl rand -base64 24 | tr -d '=+/' | head -c 24)

cat > .env <<EOF
JWT_SECRET=$JWT
ENCRYPT_KEY=$KEY
DB_PASS=$DBP
DB_ROOT_PASS=$DBRP
CLAUDE_API_KEY=sk-ant-...        # aus console.anthropic.com
MS_CLIENT_ID=<aus-Azure>
MS_CLIENT_SECRET=<aus-Azure>
EOF

chmod 600 .env
```

- [ ] `ENCRYPT_KEY` unbedingt sicher backuppen (im Passwort-Manager). **Verlust = alle Refresh-Tokens sind unbrauchbar, alle User müssen sich neu verbinden.**

## Phase 4 — Config erstellen

```bash
cd /volume1/docker/mailpilot-ai/backend
cp config/config.example.php config/config.php
```

Das `config.php` liest per Default aus `getenv(...)`, d.h. die `.env`-Werte werden durchgereicht, solange du `docker compose` nutzt. Keine Änderung nötig.

## Phase 5 — Stack starten

```bash
cd /volume1/docker/mailpilot-ai/docker

# Erst-Build
docker compose build

# Start
docker compose up -d

# Logs tailen
docker compose logs -f backend worker
```

- [ ] DB wird beim ersten Start automatisch aus `sql/schema.sql` initialisiert
- [ ] Auf `https://mailpilot.s-techsmd.de/api/v1/health` prüfen → `{"ok":true,...}`

## Phase 6 — Smoke-Test

```bash
docker compose exec backend php /app/bin/smoke.php
```

Alle Checks müssen grün sein. Falls Claude-Check fehlschlägt → API-Key prüfen.

## Phase 7 — Add-in sideloaden (deine Outlook Installation)

### Variante A: Einzelner User (Marc)

1. Outlook Desktop öffnen → Datei → Add-Ins verwalten → öffnet Browser
2. Meine Add-Ins → Benutzerdefiniertes Add-In → **Aus Datei**
3. `addin/manifest.xml` auswählen
4. Add-In erscheint im Ribbon → "Triage öffnen" klicken
5. Task Pane erscheint → Button "Mit Microsoft 365 verbinden" → OAuth-Flow durchlaufen
6. Nach Login: Briefing lädt automatisch

### Variante B: Team-Rollout (später)

1. Microsoft 365 Admin Center → Settings → **Integrated apps**
2. Upload custom apps → Office Add-in → `manifest.xml`
3. Auf Nutzer oder Gruppen zuweisen
4. Propagation: 6–12 h

## Phase 8 — Kategorien in Outlook sichtbar machen

MailPilot setzt Kategorien, aber die Farben legt Outlook einmalig fest wenn sie zum ersten Mal genutzt werden. Empfehlung: selbst vorbereiten.

Outlook → Kategorien verwalten → neu anlegen:

| Name | Farbe |
|---|---|
| MP-Direct | Rot |
| MP-Action | Orange |
| MP-CC | Blau |
| MP-Newsletter | Grau |
| MP-Auto | Dunkelgrau |
| MP-Noise | Hellgrau |

## Phase 9 — Monitoring

- [ ] `docker compose logs backend` → sollte alle 30-60s normale Zugriffe zeigen, keine 5xx
- [ ] `docker compose logs worker` → "worker.job_done" nach Syncs
- [ ] Festplattennutzung: `/volume1/docker/mailpilot-ai/var/log` regelmäßig rotieren (logrotate)
- [ ] Claude-Kosten: https://console.anthropic.com/settings/usage → Daily

## Phase 10 — Produktiv-Go

- [ ] 1 Woche im Schatten-Modus fahren (du öffnest Add-in manuell)
- [ ] Briefing morgens checken: sind die Klassifikationen sinnvoll?
- [ ] Bei Fehlklassifikationen: VIP-Liste pflegen, Project-Keywords setzen
- [ ] Kosten-Review nach 1 Woche → extrapolieren auf Monat

---

## Troubleshooting

**Add-in lädt nicht:**
- Browser-DevTools im Task Pane: F12 in Outlook Web, oder bei Desktop: Rechtsklick → "Webseite untersuchen"
- CSP-Fehler? → `manifest.xml` `AppDomains` prüfen

**OAuth-Redirect kommt nicht zurück:**
- Exakte URL-Gleichheit zwischen Azure-Redirect und `MS_REDIRECT_URI` (inkl. trailing slash!)
- `oauth_states` Tabelle anschauen: `SELECT * FROM oauth_states` → wird dort eingefügt?

**Sync läuft nicht:**
- Worker-Logs: `docker compose logs worker`
- Redis-Verbindung: `docker compose exec redis redis-cli ping`
- Mailbox in DB: `SELECT id, email, last_sync_at FROM mailboxes`

**Claude-Aufrufe fehlschlagen:**
- API-Key gültig? → `console.anthropic.com`
- Rate-Limit erreicht? → `docker compose logs backend | grep claude.call`
- Netzwerk vom NAS raus? → `docker compose exec backend curl https://api.anthropic.com`

**Token-Refresh fehlschlägt nach Azure-Secret-Rotation:**
- Neue Secret in `.env` → `docker compose up -d --force-recreate backend worker`
- User müssen sich einmal neu verbinden (refresh_token ist an Client-Credentials gebunden, bleibt aber oft gültig)
