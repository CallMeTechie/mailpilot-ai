/**
 * MailPilot Task Pane — main controller.
 * Listens to Office.js events, drives the three panels.
 */

import { api, setToken, clearToken, ApiError, startTokenRefreshLoop, stopTokenRefreshLoop } from './api.js';

// ============================================================
// State
// ============================================================
const state = {
	currentMailId: null,
	currentMailData: null,
	briefingLoaded: false,
};

/**
 * Clear every UI element that referenced the previously-focused mail
 * — score badges, summary, action banner, drafted reply, plus the
 * state.currentMailData reference itself so any in-flight handler
 * (summarizeCurrent / draftCurrent / rescoreCurrent) that fires
 * between mail-switch and re-render bails out instead of pinning the
 * old mail's id to the new mail's UI.
 *
 * Does NOT touch the header (current-subject / current-from) — the
 * caller updates those from Office.context.mailbox.item right after.
 * Does NOT touch toasts — those are global and should outlive a
 * mail switch.
 */
function resetCurrentMailUi() {
	state.currentMailData = null;

	// Score block
	toggle('current-content', false);
	toggle('current-action-section', false);
	const badge = document.getElementById('current-badge');
	if (badge) {
		badge.textContent = '—';
		badge.dataset.label = '';
		toggle('current-badge', false);
	}
	const prio = document.getElementById('current-priority');
	if (prio) prio.textContent = '';
	const action = document.getElementById('current-action');
	if (action) action.textContent = '';
	const summary = document.getElementById('current-summary');
	if (summary) {
		summary.textContent = '—';
		delete summary.dataset.detailed;
	}

	// Draft block
	toggle('draft-section', false);
	const draftText = document.getElementById('draft-text');
	if (draftText) draftText.value = '';

	// Correct block
	toggle('correct-section', false);
	const reason = document.getElementById('correct-reasoning');
	if (reason) reason.value = '';
}

// Phase 9p (Marc 2026-05-22): BULK_ACTIONS / LABEL_META / buildIconElement
// entfernt — sie wurden ausschliesslich von der alten Counter-Karten-Filter-
// View benutzt, die mit der Sender-Folder-Architektur (Phase 9m) obsolet
// wurde.

