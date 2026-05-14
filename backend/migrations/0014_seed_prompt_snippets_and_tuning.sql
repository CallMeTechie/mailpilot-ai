-- 0014_seed_prompt_snippets_and_tuning.sql
--
-- Phase C der Prompt-DB-Migration: die letzten hardcoded Bausteine
-- wandern in system_settings, damit sie über das Admin-Panel
-- editierbar werden ohne Code-Deploy.
--
-- Gruppe 1: Prompt-Hilfsblöcke
--   prompt.corrections_header      → Few-Shot-Block-Überschrift
--   prompt.sublabels_header        → existing-buckets-Block-Header
--   prompt.topic_discovery_note    → Phase-6b-Discovery-Anweisung
--   prompt.schema_sublabel_with_pool   → Output-Schema mit Pool
--   prompt.schema_sublabel_empty_pool  → Output-Schema ohne Pool
--
-- Gruppe 2: Verhaltens-Konstanten
--   worker.heartbeat_threshold_seconds      → "Worker offline"-Schwelle
--   autosort.retry_cap                      → max Failed-Move-Versuche
--   topics.fuzzy_merge_levenshtein_max      → Threshold für Topic-Merge
--   folder_default.direct/.action/.cc/...   → Default-Folder pro Primary
--
-- Idempotent via INSERT IGNORE — bestehende Werte (vom Operator
-- bereits angepasst) bleiben unangetastet.

INSERT IGNORE INTO system_settings (`key`, `value`, `type`, description) VALUES
	('prompt.corrections_header', 'PRIOR_USER_CORRECTIONS (the human overruled the model — apply the same reasoning):', 'string', 'Header line above the few-shot user-corrections block in the score prompt'),
	('prompt.sublabels_header',   'USER_SUBLABELS (existing buckets; prefer these when the mail clearly fits one):', 'string', 'Header line above the existing topics list in the score prompt'),
	('prompt.topic_discovery_note',
		'TOPIC_DISCOVERY (Phase 6b):\n- If the mail clearly belongs to a recurring category that USER_SUBLABELS does NOT yet cover, you MAY propose a NEW short topic name (max 30 chars, Title Case, e.g. "Stripe Payments", "GitHub CI", "Bestellung").\n- Only propose a new topic when you can identify a clear recurring sender or pattern. Do NOT invent topics for one-off mails.\n- Set "sub_label_is_new":true exactly when you propose a NEW topic that is NOT in USER_SUBLABELS.\n- If a USER_SUBLABEL matches, return its existing name verbatim and set "sub_label_is_new":false.\n- If neither fits (truly unique mail), return "sub_label":null and "sub_label_is_new":false.',
		'string',
		'Multi-line instruction block enabling autonomous topic discovery (Phase 6b)'),
	('prompt.schema_sublabel_with_pool',  '"sub_label":"<a topic name OR null>","sub_label_is_new":true|false', 'string', 'JSON-Schema snippet inserted into output schema when user has sub-labels'),
	('prompt.schema_sublabel_empty_pool', '"sub_label":null,"sub_label_is_new":false',                          'string', 'JSON-Schema snippet inserted when pool is empty — Claude must always return null'),

	('worker.heartbeat_threshold_seconds', '300', 'int', 'Seconds without heartbeat before the add-in shows "Worker offline"'),
	('autosort.retry_cap',                 '3',   'int', 'Max failed move attempts before a mail is permanently skipped'),
	('topics.fuzzy_merge_levenshtein_max', '3',   'int', 'Levenshtein-distance threshold for fuzzy-merging a proposed topic onto an existing one'),

	('folder_default.direct',     'MailPilot/Direct',     'string', 'Default folder for label=direct catch-all rule'),
	('folder_default.action',     'MailPilot/Aktion',     'string', 'Default folder for label=action catch-all rule'),
	('folder_default.cc',         'MailPilot/CC',         'string', 'Default folder for label=cc catch-all rule'),
	('folder_default.newsletter', 'MailPilot/Newsletter', 'string', 'Default folder for label=newsletter catch-all rule'),
	('folder_default.auto',       'MailPilot/Auto',       'string', 'Default folder for label=auto catch-all rule'),
	('folder_default.noise',      'MailPilot/Noise',      'string', 'Default folder for label=noise catch-all rule');
