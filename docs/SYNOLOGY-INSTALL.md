# MailPilot AI — Synology Install Guide

Komplette Schritt-für-Schritt-Anleitung für Deployment auf Synology NAS
mit DSM 7.2+ Container Manager. Für Erst-Inbetriebnahme ohne SSH-Zugang.

> **Hinweis:** Diese Anleitung ist auf Synology DSM 7.2+ getestet (DS218+,
> DS220+, DS920+ und höher mit Container Manager Paket). Für andere
> Plattformen (TrueNAS, Unraid, Linux-Server) reicht ein Docker-Engine ≥ 24
> mit Compose v2 — siehe Haupt-[README.md](../README.md).

---

## Voraussetzungen

| Komponente | Version | Hinweis |
|---|---|---|
| DSM | 7.2 oder höher | 7.3.x empfohlen |
| Container Manager | 24.x | DSM-Paket-Zentrum installieren |
| Speicherplatz | ≥ 2 GB frei | Volumes wachsen mit Mail-Volumen |
| Domain mit HTTPS | erforderlich | Outlook Add-ins erlauben **nur** HTTPS |
| Office 365 / Azure-Konto | erforderlich | für Add-in + Graph API |
| Anthropic-API-Key oder AWS Bedrock | erforderlich | für Scoring/Summary |

**Ports:** Standard `19080` (Backend) und `19081` (Admin). DSM Web Station
belegt 8080 — die `190xx`-Defaults vermeiden den Konflikt. Bei Bedarf in
`.env` anpassen.

---

## Architektur — was läuft auf der NAS

Der Stack besteht aus 5 Containern (frisch refactored — keine Init-Container
mehr, alle laufen dauerhaft, DSM zeigt grün):

| Container | Image | Aufgabe |
|---|---|---|
| `mailpilot-db` | `mariadb:11.4` | Persistente Datenhaltung |
| `mailpilot-redis` | `redis:7-alpine` | Cache + Queue |
| `mailpilot-backend` | `ghcr.io/callmetechie/mailpilot-ai-backend:latest` | API + Schema-Init |
| `mailpilot-worker` | `ghcr.io/callmetechie/mailpilot-ai-backend:latest` | Asynchrones Scoring |
| `mailpilot-admin` | `ghcr.io/callmetechie/mailpilot-ai-admin:latest` | Admin-UI |

Der **Backend-Container** übernimmt beim Start die Schema-Migration via
`entrypoint-init.sh` (DB-User-Setup → Migrations → php-fpm). Worker und Admin
warten via `depends_on: backend / service_healthy`, bis Backend grün ist.

---

## Schritt 1: Azure App Registration

1. https://entra.microsoft.com → **App registrations** → **New registration**
2. **Name:** „MailPilot AI" (oder beliebig)
3. **Supported account types:** „Single tenant" (oder Multi je nach Use Case)
4. **Redirect URI** → Plattform „Web" → URL:
   ```
   https://mailpilot.deine-domain.de/api/v1/auth/oauth/callback
   ```
5. Registrierung abschließen → **Application (client) ID** notieren
6. **Certificates & secrets** → **New client secret** → 24 Monate Laufzeit →
   **Wert** (nicht „Secret-ID"!) sofort kopieren — Azure zeigt ihn nur einmal
7. **API permissions** → **Add a permission** → Microsoft Graph → **Delegated
   permissions** → folgende anhaken und „Add permissions":
   - `Mail.Read`
   - `Mail.ReadWrite`
   - `MailboxSettings.Read`
   - `User.Read`
   - `offline_access`
8. **Grant admin consent for <Tenant>** klicken (nur Admin)

---

## Schritt 2: DSM-Ordner und .env vorbereiten

### 2a) Ordner anlegen

**File Station** → in den shared folder `docker` → **Create folder**:
```
/docker/mailpilot-ai/
```

### 2b) `.env` schreiben

In File Station → `/docker/mailpilot-ai/` → **Create → Create file** →
Name: `.env` (mit führendem Punkt!).

Inhalt aus [`docker/.env.example`](../docker/.env.example) kopieren und alle
`REPLACE-ME-…`-Werte ersetzen. Die Pflichtfelder:

| Variable | Wert |
|---|---|
| `JWT_SECRET` | `openssl rand -hex 32` (auf einem Linux/Mac mit OpenSSL ausführen) |
| `ENCRYPT_KEY` | `openssl rand -hex 32` (anderer Wert!) |
| `DB_PASS` | starkes Passwort für den `mailpilot`-DB-User |
| `DB_ROOT_PASS` | starkes Passwort für den DB-Root |
| `APP_BASE_URL` | `https://mailpilot.deine-domain.de` |
| `MS_REDIRECT_URI` | `https://mailpilot.deine-domain.de/api/v1/auth/oauth/callback` |
| `MS_CLIENT_ID` | aus Azure Schritt 1.5 |
| `MS_CLIENT_SECRET` | aus Azure Schritt 1.6 |
| `CLAUDE_API_KEY` | von https://console.anthropic.com |
| `ADMIN_USER` | Login-Name für Admin-UI (z.B. `admin`) |
| `ADMIN_PASS_HASH_B64` | siehe Schritt 2c |

### 2c) Admin-Passwort-Hash erzeugen

**Wichtig:** Auf DSM den base64-codierten Hash verwenden, nicht den raw
bcrypt — sonst frisst Compose das `$`-Zeichen.

Auf einer Linux/Mac-Maschine mit Docker:
```bash
PW='dein-admin-passwort'
HASH=$(docker run --rm php:8.4-cli-alpine \
        php -r "echo password_hash('$PW', PASSWORD_BCRYPT);")
echo -n "$HASH" | base64 -w0
```

Den base64-String (≈ 80 Zeichen, endet meist auf `==` oder `=`) als
`ADMIN_PASS_HASH_B64` in die `.env` einsetzen. `ADMIN_PASS_HASH=` (raw) leer
lassen.

---

## Schritt 3: Compose-Datei hochladen

Aus dem Repo
[`docker/docker-compose.synology.yml`](../docker/docker-compose.synology.yml)
herunterladen und nach `/docker/mailpilot-ai/` kopieren. **Wichtig:** Datei
auf der NAS umbenennen zu **`docker-compose.yml`** (DSM Container Manager
erwartet exakt diesen Namen — keine `.synology.yml`-Variante akzeptiert).

Quick-Check über File Station: `/docker/mailpilot-ai/` enthält jetzt:

```
.env                     ← deine Secrets, niemals committen
docker-compose.yml       ← aus dem Repo, umbenannt
```

Sonst nichts. Backend, Var, Logs etc. werden von Container Manager
angelegt.

---

## Schritt 4: Projekt in Container Manager erstellen

1. **Container Manager** öffnen → Tab **Projekt** → **Erstellen**
2. **Projektname:** `mailpilot-ai` (exakt so, lowercase, Bindestrich)
3. **Pfad:** `/docker/mailpilot-ai` (über Browser auswählen)
4. **Quelle:** **Use existing docker-compose.yml** / „bestehende
   docker-compose.yml verwenden"
5. DSM zeigt Compose-Vorschau → **Weiter**
6. **Web-Portal:** überspringen — Reverse Proxy machen wir separat (Schritt 5)
7. **Fertigstellen** — DSM startet pull + create

**Erwarteter Verlauf** (~30–90 s beim ersten Mal, weil Images gepullt werden):

```
1. db, redis pulled & started
2. db wird "healthy" (~30 s)
3. backend Started → entrypoint-init wartet auf db-root-login
   → ensure_db_user.php → migrate.php (0001 + 0002 schemas)
   → php-fpm startet
4. backend Healthcheck wird grün (~10–20 s nach php-fpm-Start)
5. worker und admin starten parallel
```

Im Endzustand zeigt DSM **alle 5 Container „Running"** und das **Projekt
grün**. Wenn nach 2 Minuten ein Container `Stopped` ist:
[Troubleshooting](#troubleshooting) konsultieren.

---

## Schritt 5: Reverse Proxy einrichten

DSM exponiert die Container intern auf `localhost:19080` und `localhost:19081`.
Damit Outlook (über HTTPS) sie erreichen kann:

**DSM Login Portal** → **Erweitert** → **Reverse Proxy** → **Erstellen**:

| Quelle | Ziel |
|---|---|
| `https://mailpilot.deine-domain.de` (HTTPS, 443) | `http://localhost:19080` |
| `https://admin.mailpilot.deine-domain.de` (HTTPS, 443) | `http://localhost:19081` |

Voraussetzung: Beide Subdomains müssen via DDNS/CNAME auf die NAS zeigen
und ein Let's-Encrypt-Zertifikat haben (DSM **Sicherheit** → **Zertifikat**).

**Custom Header** (optional, empfohlen für CORS / WebSocket):
- `Upgrade: $http_upgrade`
- `Connection: $connection_upgrade`

---

## Schritt 6: Verifikation

Im Browser (oder per `curl` von der NAS aus):

```
https://mailpilot.deine-domain.de/api/v1/ping
→ {"ok":true,"time":"…","version":"0.1.0"}

https://mailpilot.deine-domain.de/api/v1/health
→ {"ok":true,"checks":{"db":true,"redis":true}}

https://admin.mailpilot.deine-domain.de/admin/login
→ HTML-Login-Form (HTTP 200)
```

Login mit `ADMIN_USER` / dem Klartext-Passwort, das du in Schritt 2c
gehasht hast. Dashboard zeigt 0 Tenants, 0 Mailboxes — die werden bei
First-Login eines Outlook-Add-in-Users automatisch angelegt.

---

## Updates

Wenn das Repo eine neue `:latest`-Image-Version hat:

**Container Manager** → Projekt `mailpilot-ai` → **Aktion** → **Build**
(oder „erneut erstellen") → DSM pullt neue Images, recreatet Container.
Volumes bleiben erhalten — DB-Inhalt ist sicher.

Migrations werden vom Backend-Entrypoint automatisch nachgezogen
(idempotent — bereits applied wird übersprungen).

---

## Troubleshooting

### Projekt zeigt „gelb" / Container `Exited (0)`

**Ursache:** Du nutzt eine alte Compose-Variante mit separatem
`migrate`-Service.

**Fix:** [`docker/docker-compose.synology.yml`](../docker/docker-compose.synology.yml)
neu herunterladen — die aktuelle Version hat den `migrate`-Service
abgeschafft, die Migration läuft jetzt im Backend-Entrypoint. Datei nach
`/docker/mailpilot-ai/docker-compose.yml` kopieren, Projekt in DSM
löschen + neu erstellen.

### Backend hängt im Restart-Loop, Logs zeigen `1130: Host '…' is not allowed`

**Ursache:** DB-Volume aus altem Lauf hat nur `localhost`-Grants. Tritt
typisch nach Compose-Project-Rename oder Volume-Adoption aus altem
Setup auf.

**Fix:** Projekt in DSM stoppen → SSH zur NAS:
```bash
sudo docker volume rm mailpilot-ai_mailpilot-db
```
Projekt in DSM neu starten — DB initialisiert frisch mit korrekten
Grants.

### `$DGQMMGip5r9tNUU7…` Variable not set warnings

**Ursache:** Du hast `ADMIN_PASS_HASH=$2y$12$DGQ…` (raw bcrypt) in `.env`.
Compose interpretiert `$DGQ…` als Shell-Variable.

**Fix:** Auf `ADMIN_PASS_HASH_B64=` umstellen (siehe Schritt 2c). Die raw
`ADMIN_PASS_HASH=` Zeile auskommentieren oder leer lassen.

### Admin-Login schlägt fehl, obwohl Hash stimmt

**Ursache:** base64 enthält Whitespace (durch `base64`-CLI ohne `-w0`).

**Fix:** Mit `-w0`-Flag erneut encoden:
```bash
echo -n '$2y$12$…dein-hash…' | base64 -w0
```
Output muss eine einzige lange Zeile ohne Newlines sein.

### DSM zeigt zwei Volume-Sets `mailpilot_…` und `mailpilot-ai_…`

**Ursache:** Das Projekt wurde mal mit anderem Namen angelegt (z.B.
einfach `mailpilot`). Verwaiste Volumes überleben das Löschen des
Projekts.

**Fix:** Per SSH die alten Volumes wegräumen:
```bash
sudo docker volume ls --filter name=mailpilot
sudo docker volume rm <alter-volume-name>
```

### Port 19080 / 19081 schon belegt

**Fix:** In `.env` `BACKEND_PORT=19082` (oder anderen freien Port) setzen,
Reverse Proxy entsprechend anpassen, Projekt in DSM neu builden.

### Container kann nicht zu Anthropic API verbinden

**Ursache:** DSM Firewall blockiert ausgehenden HTTPS, oder Mailpilot-Network
hat keinen Internet-Zugang.

**Fix:** **Systemsteuerung** → **Sicherheit** → **Firewall** → Profil
auswählen → Regel „Outbound HTTPS" erlauben. DSM-Standard-Setup hat
Outbound offen — nur bei custom-Hardening relevant.

### Saubere Re-Installation („tabula rasa")

Per SSH:
```bash
# 1. Projekt in DSM UI stoppen + löschen
# 2. Volumes wegräumen
sudo docker volume rm mailpilot-ai_mailpilot-db mailpilot-ai_mailpilot-redis
# 3. Optional: orphaned Volumes vom alten Project-Namen aufräumen
sudo docker volume ls | grep mailpilot
sudo docker volume rm <alle-orphans>
# 4. Projekt in DSM UI neu erstellen wie in Schritt 4
```

`.env` und `docker-compose.yml` bleiben dabei stehen — nur Daten gehen
verloren.

---

## Backup

Empfehlung: DSM **Hyper Backup** auf den shared folder `docker` (oder
gezielt `/docker/mailpilot-ai/`). Folgende Dateien sind wert-tragend:

| Pfad | Was | Backup-Häufigkeit |
|---|---|---|
| `/docker/mailpilot-ai/.env` | Secrets-Konfiguration | bei Änderung |
| `/docker/mailpilot-ai/docker-compose.yml` | Stack-Definition | bei Änderung |
| Docker-Volume `mailpilot-ai_mailpilot-db` | DB-Daten | täglich |

Volume-Backup über SSH (gemäß Synology-Doku zu `Hyper Backup` für Volumes):
```bash
# Snapshot-Backup nach /volume1/backups/
sudo tar -C /var/lib/docker/volumes/mailpilot-ai_mailpilot-db/_data \
        -czf /volume1/backups/mailpilot-db-$(date +%Y%m%d).tar.gz .
```

Restore: Volume neu erstellen, tar entpacken, Stack neu builden.

---

## Bekannte DSM-Eigenheiten

| Quirk | Auswirkung | Workaround |
|---|---|---|
| `.env`-`$`-Substitution aggressiv | bcrypt-Hash mit `$DGQ…` wird mangled | `ADMIN_PASS_HASH_B64=` nutzen |
| `service_healthy` nicht 100 % zuverlässig | Container starten manchmal vor DB | Apps haben Self-Heal-Mechanismen (`wait_for_db.php`) |
| Container Manager UI sperrt Env-Edit | Env nur per `.env` editieren | siehe oben |
| `Aktion → Bereinigen` lässt Volumes stehen | DB-Daten überleben Project-Delete | per SSH `docker volume rm` |
| Crash-Loops werden ab 5–7 Fehlversuchen abgebrochen | Slow init kann Dependants verhungern | aktuelle Compose hat Healthchecks + lange `start_period` |
| Compose-File-Name muss `docker-compose.yml` sein | DSM verweigert andere Namen | beim Upload umbenennen |
| `mariadb-client` nicht im Backend-Image | Self-Heal-Skripte nutzen kein `mysql`-CLI | `wait_for_db.php` nutzt PDO |
| `/etc/sudoers.d/` ignoriert Files mit `.` im Namen | NOPASSWD-Regel still ignoriert | Dateinamen ohne Punkt: `ma-backes-docker` statt `ma.backes-docker` |
| SSH-SCP-Subsystem auf DSM deaktiviert | `scp file host:path` schlägt fehl | `cat file \| ssh host 'cat > path'` |
| Docker-Binary nicht im non-interactive PATH | `docker` ohne absoluten Pfad findet nichts in SSH-Skripten | `/usr/local/bin/docker` voll qualifizieren |

---

## Weiterführend

- [README.md](../README.md) — Projekt-Übersicht
- [docker/.env.example](../docker/.env.example) — Field-Reference für alle Env-Vars
- [docs/PRD.md](PRD.md) — Produktspezifikation
- [docs/API.md](API.md) — REST-Contract
- [docs/DSGVO.md](DSGVO.md) — Datenschutz-Notes
- [docs/BEDROCK.md](BEDROCK.md) — Bedrock statt Anthropic API für EU-Sovereign
