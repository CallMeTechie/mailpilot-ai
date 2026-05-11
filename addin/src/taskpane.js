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
	// SVG content is static (no user data) — but we still parse via
	// DOMParser to avoid touching innerHTML.
	const parser = new DOMParser();
	const doc = parser.parseFromString(svgString, 'image/svg+xml');
	return document.importNode(doc.documentElement, true);
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
		setStatus('Sync gestartet…');
		toggle('sync-progress', true);
		setSyncProgress(0, 0);
		try {
			const res = await api.sync.trigger();
			const jobId = Array.isArray(res?.job_ids) ? res.job_ids[0] : null;
			if (!jobId) {
				// No mailbox or no job created — just refresh briefing.
				toggle('sync-progress', false);
				await loadBriefing();
				setStatus('Bereit');
				return;
			}
			pollSyncStatus(jobId);
		} catch (err) {
			toggle('sync-progress', false);
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
	const pct = t > 0 ? Math.min(100, (p / t) * 100) : 0;
	fill.style.width = pct.toFixed(1) + '%';
	text.textContent = t > 0 ? `${p} / ${t}` : 'wird vorbereitet…';
}

function pollSyncStatus(jobId) {
	const startedAt = Date.now();
	const POLL_MS = 2000;
	const MAX_MS = 10 * 60 * 1000;   // 10 minutes hard cap

	const timer = setInterval(async () => {
		if (Date.now() - startedAt > MAX_MS) {
			clearInterval(timer);
			toggle('sync-progress', false);
			showToast('Sync läuft länger als 10 min — bitte später erneut prüfen.', 'info', 4000);
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
			} else if (status.status === 'error') {
				clearInterval(timer);
				toggle('sync-progress', false);
				showToast('Sync fehlgeschlagen: ' + (status.error_text || 'unbekannt'), 'error', 6000);
				setStatus('Sync-Fehler');
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
		li.addEventListener('click', () => openMailInOutlook(m.mail_id));
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
}

function onItemChanged() {
	const item = Office.context.mailbox.item;
	if (!item || !item.itemId) {
		state.currentMailId = null;
		state.currentMailData = null;
		toggle('current-header', false);
		toggle('current-loader', false);
		toggle('current-content', false);
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

async function loadCurrentMailScore(msMessageId, attempt = 0) {
	const MAX_POLL_ATTEMPTS = 20;   // 20 × 3s = 60s budget for first-sync
	const POLL_INTERVAL_MS  = 3000;

	// Loader stays visible for the entire polling window; only hidden
	// when the score arrives or the budget is exhausted.
	toggle('current-loader', true);
	toggle('current-content', false);

	try {
		const result = await api.mails.list(`?ms_message_id=${encodeURIComponent(msMessageId)}&limit=1`);
		const row = result.items?.[0];

		if (row) {
			renderCurrentMail(row);
			toggle('current-loader', false);
			toggle('current-content', true);
			return;
		}

		// Not yet scored.
		if (attempt === 0) {
			// Kick the worker on the first miss; subsequent polls just wait for it.
			try { await api.sync.trigger(); } catch (_) { /* tolerate already-running */ }
		}

		if (attempt + 1 >= MAX_POLL_ATTEMPTS) {
			toggle('current-loader', false);
			setStatus('Sync dauert länger als erwartet — bitte „Aktualisieren" im Briefing-Tab probieren.');
			return;
		}

		// Bail out if the user moved to another mail in the meantime.
		if (state.currentMailId !== msMessageId) {
			return;
		}

		setTimeout(() => loadCurrentMailScore(msMessageId, attempt + 1), POLL_INTERVAL_MS);
	} catch (err) {
		toggle('current-loader', false);
		if (err instanceof ApiError && err.status === 401) {
			// Token was cleared by api.js — bounce to the briefing tab so the
			// user finds the login button.
			toggle('current-header', false);
			toggle('current-empty', true);
			document.querySelector('.mp-tab[data-tab="briefing"]')?.click();
			return;
		}
		handleError(err);
	}
}

function renderCurrentMail(row) {
	state.currentMailData = row;
	const score = row.score ?? {};
	const badge = document.getElementById('current-badge');
	badge.textContent = labelText(score.label);
	badge.dataset.label = score.label ?? 'auto';
	toggle('current-badge', true);
	document.getElementById('current-priority').textContent = `Priorität ${score.priority ?? '—'}`;
	document.getElementById('current-summary').textContent = score.summary ?? '—';

	if (score.action_required) {
		toggle('current-action-section', true);
		document.getElementById('current-action').textContent =
			'Dieser Absender erwartet eine Antwort oder Entscheidung von dir.';
	} else {
		toggle('current-action-section', false);
	}
}

async function summarizeCurrent() {
	if (!state.currentMailData) return;
	setStatus('Zusammenfassung wird erstellt…');
	try {
		const res = await api.mails.summarize(state.currentMailData.id);
		document.getElementById('current-summary').textContent = res.summary;
		setStatus('Zusammenfassung fertig');
	} catch (err) {
		handleError(err);
	}
}

async function draftCurrent() {
	if (!state.currentMailData) return;
	setStatus('Antwort wird entworfen…');
	try {
		const res = await api.mails.draftReply(state.currentMailData.id);
		document.getElementById('draft-text').value = res.draft;
		toggle('draft-section', true);
		setStatus('Entwurf bereit');
	} catch (err) {
		handleError(err);
	}
}

async function rescoreCurrent() {
	if (!state.currentMailData) return;
	setStatus('Neu bewerten…');
	try {
		await api.mails.rescore(state.currentMailData.id);
		await loadCurrentMailScore(state.currentMailId);
		setStatus('Neu bewertet');
	} catch (err) {
		handleError(err);
	}
}

function useDraft() {
	const text = document.getElementById('draft-text').value;
	Office.context.mailbox.item.displayReplyForm(text);
}

// ============================================================
// Settings
// ============================================================
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
		const [vip, red] = await Promise.all([
			api.settings.listVip(),
			api.settings.listRedaction(),
		]);
		renderList('vip-list', vip.items ?? [], (v) => `${escape(v.email)}`, 'deleteVip');
		renderList('red-list', red.items ?? [], (r) => `<code>${escape(r.pattern)}</code> — ${escape(r.description ?? '')}`, 'deleteRedaction');
	} catch (err) {
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
