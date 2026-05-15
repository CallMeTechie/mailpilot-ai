# PRD — Phase 6: Der KI-Butler

**Status:** Draft v3 (devil's-advocate v2-reviewed), 2026-05-13
**Owner:** CallMeTechie
**Inputs:** Brainstorming-Session 2026-05-13 nach Phase-5-Deploy; zwei Devil's-Advocate-Reviews
**Changes vs v2:** Mini-Call wird gebatched + bekommt deterministischen Fallback + USER_IDENTITY (Finding 1); DSGVO-Coverage-Test mechanisch statt textlich (Finding 2); Prompt-Cache nutzt 1h-Extended-TTL statt 5min-Ephemeral, Cost-Tabelle ehrlich (Finding 3); Mail-Flow bei pending Topics spezifiziert (Finding 4); Folder-Reconciliation prüft auch Pfad-Hierarchie (Watch-For)

---

## 1. Vision

> MailPilot ist ein KI-Butler, der ein volles, unsortiertes Postfach aufnimmt und es selbstständig in Form bringt. Alte und neue Mails werden automatisch klassifiziert, in passende (notfalls neu angelegte) Ordner verschoben, mit tl;dr versehen, und alles was die Aufmerksamkeit des Users braucht landet auf einer eigenen „MailPilot Heute"-Seite — inklusive Antwort-Vorschlag oder TODO.

Die KI ist **proaktiv**, der User ist **korrigierend**. Phase 5 hatte das Verhältnis umgekehrt (User konfiguriert, KI führt aus) — das war ein Designfehler.

---

## 2. Drei-Lagen-Identitäts-Modell

Damit „Action erforderlich" nicht Würfelei wird, muss die KI wissen, **wer der User ist**, auf drei Ebenen:

```
Lage 1 — Hart (deterministisch)
  Email-Adresse aus OAuth + M365-Object-Id
  Display-Name, Vorname, Nachname aus Microsoft-Profil

Lage 2 — Weich (lernbar)
  Anrede-Aliase: Vornamen-Varianten, Nachname, Spitznamen, Kurzformen
  Geschlechts-Toleranz: "Herr X" und "Frau X" matchen denselben Alias
  Aliase werden initial aus den letzten 200 eingehenden Mails extrahiert,
  vom User in Settings bestätigt/abgelehnt, danach passiv erweitert
  bei jeder neuen Anrede-Variante

Lage 3 — Kontext (KI-Reasoning, pro Mail)
  Wer steht im To / CC / BCC
  Wer wird im Body wie angesprochen
  In welchem Tonfall, an wen gerichtet ist die Bitte
  → action_owner: 'user' | 'other' | 'group' | 'unsure'
  → action_owner_confidence: 0-100
```

`action_owner` wird zum primären Filter für das Dashboard. `action_required` allein reicht nicht — ohne Owner-Information landen Mails an Gruppen oder an Dritte fälschlicherweise auf der Heute-Liste.

### 2.1 Empfänger-Disambiguierung

Lage 2 allein löst „mehrere Marcs im To-Feld" nicht. Daher bekommt Claude bei jedem Score-Call **alle Empfänger der konkreten Mail** mit Email-Adresse plus Display-Name, nicht nur den User-Status:

```
RECIPIENTS:
- marc@callmetechie.de  (USER)
- marc.schmidt@kunde.de
- klaus@kunde.de

USER_ALIASES: Marc, Marcus, Backes, MB
```

Prompt-Anweisung:
- Wenn die Anrede im Body eindeutig auf USER zeigt (Name-Match mit Alias, kein anderer Empfänger mit gleichem Vornamen) → `action_owner=user`
- Wenn die Anrede auf einen anderen Empfänger zeigt → `action_owner=other`
- Wenn ambig (zwei Empfänger mit Namen „Marc") → **immer `action_owner=unsure`**, niemals raten
- Wenn die Mail an einen Verteiler oder die Anrede unpersönlich ist → `action_owner=group`

---

## 3. Modi — drei Toggles, jeweils drei Stufen

Statt einem binären Schalter (autonom/Vorschlag) gibt es drei separate Toggles, weil die Konsequenzen der drei Auto-Aktionen sehr unterschiedlich sind.

| Toggle | Was die KI macht | aus | vorschlag | auto |
|---|---|---|---|---|
| `auto_move` | Mail in einen **existierenden** Topic-Ordner verschieben | KI klassifiziert, verschiebt aber nichts. Du sortierst manuell. | Move landet im Genehmigungs-Tab. Du bestätigst. | Move passiert sofort. Du korrigierst nachträglich. |
| `auto_create_topic` | Neuen Topic vorschlagen + neuen Outlook-Ordner anlegen | KI bleibt im Catch-all. Nie neue Ordner. | Topic-Vorschlag im Tab. Du bestätigst, Ordner wird angelegt. | KI erstellt direkt. Du benennst nachträglich um. |
| `auto_reply` | Antwort-Entwurf für action_owner=user, prio≥4 erzeugen | Nur on-demand-Reply (Klick auf Mail). | Draft im Add-in sichtbar, nicht in Outlook. | Draft wird als Outlook-Draft an die Mail gehängt. |

**Defaults für neue User:** alle drei auf `vorschlag`. Power-User schaltet einzelne Toggles auf `auto`, wenn er sich an das Verhalten gewöhnt hat.

Beide Vorschlag-Stufen feeden denselben Genehmigungs-Tab — User sieht eine zusammengefasste Liste „16 Moves, 3 neue Topics, 4 Antwort-Entwürfe".

### 3.1 Mail-Flow bei pending Topics (neu in v3)

**Konstellation:** `auto_move=auto`, `auto_create_topic=suggest`. KI klassifiziert eine GitHub-Mail mit `sub_label='GitHub CI'`, aber dieser Topic existiert noch nicht als bestätigte Rule.

**Spezifikation:**

1. **Mail bleibt physisch im Catch-all** (`MailPilot/Auto`) oder Inbox — kein silent retroactive move.
2. Worker legt **zwei** Einträge in `pending_actions` an:
   - `kind='create_topic'` mit dem Topic-Vorschlag (Name + vorgeschlagener Folder-Pfad)
   - `kind='move_to_pending_topic'` mit der Mail-Referenz; Status `waiting_for_topic`, gekoppelt an die `create_topic`-Action via `payload.parent_pending_id`
3. Genehmigungs-Tab zeigt: *„Neuer Topic 'GitHub CI' vorgeschlagen — 12 Mails würden hier landen."* (Anzahl der gekoppelten move_to_pending_topic-Einträge)
4. **User-Approve des Topics** triggert eine **explizite Bulk-Confirm-Frage:** *„Topic 'GitHub CI' anlegen und 12 Mails dorthin verschieben?"*
   - Approve → `create_topic` wird ausgeführt, dann alle gekoppelten Moves in einem Batch
   - Topic-Only → Topic wird angelegt, Moves bleiben pending (User entscheidet pro Mail)
   - Reject → Topic-Vorschlag verworfen, gekoppelte Moves werden als `rejected` markiert; Mails bleiben im Catch-all
5. **Auto-Move für DIE Mail wartet** auf User-Entscheidung — auch wenn `auto_move=auto`. Begründung: der Ziel-Ordner existiert noch nicht; ein silent move auf einen approved Topic wäre Out-of-Order.

Akzeptanzkriterium in Sprint 6c: dieser Flow ist Pflicht. Test pinnt: „Topic-pending → Mail nicht physisch verschoben → User-Approve → Bulk-Move-Bestätigung".

---

## 4. Lern-Loop

Drei Korrektur-Signale, alle drei lokal protokolliert (DSGVO-konform, siehe §10):

1. **Score-Korrektur** (existiert seit Phase 3e). User markiert „falsch klassifiziert" → fließt als Few-Shot in den nächsten Score-Call.
2. **Move-Korrektur** (neu in 6d). Wenn der User eine auto-sortierte Mail in einen anderen Ordner verschiebt, erkennt der Worker das beim nächsten Sync. Wird als `auto_sort_correction` geloggt.
3. **Owner-Korrektur** (neu in 6a). User markiert eine Mail als „nicht meine Action / Gruppe / unklar". Fließt in den Score-Prompt zurück.

Bei einer signifikanten Move-Korrektur (mehrere Mails in Reihe gleicher Art) fragt MailPilot **proaktiv im Add-in** nach dem Grund.

**Single-Correction-Schutz:** Eine einzelne Korrektur ändert noch nichts an der Heuristik. Mindestens 3 gleichartige Korrekturen innerhalb von 30 Tagen werden zu einer Verhaltensänderung kondensiert. Ältere Korrekturen verfallen aus dem Few-Shot-Pool nach 90 Tagen, sonst wächst das Prompt unendlich.

---

## 5. Cache- und Token-Strategie

### 5.1 `action_owner` ist nicht cacheable — Mini-Call gebatched mit Fallback

`claude_cache` ist nach `(content_hash, prompt_version)` indiziert. `action_owner` hängt vom **Empfänger-Kontext der konkreten Mail** ab, nicht vom Content-Hash. Newsletter-Vorlagen mit identischem Body in verschiedenen Empfänger-Listen würden sonst den `action_owner` der ersten Mail an die zweite vererben.

**Implementation:**

- `claude_cache` speichert weiterhin: `label`, `sub_label`, `priority`, `action_required`, `summary`, `reasoning`. Diese hängen nur vom Mail-Inhalt ab.
- `action_owner` und `action_owner_confidence` werden **pro Score-Batch** in einem einzigen **batched Mini-Call** an Claude Haiku berechnet:
  - Input: USER_IDENTITY-Block (Aliase, Disambiguierungs-Regeln aus §2.1) + Liste aller Cache-Hit-Mails mit (Recipients, cached_label, body_anrede_snippets ~200 Zeichen)
  - Output: JSON-Array `[{mail_id, action_owner, confidence}, ...]`
  - Token-Aufwand: ~400 Input + 50 Token pro Mail im Batch + ~10 Output pro Mail
  - **Profitiert vom selben Prompt-Caching** wie der reguläre Score-Call (USER_IDENTITY ist im selben gecachten Segment)
- Mails ohne Cache-Hit bekommen `action_owner` direkt aus dem regulären Score-Call (im selben Token-Budget enthalten, Claude liefert beide Felder).

**Deterministischer Fallback** bei Mini-Call-Failure (timeout, 429, 5xx, ungültiges JSON, partielles Result):
1. **Stufe 1:** User im To-Feld und keine anderen Recipients mit Vornamen, der einen User-Alias matched → `action_owner='user'`, `confidence=40` (deterministisch, niedrige Confidence)
2. **Stufe 2:** User in BCC oder Mail an Verteiler-Adresse (group@, info@, no-reply@) → `action_owner='group'`, `confidence=60`
3. **Stufe 3:** Alle anderen Fälle → `action_owner='unsure'`, `confidence=0`

Fallback ist deterministisch und kostet keine Claude-Calls. Wird in `mail_scores.action_owner_source` getrackt (`'ki'` | `'fallback'`) damit Observability klar ist.

**Schema-Konsequenz:** `mail_scores.action_owner` ist eine **Post-Cache**-Spalte. `claude_cache.response_json` enthält die Felder nicht (Test pinnt das).

### 5.2 Anthropic Prompt-Caching mit 1h-Extended-TTL

Phase 6 fügt dem Score-Prompt drei wachsende statische Blöcke hinzu:

| Block | Geschätzte Token | Änderungshäufigkeit |
|---|---|---|
| System-Prompt | ~250 | Statisch |
| `USER_IDENTITY` (Email + Aliase + Salutation-Regeln) | 150-300 | Selten (User-CRUD) |
| `USER_SUBLABELS` mit Description | 200-1500 | Bei Sub-Label-CRUD |
| `USER_TOPICS` (KI-vorgeschlagen, wächst) | 0-2000 | Bei Topic-Anlage durch KI |
| `USER_CORRECTIONS` (Few-Shot) | 0-1000 | Bei jeder Korrektur |

Anthropic bietet **zwei Cache-TTL-Varianten:**

| Variante | TTL | cache_creation-Faktor | Passt zu MailPilot? |
|---|---|---|---|
| Ephemeral | 5 Min | 1.25× | **Nein.** Worker-Syncs liegen typisch 10-30 Min auseinander → Cache läuft IMMER ab → Caching kostet 25% mehr statt zu sparen. |
| Extended | 1 Stunde | 2× | **Ja.** Über den Tag verteilt 10-30 Syncs → erster Sync zahlt 2× cache_creation, alle Folgenden lesen mit 10% (cache_read) → echte 60-80% Ersparnis. |

**Entscheidung:** Extended-TTL (1h) ist Default für alle gecachten Segmente. Die 2× cache_creation-Kosten amortisieren sich ab dem zweiten Cache-Read innerhalb einer Stunde — was bei typischer Sync-Frequenz immer der Fall ist.

**Gecachte Segmente (in dieser Reihenfolge im API-Call, mit `cache_control: {type: 'ephemeral', ttl: '1h'}` markiert):**
1. System-Prompt + USER_IDENTITY (extrem stabil, ändert sich nur bei User-CRUD)
2. USER_SUBLABELS + USER_TOPICS (ändert sich bei CRUD oder Topic-Discovery, dann Cache-Invalidierung über das nachfolgende Segment)

USER_CORRECTIONS bleibt **ungekacht** (ändert sich nach jeder Korrektur).

### 5.3 Cost-Modell mit ehrlichen Cache-Annahmen

Berechnung: Haiku 4.5 ($0.80/M input, Cached-Read $0.08/M, Extended-Cache-Creation $1.60/M).

**Annahme:** 1h-Extended-TTL, Worker läuft 10 Syncs/Tag, im Schnitt 1 Sync pro Stunde tagsüber.

| Szenario | Calls/Tag | Token-Verteilung pro Tag | Effektive Kosten/Monat |
|---|---|---|---|
| Marc, 100 Mails/Tag, 5 Score-Batches in 5 Syncs | 5 Score + 4 Mini-Batches (1 ohne Cache-Hit) | 5× cache_creation (2500T) + 4× cache_read (2500T) + 5× fresh (700T) + 4× mini-fresh (1000T) | ~$0.30 |
| Power-User, 500 Mails/Tag, 25 Score-Batches in 10 Syncs | 25 Score + 20 Mini-Batches | dito, skaliert | ~$1.50 |
| 100-User × Marc-Volumen, tenant-shared Cache nicht aktiv | dito × 100 | dito × 100 | ~$30 |

**Cache-Hit-Ratio bei 1h-TTL:** ~70-80% der Score-Calls sind cache_reads (nach dem ersten des Tages innerhalb der Stunde). Test in Sprint 6a misst das nach 24h Betrieb.

Ohne Caching (oder mit Ephemeral-TTL bei 30+ Min Sync-Pausen): Power-User-Spalte 3-5× höher (~$5-8/Monat). Mit 1h-Extended ist Caching **eine echte Ersparnis**, kein Marketing-Theater.

**Wichtig:** `BudgetService` muss `cache_read_input_tokens` und `cache_creation_input_tokens` getrennt erfassen — Anthropic-API liefert sie separat in `response.usage`. Sonst sieht der Budget-Report falsche Zahlen.

### 5.4 Cache-Invalidierung bei Pool-Änderung

Bei User-CRUD auf Sub-Labels/Topics wird `claude_cache` für den Tenant atomar gewischt (Sprint-0-Bugfix). Anthropic Prompt-Cache invalidiert sich automatisch durch den geänderten Segment-Inhalt — beim nächsten Call hat das Segment einen anderen Hash, cache_read greift nicht, neuer cache_creation.

Bei KI-getriggerter Topic-Anlage (in 6b) wird **nicht** der ganze `claude_cache` gewischt — die Anlage ist Teil der Klassifikation, die nächste Score-Iteration sieht die neue Topic-Liste, das ist OK. Nur das USER_TOPICS-Cache-Segment bei Anthropic invalidiert sich automatisch.

---

## 6. Sprint-Roadmap (linear, mit Akzeptanzkriterien pro Sprint)

| Sprint | Was | Größe | Akzeptanzkriterien |
|---|---|---|---|
| **0** | Bug-Fixes aus Phase 5 | 1 Tag | • X-Klick funktioniert (Custom-Modal statt confirm()) <br>• Cache-Wisch bei Sub-Label-CRUD <br>• Description fließt in Score-Prompt <br>• „Neu klassifizieren"-Button |
| **6a — Identity & Action-Owner** | User-Profile mit Aliasen, Alias-Vorschlag aus Postfach-Scan, USER_IDENTITY-Block im Prompt, `action_owner` als post-cache Score-Feld, Settings-UI | 4-5 Tage (von 3-4 hoch) | • Anrede-Aliase pflegbar, Initial-Scan funktioniert <br>• Empfänger-Disambiguierung bei mehreren gleichnamigen Recipients → `unsure` <br>• `action_owner` wird NICHT im claude_cache gespeichert (Test pinnt das) <br>• **Mini-Call ist gebatched pro Score-Batch** (1 Call statt N Calls) <br>• **Deterministischer Fallback bei Mini-Call-Failure** mit `action_owner_source='fallback'` <br>• **Mini-Call enthält USER_IDENTITY-Segment und profitiert vom Prompt-Cache** <br>• **Anthropic Prompt-Caching mit 1h-Extended-TTL** aktiv für System-Prompt + USER_IDENTITY + USER_SUBLABELS <br>• Cache-Read-Ratio-Test misst nach 24h Betrieb ≥ 70% <br>• `BudgetService` erfasst `cache_read_input_tokens` und `cache_creation_input_tokens` getrennt <br>• **DSGVO-Coverage-Test:** `MeControllerTest::testEveryUserScopedTableIsInExport` — scannt `INFORMATION_SCHEMA.COLUMNS` nach allen Tabellen mit `user_id`-Spalte, prüft gegen Export-Mapping. Analog für `/me/delete`. <br>• `users.aliases` in Export/Delete inkludiert <br>• Nachzieher: `mail_score_corrections` (Phase 3e!) wird in Export/Delete inkludiert |
| **6b — Autonome Topic-Discovery** | KI darf neue Sub-Label-Namen vorschlagen, Backend legt Outlook-Ordner an (nur wenn Modus = auto), alte Sub-Label-UI wird Korrektur-Schicht | 2-3 Tage | • Claude darf neue Topics vorschlagen + automatisch ablegen <br>• `auto_sort_rules.created_by='ki'` für KI-Anlagen <br>• Topic-Fuzzy-Merge-Check (Levenshtein) bei Vorschlag schon im Backend <br>• `USER_TOPICS` als eigenes Cache-Segment mit Extended-TTL <br>• Topic-Anlage von KI invalidiert das USER_TOPICS-Segment automatisch über Inhalt-Hash, kein expliziter Wisch nötig |
| **6c — Modus-Schalter (granular) + Pending-Mail-Flow** | Drei Toggles × drei Stufen in Settings, Pending-Action-Queue mit Bounded-Behavior, Genehmigungs-Tab, **Pending-Topic-Mail-Flow** | 2-3 Tage (von 2 hoch) | • Drei separate Toggles, Stufen aus/vorschlag/auto <br>• Pending-Tab zeigt Moves + Topic-Anlagen + Reply-Drafts in einer Liste <br>• Batch-Approval pro Kategorie <br>• Age-Out: Pending älter 30 Tage → auto-approve im Auto-Modus, Notification im Vorschlag-Modus <br>• Max-Pending-Banner bei 100+ Einträgen <br>• **Mail-Flow bei pending Topics (§3.1):** Mail bleibt im Catch-all, gekoppelte `pending_actions(kind='move_to_pending_topic')` <br>• **Topic-Approval triggert explizite Bulk-Move-Bestätigung** mit Mail-Anzahl <br>• Test pinnt Out-of-Order-Schutz: kein silent retroactive move |
| **6d — Move-Lern-Loop + Folder-Reconciliation** | Sync detektiert User-Moves, `auto_sort_corrections`-Tabelle, Reason-Capture-Banner, Folder-Rename-Detection inkl. Pfad-Hierarchie | 3 Tage | • Worker erkennt Moves zwischen Outlook-Ordnern und loggt sie <br>• Periodischer Job vergleicht `auto_sort_rules.folder_name` **UND** `parent_folder_id` mit Graph `displayName` + `parentFolderId` <br>• Bei reinem displayName-Drift: folder_name aktualisieren <br>• Bei parentFolderId-Drift (Hierarchie-Move): vollen Pfad neu auflösen, `folder_name` mit dem neuen Pfad aktualisieren <br>• User-Customization gewinnt immer — unsere Spalten folgen Outlook <br>• Single-Correction triggert keine Verhaltensänderung; Schwellwert 3 gleichartige in 30 Tagen <br>• `auto_sort_corrections` in /me/export und /me/delete <br>• 90-Tage-Retention für Korrekturen <br>• DSGVO-Coverage-Test deckt neue Tabellen automatisch ab (aus 6a-Test) |
| **6e — „MailPilot Heute"-Dashboard** | Neuer Tab mit drei Sektionen: Wichtige (`action_owner=user`), Unklare (`action_owner=unsure`), Erledigt | 3-4 Tage | • Cards mit Subject, From, tl;dr (Haiku-Summary), Action-Buttons <br>• Unklare-Sektion erlaubt User, action_owner direkt zu korrigieren <br>• Filter „heute / Woche / alles" |
| **6f — Auto-Reply-Drafts** | Hintergrund-Job für `action_owner=user AND priority >= 4`: Opus erzeugt Reply-Entwurf | 2-3 Tage | • Stale-Detection: wenn neue Mail im Thread eintrifft nach Draft-Erzeugung → Draft als „veraltet" markieren <br>• Cost-Cap: max N Drafts pro Tag (configurable in System-Settings) <br>• Drafts in /me/export und /me/delete |
| **6g — Auto-Rule-Inference aus Korrektur-Begründungen** | Bei Klassifikations-Korrektur extrahiert die KI aus dem `reasoning`-Freitext eine AutoSort-Regel und wendet sie sofort an (≥80% Konfidenz) oder schlägt sie im Pending-Tab vor | 2-3 Tage (hoch von 1-2 nach DA-Runde 2) | • Neuer Prompt `P-RULE-EXTRACT@1.0` in `prompt_versions` (admin-editable), Haiku-Call mit Mail-Kontext (subject/from/sub_label) + reasoning → JSON `{create_rule, label, sub_label, folder_name, match_signals, confidence}` <br>• **DA-R2 Finding 1 (High) — Echte Redaction:** Neuer dedicated `RedactionService::redactReasoning()` mit eigenem Patternset (IBAN, CC, plus konfigurierbare Namensliste in `system_settings.reasoning_pii_names`). `from`-Feld wird auf Domain reduziert. Bestehendes `redactMail` whitelistet nur subject/body — verifiziert. Tests verifizieren echte Redaction-Wirkung. <br>• **DA-R1 Finding 2 (Critical) — Backfill-Schutz:** Bei `confidence≥80` + `autosort_move_mode=auto` + Range ≠ `all` + matches ≤ Cap: Regel anlegen + Backfill via `AutoSortService::move`. ABER: bei `range=all` ODER matches > `rule_inference_backfill_max` (Default 100): IMMER Pending erzwingen. <br>• **DA-R2 Finding 3 (Medium) — Single Bulk-Pending:** Bei Force-Pending **eine** `pending_actions(kind='rule_suggestion')` mit `payload.affected_mail_ids[]` Array — nicht N Einzel-Items. Approval = ein Klick → Bulk-Move. <br>• **Backfill-Range als User-Setting** `rule_inference_backfill_range` (`future_only` / `last_30_days` / `all`, Default `last_30_days`) — UI im Auto-Sort-Tab <br>• **DA-R1 Finding 3 (High) — Sub-Label-Fuzzy-Merge:** Vor `AutoSortRepository::upsert` Levenshtein-Check (≤2 ODER normalized lowercase match) gegen bestehende sub_labels desselben Labels. Verifiziert: Levenshtein-Helper aus `MailScoringService:973` ist nutzbar. <br>• **DA-R2 Finding 2 (High) — Quota-Counter-Infrastruktur neu bauen:** `autoreply_max_per_day` aus 0015 ist nur Seed-Row, kein Service. 6g baut die Infrastruktur **erstmals**: Tabelle `usage_counters(tenant_id, user_id, kind, date, count, PRIMARY KEY)` + `UsageCounterRepository::incrementOrFail($kind, $cap)` mit atomic UPSERT. Wird von 6f mitbenutzt. <br>• **DA-R1 Finding 4 (Medium) — Idempotenz:** Hash auf `sha256(mail_id + reasoning)` in `mail_score_corrections.rule_inference_hash`. Doppel-Submit → 409 Conflict mit Verweis auf existierendes Pending / angelegte Regel, kein silent skip. <br>• Cost-Cap `rule_inference_max_per_user_per_day` (Default 30) in `system_settings`, enforced via UsageCounterRepository <br>• `rule_suggestion` als neuer Wert in `pending_actions.kind`-ENUM, in /me/export + /me/delete inkludiert <br>• `usage_counters` in /me/export + /me/delete inkludiert <br>• DSGVO-Coverage-Test deckt neue Tabellen + ENUM-Werte mechanisch ab |

**Gesamt:** ~18-25 Arbeitstage (leicht hoch ggü v2: +1 Tag in 6a für Batching + Fallback + DSGVO-Test, +0-1 Tag in 6c für Pending-Mail-Flow).

---

## 7. Daten-Modell — Erweiterungen

**`users`** (oder neu `user_profile`):
- `aliases JSON DEFAULT NULL` — Liste der Anrede-Varianten
- `privacy_acknowledged_at DATETIME(3) NULL` — DSGVO-Disclaimer-Akzept (siehe §10)

**`mail_scores`** (Migration 0010):
- `action_owner ENUM('user','other','group','unsure') DEFAULT 'unsure' AFTER action_required`
- `action_owner_confidence TINYINT UNSIGNED DEFAULT NULL` (0-100)
- `action_owner_source ENUM('ki','fallback') DEFAULT NULL` — Observability für Fallback-Hits
- **WICHTIG:** Diese drei Felder werden **nicht** im `claude_cache` gespeichert. Test in `CacheRepositoryTest::testActionOwnerFieldsAreNotCached` pinnt das.

**`auto_sort_rules`**:
- `folder_id` ist bereits da (5c) — keine Schema-Änderung
- `created_by ENUM('user','ki') DEFAULT 'user' AFTER folder_name` — markiert KI-vorgeschlagene Regeln
- `parent_folder_id VARCHAR(255) NULL AFTER folder_id` — für Folder-Hierarchie-Move-Detection (§9)
- `last_reconciled_at DATETIME(3) NULL` — wann der Reconciliation-Job zuletzt diese Row geprüft hat

**Neu — `auto_sort_corrections`** (Migration 0011):
```sql
CREATE TABLE auto_sort_corrections (
    id CHAR(36) PRIMARY KEY,
    tenant_id CHAR(36),
    user_id CHAR(36),
    mail_id CHAR(36),
    original_folder_path VARCHAR(255),
    corrected_folder_path VARCHAR(255),
    original_sub_label VARCHAR(50) NULL,
    suggested_sub_label VARCHAR(50) NULL,
    user_reason VARCHAR(500) NULL,
    created_at DATETIME(3) NOT NULL,
    deleted_at DATETIME(3) NULL,
    INDEX idx_tenant_user_created (tenant_id, user_id, created_at)
);
```
- Soft-Delete via `deleted_at` (für DSGVO `/me/delete`)
- 90-Tage-Retention: nightly purge von Rows mit `created_at < NOW() - 90 days`

**`system_settings`** (Migration 0010):
- `autosort_move_mode ENUM('off','suggest','auto') DEFAULT 'suggest'`
- `autosort_create_topic_mode ENUM('off','suggest','auto') DEFAULT 'suggest'`
- `autosort_reply_mode ENUM('off','suggest','auto') DEFAULT 'suggest'`
- `autoreply_max_per_day INT UNSIGNED DEFAULT 15` (Cost-Cap)

**Neu — `pending_actions`** (Migration 0011):
```sql
CREATE TABLE pending_actions (
    id CHAR(36) PRIMARY KEY,
    tenant_id CHAR(36),
    user_id CHAR(36),
    kind ENUM('move','create_topic','move_to_pending_topic','reply_draft') NOT NULL,
    payload JSON NOT NULL,
    parent_pending_id CHAR(36) NULL,        -- koppelt move_to_pending_topic an create_topic
    status ENUM('pending','approved','rejected','aged_out') DEFAULT 'pending',
    created_at DATETIME(3) NOT NULL,
    decided_at DATETIME(3) NULL,
    INDEX idx_user_status (tenant_id, user_id, status),
    INDEX idx_parent_pending (parent_pending_id),
    FOREIGN KEY (parent_pending_id) REFERENCES pending_actions(id) ON DELETE CASCADE
);
```
`kind='move_to_pending_topic'` benutzt `parent_pending_id` um an die zugehörige `create_topic`-Action zu hängen. Bei Topic-Approval werden gekoppelte Moves in einem Batch ausgeführt (siehe §3.1).

---

## 8. Cost-Modell (ehrlich mit 1h-Extended-Cache)

Detaillierte Token-Schätzungen siehe §5.3. Zusammenfassung pro User pro Monat:

| Szenario | Score-Calls (Haiku) | Mini-action_owner-Batches (Haiku) | Auto-Reply (Opus) | Total |
|---|---|---|---|---|
| Marc, 100 Mails/Tag | $0.20 | $0.05 | $0.30 | **~$0.55** |
| Power-User, 500 Mails/Tag | $1.00 | $0.25 | $0.45 (gekappt) | **~$1.70** |
| 10-User-Tenant, Marc-Volumen | $2.00 | $0.50 | $3.00 | **~$5.50** |

Ohne 1h-Extended-Cache wären Score-Calls 3-5× teurer. Caching ist nicht optional und nicht „Marketing-Math": bei 1h-TTL und tagsüber-aktiven Syncs ist die Cache-Read-Ratio echt bei 70-80%.

`BudgetService` (existiert seit Phase 5) wird in 6a erweitert:
- Neue Felder in `api_usage`-Logs: `cache_read_input_tokens`, `cache_creation_input_tokens`
- Budget-Report unterscheidet die drei Token-Klassen (Cost-Transparenz für Marc und zukünftige Tenant-Admins)

---

## 9. Risiken

- **Folder-Rename (Display):** User benennt einen KI-angelegten Ordner um. **Mitigation (6d):** Reconciliation-Job vergleicht `displayName` ↔ `folder_name`, bei Drift wird unsere Spalte angepasst.
- **Folder-Hierarchie-Move:** User verschiebt KI-angelegten Ordner an einen anderen Parent (z.B. `MailPilot/Auto/GitHub CI` → `Archiv/GitHub CI`). `displayName` bleibt gleich, nur `parentFolderId` ändert sich. **Mitigation (6d, Watch-For aus DA-Review):** Reconciliation prüft auch `parent_folder_id`; bei Drift wird voller Pfad neu aufgelöst und `folder_name` aktualisiert. Funktional war's auch ohne korrekt (folder_id stabil), aber UI würde sonst veralteten Pfad zeigen.
- **Folder-Delete:** User löscht den Ordner. Graph 404 → folder_id wird gedropped (Phase-5-Pattern), Rule wird `enabled=false` mit `last_error='folder_gone'`.
- **Topic-Drift:** KI schlägt über Wochen ähnliche Topic-Namen vor. **Mitigation:** Levenshtein-Dedupe vor Anlage, Topic-Pool sichtbar in Settings, User kann mergen.
- **Alias-False-Positives:** Anrede „Marc" matched auf User und einen anderen Empfänger. **Mitigation (§2.1):** Empfänger-Disambiguierung, bei Ambiguität immer `unsure`.
- **Mini-Call-Failure:** Anthropic 5xx/429/timeout. **Mitigation (§5.1):** deterministischer 3-Stufen-Fallback, `action_owner_source='fallback'` getrackt.
- **Move-Detection-Latenz:** Graph-Delta sieht Mobile-Moves erst beim nächsten Sync. Akzeptabel.
- **Pending-Queue staut sich:** Age-Out + Batch-Approval + Max-Pending-Banner (6c).
- **Reply-Draft-Stale:** neue Mail im Thread → Draft veraltet. **Mitigation (6f):** Stale-Marker + Re-Generation-Button.

---

## 10. DSGVO — Mechanische Coverage statt nur Pflicht-Text

### 10.1 Surface (textliche Liste, muss mit jedem Sprint aktualisiert werden)

Alle Tabellen, die `user_id` enthalten und damit unter DSGVO-Auskunft / -Löschung fallen:

| Tabelle | Inhalt | Export | Soft-Delete | Retention |
|---|---|---|---|---|
| `users` | Profil, Aliases (neu 6a), privacy_acknowledged_at | Ja | Ja (deleted_at) | — |
| `mailboxes` | OAuth-Tokens | Ja | Ja | bei Account-Delete |
| `mails` | Bodies, Headers | Ja | Ja | 7 Tage Bodies, 30 Tage Headers (PRD-Phase-1) |
| `mail_scores` | Klassifizierungs-Ergebnis inkl. action_owner | Ja | Ja | 30 Tage |
| `mail_summaries` | Opus-Summaries | Ja | Ja | 30 Tage |
| `reply_drafts` | Opus-Drafts | Ja | Ja | 30 Tage |
| `mail_score_corrections` (Phase 3e, **bisher fehlend!**) | User-Korrekturen mit Begründungen | **Wird in 6a nachgezogen** | Ja | 90 Tage |
| `vip_senders` | Email-Adressen Dritter | Ja | Ja | bei Account-Delete |
| `redaction_rules` | Regex-Patterns | Ja | Ja | bei Account-Delete |
| `auto_sort_rules` | Folder-Konfiguration, KI-vorgeschlagen oder User | Ja | Ja | bei Account-Delete |
| `auto_sort_corrections` (neu 6d) | Move-Korrekturen inkl. user_reason | Ja | Ja (deleted_at) | 90 Tage |
| `pending_actions` (neu 6c) | Pending-Moves, Topic-Vorschläge, Reply-Drafts mit Mail-Subjects + Recipient-Names im payload | Ja | Ja | 30 Tage |

### 10.2 Mechanische Garantie (neu in v3)

Pflicht-Test ab Sprint 6a:

```php
public function testEveryUserScopedTableIsInExport(): void {
    // INFORMATION_SCHEMA: alle Tabellen im Schema mit 'user_id'-Spalte
    $stmt = $this->pdo()->prepare("
        SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'user_id'
    ");
    $stmt->execute();
    $userTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $exportedKeys = $this->kernel()->get(MeController::class)->exportTableList();
    foreach ($userTables as $table) {
        $this->assertContains($table, $exportedKeys,
            "Table '{$table}' has user_id but is not in /me/export — DSGVO leak");
    }
}

public function testEveryUserScopedTableIsInDelete(): void { /* analog für /me */ }
```

Damit kann ein neuer Sprint keine `user_id`-Tabelle anlegen, ohne dass der Test rot wird. `MeController::exportTableList()` ist die einzige Stelle, wo neue Tabellen registriert werden müssen — und der Test fängt das Vergessen.

### 10.3 User-Disclaimer

Aliase + Korrekturen enthalten Namen Dritter (Kollegen, Klienten). DSGVO Art. 6 Abs. 1 (f) — berechtigtes Interesse. Beim ersten Speichern eines Alias oder einer Reason erscheint einmalig im Settings-Tab: *„Diese Information bleibt lokal auf deinem Server und wird nur als Kontext an die KI gesendet."* Akzept-Klick wird in `users.privacy_acknowledged_at` festgehalten.

---

## 11. Was bleibt aus Phase 5

- Schema-Felder `mail_scores.sub_label`, `auto_sort_rules.sub_label` werden weiter genutzt — nur der **Ursprung** ändert sich (KI statt User)
- `claude_cache` bleibt mit Phase-5-Schema. `action_owner`, `action_owner_confidence`, `action_owner_source` werden bewusst **nicht** im Cache gespeichert
- Sub-Label-Settings-UI aus 5d wird zur Korrektur-Schicht
- AutoSort-Sub-Rules-Tabelle bleibt — User kann weiterhin manuell Override-Pfade setzen
- Tenant-Isolation, Multi-Tenant-Disziplin: unverändert
- `BudgetService` wird in 6a um die getrennte Cache-Token-Erfassung erweitert

---

## 12. Entscheidungs-Log

| Frage | Entscheidung | Datum |
|---|---|---|
| Wie kommen Aliase ins System? | KI schlägt aus Postfach vor, User bestätigt | 2026-05-13 |
| Was bei `action_owner=unsure`? | Eigene Sektion im Dashboard, User entscheidet pro Mail | 2026-05-13 |
| Modi-Setting | Drei Toggles (move / create_topic / reply) × drei Stufen (off / suggest / auto), Default `suggest` | 2026-05-13 (v2) |
| Auto-Reply-Aggressivität | Nur `action_owner=user AND prio >= 4`, hard cap N pro Tag | 2026-05-13 |
| Sprint-Reihenfolge | Linear: 0 → 6a → 6b → 6c → 6d → 6e → 6f | 2026-05-13 |
| `action_owner` im Cache? | **Nein.** Post-Cache-Spalten, batched Mini-Call bei Cache-Hit | 2026-05-13 (v2) |
| Empfänger-Disambiguierung | **Pflicht in 6a-Prompt.** Alle Recipients an Claude, ambiguous → `unsure`. | 2026-05-13 (v2) |
| Single-Correction-Schutz | Schwellwert 3 gleichartige Korrekturen / 30 Tage. 90-Tage-TTL auf Few-Shot-Pool. | 2026-05-13 (v2) |
| Mini-Call: pro Mail oder pro Batch? | **Pro Batch.** 1 Call pro Score-Batch, JSON-Array Output. Profitiert vom selben Prompt-Cache wie der reguläre Score-Call. | 2026-05-13 (v3, DA-Finding 1) |
| Mini-Call: Fallback bei Failure? | **Deterministischer 3-Stufen-Fallback.** User-im-To → `user`/40. BCC/Verteiler → `group`/60. Sonst → `unsure`/0. Source-Tracking via `action_owner_source='fallback'`. | 2026-05-13 (v3, DA-Finding 1) |
| Anthropic Cache TTL? | **Extended (1h)** statt Ephemeral (5min). 2× cache_creation-Kosten amortisieren sich ab dem zweiten Read in der Stunde. Passt zur tatsächlichen Worker-Sync-Frequenz. | 2026-05-13 (v3, DA-Finding 3) |
| Cost-Tabelle ehrlich rechnen | Mit 1h-Extended-TTL, realistische 70-80% Cache-Read-Ratio. Ergibt ~$0.55/Monat für Marc, ~$1.70 für Power-User. Ohne Caching 3-5×. | 2026-05-13 (v3, DA-Finding 3) |
| DSGVO-Coverage: textlich oder mechanisch? | **Beides.** Explizite Tabellen-Liste in §10.1 + Test `testEveryUserScopedTableIsInExport` als mechanische Garantie. Test ist Pflicht ab Sprint 6a. | 2026-05-13 (v3, DA-Finding 2) |
| Mail-Flow bei pending Topic? | **Mail bleibt im Catch-all, gekoppelt via `pending_actions.parent_pending_id`. Topic-Approval triggert Bulk-Move-Bestätigung** — kein silent retroactive move. | 2026-05-13 (v3, DA-Finding 4) |
| Folder-Reconciliation: was prüfen? | **`displayName` UND `parentFolderId`.** Reiner Rename → folder_name aktualisieren. Hierarchie-Move → vollen Pfad neu auflösen. User-Customization gewinnt immer. | 2026-05-13 (v3, DA-Watch-For) |
