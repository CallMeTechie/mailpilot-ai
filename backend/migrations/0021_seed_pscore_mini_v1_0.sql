-- 0021_seed_pscore_mini_v1_0.sql
--
-- Carry-Over aus DA-Impl 6a Finding 3: Mini-Call für action_owner war
-- bisher mit Model + System-Prompt hardcoded in MailScoringService.
-- Daraus folgte (a) divergierende Model-Versionen wenn Admin P-SCORE
-- migriert + Mini-Call zurückbleibt; (b) usage_daily.prompt_version
-- enthielt einen synthetischen Tag der nicht zu prompt_versions jointe.
--
-- Lösung: P-SCORE-MINI@1.0 als eigene Row. Service liest beide via
-- PromptRepository.getActive — Admin kann sie unabhängig versionieren,
-- austauschen, Token-Budget setzen.
--
-- Output-Schema bleibt: {results: [{mail_id, action_owner, confidence}]}.
-- system_prompt minimal weil USER_IDENTITY-Segment vom gemeinsamen
-- Cache-Prefix kommt (buildCachedSystemSegments injiziert es).

INSERT INTO prompt_versions
	(id, key_name, version, system_prompt, user_template, model, max_tokens, temperature, active)
SELECT
	'00000000-0000-4000-8000-000000000021',
	'P-SCORE-MINI',
	'1.0',
	'Du bist MailPilot, action_owner-Reasoner. Du antwortest AUSSCHLIESSLICH in gültigem JSON nach dem vorgegebenen Schema. Kein Prosa, keine Markdown-Codefences.',
	'{{action_owner_rules}}
MAILS:
{{mails_json}}

Gib exakt ein JSON-Objekt zurück:
{"results":[{"mail_id":"<id>","action_owner":"user|other|group|unsure","confidence":0-100}]}

Anzahl results = Anzahl MAILS, in derselben Reihenfolge.',
	'claude-haiku-4-5-20251001',
	1000,
	0.10,
	1
WHERE NOT EXISTS (SELECT 1 FROM prompt_versions WHERE key_name = 'P-SCORE-MINI' AND version = '1.0');
