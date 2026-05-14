-- 0024_autosort_default_auto.sql
--
-- Sprint 6e Hotfix: Default-Wechsel der AutoSort-Modi von 'suggest' auf 'auto'.
--
-- Hintergrund: Marc hat 22 AutoSort-Rules aktiv, aber neue Mails landeten
-- nur in der Pending-Queue statt direkt im richtigen Ordner. Ursache:
-- 0018 seedete 'suggest' als Vorsicht-Default (PRD §3 DA-Finding 3),
-- aber für den Solo-User-Fall ist 'auto' die intuitive Erwartung —
-- „neue Mail kommt rein, MailPilot sortiert sie sofort, ich lese nur
-- noch wichtige Dinge".
--
-- Idempotent: nur Rows updaten die noch auf dem seed-Default 'suggest'
-- stehen. User die via Admin-UI bewusst auf 'off' gestellt haben, bleiben
-- unangetastet. Wer schon auf 'auto' steht, ist no-op.

UPDATE system_settings
SET `value` = 'auto'
WHERE `key` IN ('autosort_move_mode', 'autosort_create_topic_mode')
  AND `value` = 'suggest';
