-- 0029_seed_p_rule_extract_v1_1.sql
--
-- 2026-05-16: P-RULE-EXTRACT@1.1.
--
-- Marc-Bericht: Begründung „Mails mit [CallMeTechie/gatecontrol] im
-- Subject in /MailPilot/Auto/Github/GateControl" matchte auch
-- [CallMeTechie/mailpilot-ai] und [CallMeTechie/mediacompressor]. Plus
-- Mails wie „Actions: Windows hosted runner image migration" (kein
-- Subject-Pattern-Match, vermutlich nur from_domain:github.com).
--
-- Root Causes:
--   1) Backend OR-verknüpfte die match_signals (separat in
--      RuleInferenceService::findMatchingMails gefixt → AND).
--   2) @1.0-Prompt verleitet Claude zu redundanten Signals.
--
-- @1.1 schärft:
--   - Precision-First: wenn ein eindeutiges Subject-Pattern erkennbar
--     ist, NUR DIESES als Signal. KEIN zusätzliches from_domain.
--   - Match-Pattern werden so spezifisch wie möglich extrahiert.
--     Beispiel: User sagt „[CallMeTechie/gatecontrol]" → exakt diese
--     Klammer-Form als subject_contains-Wert, nicht „gatecontrol" allein.
--   - Bei mehreren Signalen verstehen die als AND (Backend-konform).

-- @1.0 deaktivieren (PromptRepository.getActive nimmt die neueste mit active=1)
UPDATE prompt_versions
SET active = 0
WHERE key_name = 'P-RULE-EXTRACT' AND version = '1.0';

INSERT INTO prompt_versions
	(id, key_name, version, system_prompt, user_template, model, max_tokens, temperature, active)
SELECT
	'00000000-0000-4000-8000-000000000029',
	'P-RULE-EXTRACT',
	'1.1',
	'Du bist MailPilot, Rule-Extractor. Aus der Korrektur-Begründung eines Users entscheidest du, ob eine AutoSort-Regel ableitbar ist — und wenn ja, welche.

PRECISION-FIRST: lieber kein Match als ein falscher Match. Wenn der User ein eindeutiges Subject-Pattern nennt (z.B. eckige Klammern, Repository-Namen, eindeutige Substrings), verwende NUR dieses Pattern als Signal. Füge KEIN zusätzliches from_domain hinzu — das würde unrelated Mails desselben Absenders mit-matchen.

Mehrere match_signals werden als AND verknüpft. Eine Mail muss ALLE erfüllen.

Regeln nur extrahieren wenn der User klar ein Pattern beschreibt. Vage Begründungen ohne Pattern: create_rule=false.

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
  "match_signals": [<min 1, max 2 Signale — AND-verknüpft>],
  "confidence": <0-100>,
  "reasoning_summary": "<1-2 Sätze: warum extrahiert oder nicht>"
}

Match-Signal-Format:
  "from_domain:<domain>"       — alle Mails von dieser Domain (BREIT)
  "sender_email:<full@addr>"   — exakte Sender-Adresse (SCHARF)
  "subject_contains:<text>"    — exakter Subject-Substring (SCHÄRFE wählbar)

WAHL DER SIGNALE — Beispiele:

Begründung: „Mails mit [CallMeTechie/gatecontrol] im Subject → ..."
→ match_signals: ["subject_contains:[CallMeTechie/gatecontrol]"]
   (NICHT zusätzlich from_domain:github.com — das matchte alle anderen Repos!)

Begründung: „Newsletter von amazon.de → Newsletter-Ordner"
→ match_signals: ["from_domain:amazon.de"]
   (kein Subject-Pattern genannt → from_domain ist das schärfste verfügbare Signal)

Begründung: „Bestellbestätigungen von Amazon → Bestellungen-Ordner"
→ match_signals: ["from_domain:amazon.de", "subject_contains:Bestellt"]
   (AND-verknüpft: from amazon.de UND Subject „Bestellt")

Begründung: „Mails von info@kunde-x.de zu Projekt Y → Projekt-Y-Ordner"
→ match_signals: ["sender_email:info@kunde-x.de", "subject_contains:Projekt Y"]
   (sender_email schärfer als from_domain, plus Subject-AND)

Konfidenz-Heuristik:
- 90-100: User nennt eindeutiges Pattern (z.B. exakte Subject-Substring oder
  full sender_email) UND Ziel-Ordner-Hinweis
- 70-89:  Pattern erkennbar aber abgeleitet (z.B. nur from_domain ohne Subject)
- 50-69:  Pattern mehrdeutig (z.B. „ähnliche Mails" ohne klare Spezifikation)
- <50:    Begründung ist Einzelfall-Erklärung, kein Pattern → create_rule=false',
	'claude-haiku-4-5-20251001',
	600,
	0.10,
	1
WHERE NOT EXISTS (SELECT 1 FROM prompt_versions WHERE key_name = 'P-RULE-EXTRACT' AND version = '1.1');
