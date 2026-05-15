-- 0026_seed_p_rule_extract_v1_0.sql
--
-- Sprint 6g — Seedet den Prompt P-RULE-EXTRACT@1.0.
--
-- Eingabe: Korrektur-Kontext (subject, from_domain, label, sub_label) +
-- redacted reasoning-Freitext. Ausgabe: striktes JSON ob aus dem reasoning
-- eine AutoSort-Regel extrahiert werden kann.
--
-- Admin-Panel kann den Prompt jederzeit überschreiben (versioniert via
-- prompt_versions, RuleInferenceService liest die aktive Row).

INSERT INTO prompt_versions
	(id, key_name, version, system_prompt, user_template, model, max_tokens, temperature, active)
SELECT
	'00000000-0000-4000-8000-000000000026',
	'P-RULE-EXTRACT',
	'1.0',
	'Du bist MailPilot, Rule-Extractor. Aus der Korrektur-Begründung eines Users entscheidest du, ob eine AutoSort-Regel ableitbar ist — und wenn ja, welche.

Regeln nur extrahieren wenn der User klar ein Pattern beschreibt (z.B. Absender-Domain, Subject-Stichwort, Kombination). Vage Begründungen ohne Pattern: create_rule=false.

Du antwortest AUSSCHLIESSLICH in gültigem JSON nach dem vorgegebenen Schema. Kein Prosa, keine Markdown-Codefences.',
	'KONTEXT-MAIL:
- Label: {{mail_label}}
- Sub-Label: {{mail_sub_label}}
- From-Domain: {{mail_from_domain}}
- Subject: {{mail_subject}}

USER-BEGRÜNDUNG:
{{reasoning}}

ERLAUBTE LABELS: direct, action, cc, newsletter, auto, noise

Gib exakt ein JSON-Objekt zurück:
{
  "create_rule": <true|false>,
  "label": "<eines der erlaubten Labels, MUSS gesetzt sein wenn create_rule=true>",
  "sub_label": "<kebab-case-Name oder null>",
  "folder_name": "<Outlook-Folder-Pfad relativ zur Inbox, z.B. MailPilot/Auto/Zertifikate>",
  "match_signals": ["from_domain:<domain>", "subject_contains:<wort>", "sender_email:<addr>"],
  "confidence": <0-100>,
  "reasoning_summary": "<1-2 Sätze: warum extrahiert oder nicht>"
}

Konfidenz-Heuristik:
- 90-100: User nennt explizit Absender-Domain ODER eindeutiges Subject-Pattern UND Ziel-Ordner-Hinweis
- 70-89:  User nennt Pattern aber Ziel-Ordner ist abgeleitet
- 50-69:  Pattern erkennbar aber mehrdeutig (z.B. "ähnliche Mails")
- <50:    Begründung ist Einzelfall-Erklärung, kein Pattern → create_rule=false',
	'claude-haiku-4-5-20251001',
	600,
	0.10,
	1
WHERE NOT EXISTS (SELECT 1 FROM prompt_versions WHERE key_name = 'P-RULE-EXTRACT' AND version = '1.0');
