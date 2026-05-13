/**
 * MailPilot Task Pane — main controller.
 * Listens to Office.js events, drives the three panels.
 */

import { api, setToken, clearToken, ApiError } from './api.js';

// ============================================================
// State
// ============================================================
const state = {
	currentMailId: null,
	currentMailData: null,
	briefingLoaded: false,
	filterLabel: null,
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

// Available bulk actions. `confirm` requires a user confirm() before running.
// `icon` is an inline SVG (feather-style stroke) shown on the icon button.
const BULK_ACTIONS = {
	'mark-read': {
		label: 'Alle als gelesen markieren',
		icon:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7l8 6 8-6"/><rect x="3" y="5" width="18" height="14" rx="2"/><polyline points="9 13 11 15 15 11"/></svg>',
		confirm: false,
		kind: 'secondary',
	},
	'archive': {
		label: 'Alle archivieren',
		icon:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="5" rx="1"/><path d="M4 9v11h16V9"/><path d="M10 13h4"/></svg>',
		confirm: true,
		kind: 'secondary',
	},
	'delete': {
		label: 'Alle löschen (Papierkorb)',
		icon:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14H7L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>',
		confirm: true,
		kind: 'danger',
	},
	'hide': {
		label: 'Aus MailPilot ausblenden',
		icon:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s4-7 10-7c2 0 3.6.6 5 1.5"/><path d="M22 12s-4 7-10 7c-2 0-3.6-.6-5-1.5"/><path d="M3 3l18 18"/></svg>',
		confirm: false,
		kind: 'ghost',
	},
};

function buildIconElement(svgString) {
	// SVG content is static (defined in BULK_ACTIONS, not user input).
	// DOMParser('image/svg+xml') is flaky in some Office WebView2
	// contexts — <template> parsing is reliable across hosts.
	const tpl = document.createElement('template');
	tpl.innerHTML = svgString.trim();
	return tpl.content.firstElementChild;
}

// Label-specific hint copy + which bulk actions to show in the filter view.
// Direct/Action mails are intentionally action-free here — too important for
// bulk operations; user opens them individually.
const LABEL_META = {
	direct: {
		title: 'Direkt',
		hint:  'Mails, in denen du persönlich angesprochen wirst — meist heute lesen.',
		actions: [],
	},
	action: {
		title: 'Aktion erforderlich',
		hint:  'Diese Mails erwarten eine Antwort oder Entscheidung von dir.',
		actions: [],
	},
	cc: {
		title: 'CC',
		hint:  'Du bist nur informativ im Verteiler — meist überfliegbar.',
		actions: ['mark-read'],
	},
	newsletter: {
		title: 'Newsletter',
		hint:  'Abonnierter Marketing-Content.',
		actions: ['mark-read', 'archive', 'hide'],
	},
	auto: {
		title: 'Auto',
		hint:  'Maschinell generierte Mails (CI, Monitoring, Rechnungen). Auf Fehler-Alerts achten.',
		actions: ['mark-read', 'archive', 'hide'],
	},
	noise: {
		title: 'Noise',
		hint:  'Wahrscheinlich irrelevant oder Spam.',
		actions: ['delete', 'hide'],
	},
};

// ============================================================
// Office.js init
// ============================================================
Office.onReady((info) => {
	if (info.host !== Office.HostType.Outlook) {
		showError('MailPilot läuft nur in Outlook.');
		return;
	}

	// Pick up a token left behind by a recent auth-complete popup. localStorage
	// survives across the popup → taskpane handoff even when window.opener is
	// stripped by Cross-Origin-Opener-Policy.
	consumeHandoff();

	initTabs();
	initBriefing();
	initCurrentMail();
	initSettings();
	startAutoRefresh();

	// Path 1: postMessage (works when window.opener survived).
	window.addEventListener('message', (event) => {
		if (event.data?.type === 'mp-auth-complete' && event.data.token) {
			localStorage.setItem('mp_jwt', event.data.token);
			setStatus('Angemeldet — lade Briefing…');
			loadBriefing();
		}
	});

	// Path 2: storage event from auth-complete.html running in another tab.
	window.addEventListener('storage', (event) => {
		if (event.key === 'mp_jwt_handoff' && event.newValue) {
			consumeHandoff();
			setStatus('Angemeldet — lade Briefing…');
			loadBriefing();
		}
	});

	// React to mail selection changes in Outlook
	Office.context.mailbox.addHandlerAsync(
		Office.EventType.ItemChanged,
		onItemChanged,
	);

	onItemChanged();
});

// ============================================================
// Auth handoff — drain any token written by auth-complete.html
// ============================================================
function consumeHandoff() {
	const raw = localStorage.getItem('mp_jwt_handoff');
	if (!raw) return;
	try {
		const { token, ts } = JSON.parse(raw);
		if (token && typeof ts === 'number' && Date.now() - ts < 5 * 60 * 1000) {
			localStorage.setItem('mp_jwt', token);
		}
	} catch (e) { /* ignore malformed handoff */ }
	localStorage.removeItem('mp_jwt_handoff');
}

// ============================================================
// Tabs
// ============================================================
function initTabs() {
	document.querySelectorAll('.mp-tab').forEach((tab) => {
		tab.addEventListener('click', () => {
			const name = tab.dataset.tab;
			document.querySelectorAll('.mp-tab').forEach(t => t.classList.remove('is-active'));
			document.querySelectorAll('.mp-panel').forEach(p => p.classList.remove('is-active'));
			tab.classList.add('is-active');
			document.querySelector(`.mp-panel[data-panel="${name}"]`).classList.add('is-active');

			if (name === 'briefing') {
				// Always refresh when the user opens the briefing tab — the
				// counts can change anytime as the worker keeps scoring.
				loadBriefing();
			}
			if (name === 'current') {
				// Re-evaluate the currently-open mail when user opens this tab.
				onItemChanged();
			}
			if (name === 'settings') {
				loadSettings();
			}
		});
	});
}

// ============================================================
// Briefing
// ============================================================
function initBriefing() {
	document.getElementById('btn-sync').addEventListener('click', async () => {
		const btn = document.getElementById('btn-sync');
		btn.classList.add('is-spinning');
		setStatus('Sync gestartet…');
		toggle('sync-progress', true);
		setSyncProgress(0, 0);
		const stop = () => btn.classList.remove('is-spinning');
		try {
			const res = await api.sync.trigger();
			const jobId = Array.isArray(res?.job_ids) ? res.job_ids[0] : null;
			if (!jobId) {
				toggle('sync-progress', false);
				await loadBriefing();
				setStatus('Bereit');
				stop();
				return;
			}
			pollSyncStatus(jobId, stop);
		} catch (err) {
			toggle('sync-progress', false);
			stop();
			handleError(err);
		}
	});

	// Counter cards: click → open filtered list for that label
	document.querySelectorAll('.mp-counter[data-label]').forEach((card) => {
		card.addEventListener('click', () => {
			openFilteredList(card.dataset.label);
		});
	});

	// Back button from the filter view
	document.getElementById('btn-back-summary').addEventListener('click', closeFilteredList);

	document.getElementById('btn-connect').addEventListener('click', async () => {
		try {
			const { auth_url } = await api.auth.oauthStart();
			// Pull the state UUID out of the Microsoft authorize URL —
			// backend uses it as the handoff key for /auth/oauth/exchange.
			const state = new URL(auth_url).searchParams.get('state');
			if (!state) {
				showError('OAuth-State fehlt — Login abgebrochen.');
				return;
			}

			// Office Dialog API — required from inside Office add-in iframes
			// (window.open is blocked by the sandboxed iframe context).
			// Initial URL must live inside the add-in's AppDomain, so we go
			// through the wrapper page which window.location.replace()s to
			// the Microsoft authorize URL.
			//
			// The taskpane polls /auth/oauth/exchange independently — the
			// JWT handoff does not depend on the dialog closing. Even if the
			// dialog stays open after success (pinning-related Office bug),
			// the briefing loads inside the taskpane.
			const wrapper = `${window.location.origin}/addin/auth-redirect.html?ms=${encodeURIComponent(auth_url)}`;
			Office.context.ui.displayDialogAsync(
				wrapper,
				{ width: 30, height: 50, displayInIframe: false },
				(result) => {
					if (result.status !== Office.AsyncResultStatus.Succeeded) {
						showError('Login-Dialog konnte nicht geöffnet werden: ' + result.error.message);
						return;
					}
					const dialog = result.value;

					const startedAt = Date.now();
					const pollInterval = setInterval(async () => {
						if (Date.now() - startedAt > 5 * 60 * 1000) {
							clearInterval(pollInterval);
							return;
						}
						try {
							const res = await api.auth.exchange(state);
							if (res && res.token) {
								clearInterval(pollInterval);
								localStorage.setItem('mp_jwt', res.token);
								setStatus('Angemeldet — lade Briefing…');
								try { dialog.close(); } catch (e) { /* ignore */ }
								loadBriefing();
							}
							// 204 → request() returns null; loop continues.
						} catch (_) { /* transient — retry */ }
					}, 1000);

					dialog.addEventHandler(Office.EventType.DialogEventReceived, () => {
						clearInterval(pollInterval);
						if (localStorage.getItem('mp_jwt') && !state.briefingLoaded) {
							loadBriefing();
						}
					});
				},
			);
		} catch (err) {
			handleError(err);
		}
	});

	loadBriefing();
}

async function loadBriefing() {
	toggle('briefing-loader', true);
	toggle('briefing-content', false);
	toggle('briefing-empty', false);

	try {
		const data = await api.briefing.today();
		renderBriefing(data);
		toggle('briefing-content', true);
		state.briefingLoaded = true;
		setStatus('Bereit');
	} catch (err) {
		if (err instanceof ApiError && err.code === 'MAILBOX_NOT_CONNECTED') {
			toggle('briefing-empty', true);
		} else if (err instanceof ApiError && err.status === 401) {
			// api.js already cleared the dead token. Show the login button
			// and a discreet status hint so the user knows why.
			toggle('briefing-empty', true);
			setStatus('Bitte erneut anmelden');
		} else {
			handleError(err);
		}
	} finally {
		toggle('briefing-loader', false);
	}
}

// ============================================================
// Sync progress — runs while a sync job is in flight. Polls
// /api/v1/sync/status/<id> every 2s, updates the bar, and reloads
// the briefing when the worker finishes.
// ============================================================
function setSyncProgress(processed, total) {
	const fill = document.getElementById('sync-progress-fill');
	const text = document.getElementById('sync-progress-text');
	if (!fill || !text) return;
	const p = Math.max(0, Number(processed) || 0);
	const t = Math.max(0, Number(total) || 0);
	// Worker uses (0, 1) as the "fetching delta" sentinel — render that
	// as the prep label, not a misleading "0 / 1".
	const isPrep = p === 0 && t <= 1;
	const pct = t > 0 ? Math.min(100, (p / t) * 100) : 0;
	fill.style.width = pct.toFixed(1) + '%';
	text.textContent = isPrep ? 'wird vorbereitet…' : `${p} / ${t}`;
}

function pollSyncStatus(jobId, onDone) {
	const startedAt = Date.now();
	const POLL_MS = 2000;
	const MAX_MS = 10 * 60 * 1000;
	const finish = () => { if (typeof onDone === 'function') onDone(); };

	const timer = setInterval(async () => {
		if (Date.now() - startedAt > MAX_MS) {
			clearInterval(timer);
			toggle('sync-progress', false);
			showToast('Sync läuft länger als 10 min — bitte später erneut prüfen.', 'info', 4000);
			finish();
			return;
		}
		try {
			const status = await api.sync.status(jobId);
			setSyncProgress(status.processed, status.total);
			if (status.status === 'done') {
				clearInterval(timer);
				toggle('sync-progress', false);
				showToast('Sync abgeschlossen', 'success', 2000);
				await loadBriefing();
				setStatus('Bereit');
				finish();
			} else if (status.status === 'error') {
				clearInterval(timer);
				toggle('sync-progress', false);
				showToast('Sync fehlgeschlagen: ' + (status.error_text || 'unbekannt'), 'error', 6000);
				setStatus('Sync-Fehler');
				finish();
			}
		} catch (_) { /* transient — retry */ }
	}, POLL_MS);
}

// ============================================================
// Auto-refresh — quietly reload briefing every 60 s while the user
// is sitting on the briefing summary view. Pauses during filter view
// and when the document is hidden, so we don't burn API calls.
// ============================================================
let autoRefreshTimer = null;

function startAutoRefresh() {
	if (autoRefreshTimer !== null) return;
	autoRefreshTimer = setInterval(() => {
		if (document.hidden) return;
		if (state.filterLabel !== null) return;
		const activeTab = document.querySelector('.mp-tab.is-active')?.dataset.tab;
		if (activeTab !== 'briefing') return;
		if (!localStorage.getItem('mp_jwt')) return;
		loadBriefing();
	}, 60 * 1000);
}

// ============================================================
// Filter view: click on a counter card → list of mails in that label
// ============================================================
async function openFilteredList(label) {
	const meta = LABEL_META[label];
	if (!meta) return;

	state.filterLabel = label;
	document.getElementById('briefing-summary').dataset.hidden = 'true';
	document.getElementById('briefing-filtered').dataset.hidden = 'false';
	document.getElementById('filter-title').textContent = meta.title;
	document.getElementById('filter-hint').textContent  = meta.hint;
	const listEl = document.getElementById('filter-mail-list');
	listEl.replaceChildren();
	document.getElementById('filter-empty').dataset.hidden = 'true';

	// Render bulk-action icon buttons for this label.
	const bulkContainer = document.getElementById('filter-bulk-actions');
	bulkContainer.replaceChildren();
	(meta.actions ?? []).forEach((action) => {
		const spec = BULK_ACTIONS[action];
		if (!spec) return;
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = `mp-icon-btn mp-icon-btn-${spec.kind}`;
		btn.setAttribute('aria-label', spec.label);
		btn.title = spec.label;
		btn.dataset.action = action;
		btn.appendChild(buildIconElement(spec.icon));
		btn.addEventListener('click', () => runBulkAction(action, label));
		bulkContainer.appendChild(btn);
	});

	await reloadFilterList(label);
}

async function reloadFilterList(label) {
	const listEl = document.getElementById('filter-mail-list');
	listEl.replaceChildren();
	document.getElementById('filter-empty').dataset.hidden = 'true';
	const sinceUtc = filterSinceUtc();
	try {
		const res = await api.mails.list(`?label=${encodeURIComponent(label)}&since=${encodeURIComponent(sinceUtc)}&limit=50`);
		const items = res.items ?? [];
		if (items.length === 0) {
			document.getElementById('filter-empty').dataset.hidden = 'false';
			return;
		}
		items.forEach((m) => listEl.appendChild(buildMailListItem(m, label)));
	} catch (err) {
		handleError(err);
	}
}

function filterSinceUtc() {
	return new Date(Date.now() - 7 * 86400 * 1000).toISOString().replace('T', ' ').replace('Z', '');
}

async function runBulkAction(action, label) {
	const spec = BULK_ACTIONS[action];
	if (!spec) return;
	if (spec.confirm && !window.confirm(`${spec.label} — wirklich für alle gefilterten Mails ausführen?`)) {
		return;
	}

	// Lock all bulk buttons + show progress immediately so the user sees
	// that the click registered. Per-Mail Graph calls take ~200 ms each;
	// a batch of 30+ mails can run several seconds.
	const bulkButtons = document.querySelectorAll('#filter-bulk-actions .mp-icon-btn');
	bulkButtons.forEach((b) => {
		b.disabled = true;
		b.classList.add('is-running');
	});
	const visibleCount = document.getElementById('filter-mail-list').children.length;
	const startToastId = showToast(
		visibleCount > 0
			? `${spec.label} — ${visibleCount} Mails werden verarbeitet…`
			: `${spec.label}…`,
		'info',
		20000,
	);
	setStatus(`${spec.label}…`);

	const sinceUtc = filterSinceUtc();
	try {
		const res = await api.mails.bulkAction(action, label, sinceUtc);
		dismissToast(startToastId);
		const failed = res?.failed?.length ?? 0;
		const ok = res?.processed ?? 0;
		if (failed > 0) {
			showToast(`${ok} verarbeitet, ${failed} fehlgeschlagen`, 'error', 5000);
		} else {
			showToast(`${ok} Mails verarbeitet`, 'success', 3000);
		}
		await reloadFilterList(label);
		state.briefingLoaded = false;
	} catch (err) {
		dismissToast(startToastId);
		handleError(err);
	} finally {
		bulkButtons.forEach((b) => {
			b.disabled = false;
			b.classList.remove('is-running');
		});
		setStatus('Bereit');
	}
}

function buildMailListItem(m, fallbackLabel) {
	const score = m.score ?? {};
	const li = document.createElement('li');
	li.className = 'mp-mail-item';

	const top = document.createElement('div');
	top.className = 'mp-mail-top';
	const badge = document.createElement('span');
	badge.className = 'mp-badge';
	badge.dataset.label = score.label ?? fallbackLabel ?? 'auto';
	badge.textContent = labelText(score.label ?? fallbackLabel);
	const prio = document.createElement('span');
	prio.className = 'mp-priority';
	prio.textContent = `Priorität ${score.priority ?? '—'}`;
	top.append(badge, prio);

	const from = document.createElement('div');
	from.className = 'mp-mail-from';
	from.textContent = m.from_name || m.from_email || '—';

	const subj = document.createElement('div');
	subj.className = 'mp-mail-subject';
	subj.textContent = m.subject ?? '—';

	const summary = document.createElement('div');
	summary.className = 'mp-mail-summary';
	summary.textContent = score.summary ?? '';

	li.append(top, from, subj, summary);
	li.addEventListener('click', () => openMailInOutlook(m.ms_message_id || m.id));
	return li;
}

function closeFilteredList() {
	state.filterLabel = null;
	document.getElementById('briefing-filtered').dataset.hidden = 'true';
	document.getElementById('briefing-summary').dataset.hidden = 'false';
}

function renderBriefing(data) {
	const c = data.counters ?? {};
	document.getElementById('c-direct').textContent     = c.direct ?? 0;
	document.getElementById('c-action').textContent     = c.action ?? 0;
	document.getElementById('c-cc').textContent         = c.cc ?? 0;
	document.getElementById('c-newsletter').textContent = c.newsletter ?? 0;
	document.getElementById('c-auto').textContent       = c.auto ?? 0;
	document.getElementById('c-noise').textContent      = c.noise ?? 0;

	renderFooterStats(data);

	const total = Object.values(c).reduce((a, b) => a + b, 0);
	document.getElementById('briefing-subtitle').textContent =
		`${total} neue Mails · ${c.direct ?? 0} direkt · ${c.action ?? 0} mit Aktion`;

	const list = document.getElementById('top-priority-list');
	list.innerHTML = '';
	(data.top_priority ?? []).forEach((m) => {
		const li = document.createElement('li');
		li.className = 'mp-mail-item';
		li.innerHTML = `
			<div class="mp-mail-top">
				<span class="mp-badge" data-label="${escape(m.label)}">${labelText(m.label)}</span>
				<span class="mp-priority">Priorität ${m.priority}</span>
			</div>
			<div class="mp-mail-from">${escape(m.from_name || m.from_email)}</div>
			<div class="mp-mail-subject">${escape(m.subject)}</div>
			<div class="mp-mail-summary">${escape(m.summary ?? '')}</div>
		`;
		// Outlook needs the Graph REST id (AQMk…), not our internal UUID.
		// topPrioritySince now ships ms_message_id; mail_id is kept as
		// fallback only for older payloads.
		li.addEventListener('click', () => openMailInOutlook(m.ms_message_id || m.mail_id));
		list.appendChild(li);
	});
}

// ============================================================
// Current mail (from Outlook reading pane)
// ============================================================
function initCurrentMail() {
	document.getElementById('btn-summarize').addEventListener('click', summarizeCurrent);
	document.getElementById('btn-draft').addEventListener('click',     draftCurrent);
	document.getElementById('btn-rescore').addEventListener('click',   rescoreCurrent);
	document.getElementById('btn-use-draft').addEventListener('click', useDraft);
	document.getElementById('btn-regenerate').addEventListener('click', draftCurrent);
	document.getElementById('btn-correct')?.addEventListener('click',  openCorrectForm);
	document.getElementById('btn-save-correction')?.addEventListener('click', saveCorrection);
	document.getElementById('btn-cancel-correction')?.addEventListener('click', () => toggle('correct-section', false));
}

function openCorrectForm() {
	if (!state.currentMailData) return;
	const score = state.currentMailData.score ?? {};
	document.getElementById('correct-label').value     = score.label    ?? 'auto';
	document.getElementById('correct-priority').value  = String(score.priority ?? 3);
	document.getElementById('correct-action-required').checked = !!score.action_required;
	document.getElementById('correct-reasoning').value = '';
	toggle('correct-section', true);
	document.getElementById('correct-reasoning').focus();
}

async function saveCorrection() {
	if (!state.currentMailData) return;
	const expectedMailId = state.currentMailId;
	const mailDbId = state.currentMailData.id;
	const payload = {
		label:           document.getElementById('correct-label').value,
		priority:        parseInt(document.getElementById('correct-priority').value, 10),
		action_required: document.getElementById('correct-action-required').checked,
		reasoning:       document.getElementById('correct-reasoning').value.trim() || null,
	};
	setStatus('Korrektur speichern…');
	try {
		const res = await api.mails.correctScore(mailDbId, payload);
		if (state.currentMailId !== expectedMailId) return;
		// Apply locally without a round-trip — backend already wrote it.
		if (state.currentMailData) {
			state.currentMailData.score = {
				...(state.currentMailData.score ?? {}),
				label:           payload.label,
				priority:        payload.priority,
				action_required: payload.action_required,
			};
			renderCurrentMail(state.currentMailData);
		}
		toggle('correct-section', false);
		showToast('Klassifikation korrigiert — die KI lernt daraus.', 'success', 5000);
		setStatus('Bereit');
		// Counter cards may shift — reload briefing on next tab visit.
		state.briefingLoaded = false;
	} catch (err) {
		handleError(err);
	}
}

function onItemChanged() {
	const item = Office.context.mailbox.item;
	if (!item || !item.itemId) {
		state.currentMailId = null;
		resetCurrentMailUi();
		toggle('current-header', false);
		toggle('current-loader', false);
		toggle('current-empty', true);
		return;
	}

	// Office gives us an EWS-format itemId; the backend stores the
	// Microsoft Graph REST-ID. Convert client-side so the lookup matches.
	const msMessageId = Office.context.mailbox.convertToRestId(
		item.itemId,
		Office.MailboxEnums.RestVersion.v2_0,
	);
	state.currentMailId = msMessageId;

	document.getElementById('current-subject').textContent = item.subject ?? '—';
	document.getElementById('current-from').textContent =
		item.from ? `${item.from.displayName ?? ''} <${item.from.emailAddress ?? ''}>` : '—';
	toggle('current-header', true);
	toggle('current-empty', false);

	loadCurrentMailScore(msMessageId);
}

async function loadCurrentMailScore(msMessageId) {
	// Single synchronous call: backend fetches the mail directly from
	// Graph if missing, scores it inline, returns the row. No polling,
	// no delta-cursor roulette, no 60-second timeout window.
	resetCurrentMailUi();
	toggle('current-loader', true);

	try {
		const res = await api.mails.ensureScored(msMessageId);
		// User moved on while we were waiting → skip render.
		if (state.currentMailId !== msMessageId) return;

		if (res?.mail) {
			renderCurrentMail(res.mail);
			toggle('current-loader', false);
			toggle('current-content', true);
		} else {
			toggle('current-loader', false);
			showToast('Mail konnte nicht analysiert werden.', 'error', 7000);
		}
	} catch (err) {
		toggle('current-loader', false);
		if (err instanceof ApiError && err.code === 'BUDGET_EXCEEDED') {
			showToast('Tageslimit erreicht — heute keine neuen Analysen mehr.', 'warn', 9000);
			return;
		}
		if (err instanceof ApiError && err.status === 401) {
			return bounceToLogin();
		}
		if (err instanceof ApiError && err.code === 'NOT_FOUND') {
			showToast('Mail in Microsoft 365 nicht gefunden — eventuell gelöscht?', 'info', 7000);
			return;
		}
		handleError(err);
	}
}

function renderFooterStats(data) {
	const budget = data.budget;
	const budgetEl = document.getElementById('mp-budget');
	if (budgetEl && budget && budget.user_limit > 0) {
		const used  = compactNumber(budget.user_used);
		const limit = compactNumber(budget.user_limit);
		const pct   = budget.percent;
		budgetEl.textContent = `${used} / ${limit} Tokens (${pct}%)`;
		budgetEl.classList.remove('mp-budget-warn', 'mp-budget-crit');
		if (pct >= 100)      budgetEl.classList.add('mp-budget-crit');
		else if (pct >= 80)  budgetEl.classList.add('mp-budget-warn');
		toggle('mp-budget', true);
	}

	const worker = data.worker;
	const workerEl = document.getElementById('mp-worker');
	if (workerEl && worker) {
		workerEl.classList.remove('mp-worker-ok', 'mp-worker-stale');
		if (worker.healthy) {
			workerEl.textContent = '● Worker';
			workerEl.classList.add('mp-worker-ok');
			workerEl.title = 'Worker aktiv · letzter Heartbeat ' + (worker.last_seen || '?');
		} else {
			workerEl.textContent = '● Worker offline';
			workerEl.classList.add('mp-worker-stale');
			workerEl.title = 'Worker nicht erreichbar · letzter Heartbeat ' + (worker.last_seen || '?');
		}
		toggle('mp-worker', true);
	}
}

function compactNumber(n) {
	n = Number(n) || 0;
	if (n >= 1_000_000) return (n / 1_000_000).toFixed(1).replace('.0', '') + 'M';
	if (n >= 1_000)     return (n / 1_000).toFixed(1).replace('.0', '') + 'k';
	return String(n);
}

function handleCurrentMailError(err) {
	toggle('current-loader', false);
	if (err instanceof ApiError && err.status === 401) {
		return bounceToLogin();
	}
	handleError(err);
}

function bounceToLogin() {
	toggle('current-header', false);
	toggle('current-empty', true);
	document.querySelector('.mp-tab[data-tab="briefing"]')?.click();
}

function renderCurrentMail(row) {
	state.currentMailData = row;
	const score = row.score ?? {};
	const badge = document.getElementById('current-badge');
	badge.textContent = labelText(score.label);
	badge.dataset.label = score.label ?? 'auto';
	toggle('current-badge', true);
	document.getElementById('current-priority').textContent = `Priorität ${score.priority ?? '—'}`;
	// Don't clobber an already-fetched detailed summary on re-render.
	const summaryEl = document.getElementById('current-summary');
	if (summaryEl.dataset.detailed !== '1') {
		summaryEl.textContent = score.summary ?? '—';
	}

	if (score.action_required) {
		toggle('current-action-section', true);
		document.getElementById('current-action').textContent =
			'Dieser Absender erwartet eine Antwort oder Entscheidung von dir.';
	} else {
		toggle('current-action-section', false);
	}

	// Office InfoBar above the mail when this is a high-priority direct/
	// action mail. Visible even when the add-in pane is closed, and
	// MailPilot is the only one that can attach it. Key is unique per
	// add-in so it replaces (not stacks) on every re-render.
	maybeShowOutlookNotification(score);
}

function maybeShowOutlookNotification(score) {
	const item = Office.context.mailbox.item;
	const messages = item?.notificationMessages;
	if (!messages) return;
	const KEY = 'mp-priority';
	const isHigh = (score.label === 'direct' || score.label === 'action')
		&& (score.action_required === true || score.action_required === 1)
		&& (score.priority ?? 0) >= 4;
	if (!isHigh) {
		try { messages.removeAsync(KEY, () => {}); } catch (_) {}
		return;
	}
	const text = score.label === 'action'
		? `Wichtige Antwort/Aktion erforderlich (Priorität ${score.priority}).`
		: `Wichtige direkte Mail (Priorität ${score.priority}).`;
	try {
		messages.replaceAsync(KEY, {
			type:    Office.MailboxEnums.ItemNotificationMessageType.InformationalMessage,
			message: text.slice(0, 150),
			icon:    'icon-16',
			persistent: true,
		}, () => {});
	} catch (_) {}
}

async function summarizeCurrent() {
	if (!state.currentMailData) return;
	const expectedMailId = state.currentMailId;
	const mailDbId = state.currentMailData.id;
	setStatus('Zusammenfassung wird erstellt…');
	try {
		const res = await api.mails.summarize(mailDbId);
		// User moved on while Claude was thinking — don't paint a stale
		// summary onto the new mail's panel.
		if (state.currentMailId !== expectedMailId) return;
		const text = res?.summary;
		if (!text) {
			showToast('Backend lieferte keine Zusammenfassung.', 'error', 6000);
			setStatus('Bereit');
			return;
		}
		const el = document.getElementById('current-summary');
		el.textContent = text;
		el.dataset.detailed = '1';
		setStatus('Zusammenfassung fertig');
	} catch (err) {
		handleError(err);
	}
}

async function draftCurrent() {
	if (!state.currentMailData) return;
	const expectedMailId = state.currentMailId;
	const mailDbId = state.currentMailData.id;
	setStatus('Antwort wird entworfen…');
	try {
		const res = await api.mails.draftReply(mailDbId);
		if (state.currentMailId !== expectedMailId) return;
		document.getElementById('draft-text').value = res.draft ?? '';
		toggle('draft-section', true);
		setStatus('Entwurf bereit');
	} catch (err) {
		handleError(err);
	}
}

async function rescoreCurrent() {
	if (!state.currentMailData) return;
	const expectedMailId = state.currentMailId;
	const mailDbId = state.currentMailData.id;
	setStatus('Neu bewerten…');
	try {
		await api.mails.rescore(mailDbId);
		if (state.currentMailId !== expectedMailId) return;
		await loadCurrentMailScore(state.currentMailId);
		setStatus('Neu bewertet');
	} catch (err) {
		handleError(err);
	}
}

function useDraft() {
	const text = document.getElementById('draft-text').value;
	if (!text) return;
	// Outlook renders reply bodies as HTML; a plain string drops every
	// line break because HTML collapses consecutive whitespace. Convert
	// blank-line-separated blocks to <p>, single newlines to <br>, and
	// escape special characters so user-typed "<" doesn't break the
	// composed message.
	const esc = (s) => s
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;');
	const html = text
		.split(/\r?\n\s*\r?\n/)
		.map((p) => '<p>' + esc(p).replace(/\r?\n/g, '<br>') + '</p>')
		.join('');
	Office.context.mailbox.item.displayReplyForm({ htmlBody: html });
}

// ============================================================
// Settings
// ============================================================
// Cache of the user's sub-labels, grouped by primary. Populated by
// loadSettings(), consumed by the "add sub-rule" dropdown in the
// AutoSort section so the user can only pick a sub they actually
// created — no free-form typos that would never match a score.
/** @type {Record<string, Array<{id:string, name:string}>>} */
let subLabelsByParent = {};

// Last-loaded AutoSort rules, used by the sub-label-delete confirm
// to preview which sub-rules will cascade with it. Kept in sync by
// loadSettings + renderAutoSortRules.
/** @type {Array<{label:string, sub_label:?string, enabled:boolean, folder_name:string}>} */
let autoSortRulesCache = [];

function initSettings() {
	document.getElementById('btn-add-vip').addEventListener('click', async () => {
		const email = document.getElementById('vip-email').value.trim();
		if (!email) return;
		try {
			await api.settings.addVip(email, null);
			document.getElementById('vip-email').value = '';
			loadSettings();
		} catch (err) { handleError(err); }
	});

	document.getElementById('btn-add-red').addEventListener('click', async () => {
		const p = document.getElementById('red-pattern').value.trim();
		const d = document.getElementById('red-desc').value.trim();
		if (!p) return;
		try {
			await api.settings.addRedaction(p, d);
			document.getElementById('red-pattern').value = '';
			document.getElementById('red-desc').value = '';
			loadSettings();
		} catch (err) { handleError(err); }
	});

	document.getElementById('btn-add-sub')?.addEventListener('click', async () => {
		const parent = document.getElementById('sub-parent').value;
		const name   = document.getElementById('sub-name').value.trim();
		const desc   = document.getElementById('sub-desc').value.trim();
		if (!parent || !name) return;
		try {
			await api.settings.addSubLabel({ parent, name, description: desc || null });
			document.getElementById('sub-name').value = '';
			document.getElementById('sub-desc').value = '';
			loadSettings();
		} catch (err) { handleError(err); }
	});

	document.getElementById('sub-list')?.addEventListener('click', async (e) => {
		const btn = e.target.closest('button.remove');
		if (!btn) return;

		// Look the sub-label up in the cache so we can preview its name
		// and any AutoSort rules that will cascade with it.
		const id   = btn.dataset.id;
		const meta = findSubLabelMetaById(id);
		const deps = meta ? dependentRulesForSubLabel(meta.parent, meta.name) : [];

		let prompt = meta
			? `Unter-Kategorie "${labelText(meta.parent)} / ${meta.name}" löschen?`
			: 'Unter-Kategorie löschen?';
		if (deps.length > 0) {
			const list = deps.map((r) => '• ' + r.folder_name).join('\n');
			prompt += `\n\nDiese ${deps.length} Auto-Sort-Sub-Regel(n) werden mitgelöscht:\n${list}`;
		}
		if (!confirm(prompt)) return;

		try {
			const res = await api.settings.deleteSubLabel(id);
			loadSettings();
			const removed = (res && typeof res.deleted_rules === 'number') ? res.deleted_rules : 0;
			showToast(
				removed > 0
					? `Sub-Kategorie + ${removed} Auto-Sort-Regel${removed === 1 ? '' : 'n'} entfernt`
					: 'Sub-Kategorie entfernt',
				'success',
				3500,
			);
		} catch (err) { handleError(err); }
	});

	document.getElementById('btn-add-autosort-sub')?.addEventListener('click', addAutoSortSubRule);
	document.getElementById('autosort-sub-rows')?.addEventListener('click', async (e) => {
		const btn = e.target.closest('button.remove-sub-rule');
		if (!btn) return;
		const { label, name } = btn.dataset;
		if (!confirm(`Sub-Regel "${label} / ${name}" entfernen?`)) return;
		try {
			await api.settings.deleteAutoSortSub(label, name);
			loadSettings();
		} catch (err) { handleError(err); }
	});

	document.getElementById('btn-save-autosort')?.addEventListener('click', saveAutoSort);
	document.getElementById('btn-apply-autosort-now')?.addEventListener('click', applyAutoSortNow);

	document.getElementById('btn-export').addEventListener('click', async () => {
		try {
			const data = await api.me.export();
			const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
			const url = URL.createObjectURL(blob);
			const a = document.createElement('a');
			a.href = url;
			a.download = 'mailpilot-export.json';
			a.click();
			URL.revokeObjectURL(url);
		} catch (err) { handleError(err); }
	});

	document.getElementById('btn-delete').addEventListener('click', async () => {
		if (!confirm('Wirklich Account und alle Daten löschen? Nicht rückgängig zu machen.')) return;
		try {
			await api.me.deleteAccount();
			clearToken();
			setStatus('Account gelöscht');
		} catch (err) { handleError(err); }
	});
}

async function loadSettings() {
	try {
		const [vip, red, autosort, subs] = await Promise.all([
			api.settings.listVip(),
			api.settings.listRedaction(),
			api.settings.listAutoSort(),
			api.settings.listSubLabels(),
		]);
		renderList('vip-list', vip.items ?? [], (v) => `${escape(v.email)}`, 'deleteVip');
		renderList('red-list', red.items ?? [], (r) => `<code>${escape(r.pattern)}</code> — ${escape(r.description ?? '')}`, 'deleteRedaction');
		renderSubLabels(subs.items ?? []);
		renderAutoSortRules(autosort.rules ?? []);
	} catch (err) {
		handleError(err);
	}
}

/**
 * Build a <td> with a small inline badge (label colour) followed by
 * arbitrary plain-text content. Uses textContent for the user-supplied
 * part so a sub-label called `<script>` can never escape.
 */
function buildLabelTd(label, plainSuffix = '') {
	const td = document.createElement('td');
	const badge = document.createElement('span');
	badge.className = 'mp-badge';
	badge.dataset.label = label;
	badge.textContent = labelText(label);
	td.appendChild(badge);
	if (plainSuffix) {
		td.appendChild(document.createTextNode(' ' + plainSuffix));
	}
	return td;
}

function renderSubLabels(items) {
	subLabelsByParent = {};
	for (const it of items) {
		(subLabelsByParent[it.parent] ??= []).push({ id: it.id, name: it.name });
	}

	const ul = document.getElementById('sub-list');
	if (ul) {
		ul.replaceChildren();
		for (const s of items) {
			const li = document.createElement('li');

			const wrap = document.createElement('span');
			const badge = document.createElement('span');
			badge.className = 'mp-badge';
			badge.dataset.label = s.parent;
			badge.textContent = labelText(s.parent);
			wrap.appendChild(badge);
			wrap.appendChild(document.createTextNode(' ' + s.name));
			if (s.description) {
				wrap.appendChild(document.createTextNode(' — '));
				const muted = document.createElement('span');
				muted.className = 'mp-muted';
				muted.textContent = s.description;
				wrap.appendChild(muted);
			}
			li.appendChild(wrap);

			const btn = document.createElement('button');
			btn.className = 'remove';
			btn.dataset.id = s.id;
			btn.setAttribute('aria-label', 'Löschen');
			btn.textContent = '×';
			li.appendChild(btn);

			ul.appendChild(li);
		}
	}

	populateAutoSortSubPick();
}

/**
 * Walk subLabelsByParent and return { parent, name } for the given id,
 * or null when the cache is stale.
 */
function findSubLabelMetaById(id) {
	for (const [parent, list] of Object.entries(subLabelsByParent)) {
		const hit = list.find((s) => s.id === id);
		if (hit) return { parent, name: hit.name };
	}
	return null;
}

/**
 * Returns all AutoSort sub-rules that would cascade if the given
 * (parent, name) sub-label is removed. Driven by autoSortRulesCache
 * so no extra HTTP round-trip is needed.
 */
function dependentRulesForSubLabel(parent, name) {
	return autoSortRulesCache.filter((r) => r.label === parent && r.sub_label === name);
}

function populateAutoSortSubPick() {
	const select = document.getElementById('autosort-sub-pick');
	const addBtn = document.getElementById('btn-add-autosort-sub');
	if (!select || !addBtn) return;

	select.replaceChildren();
	const totalSubs = Object.values(subLabelsByParent).reduce((n, arr) => n + arr.length, 0);

	const placeholder = document.createElement('option');
	placeholder.value = '';
	placeholder.textContent = totalSubs === 0
		? '— erst Unter-Kategorie anlegen —'
		: '— Unter-Kategorie wählen —';
	select.appendChild(placeholder);

	for (const parent of ['direct', 'action', 'cc', 'newsletter', 'auto', 'noise']) {
		for (const s of (subLabelsByParent[parent] ?? [])) {
			const opt = document.createElement('option');
			opt.value = parent + '|' + s.name;
			opt.textContent = labelText(parent) + ' / ' + s.name;
			select.appendChild(opt);
		}
	}

	select.disabled = totalSubs === 0;
	addBtn.disabled = totalSubs === 0;
}

function renderAutoSortRules(rules) {
	autoSortRulesCache = rules;
	const catchAlls = rules.filter((r) => r.sub_label === null);
	const subRules  = rules.filter((r) => r.sub_label !== null);

	const tbody = document.getElementById('autosort-rows');
	if (tbody) {
		tbody.replaceChildren();
		for (const r of catchAlls) {
			tbody.appendChild(buildAutoSortRow(r, false));
		}
	}

	const subTbody = document.getElementById('autosort-sub-rows');
	if (subTbody) {
		subTbody.replaceChildren();
		if (subRules.length === 0) {
			const tr = document.createElement('tr');
			const td = document.createElement('td');
			td.colSpan = 5;
			td.className = 'mp-muted';
			td.textContent = 'Noch keine Sub-Regeln. Lege unten eine an.';
			tr.appendChild(td);
			subTbody.appendChild(tr);
		} else {
			for (const r of subRules) {
				subTbody.appendChild(buildAutoSortRow(r, true));
			}
		}
	}
}

/**
 * Build a single <tr> for the AutoSort table, either as a catch-all
 * row (4 cells: label, enabled, folder, optional error) or as a
 * sub-rule row (5 cells: label, sub, enabled, folder, controls).
 * All user-supplied text goes via textContent/dataset — no innerHTML
 * concatenation with untrusted values.
 */
function buildAutoSortRow(r, isSub) {
	const tr = document.createElement('tr');
	tr.dataset.label = r.label;
	if (isSub) tr.dataset.subLabel = r.sub_label;

	// label badge
	const labelTd = buildLabelTd(r.label);
	if (!isSub && (r.label === 'direct' || r.label === 'action')) {
		const small = document.createElement('small');
		small.className = 'mp-muted';
		small.textContent = ' (nur Prio < 4)';
		labelTd.appendChild(small);
	}
	tr.appendChild(labelTd);

	// sub-label cell (only on sub rows)
	if (isSub) {
		const subTd = document.createElement('td');
		subTd.textContent = r.sub_label;
		tr.appendChild(subTd);
	}

	// enabled switch
	const enabledTd = document.createElement('td');
	const switchLbl = document.createElement('label');
	switchLbl.className = 'mp-switch';
	const cb = document.createElement('input');
	cb.type = 'checkbox';
	cb.className = 'autosort-enabled';
	cb.checked = !!r.enabled;
	const knob = document.createElement('span');
	switchLbl.appendChild(cb);
	switchLbl.appendChild(knob);
	enabledTd.appendChild(switchLbl);
	tr.appendChild(enabledTd);

	// folder input
	const folderTd = document.createElement('td');
	const folderInput = document.createElement('input');
	folderInput.type = 'text';
	folderInput.className = 'autosort-folder';
	folderInput.value = r.folder_name ?? '';
	folderInput.placeholder = isSub
		? `MailPilot/${labelText(r.label)}/${r.sub_label}`
		: `MailPilot/${labelText(r.label)}`;
	folderTd.appendChild(folderInput);
	tr.appendChild(folderTd);

	// controls (sub) / error indicator (catch-all)
	if (isSub) {
		const ctrlTd = document.createElement('td');
		if (r.last_error) {
			const errSpan = document.createElement('span');
			errSpan.className = 'mp-error-hint';
			errSpan.title = r.last_error;
			errSpan.textContent = '⚠ ';
			ctrlTd.appendChild(errSpan);
		}
		const rm = document.createElement('button');
		rm.className = 'mp-icon-btn mp-icon-btn-ghost remove-sub-rule';
		rm.dataset.label = r.label;
		rm.dataset.name = r.sub_label;
		rm.setAttribute('aria-label', 'Sub-Regel löschen');
		rm.title = 'Sub-Regel löschen';
		rm.textContent = '×';
		ctrlTd.appendChild(rm);
		tr.appendChild(ctrlTd);
	} else if (r.last_error) {
		const errTd = document.createElement('td');
		errTd.className = 'mp-error-hint';
		errTd.title = r.last_error;
		errTd.textContent = '⚠';
		tr.appendChild(errTd);
	}

	return tr;
}

async function addAutoSortSubRule() {
	const pick   = document.getElementById('autosort-sub-pick');
	const folder = document.getElementById('autosort-sub-folder');
	const value  = pick?.value || '';
	if (!value) return;
	const [label, subName] = value.split('|');
	const folderName = folder?.value.trim() || `MailPilot/${labelText(label)}/${subName}`;
	try {
		await api.settings.updateAutoSort([
			{ label, sub_label: subName, enabled: true, folder_name: folderName },
		]);
		if (folder) folder.value = '';
		pick.value = '';
		loadSettings();
		showToast(`Sub-Regel ${labelText(label)} / ${subName} angelegt`, 'success', 3000);
	} catch (err) { handleError(err); }
}

async function applyAutoSortNow() {
	const btn = document.getElementById('btn-apply-autosort-now');
	const status = document.getElementById('autosort-status');
	if (!btn || btn.disabled) return;
	if (!confirm('Aktive Regeln auf bestehende Mails anwenden? Das verschiebt rückwirkend alle gescoreten Mails in die konfigurierten Ordner.')) return;

	btn.disabled = true;
	const totals = { processed: 0, moved: 0, protected: 0, errors: 0 };
	const toastId = showToast('Wende Regeln an…', 'info', 60000);
	try {
		let hasMore = true;
		let safety = 50; // up to 50 × 50 = 2500 mails worst-case
		while (hasMore && safety-- > 0) {
			const res = await api.settings.applyAutoSortNow(50);
			totals.processed += res.processed ?? 0;
			totals.moved     += res.moved ?? 0;
			totals.protected += res.protected ?? 0;
			totals.errors    += res.errors ?? 0;
			status.textContent = `${totals.moved} verschoben · ${totals.processed} verarbeitet${res.has_more ? ' · läuft…' : ''}`;
			hasMore = !!res.has_more;
		}
		dismissToast(toastId);
		const parts = [`${totals.moved} verschoben`];
		if (totals.protected) parts.push(`${totals.protected} geschützt (Prio≥4)`);
		if (totals.errors)    parts.push(`${totals.errors} Fehler`);
		showToast('Regeln angewendet: ' + parts.join(' · '), totals.errors ? 'error' : 'success', 6000);
		status.textContent = `Fertig: ${parts.join(' · ')}.`;
	} catch (err) {
		dismissToast(toastId);
		handleError(err);
	} finally {
		btn.disabled = false;
	}
}

async function saveAutoSort() {
	const tbody     = document.getElementById('autosort-rows');
	const subTbody  = document.getElementById('autosort-sub-rows');
	if (!tbody) return;

	const collectFrom = (root, withSub) => Array.from(root.querySelectorAll('tr'))
		// the "no sub-rules yet" placeholder has no .autosort-enabled — skip it.
		.filter((tr) => tr.querySelector('.autosort-enabled'))
		.map((tr) => {
			const rule = {
				label:       tr.dataset.label,
				enabled:     tr.querySelector('.autosort-enabled').checked,
				folder_name: tr.querySelector('.autosort-folder').value.trim(),
			};
			if (withSub && tr.dataset.subLabel) rule.sub_label = tr.dataset.subLabel;
			return rule;
		});

	const rules = [
		...collectFrom(tbody, false),
		...(subTbody ? collectFrom(subTbody, true) : []),
	];

	const status = document.getElementById('autosort-status');
	if (status) status.textContent = 'Speichere…';
	try {
		const res = await api.settings.updateAutoSort(rules);
		renderAutoSortRules(res.rules ?? []);
		if (status) status.textContent = `${res.updated ?? 0} Regeln gespeichert.`;
		showToast('Auto-Sort gespeichert', 'success', 3000);
	} catch (err) {
		if (status) status.textContent = '';
		handleError(err);
	}
}

function renderList(id, items, labelFn, _deleteMethod) {
	const ul = document.getElementById(id);
	ul.innerHTML = '';
	items.forEach((item) => {
		const li = document.createElement('li');
		li.innerHTML = `<span>${labelFn(item)}</span><button class="remove" data-id="${item.id}">×</button>`;
		ul.appendChild(li);
	});
}

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
	const msg = err instanceof ApiError ? err.message : 'Unerwarteter Fehler';
	showToast(msg, 'error', 6000);
}

// ============================================================
// Toast notifications — non-blocking, layered, auto-dismiss.
// Replaces status-text overwrites for transient messages.
// ============================================================
let toastSeq = 0;
function showToast(message, kind = 'info', durationMs = 4000) {
	const stack = document.getElementById('mp-toast-stack');
	if (!stack) return null;
	const id = `mp-toast-${++toastSeq}`;
	const toast = document.createElement('div');
	toast.id = id;
	toast.className = `mp-toast mp-toast-${kind}`;
	toast.textContent = String(message ?? '');
	stack.appendChild(toast);
	setTimeout(() => dismissToast(id), durationMs);
	return id;
}

function dismissToast(id) {
	if (!id) return;
	const toast = document.getElementById(id);
	if (!toast || toast.classList.contains('is-leaving')) return;
	toast.classList.add('is-leaving');
	toast.addEventListener('animationend', () => toast.remove(), { once: true });
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

function openMailInOutlook(mailId) {
	Office.context.mailbox.displayMessageFormAsync?.(mailId);
}
