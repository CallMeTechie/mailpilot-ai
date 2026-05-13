-- 0013_seed_psummary_preply_v1_1.sql
--
-- Phase B der Prompt-DB-Integration: P-SUMMARY und P-REPLY werden
-- jetzt ebenfalls aus prompt_versions geladen statt aus Heredocs im
-- PHP-Code. Diese Migration legt v1.1-Versionen mit dem Doppel-
-- Geschweiften-Klammer-Platzhalter-Pattern an, das auch P-SCORE
-- nutzt (siehe Migration 0012).
--
-- Platzhalter in P-SUMMARY user_template:
--   {{from_name}}, {{from_email}}, {{subject}}, {{body}}
-- Platzhalter in P-SUMMARY system_prompt:
--   {{user_email}}, {{user_language}}
--
-- Platzhalter in P-REPLY user_template:
--   {{from_name}}, {{from_email}}, {{subject}}, {{body}},
--   {{instruction_block}}  (leer wenn keine Instruction übergeben)
--
-- Strategie: alte v1.0-seed-Versionen deaktivieren, v1.1 als active=1.

INSERT INTO prompt_versions
	(id, key_name, version, system_prompt, user_template, model, max_tokens, temperature, active)
SELECT
	'00000000-0000-4000-8000-000000000013',
	'P-SUMMARY',
	'1.1',
	'Du fasst eine E-Mail für {{user_email}} zusammen. Deine Zusammenfassung ersetzt das Lesen der Mail. Struktur:

**Worum geht''s:** Ein Satz.
**Was wird erwartet:** Ein Satz — was soll der Nutzer tun? Oder "Nichts, nur Information."
**Deadline:** Datum/Zeit falls genannt, sonst "keine".
**Kontext:** Ein Satz zum Thread-Kontext falls Reply, sonst weglassen.

Antworte auf {{user_language}}. Kein Markdown außer den Labels, klare Sätze. Max 120 Wörter.',
	'From: {{from_name}} <{{from_email}}>
Subject: {{subject}}
---
{{body}}',
	'claude-opus-4-7',
	400,
	0.20,
	1
WHERE NOT EXISTS (SELECT 1 FROM prompt_versions WHERE key_name = 'P-SUMMARY' AND version = '1.1');

UPDATE prompt_versions SET active = 0 WHERE key_name = 'P-SUMMARY' AND version <> '1.1';
UPDATE prompt_versions SET active = 1 WHERE key_name = 'P-SUMMARY' AND version = '1.1';

INSERT INTO prompt_versions
	(id, key_name, version, system_prompt, user_template, model, max_tokens, temperature, active)
SELECT
	'00000000-0000-4000-8000-000000000014',
	'P-REPLY',
	'1.1',
	'Du entwirfst eine Antwort auf eine E-Mail. Der Nutzer reviewt und sendet selbst.

Regeln:
- Ton aus dem Thread ableiten (Du/Sie, formal/locker)
- Gleiche Sprache wie die eingehende Mail
- Keine erfundenen Zusagen, Termine oder Zahlen
- Wenn Entscheidung ansteht, die DER NUTZER treffen muss: Platzhalter [ENTSCHEIDUNG]
- Grußformel passend zum Thread
- Keine KI-Floskeln
- Max 150 Wörter

Output: Nur der Mail-Body. Keine Subject-Zeile, kein Markdown, keine Erklärung.',
	'ORIGINAL_MAIL:
From: {{from_name}} <{{from_email}}>
Subject: {{subject}}
---
{{body}}{{instruction_block}}

Entwirf die Antwort.',
	'claude-opus-4-7',
	800,
	0.30,
	1
WHERE NOT EXISTS (SELECT 1 FROM prompt_versions WHERE key_name = 'P-REPLY' AND version = '1.1');

UPDATE prompt_versions SET active = 0 WHERE key_name = 'P-REPLY' AND version <> '1.1';
UPDATE prompt_versions SET active = 1 WHERE key_name = 'P-REPLY' AND version = '1.1';
