// ============================================================
// Long-Op Lock & Progress-Modal
// ============================================================
// Globaler Busy-State. Setzt UI-Knöpfe disabled, damit der User
// nicht „Mails neu klassifizieren" und „Regeln jetzt anwenden"
// parallel anwirft (beide würden auf denselben Score-Daten arbeiten
// und sich gegenseitig die Cursor stehlen).
let mpBusy = null; // null | 'rescore' | 'autosort' | 'canceling'

function setBusy(kind) {
	mpBusy = kind;
	refreshButtonStates();
}

function clearBusy() {
	mpBusy = null;
	refreshButtonStates();
}

function refreshButtonStates() {
	const locked = mpBusy !== null && mpBusy !== 'canceling';
	['btn-rescore-all', 'btn-apply-autosort-now'].forEach((id) => {
		const el = document.getElementById(id);
		if (el) el.disabled = locked;
	});
}

/**
 * Promise-basiertes Progress-Modal. Returns a controller with
 *   update({ done, total, status })  → live updates
 *   close()                            → success path
 *   waitForCancel()                    → Promise that resolves on Cancel-click
 */
function mpProgress(opts) {
	const overlay = document.getElementById('mp-progress-overlay');
	const titleEl = document.getElementById('mp-progress-title');
	const statusEl = document.getElementById('mp-progress-status');
	const fillEl = document.getElementById('mp-progress-fill');
	const cancelBtn = document.getElementById('mp-progress-cancel');
	if (!overlay || !titleEl || !statusEl || !fillEl || !cancelBtn) {
		return { update: () => {}, close: () => {}, waitForCancel: () => new Promise(() => {}) };
	}

	titleEl.textContent = opts.title || '';
	statusEl.textContent = opts.status || '';
	fillEl.style.width = '0%';
	overlay.dataset.hidden = 'false';

	let cancelResolve = null;
	const cancelPromise = new Promise((res) => { cancelResolve = res; });
	const onCancel = () => {
		cancelBtn.disabled = true;
		cancelBtn.textContent = 'Wird abgebrochen ...';
		if (cancelResolve) cancelResolve();
	};
	cancelBtn.disabled = false;
	cancelBtn.textContent = opts.cancelLabel || 'Abbrechen';
	cancelBtn.addEventListener('click', onCancel);

	return {
		update({ done, total, status }) {
			if (typeof total === 'number' && total > 0 && typeof done === 'number') {
				const pct = Math.max(0, Math.min(100, Math.round((done / total) * 100)));
				fillEl.style.width = pct + '%';
			}
			if (status !== undefined) statusEl.textContent = status;
		},
		close() {
			overlay.dataset.hidden = 'true';
			cancelBtn.removeEventListener('click', onCancel);
		},
		waitForCancel: () => cancelPromise,
	};
}

