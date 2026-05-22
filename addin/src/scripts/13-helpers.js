// ============================================================
// Helpers
// ============================================================
function toggle(id, visible) {
	const el = document.getElementById(id);
	if (el) el.dataset.hidden = visible ? 'false' : 'true';
}

function setStatus(text) {
	document.getElementById('status-text').textContent = text;
}

function showError(msg) {
	setStatus(`⚠ ${msg}`);
}

function handleError(err) {
	console.error(err);
	if (err instanceof ApiError && err.code === 'BUDGET_EXCEEDED') {
		showToast(`Tageslimit erreicht – ${err.message}`, 'warn', 8000);
		return;
	}
	// Phase 9p-Hotfix (Marc 2026-05-22): Anthropic-Auslastung (Backend
	// liefert 503 + AI_OVERLOADED). Freundlicher Toast statt rotem Error,
	// damit Marc weiss dass das transient ist und gleich nochmal klappen sollte.
	if (err instanceof ApiError && err.code === 'AI_OVERLOADED') {
		showToast('KI-Anbieter überlastet — bitte gleich noch einmal versuchen.', 'warn', 6000);
		return;
	}
	const msg = err instanceof ApiError ? err.message : 'Unerwarteter Fehler';
	showToast(msg, 'error', 6000);
}

