# Spec 2 — Lern-Loop: Korrekturen wirken aufs künftige Scoring (Design)

**Datum:** 2026-06-02
**Status:** Entwurf zur Review
**Autor:** MailPilot AI (Brainstorming mit Marc)
**Voraussetzung:** [Spec 1 — Modellwahl pro Rolle](2026-06-02-per-role-model-selection-design.md) (liefert die Rollen-Modellwahl, auf der die neue Rolle `match` aufsetzt)

---

## Ziel

Händische Korrekturen (inkl. User-Begründung) sollen das **künftige** Scoring ähnlicher / gleicher-Absender-Mails **zuverlässig** beeinflussen. Heute entsteht der Eindruck „die Regeln werden nie angewendet" — obwohl viel Lern-Maschinerie existiert. Spec 2 macht den Loop verlässlich: dauerhafte Regeln aus Korrekturen, ein **Per-Mail-Match-Score** gegen gelernte Regeln mit **Bändern** (auto / Vorschlag / ignorieren), ein **Admin-wählbarer Match-Modus** (deterministisch / LLM / hybrid) und eine geschlossene **Feedback-Schleife**.

## Problem / Ist-Zustand (Prod-Diagnose 2026-06-02)

Die Maschinerie existiert und greift **teilweise** (Marcs Mailbox): 53 Korrekturen (47 mit Begründung), Few-Shot liefert live 18 Beispiele in den Score-Prompt, 6 aktive Override-Regeln mit zusammen 87 Anwendungen (zuletzt am selben Tag). **Aber** sie wirft ihr Lernen weg:

1. **Autoclean ist feindlich zum eigenen Lernen (Hauptursache).** Eine Korrektur, bei der die KI nur *mittel* sicher ist (Confidence 80–84), erzeugt eine Regel **disabled** (Auto-Enable erst ab `score_rule_auto_enable_threshold=85`). Der tägliche `ScoreOverrideCleanupService` löscht aber **alle disabled-Regeln** (`delete_disabled=1`) **und** enabled-Regeln, die 7 Tage nicht griffen. Ergebnis: **28 von 34 Regeln gelöscht**, bevor sie je greifen konnten.
2. **Der Cache wird bei einer Korrektur nie invalidiert.** Code-Grep über `MailController`/`CorrectionRepository`/`ScoreOverrideService` findet null Cache-Purge. `MailScoringService::scoreBatch()` prüft `claude_cache` **vor** dem Prompt — wiederkehrende inhaltsgleiche Mails liefern den **alten** Score zurück, Korrektur wird nie neu bewertet.
3. **Score-Modell = Haiku** → das Few-Shot-Lernen (`{{corrections_block}}`) ist „weich" und wird vom kleinsten Modell unzuverlässig honoriert. (Spec 1 erlaubt ein stärkeres Score-Modell → direkte Verbesserung.)
4. **Regel-Anwendung ist rein deterministisch** (`ScoreOverrideService::matches()`: `sender_key`/`from_local`/`label` exakt, `priority_min` ≥, `subject_regex`). Binär — kein gradueller Match, kein Mittelweg, keine „mittel sicher"-Behandlung.

Die Schwelle ist heute eine **klare Punktzahl 85** (kein fließender Korridor), und sie misst die **Regel-Güte bei Erstellung** — **nicht** einen Match pro Mail. Letzteren gibt es bisher gar nicht.

## Design

### D1 — Korrektur = dauerhafte, aber gebundene Regel
- Aus einer User-Korrektur abgeleitete Regel wird von Autoclean **nicht** gelöscht (`delete_disabled`/unused-7-Tage greifen nicht auf user-derived Regeln).
- **Markierung:** neue Spalte `origin_correction_id CHAR(36) NULL` an `score_override_rules` — der Cleanup erkennt & schützt user-derived Regeln darüber.
- Die Erstell-Confidence steuert die **Match-Breite**, nicht das Überleben: hohe Confidence → breiter (z. B. Domain statt nur Absender); niedrige → **eng** (nur exakter Absender) statt verworfen.

**Wachstums-Grenze (DA-Fund #1 — sonst kehrt der Regel-Bloat zurück, den Autoclean ursprünglich bekämpfte):** „dauerhaft" darf nicht „unbegrenzt" heißen. `ScoreOverrideService::apply()` iteriert **alle** enabled Regeln **pro Mail** → unkontrolliertes Wachstum = `O(Mails × Regeln)` auf dem Hot-Path.

- **„Dimension" = Set-Feld.** Das bestehende Modell ist bewusst **orthogonal** (`applySetFieldsOrthogonal`): eine Regel-Zeile kann mehrere Set-Felder gleichzeitig tragen (priority / action_required / label / folder_segments), und pro Absender dürfen mehrere Zeilen je ein *anderes* Feld liefern. Die Invariante ist ein **logischer Dedup-Schlüssel, kein „eine Zeile pro Feld"**: es darf **keine zweite Zeile** ein `(Match-Key, Set-Feld)` setzen, das eine bestehende Zeile schon besitzt — stattdessen wird dieses Feld **in der bestehenden Zeile feldweise aktualisiert**. So bleibt Orthogonalität erhalten und eine Korrektur, die priority+label+folder ändert, pflegt weiterhin den jeweils einen Slot je Feld.
- **Match-Key:** primär `sender_key`; für regeln **ohne** `sender_key` (nur `subject_regex`/`from_local`) ist der Match-Key das tatsächliche Match-Tupel — der Dedup gilt dort über dieses Tupel, damit auch dieser Pfad nicht zur Bloat-Tür wird.
- **Update-in-place statt anhäufen:** Eine neue Korrektur, deren `(Match-Key, Set-Feld)` schon existiert, **aktualisiert** die bestehende Regel (Breite/Wert/Confidence) per **deterministischem Feld-Update** — sie erzeugt **keine** zweite Zeile. (`P-RULE-MERGE` (Migration 0043) ist **nicht** dieser Updater: es ist der **admin-getriggerte, LLM-basierte Konflikt-Merge** (`mergeRules()`, schreibt selbst nichts) — der bleibt der manuelle Konfliktpfad und wird, wenn genutzt, budgetiert.)
- **Soft-Cap + LRU** auch für geschützte Regeln: pro User eine Obergrenze; bei Überschreitung wird die am längsten nicht angewandte user-derived Regel **deaktiviert** (nicht hart gelöscht) — **mit Admin-Hinweis** (s. D8 Observability), damit nicht erneut der Eindruck „Regeln verschwinden still" entsteht.

### D2 — Per-Mail-Match-Score (neuer Baustein)
Nach der KI-Klassifikation (integriert mit/vor `ScoreOverrideService`) wird pro eingehender Mail gegen die gelernten Regeln ein **Match-Score 0–100** berechnet. Globaler Admin-Modus `learning.match_mode`:

| Modus | Wie | Konsequenz (UI-Text) |
|---|---|---|
| `deterministic` *(Default)* | Gewichtete Features (Absender exakt = viel, gleiche Domain = mittel, `from_local`, Betreff-Keyword/Regex, Label-Kontext) — **kein LLM** | Kosten 0, sofort, voll erklärbar, vorhersehbar; erkennt nur, was gewichtet ist |
| `llm` | Rolle `match` bewertet „passt Mail zu Regel R? 0–100" (ein Call, mehrere Kandidaten gebündelt) | semantisch/flexibel; LLM-Call **nur bei Cache-Miss** (s. u.) → Kosten/Latenz gebunden |
| `hybrid` | deterministisch entscheidet klare Fälle (hoch/niedrig); nur die **mittlere Zone** geht an `match`-LLM | billig für die Masse, schlau bei Grenzfällen |

Der `deterministische` Scorer ist eine eigene, testbare Einheit (`MatchScorer`); der LLM-Teil ein `RuleMatchService` über `LlmRouter->complete($req,'match')`.

**Kosten-Schutz (DA-Fund #2 — der Cache darf nicht ausgehebelt werden):**
- Der **deterministische** Match läuft immer (gratis) — auch auf Cache-Hits, genau wie der heutige Override.
- Der **LLM-Match (`llm`/`hybrid`) läuft NUR bei Cache-Miss.** Cache-Hits (der häufige, billige Fall) bekommen keinen LLM-Match. Für „ich habe das korrigiert, künftige gleiche Mails sollen anders" sorgt die **Cache-Invalidierung bei Korrektur** (D5) — die erzwingt einen Miss für den betroffenen Absender/Inhalt, der dann den vollen Match bekommt.
- Pro Mail werden alle Kandidaten-Regeln in **einem** LLM-Call gebündelt (Hard-Cap auf die Anzahl Kandidaten). Zusätzlich ein **Per-Batch-LLM-Match-Budget** (Obergrenze an Match-Calls je Scoring-Batch); darüber → deterministischer Fallback.
- Default ist **`deterministic`** (0 LLM-Kosten ab Werk); `llm`/`hybrid` bewusst zuschaltbar.

### D3 — Bänder
Zwei tunbare Settings:
- `learning.match_auto_threshold` (Default 80): Score ≥ → Regel **anwenden** (deterministischer Override-Write wie heute, jetzt match-getrieben).
- `learning.match_suggest_threshold` (Default 50): suggest ≤ Score < auto → **Vorschlag** (pending) erzeugen, Mail bleibt unverändert. Score < suggest → ignorieren.

### D4 — Feedback-Schleife (mutiert, erzeugt nicht)
Vorschläge erscheinen **sowohl im Add-in (beim Triage) als auch im Admin-Panel** — der Admin kann sie dort prüfen und ggf. korrigieren.

**Mutate-in-place-Kontrakt (DA-Fund #7 — sonst self-amplifying Duplikate):** Ein Vorschlag trägt die `rule_id` (und `origin_correction_id`) der **Ursprungsregel** mit. Bestätigen/Ändern/Verwerfen **aktualisiert genau diese Regel** — es entsteht **keine** neue Regel:
- **Bestätigen** → `applies_count`++/Confidence rauf, Regel bleibt/wird enabled.
- **Ändern** → Felder/Match-Breite der Regel werden angepasst (über `P-RULE-MERGE`).
- **Verwerfen** → Regel wird **verengt** (nur exakter Treffer) oder deaktiviert; kein erneuter Vorschlag für denselben Fall (Dedup über `origin_correction_id`).

So lernt das System aus Grenzfällen, ohne dass `confirm → re-infer → neue Regel` Geschwister-Regeln erzeugt (die sonst dank D1 nie aufgeräumt würden).

### D5 — Cache-Invalidierung bei Korrektur (Mechanik-Split, DA-Fund #2/C2)
**Wichtig: Zwei verschiedene Mechanismen decken zwei verschiedene Fälle — der Cache ist nur für den exakten Fall zuständig.**

- **„Künftige ähnliche / gleicher-Absender"-Mail → die gelernte Regel.** Eine ähnliche Mail desselben Absenders hat anderen Inhalt → anderen `content_hash` → ist **per Definition ein Cache-Miss** → frischer Score → darauf greift die **absender-keyed Override-Regel** (D1). Override-Regeln greifen zudem **auch auf Cache-Hits** (`enrichScoresWithSender` läuft für alle Score-Rows). Die Regel — nicht der Cache — ist also der Mechanismus für „ähnlich/gleicher Absender". Genau das war bisher kaputt (Regeln wurden weggeräumt) und wird durch D1 repariert.
- **Cache-Invalidierung = nur die exakte korrigierte Mail.** `claude_cache` ist ausschließlich per `content_hash` (+ tenant) ge-keyed — **keine** `sender_key`-Spalte. Beim Speichern einer Korrektur wird daher gezielt der/die `content_hash`-Row(s) der korrigierten Mail per **`DELETE`** invalidiert (auch byte-identische Wiederkehrer teilen den Hash). Das verhindert, dass dieselbe Mail beim nächsten Mal noch den **vor**-Korrektur-Score aus dem Cache liefert. Eine „Invalidierung pro Absender" ist mangels Spalte **nicht** vorgesehen und auch **nicht nötig** (siehe Regel-Pfad oben).
- Kein tenant-weiter Purge.

### D6 — Neue Rolle `match` (aus Spec-1-Mechanik)
`llm_models` bekommt eine Zeile `role='match'` (Default billig/schnell, niedriger Effort). Nur genutzt bei `match_mode` `llm`/`hybrid`. Admin-UI-Warnung „läuft pro Mail". Modell-Picker = Spec-1-Mechanismus (5. Rolle).

### D7 — Privacy/Redaction & `local_only` für `match` (DA-Fund #3)
Der LLM-Match schickt Mailinhalt an einen Provider — pro Mail, also eine **neue, größere PII-Fläche** als das gebatchte Scoring. CLAUDE.md §5 verlangt PII-Redaction vor **jedem** Provider, DSGVO ist First-Class.
- **Redaction Pflicht:** `RuleMatchService`-Payloads laufen durch `RedactionService` (Absender → Domain, Betreff/Body redacted) — wie Scoring (`redactMail`) und Inferenz. Kein Roh-Inhalt an einen Cloud-Provider.
- **`local_only` muss sauber degradieren:** Es **muss** ein lokales `match`-Modell seedbar sein. Ist unter `local_only` kein lokaler Provider verfügbar, fällt der Match **auf `deterministic`** zurück (nie ein Cloud-Call, nie ein leerer-Chain-Fehler auf dem Scoring-Pfad).
- Test: „`match` unter `local_only` erreicht nie einen Cloud-Provider".

### D8 — Observability der stillen Degradationen (DA-Fund C4)
Mehrere Pfade degradieren bewusst still — das darf **nicht** wieder den Eindruck „es passiert heimlich etwas / Regeln verschwinden" erzeugen. Jede Degradation wird sichtbar gemacht (Log + Admin-Signal, z. B. Banner/Usage-Zeile):
- user-derived Regel per **LRU deaktiviert** (D1) → Admin-Hinweis „Regel X wegen Soft-Cap deaktiviert".
- **Match-Budget** je Batch überschritten → deterministischer Fallback (Zähler im Log).
- **`local_only` ohne lokales `match`-Modell** → deterministischer Fallback (Hinweis).
- Beim Upgrade gesetztes `routing_mode=router` (Spec 1 D8) → einmaliger Admin-Hinweis.

## Betroffene Komponenten / Dateien

- **Neu:** `backend/src/Services/Scoring/MatchScorer.php` (deterministisch), `backend/src/Services/RuleMatchService.php` (LLM/hybrid über Router-Rolle `match`; **Redaction Pflicht**, **nur bei Cache-Miss**, Kandidaten-Bündelung + Per-Batch-Budget, `local_only`→deterministic).
- `backend/src/Services/ScoreOverrideService.php` — Match-Score + Bänder statt rein binärem `matches()`.
- `backend/src/Services/ScoreOverrideCleanupService.php` — user-derived Regeln (via `origin_correction_id`) schützen.
- `backend/src/Repositories/ScoreOverrideRepository.php` — Update-in-place pro `(sender_key,Dimension)`; Soft-Cap/LRU-Deaktivierung für user-derived Regeln.
- `backend/src/Services/RuleInferenceService.php` — Confidence → Match-Breite; **Update-in-place/Merge (`P-RULE-MERGE`)** statt neuer Regel; Vorschlags-Pfad mit `rule_id`/`origin_correction_id`.
- `backend/src/Repositories/CacheRepository.php` + `CorrectionRepository.php` (oder `MailController`) — gezielte Cache-Invalidierung bei Korrektur.
- `backend/src/Controllers/MailController.php` + pending-actions — Vorschlags-Flow + Feedback.
- **Migrationen:** `learning.match_mode` + `learning.match_auto_threshold` + `learning.match_suggest_threshold`; Schutz-Markierung an `score_override_rules` (`origin_correction_id`); `llm_models`-Zeile `role='match'`.
- **Admin-UI:** `match_mode`-Setting + Konsequenz-Text; `match`-Modell-Dropdown (Spec 1); **Vorschlags-Review/-Korrektur** (Liste der pending Vorschläge, bestätigen/ändern/verwerfen).
- **Add-in:** Vorschlags-Anzeige + Aktion (bestätigen/ändern/verwerfen).

## Fehlerbehandlung

- `match`-LLM down → sauberer Fallback auf `deterministic` (hybrid/llm degradiert), kein Scoring-Killer.
- Cache-Invalidierung idempotent.
- Vorschlags-Erzeugung best-effort; Scheitern killt den Score-Pfad nicht.

## Teststrategie (Projekt-Konvention: **kein Mocking**)

- **Unit:** `MatchScorer` — Feature-Gewichte → Score; Band-Einordnung (auto/Vorschlag/ignorieren).
- **Integration:** Korrektur → Cache der Mail invalidiert → nächste gleiche Mail wird neu gescort (`cached=0`); Autoclean **schützt** user-derived Regeln; `match`-Rolle via Router (`modelHint=''`) resolved das `match`-Modell; Band-Schwellen → korrekte Aktion (apply vs. pending vs. ignore).
- **Wachstum/Loop (#1/#7):** zwei Korrekturen desselben Absenders → **genau eine** Regel (update-in-place); Vorschlag zweimal bestätigen → eine Regel, Confidence rauf, **keine** neue Zeile.
- **Kosten (#2):** Cache-Hit-Mail bekommt **keinen** LLM-Match; Per-Batch-Match-Budget überschritten → deterministischer Fallback.
- **Privacy (#3):** `match` unter `local_only` erreicht **nie** einen Cloud-Provider; Match-Payload ist redacted.

## Out of Scope

- Per-Regel-Match-Modus (kommt später; Spec startet global).
- Auto-Tuning der Schwellen/Gewichte.

## Entscheidungen (Review 2026-06-02, Marc)

1. **Vorschlags-UX:** Vorschläge erscheinen **im Add-in UND im Admin-Panel** — der Admin kann sie prüfen und ggf. korrigieren.
2. **Schutz-Markierung:** neue Spalte `origin_correction_id` an `score_override_rules`.
3. **Cache-Invalidierung:** gezieltes `DELETE` der betroffenen `content_hash`-Rows.
4. **Match-Default:** `deterministic` (0 LLM-Kosten ab Werk; `llm`/`hybrid` bewusst zuschaltbar, LLM nur bei Cache-Miss).

*DA-Runde 1 (2026-06-02) eingearbeitet:* #1 Wachstums-Grenze (D1), #2 Cache-Miss-Gating (D2), #3 Privacy/`local_only` (D7), #7 Mutate-in-place (D4).
*DA-Runde 2 eingearbeitet:* C1 „Dimension = Set-Feld" + Update-Mechanik präzisiert (D1), C2 Mechanik-Split Regel-vs-Cache (D5), C4 Observability (D8), C5 Per-Feld-Clear (offene Punkte).

## Offene Punkte (für Plan)

- Default-Feature-Gewichte (deterministisch) + konkrete Band-Schwellen — im Implementierungsplan festzurren.
- **Per-Feld-Clear-Semantik (DA C5):** „Ändern/Verwerfen" eines Vorschlags setzt **das betroffene Set-Feld** der Regel auf NULL (statt die ganze Regel zu deaktivieren), damit eine partielle Korrektur nicht unbeteiligte Dimensionen derselben Regel mitreißt.
- Soft-Cap-Höhe (max. user-derived Regeln pro User) + Match-Budget pro Batch — konkrete Werte im Plan.
