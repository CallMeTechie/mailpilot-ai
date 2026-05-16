// ============================================================
// Modal confirm — replaces native confirm() because Office task
// panes (especially in Outlook on the web and on iOS) silently
// suppress synchronous dialogs that would block the host. Returns
// a Promise<boolean>: true on OK, false on Cancel/Esc/overlay-click.
// ============================================================
/**
 * @param {{title: string, body: string, okLabel?: string, cancelLabel?: string, danger?: boolean}} opts
 * @returns {Promise<boolean>}
 */
function mpConfirm(opts) {
	return new Promise((resolve) => {
		const overlay = document.getElementById('mp-modal-overlay');
		const titleEl = document.getElementById('mp-modal-title');
		const bodyEl  = document.getElementById('mp-modal-body');
		const okBtn   = document.getElementById('mp-modal-ok');
		const cancelBtn = document.getElementById('mp-modal-cancel');
		if (!overlay || !titleEl || !bodyEl || !okBtn || !cancelBtn) {
			// Last-resort fallback — keeps the flow alive on a half-broken DOM.
			resolve(window.confirm(opts.title + '\n\n' + opts.body));
			return;
		}

		titleEl.textContent = opts.title || '';
		bodyEl.textContent  = opts.body  || '';
		okBtn.textContent     = opts.okLabel     || 'OK';
		cancelBtn.textContent = opts.cancelLabel || 'Abbrechen';
		okBtn.classList.toggle('mp-btn-danger',  !!opts.danger);
		okBtn.classList.toggle('mp-btn-primary', !opts.danger);
		overlay.dataset.hidden = 'false';

		const cleanup = (result) => {
			overlay.dataset.hidden = 'true';
			okBtn.removeEventListener('click', onOk);
			cancelBtn.removeEventListener('click', onCancel);
			overlay.removeEventListener('click', onOverlay);
			document.removeEventListener('keydown', onKey);
			resolve(result);
		};
		const onOk      = () => cleanup(true);
		const onCancel  = () => cleanup(false);
		const onOverlay = (e) => { if (e.target === overlay) cleanup(false); };
		const onKey     = (e) => {
			if (e.key === 'Escape') cleanup(false);
			else if (e.key === 'Enter') cleanup(true);
		};
		okBtn.addEventListener('click', onOk);
		cancelBtn.addEventListener('click', onCancel);
		overlay.addEventListener('click', onOverlay);
		document.addEventListener('keydown', onKey);
		okBtn.focus();
	});
}

function escape(s) {
	return String(s ?? '').replace(/[&<>"']/g, (c) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
	}[c]));
}

function labelText(label) {
	return {
		direct: 'Direkt',
		action: 'Aktion',
		cc: 'CC',
		newsletter: 'Newsletter',
		auto: 'Auto',
		noise: 'Noise',
	}[label] ?? '—';
}

/**
 * Öffnet eine Mail in Outlook (separates Lese-Fenster).
 *
 * Microsoft Graph kann Message-IDs in zwei Formaten zurückgeben:
 *   - AAMk… = REST Item ID (klassisch)
 *   - AQMk… = Immutable Item ID (default seit Graph v1.0 für viele Mailboxen)
 * Beide werden von displayMessageFormAsync akzeptiert. Marc's gesamte
 * Inbox hat AQMk-IDs — der frühere AAMk-only-Filter ließ jede „Öffnen"-
 * Aktion in den „Mail-ID nicht verfügbar"-Toast laufen.
 *
 * Bei wirklich unbrauchbarem Parameter (interne UUID, leerer String)
 * failed displayMessageFormAsync silent — daher Callback + Toast,
 * sonst denkt der User „Button macht nichts".
 */
function openMailInOutlook(mailId) {
	if (!mailId || typeof mailId !== 'string' || !/^(AAMk|AQMk)/.test(mailId)) {
		setStatus('Mail-ID nicht verfügbar — vielleicht zu alt oder noch nicht synchronisiert.');
		return;
	}
	const api = Office?.context?.mailbox?.displayMessageFormAsync;
	if (typeof api !== 'function') {
		setStatus('Dieser Outlook-Build kann externe Mails nicht öffnen. Bitte direkt in Outlook anklicken.');
		return;
	}
	api.call(Office.context.mailbox, mailId, (res) => {
		if (res?.status === Office.AsyncResultStatus?.Failed) {
			const msg = res.error?.message || 'unbekannt';
			// Marc's Inbox hat AQMk-IDs (nicht stabil über Moves). Eine Mail
			// die vor dem ms-id-refresh-Fix verschoben wurde, hat in der DB
			// noch die alte ID — Graph antwortet ErrorItemNotFound. Klare
			// Botschaft statt MS-Originaltext.
			if (/notfound|nicht vorhanden|nicht erstellt/i.test(msg)) {
				setStatus('Mail liegt nicht mehr unter dieser ID — vermutlich von MailPilot verschoben. In Outlook bitte nach dem Betreff suchen.');
			} else {
				setStatus(`Konnte Mail nicht öffnen: ${msg}`);
			}
		}
	});
}
