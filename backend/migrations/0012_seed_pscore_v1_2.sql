-- 0012_seed_pscore_v1_2.sql
--
-- Bindet die hardcoded Prompts aus dem MailScoringService an die
-- prompt_versions-Tabelle, die seit Migration 0003 existiert aber
-- vom Backend bisher ignoriert wurde. P-SCORE@1.2 entspricht dem
-- Stand nach Phase 6b (Topic-Discovery).
--
-- Strategie: bestehende P-SCORE-Versionen werden deaktiviert,
-- v1.2 wird als aktive Version eingespielt. Bei Re-Run ist die
-- INSERT idempotent (UNIQUE auf key_name + version), das UPDATE
-- danach setzt active = 1 für genau diese Zeile.
--
-- Platzhalter im user_template:
--   {{user_email}}            — User-Email
--   {{user_language}}         — de / en
--   {{vip_senders_csv}}       — Komma-separierte VIP-Liste
--   {{project_keywords_csv}}  — Komma-separierte Stichwörter
--   {{corrections_block}}     — Few-Shot aus mail_score_corrections (leer, wenn keine)
--   {{user_sublabels_block}}  — User + KI Topics (leer, wenn keine)
--   {{topic_discovery_note}}  — TOPIC_DISCOVERY-Anweisung (Phase 6b)
--   {{mails_json}}            — Mails als JSON-Array
--   {{output_schema_sub_label}} — Schema-Snippet (Pool-State-abhängig)
--
-- P-SUMMARY und P-REPLY bleiben in Migration 0003-Seed; deren
-- DB-Integration kommt in Phase B (eigener Sprint).

INSERT INTO prompt_versions
	(id, key_name, version, system_prompt, user_template, model, max_tokens, temperature, active)
SELECT
	'00000000-0000-4000-8000-000000000012',
	'P-SCORE',
	'1.2',
	'Du bist MailPilot, ein präziser E-Mail-Triage-Assistent. Du klassifizierst eingehende E-Mails aus Sicht eines bestimmten Nutzers. Du antwortest AUSSCHLIESSLICH in gültigem JSON nach dem vorgegebenen Schema. Kein Prosa, keine Markdown-Codefences, kein Kommentar.

Labels:
- direct: E-Mail ist persönlich an den Nutzer gerichtet, erwartet Wahrnehmung
- action: Absender erwartet konkret Antwort/Entscheidung/Handlung
- cc: Nutzer ist nur informativ im CC/BCC
- newsletter: Marketing, Abonnement (List-Unsubscribe gesetzt)
- auto: Automatisiert (CI, Monitoring, Rechnungen, Versandbestätigungen)
- noise: Spam-verdächtig / irrelevant

direct und cc schließen sich aus. action_required kann zusätzlich gesetzt sein. Bei Newsletter/Auto/Noise ist action_required immer false.

Priorität 1-5: 5=sofort, 4=heute, 3=diese Woche, 2=wann passt, 1=ignorierbar.

Zusammenfassung max. 160 Zeichen, in user.language, keine Anführungszeichen, keine Emojis.',
	'USER_PROFILE:
- email: {{user_email}}
- language: {{user_language}}
- vip_senders: [{{vip_senders_csv}}]
- project_keywords: [{{project_keywords_csv}}]
{{corrections_block}}{{user_sublabels_block}}{{topic_discovery_note}}
MAILS_TO_CLASSIFY:
{{mails_json}}

Gib exakt ein JSON-Objekt zurück:
{"results":[{"id":"<mail.id>","label":"direct|action|cc|newsletter|auto|noise",{{output_schema_sub_label}},"action_required":true|false,"priority":1-5,"summary":"max 160 chars","reasoning":"max 80 chars"}]}

Anzahl results = Anzahl mails, in derselben Reihenfolge.',
	'claude-haiku-4-5-20251001',
	2000,
	0.10,
	1
WHERE NOT EXISTS (SELECT 1 FROM prompt_versions WHERE key_name = 'P-SCORE' AND version = '1.2');

UPDATE prompt_versions SET active = 0 WHERE key_name = 'P-SCORE' AND version <> '1.2';
UPDATE prompt_versions SET active = 1 WHERE key_name = 'P-SCORE' AND version = '1.2';
