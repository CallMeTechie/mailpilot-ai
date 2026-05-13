-- 0010_mail_scores_auto_sort_attempts.sql
--
-- Adds an attempt counter so applyAutoSortNow can stop hammering
-- mails that consistently fail to move (Graph 401, folder gone,
-- permission denied). Without this, a single broken access-token
-- caused the frontend loop to bounce the same 5-10 mails through
-- 40 iterations until the 2500-mail safety cap kicked in.
--
-- Semantics:
--   0..2 → still eligible for retry
--   3+   → permanently skipped; the worker sets auto_sorted_at = NOW()
--          plus last_error = 'failed_after_3_tries' so the row exits
--          the candidate set the same way successful moves do.
--
-- "Mails neu klassifizieren" resets attempts to 0 for non-corrected
-- scores so the user can recover after a token rotation.

ALTER TABLE mail_scores
	ADD COLUMN auto_sort_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER auto_sorted_at,
	ADD INDEX idx_autosort_eligible (auto_sorted_at, auto_sort_attempts);
