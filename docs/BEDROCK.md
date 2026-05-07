# BEDROCK.md — EU-Sovereign Setup mit AWS Bedrock

Die Bedrock-Variante leitet Claude-Aufrufe über AWS Bedrock Runtime in der
eu-central-1 Region (Frankfurt). Die Mail-Daten verlassen damit die EU
nicht und der AVV läuft mit AWS statt mit Anthropic direkt.

---

## Wann Bedrock statt direkte Anthropic-API?

| Kriterium | Anthropic direkt | Bedrock eu-central-1 |
|---|---|---|
| Setup-Aufwand | 5 Min (nur API-Key) | 30 Min (AWS-Account, IAM, Model Access) |
| Latenz | niedrig (ca. 300-800ms) | minimal höher (+50-150ms) |
| Kosten | Anthropic-Preise | etwas höher, AWS-Margin |
| EU-Datenresidenz | nein (USA) | **ja** |
| AVV mit | Anthropic | AWS (oft schon vorhanden) |
| Nutzer-Kommunikation | "Transfer in USA" | "Verarbeitung in DE" |

Faustregel: Single-User / Eigen-Einsatz → Anthropic direkt. Team mit echten
Kunden-Mails oder regulierten Branchen (Kanzlei, Medizin, Behörden) → Bedrock.

---

## Schritt 1: AWS Account + Bedrock aktivieren

- [ ] AWS Account anlegen (falls nicht vorhanden)
- [ ] Region oben rechts auf **Europe (Frankfurt) eu-central-1** stellen
- [ ] Service "Amazon Bedrock" öffnen
- [ ] Links → **Model access** → **Manage model access**
- [ ] Claude-Modelle anhaken (Haiku 4.5, Opus 4.7 etc.) → Submit
- [ ] Auf Freischaltung warten (meist Sekunden bis Minuten)

Hinweis: Nur das Haken von Modellen reicht — Bedrock ist pro-request-billed,
kein separater Vertrag nötig.

## Schritt 2: IAM User mit minimalen Rechten

- [ ] IAM → Users → **Create user** → Name z.B. `mailpilot-bedrock`
- [ ] Permissions → Attach policies directly → Create policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "InvokeClaudeModelsEU",
      "Effect": "Allow",
      "Action": "bedrock:InvokeModel",
      "Resource": [
        "arn:aws:bedrock:eu-central-1::foundation-model/anthropic.claude-haiku-4-5-v1:0",
        "arn:aws:bedrock:eu-central-1::foundation-model/anthropic.claude-opus-4-7-v1:0",
        "arn:aws:bedrock:eu-central-1:*:inference-profile/eu.anthropic.claude-haiku-4-5-v1:0",
        "arn:aws:bedrock:eu-central-1:*:inference-profile/eu.anthropic.claude-opus-4-7-v1:0"
      ]
    }
  ]
}
```

- [ ] Policy-Name: `MailPilotBedrockInvoke`
- [ ] User → Policy attachen
- [ ] User → Security credentials → **Create access key** → Use case:
      "Application running outside AWS" → Notieren (nur einmal sichtbar!):
	- `AWS_ACCESS_KEY_ID`
	- `AWS_SECRET_ACCESS_KEY`

## Schritt 3: Cross-Region Inference Profile IDs prüfen

Anthropic-Modelle laufen in EU-Regionen meist über "Inference Profiles" mit
Prefix `eu.`. Die IDs stehen in der Bedrock Console unter **Inference profiles**.

Die in `config.example.php` hinterlegten IDs:
```
eu.anthropic.claude-haiku-4-5-v1:0
eu.anthropic.claude-opus-4-7-v1:0
```

Falls AWS die IDs ändert, hier und in `config.php` anpassen:
`claude.bedrock.model_map`.

## Schritt 4: MailPilot auf Bedrock umstellen

`.env` im Docker-Ordner:

```env
# Von Anthropic direkt wegschalten
CLAUDE_PROVIDER=bedrock

# Bedrock-Credentials
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=...
AWS_REGION=eu-central-1

# Anthropic-Key kann leer bleiben bei provider=bedrock
CLAUDE_API_KEY=
```

Stack neu starten:

```bash
cd docker/
docker compose up -d --force-recreate backend worker
docker compose logs -f backend | grep claude.bedrock
```

## Schritt 5: Smoke-Test

```bash
docker compose exec backend php /app/bin/smoke.php
```

Beim Claude-Test sollte in den Logs `claude.bedrock.call` auftauchen, nicht
`claude.anthropic.call`.

## Umschalten zwischen Providern

Jederzeit möglich per `CLAUDE_PROVIDER` env. Der Cache ist provider-agnostisch
(gleicher Prompt + gleiches Modell = gleicher Hash), d.h. ein Wechsel erzeugt
keine unnötigen Recalls — nur neue Requests gehen an den neuen Provider.

Falls du parallel testen willst: zwei Tenants mit unterschiedlicher Config
betreiben geht (noch) nicht mit dieser Kernel-Architektur — Provider ist
global. Für späteren Team-Rollout mit gemischten Präferenzen müsste der
Provider pro-Tenant aus der DB gelesen werden (siehe Roadmap).

## Troubleshooting

**"AccessDeniedException" beim ersten Call**
- Model Access noch nicht approved → Bedrock Console → Model access prüfen
- IAM-Policy fehlt oder falsche Region im ARN

**"ValidationException: The model ID ... is not supported"**
- Inference Profile ID falsch. Bedrock Console → Inference profiles →
  die aktuelle ID kopieren, in `config.bedrock.model_map` einfügen

**SigV4-Fehler ("The request signature we calculated does not match")**
- System-Zeit auf dem NAS prüfen: `docker compose exec backend date` —
  muss auf ±5 Min genau zu UTC sein
- Secret Access Key korrekt eingefügt (keine Whitespaces am Rand)

**Höhere Latenz als erwartet**
- Normal bei Bedrock, +50-200ms gegenüber Anthropic direkt. Bei >2s:
  NAS-Internet-Uplink prüfen, AWS-Region-Wahl
