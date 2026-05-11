-- 0004_oauth_states_jwt_handoff.sql
--
-- Office Add-in auth ran into storage partitioning: the OAuth dialog window
-- and the taskpane iframe live in different top-level browsing contexts,
-- so localStorage / postMessage / sessionStorage cannot reliably hand the
-- JWT over from the dialog to the taskpane.
--
-- Fix: keep the JWT server-side briefly after the OAuth callback. The
-- taskpane polls /api/v1/auth/oauth/exchange?state=… (a same-context HTTP
-- request, immune to storage partitioning) and picks the token up there.

ALTER TABLE oauth_states
	ADD COLUMN jwt TEXT NULL AFTER code_verifier,
	ADD COLUMN jwt_expires_at DATETIME(3) NULL AFTER jwt;
