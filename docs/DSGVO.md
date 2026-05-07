# DSGVO / Datenschutz-Notizen

> Disclaimer: Das hier ist keine Rechtsberatung. Vor Produktiveinsatz im Team
> oder mit Kundendaten unbedingt mit Datenschutzbeauftragtem / Anwalt prüfen.

## 1. Datenflüsse

### Variante A: Anthropic direkt (provider = `anthropic`)

```
M365 Postfach  ──(Graph API)──►  MailPilot Backend (DE, Synology NAS)
                                        │
                                        ├─► MariaDB (DE) — Scores, Cache 30d, Bodies 7d
                                        │
                                        └─► Claude API (Anthropic, USA)
```

**Transfer-Rechtsgrundlage nötig** für den USA-Export der Mail-Inhalte:
- Standardvertragsklauseln (SCC) im AVV mit Anthropic
- TIA (Transfer Impact Assessment) dokumentieren
- Nutzer-Information in der Datenschutzerklärung

### Variante B: AWS Bedrock EU-Region (provider = `bedrock`, region = `eu-central-1`)

```
M365 Postfach  ──(Graph API)──►  MailPilot Backend (DE, Synology NAS)
                                        │
                                        ├─► MariaDB (DE) — wie oben
                                        │
                                        └─► AWS Bedrock (Frankfurt, eu-central-1)
                                             Anthropic Claude Modelle im
                                             AWS-Rechenzentrum in der EU
```

**Vorteile:** Daten verlassen die EU nicht. AVV mit AWS statt Anthropic.
Bedrock bietet keine Nutzung der Eingaben für Training und speichert
Prompts/Completions standardmäßig nicht.

**Zu beachten:**
- AWS Bedrock ist ein AWS-Dienst → AVV mit AWS erforderlich (Standard-DPA von AWS)
- AWS CLOUD Act: AWS kann theoretisch von US-Behörden zu Datenherausgabe
  gezwungen werden, auch für EU-Daten. Das ist der einzige DSGVO-Rest-Risiko.
  Mitigation: Sensible Daten redaktionieren (machen wir ohnehin).
- Model Access muss in der Bedrock Console explizit angefordert werden

## 2. Was geht an Claude

**Pro Mail beim Scoring (Haiku):**
- Absender-Adresse + Name
- Empfänger/CC-Adressen
- Subject
- Erste 2 KB des Plaintext-Bodys (nach Redaktion)
- Header-Flags (reply, attachment, list-unsubscribe)
- Zeitstempel

**Beim Summary/Reply (Opus):**
- Vollständiger Plaintext-Body (nach Redaktion)
- Bei Reply: letzte 3 Nachrichten des Threads

## 3. Was NICHT an Claude geht

- Keine Anhänge (MVP)
- Keine HTML-Quelle (nur extrahierter Text)
- Keine Bilder
- Nichts, was durch Redaktions-Regeln matcht (IBAN, CC-Nummern, custom regex)
- Keine internen Customer-IDs falls in Redaktion konfiguriert

## 4. Redaktion (verpflichtend)

Vor jedem API-Call wendet der Backend `RedactionService` diese Patterns an:

| Pattern | Ersetzung |
|---------|-----------|
| `DE\d{2}[ \d]{18,}` (IBAN) | `[IBAN-REDACTED]` |
| `\b\d{4}[ -]?\d{4}[ -]?\d{4}[ -]?\d{4}\b` (Kreditkarte) | `[CC-REDACTED]` |
| `\b\d{3}-\d{2}-\d{4}\b` (SSN-like) | `[PII-REDACTED]` |
| User-spezifisch aus `redaction_rules` | `[REDACTED]` |

## 5. Rechtsgrundlage

Für den eigenen Einsatz: Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse).
Für Team/Kundeneinsatz:
- AV-Vertrag mit Anthropic (verfügbar über commercial agreement)
- TIA (Transfer Impact Assessment) für USA-Transfer wenn Anthropic API direkt
- Empfehlung: Bedrock eu-central-1 nutzen, dann bleibt Transfer innerhalb EU

## 6. Retention

| Daten | Aufbewahrung | Grund |
|-------|--------------|-------|
| `mails.body_text` | 7 Tage | Zum Retry bei fehlgeschlagenem Scoring; danach purge |
| `mail_scores` | 30 Tage | Briefing-Historie |
| `claude_cache` | 30 Tage | Kosten sparen, kein Mail-Body, nur Hash + Ergebnis |
| `audit_log` | 90 Tage | Sicherheit, Debug |
| User account | bis Löschung | DSGVO Art. 17 Recht auf Löschung |

## 7. Nutzerrechte (umgesetzt im Admin-UI)

- **Auskunft (Art. 15):** Endpoint `/api/v1/me/export` → JSON-Dump aller User-bezogenen Daten
- **Löschung (Art. 17):** Endpoint `/api/v1/me/delete` → Soft-Delete sofort, Hard-Delete nach 30 Tagen
- **Widerruf:** User kann Mailbox jederzeit entkoppeln → OAuth-Token revoke + Daten-Purge
- **Einschränkung:** Pausieren des Sync ohne Datenlöschung

## 8. Technische Maßnahmen

- TLS 1.3 für alle Verbindungen
- JWT mit 1h Ablauf, Refresh-Token rotating
- Claude API Key nur in `config.php` (chmod 600, nicht in git)
- MariaDB mit TLS zwischen Containern
- Logs redacted (nie Body, nie PII)
- Backups verschlüsselt (LUKS auf NAS)

## 9. AVV mit Anthropic

Muss vor Team-Rollout abgeschlossen sein. Zu klären:
- Subprozessoren-Liste
- Zweckbindung (keine Nutzung zum Training)
- Löschfristen
- Auditrechte
