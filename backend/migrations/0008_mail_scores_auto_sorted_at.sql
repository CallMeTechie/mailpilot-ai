-- 0008_mail_scores_auto_sorted_at.sql
--
-- Track whether a scored mail has already been moved by AutoSort.
-- The worker's background sweep uses this to find scored-but-not-yet-
-- moved mails and back-fill them — covers the "I enabled the rule
-- after these mails arrived" case without the user having to press
-- "Regeln jetzt anwenden".
--
-- NULL = never moved. Set by AutoSortService::applyToScoredMail on
-- a successful Graph move. Survives rescore.

ALTER TABLE mail_scores
	ADD COLUMN auto_sorted_at DATETIME(3) DEFAULT NULL AFTER user_corrected_at,
	ADD INDEX idx_unsorted (auto_sorted_at, label);
