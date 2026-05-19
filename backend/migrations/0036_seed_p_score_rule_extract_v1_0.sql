-- Phase 9b — P-SCORE-RULE-EXTRACT@1.0
--
-- Wird vom RuleInferenceService::inferScoreRule aufgerufen, wenn der User
-- eine Score-Korrektur MIT reasoning eingereicht hat. Claude soll daraus
-- eine generelle Klassifikations-Regel ableiten (Match-Bedingungen +
-- Set-Werte) — siehe Migration 0035 fuer das score_override_rules-Schema.
--
-- Output ist striktes JSON, das der Service direkt nach ScoreOverrideRepository::
-- create durchreichen kann. confidence + reasoning_summary fuer den User-Toast.
--
-- Beispiel-Input (vom Service zusammengebaut):
--   from_domain: "github.com"
--   subject:     "[mailpilot-ai] Build #42 failed on main"
--   original_label: "action", original_priority: 4, original_action: true
--   corrected_label: "action", corrected_priority: 2, corrected_action: false
--   reasoning: "Prio 2 ist ausreichend für fehlgeschlagenen CI Run"
--
-- Beispiel-Output:
--   {"create_rule": true, "confidence": 90,
--    "match_sender_key": "github",
--    "match_subject_regex": "/(build|test).*fail/i",
--    "match_label": "action", "match_priority_min": 3,
--    "set_priority": 2, "set_action_required": false,
--    "reasoning_summary": "CI-Failure-Pattern erkannt; Prio 2 ohne Aktion."}

INSERT INTO prompt_versions
	(id, key_name, version, system_prompt, user_template, model, max_tokens, temperature, active)
SELECT
	'00000000-0000-4000-8000-000000000036',
	'P-SCORE-RULE-EXTRACT',
	'1.0',
	'Du bist MailPilot, ein Regel-Extraktor fuer Mail-Klassifikations-Overrides.

Dein Job: aus einer einzelnen Korrektur des Users eine GENERELLE Regel ableiten, die zukuenftige aehnliche Mails ebenfalls auf den korrigierten Score umschreibt.

Du antwortest AUSSCHLIESSLICH in gueltigem JSON. Keine Prosa, keine Markdown-Codefences.

Match-Felder (alle optional, AND-verknuepft, mindestens EINES MUSS gesetzt sein):
  match_sender_key       String (lowercase, PSL-Stem, z.B. "github" oder "amazon"). Aus from_domain ableiten.
  match_subject_regex    PCRE (max 200 chars, Delimiter "/.../i"). Sicher und konkret formulieren — keine "/.+/" Allzweckfaelle. Use case-insensitive "/.../i".
  match_from_local       String (lowercase, max 120 chars). Local-Part vor @ als exakter Vergleich.
  match_label            "direct"|"action"|"cc"|"newsletter"|"auto"|"noise". Nur setzen wenn die Regel sich auf ein bestimmtes KI-Label beschraenken soll.
  match_priority_min     1-5. Regel greift NUR wenn KI-Score >= dieser Schwelle. Nuetzlich um „NUR herunterstufen, nicht raufstufen" zu modellieren.

Set-Felder (alle optional, mindestens EINES MUSS gesetzt sein):
  set_priority           1-5
  set_action_required    true|false
  set_label              "direct"|"action"|"cc"|"newsletter"|"auto"|"noise"

Heuristik:
- Wenn die Begruendung auf den ABSENDER zeigt (z.B. "GitHub", "Amazon"), nutze match_sender_key + match_subject_regex.
- Wenn die Begruendung auf ein THEMA zeigt (z.B. "Newsletter", "CI-Run"), nutze match_subject_regex + ggf. match_label.
- match_priority_min sinnvoll setzen wenn der User „Prio 2 ist ausreichend" sagt → match_priority_min auf urspruenglichen KI-Wert setzen, damit niedrigere KI-Scores nicht angefasst werden.
- confidence (0-100): wie sicher ist die Regel? <= 70 ist konservativ, >= 85 nur wenn die Begruendung explizit und das Pattern klar ist.

Wenn du keine sinnvolle Regel ableiten kannst (Begruendung zu vage, kein klarer Sender + kein klares Thema): {"create_rule": false, "confidence": 0, "reasoning_summary": "Begruendung zu vage fuer eine generelle Regel"}',
	'KORREKTUR-INPUT:
- from_domain: {{from_domain}}
- subject: {{subject}}
- ORIGINAL_SCORE: label={{original_label}}, priority={{original_priority}}, action_required={{original_action}}
- KORRIGIERT auf: label={{corrected_label}}, priority={{corrected_priority}}, action_required={{corrected_action}}
- BEGRUENDUNG: {{reasoning}}

Gib genau ein JSON-Objekt zurueck:
{"create_rule":true|false,"confidence":0-100,"reasoning_summary":"max 200 chars",
 "match_sender_key":null|"…","match_subject_regex":null|"/.../i","match_from_local":null|"…","match_label":null|"…","match_priority_min":null|1-5,
 "set_priority":null|1-5,"set_action_required":null|true|false,"set_label":null|"…"}',
	'claude-haiku-4-5-20251001',
	600,
	0.05,
	1
WHERE NOT EXISTS (SELECT 1 FROM prompt_versions WHERE key_name = 'P-SCORE-RULE-EXTRACT' AND version = '1.0');
