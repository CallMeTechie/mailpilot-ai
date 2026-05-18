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
	initToday();
	initCurrentMail();
	initSettings();
	initSettingsOverlay();
	startAutoRefresh();

	// If we already have a JWT in storage (page reload after login),
	// arm the pre-emptive refresh loop right away. setToken() does
	// this automatically for fresh logins; this handles the reload case.
	if (localStorage.getItem('mp_jwt')) {
		startTokenRefreshLoop();
		// 2026-05-16: Initial-Badge-Fix. Bisher war der Pending-Badge bei
		// App-Start leer, bis der User entweder einen Tab wechselte oder
		// 60 s auf den autoRefresh-Tick wartete. Direkt nach JWT-Check
		// einmal feuern damit das Badge sofort die richtige Zahl zeigt.
		loadPending().catch(() => { /* silent, badge stays 0 */ });
	}

	// Path 1: postMessage (works when window.opener survived).
	// 2026-05-18 Security-Hardening (CWE-345): Origin-Whitelist erzwingen.
	// Der Auth-Popup laeuft auf demselben Backend-Origin wie das Add-in
	// (localStorage-Handoff funktioniert ohnehin nur same-origin). Ohne
	// diesen Check koennte jede Seite, die das Add-in im iframe einbettet,
	// ein gefaktes 'mp-auth-complete'-Token injizieren.
	window.addEventListener('message', (event) => {
		if (event.origin !== window.location.origin) return;
		if (event.data?.type === 'mp-auth-complete' && event.data.token) {
			setToken(event.data.token);
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

	// 2026-05-18 Marc-Wunsch: Wenn das TaskPane mit einem aktiven Mail-
	// Kontext geoeffnet wird (User klickt eine Mail an, Outlook oeffnet
	// das Pane mit), direkt den „Diese Mail"-Tab zeigen — nicht erst auf
	// Briefing landen und dann manuell wechseln muessen. Ohne Item-Kontext
	// bleibt der Default-Tab (Briefing) aktiv.
	try {
		const item = Office.context.mailbox.item;
		if (item && item.itemId) {
			document.querySelector('.mp-tab[data-tab="current"]')?.click();
		}
	} catch (_) { /* kein Item-Kontext — Briefing bleibt aktiv */ }
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
			setToken(token);
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
				loadBriefing();
			}
			if (name === 'today') {
				loadToday();
			}
			if (name === 'current') {
				onItemChanged();
			}
			if (name === 'pending') {
				loadPending();
			}
			// Bei jedem Tab-Switch: Pending-Badge silent refresh — Marc
			// soll auch in „Diese Mail" sehen, wenn ein neuer Vorschlag
			// reinkommt, ohne erst auf Pending oder Briefing wechseln.
			if (name !== 'pending') {
				loadPending().catch(() => { /* badge stays stale, harmless */ });
			}
		});
	});
}

// ============================================================
// Sprint 6e — "MailPilot Heute"-Dashboard
// ============================================================
const todayState = { range: 'today' };

function initToday() {
	document.querySelectorAll('.mp-today-range-btn').forEach((btn) => {
		btn.addEventListener('click', () => {
			document.querySelectorAll('.mp-today-range-btn').forEach(b => b.classList.remove('is-active'));
			btn.classList.add('is-active');
			todayState.range = btn.dataset.range || 'today';
			loadToday();
		});
	});
}

async function loadToday() {
	const loader = document.getElementById('today-loader');
	if (loader) loader.dataset.hidden = 'false';
	try {
		const res = await api.today.fetch(todayState.range);
		renderTodaySection('important', res.important);
		renderTodaySection('unclear',   res.unclear);
		renderTodaySection('done',      res.done);
	} catch (err) {
		if (err instanceof ApiError && err.status === 401) return bounceToLogin();
		handleError(err);
	} finally {
		if (loader) loader.dataset.hidden = 'true';
	}
}

function renderTodaySection(name, section) {
	const items = section?.items ?? [];
	const ul = document.getElementById(`today-list-${name}`);
	const count = document.getElementById(`today-count-${name}`);
	if (count) count.textContent = String(items.length);
	if (!ul) return;
	ul.replaceChildren();
	if (items.length === 0) {
		const li = document.createElement('li');
		li.className = 'mp-muted';
		li.textContent = 'Keine Einträge in diesem Zeitraum.';
		ul.appendChild(li);
		return;
	}
	for (const item of items) {
		ul.appendChild(buildTodayCard(item, name));
	}
}

function buildTodayCard(item, section) {
	const li = document.createElement('li');
	li.className = 'mp-today-card';
	li.dataset.label = item.label;

	const headLine = document.createElement('div');
	headLine.className = 'mp-today-card-head';
	const subj = document.createElement('strong');
	subj.textContent = item.subject || '(ohne Betreff)';
	headLine.appendChild(subj);
	const prio = document.createElement('span');
	prio.className = 'mp-prio-pill';
	prio.dataset.priority = String(item.priority ?? 2);
	prio.textContent = 'P' + (item.priority ?? '?');
	headLine.appendChild(prio);
	li.appendChild(headLine);

	const from = document.createElement('div');
	from.className = 'mp-muted mp-today-card-from';
	from.textContent = item.from_name ? `${item.from_name} <${item.from_email}>` : (item.from_email || '');
	li.appendChild(from);

	if (item.summary) {
		const sm = document.createElement('div');
		sm.className = 'mp-today-card-summary';
		sm.textContent = item.summary;
		li.appendChild(sm);
	}

	const actions = document.createElement('div');
	actions.className = 'mp-actions';
	if (section === 'unclear') {
		const notMine = document.createElement('button');
		notMine.className = 'mp-btn mp-btn-secondary';
		notMine.textContent = 'Nicht meins';
		notMine.addEventListener('click', () => correctOwnerInline(item.mail_id, 'other', li));
		actions.appendChild(notMine);
	}
	const open = document.createElement('button');
	open.className = 'mp-btn mp-btn-ghost';
	open.textContent = 'Öffnen';
	// Marc-Bug-Fix 2026-05-14: Heute-Tab nutzt jetzt die echte Office.js-
	// API zum Öffnen einer anderen Mail (wie Briefing-Top-Priority), statt
	// nur den Tab zu wechseln. ms_message_id ist die REST-ID die
	// displayMessageFormAsync erwartet; bei stale-Payload mit UUID gibt
	// openMailInOutlook einen erklärenden Toast.
	open.addEventListener('click', () => openMailInOutlook(item.ms_message_id || item.mail_id));
	actions.appendChild(open);
	li.appendChild(actions);

	return li;
}

async function correctOwnerInline(mailId, owner, cardEl) {
	try {
		await api.mails.correctOwner(mailId, owner);
		cardEl?.remove();
		setStatus('Owner-Korrektur gespeichert.');
	} catch (err) { handleError(err); }
}

// Sprint 0.3: Settings sind nicht mehr Tab, sondern Vollbild-Overlay,
// das per Zahnrad-Icon im Header geöffnet wird. Sub-Tabs gruppieren die
// fünf Bereiche (Profil&Filter / Topics / Auto-Sort / Wartung / Daten);
// loadSettings() läuft beim Öffnen (wie zuvor beim Tab-Wechsel).
function initSettingsOverlay() {
	const overlay = document.getElementById('mp-settings-overlay');
	const open    = document.getElementById('btn-open-settings');
	const close   = document.getElementById('btn-close-settings');
	if (!overlay || !open || !close) return;

	const openOverlay  = () => { overlay.dataset.hidden = 'false'; loadSettings(); };
	const closeOverlay = () => { overlay.dataset.hidden = 'true'; };

	open.addEventListener('click',  openOverlay);
	close.addEventListener('click', closeOverlay);
	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' && overlay.dataset.hidden === 'false') closeOverlay();
	});

	document.querySelectorAll('.mp-subtab').forEach((tab) => {
		tab.addEventListener('click', () => {
			const name = tab.dataset.subtab;
			document.querySelectorAll('.mp-subtab').forEach(t => t.classList.remove('is-active'));
			document.querySelectorAll('.mp-subpanel').forEach(p => p.classList.remove('is-active'));
			tab.classList.add('is-active');
			document.querySelector(`.mp-subpanel[data-subpanel="${name}"]`)?.classList.add('is-active');

			// Sprint 6c: Lazy-Load der Tab-Daten beim Wechsel.
			if (name === 'modes')   loadModes();
			// Sprint 6g — Rule-Inference Settings liegen im Auto-Sort-Tab.
			// Modes-Endpoint liefert beides, daher derselbe Loader.
			if (name === 'autosort') loadModes();
		});
	});

	// Buttons im neuen Modi-Sub-Tab
	document.getElementById('btn-save-modes')?.addEventListener('click', saveModes);
	['mode-move','mode-topic','mode-reply'].forEach((id) => {
		document.getElementById(id)?.addEventListener('change', refreshModeHints);
	});

	// Buttons im Pending-Sub-Tab
	document.getElementById('btn-pending-reload')?.addEventListener('click', () => loadPending());
	document.getElementById('btn-pending-approve-all')?.addEventListener('click', bulkApproveAllPending);
	// Filter-Pills im Pending-Tab (2026-05-15 Redesign)
	document.querySelectorAll('.mp-pending-filter').forEach((btn) => {
		btn.addEventListener('click', () => setPendingFilter(btn.dataset.filterKind || 'all'));
	});
}

// ----- Sprint 6c: Modi -----
async function loadModes() {
	try {
		const m = await api.modes.get();
		document.getElementById('mode-move').value  = m.autosort_move_mode         ?? 'suggest';
		document.getElementById('mode-topic').value = m.autosort_create_topic_mode ?? 'suggest';
		document.getElementById('mode-reply').value = m.autosort_reply_mode        ?? 'suggest';
		// Sprint 6g — Rule-Inference Settings
		const riEnabled = document.getElementById('rule-inference-enabled');
		const riRange   = document.getElementById('rule-inference-backfill-range');
		if (riEnabled) riEnabled.checked = (m.rule_inference_enabled !== false);
		if (riRange)   riRange.value     = m.rule_inference_backfill_range ?? 'last_30_days';
		// Sprint 6f — Auto-Reply Settings
		const arEnabled = document.getElementById('autoreply-enabled');
		if (arEnabled) arEnabled.checked = !!m.autoreply_enabled;
		refreshModeHints();
	} catch (err) { handleError(err); }
}

// ============================================================
// Sprint 6f — Auto-Reply-Drafts
// ============================================================
let _draftState = { mailId: null, draftId: null, text: '', stale: false };

async function loadActiveDraft(mailDbId) {
	const box = document.getElementById('current-draft-box');
	if (!box) return;
	box.dataset.hidden = 'true';
	if (!mailDbId) return;
	const res = await api.drafts.getActive(mailDbId);
	const d = res?.draft;
	if (!d) return;
	_draftState = {
		mailId:  mailDbId,
		draftId: d.id,
		text:    d.draft_text || '',
		stale:   Boolean(d.stale_at),
	};
	document.getElementById('current-draft-text').textContent = _draftState.text;
	const meta = document.getElementById('current-draft-meta');
	if (meta) {
		const tag = d.created_by === 'auto' ? 'KI-Vorschlag' : 'on-demand';
		meta.textContent = _draftState.stale ? `${tag} · veraltet` : tag;
	}
	box.classList.toggle('is-stale', _draftState.stale);
	box.dataset.hidden = 'false';
}

function openDraftInOutlook() {
	if (!_draftState.text) return;
	const text = _draftState.text;
	// Versuche Reply-Form mit Body zu öffnen; fallback Clipboard.
	const api2 = Office?.context?.mailbox?.item?.displayReplyForm;
	try {
		if (typeof api2 === 'function') {
			Office.context.mailbox.item.displayReplyForm({ htmlBody: text.replace(/\n/g, '<br>') });
			setStatus('Reply-Form mit Entwurf geöffnet.');
			return;
		}
	} catch (_) { /* fallback below */ }
	try {
		navigator.clipboard?.writeText(text);
		setStatus('Entwurf in die Zwischenablage kopiert. In Outlook „Antworten" klicken und einfügen.');
	} catch (_) {
		setStatus('Entwurf konnte nicht geöffnet werden — manuell kopieren.');
	}
}

async function regenerateDraft() {
	if (!_draftState.mailId) return;
	setStatus('Neuen Entwurf generieren…');
	try {
		// Wir verwerfen den alten zuerst, damit der Worker keinen Race generiert.
		if (_draftState.draftId) {
			await api.drafts.dismiss(_draftState.draftId).catch(() => {});
		}
		await api.drafts.regenerate(_draftState.mailId);
		await loadActiveDraft(_draftState.mailId);
		setStatus('Neuer Entwurf bereit.');
	} catch (err) { handleError(err); }
}

async function dismissDraft() {
	if (!_draftState.draftId) return;
	try {
		await api.drafts.dismiss(_draftState.draftId);
		document.getElementById('current-draft-box').dataset.hidden = 'true';
		_draftState = { mailId: null, draftId: null, text: '', stale: false };
		setStatus('Entwurf verworfen.');
	} catch (err) { handleError(err); }
}

async function saveAutoReplySettings() {
	const status = document.getElementById('autoreply-status');
	if (status) status.textContent = 'Speichere…';
	try {
		await api.modes.save({
			autoreply_enabled: document.getElementById('autoreply-enabled').checked,
		});
		if (status) status.textContent = 'Gespeichert.';
		showToast('Auto-Reply-Settings gespeichert', 'success', 2500);
	} catch (err) {
		if (status) status.textContent = '';
		handleError(err);
	}
}

async function includeAutoReplyBacklog() {
	const status = document.getElementById('autoreply-status');
	if (status) status.textContent = 'Backlog läuft an…';
	try {
		const res = await api.drafts.includeBacklog();
		const t = res?.tick ?? {};
		const msg = `Backlog: ${t.generated ?? 0} Drafts erzeugt, ${t.candidates ?? 0} Kandidaten geprüft.`;
		if (status) status.textContent = msg;
		showToast(msg, 'success', 4000);
	} catch (err) {
		if (status) status.textContent = '';
		handleError(err);
	}
}

async function saveRuleInference() {
	const status = document.getElementById('rule-inference-status');
	if (status) status.textContent = 'Speichere…';
	try {
		await api.modes.save({
			rule_inference_enabled:        document.getElementById('rule-inference-enabled').checked,
			rule_inference_backfill_range: document.getElementById('rule-inference-backfill-range').value,
		});
		if (status) status.textContent = 'Gespeichert.';
		showToast('Lern-Settings gespeichert', 'success', 2500);
	} catch (err) {
		if (status) status.textContent = '';
		handleError(err);
	}
}

function refreshModeHints() {
	const lv = { off: 0, suggest: 1, auto: 2 };
	const move  = document.getElementById('mode-move').value;
	const topic = document.getElementById('mode-topic').value;
	const moveHint  = document.getElementById('mode-move-hint');
	const topicHint = document.getElementById('mode-topic-hint');
	if (moveHint) {
		moveHint.textContent = move === 'off'
			? 'Mails bleiben im Inbox. KI klassifiziert weiter, verschiebt aber nicht.'
			: (move === 'suggest' ? 'Jeder Move landet im Pending-Tab.' : 'Mails werden sofort in den Ziel-Ordner verschoben.');
	}
	if (topicHint) {
		if (lv[topic] > lv[move]) {
			topicHint.textContent = `⚠ ${topic} ist aggressiver als „${move}" für Move — Speichern wird mit 422 abgelehnt. Setze erst Move höher.`;
			topicHint.style.color = '#b91c1c';
		} else {
			topicHint.style.color = '';
			topicHint.textContent = topic === 'off'
				? 'Keine KI-Discovery. Sub-Labels nur manuell anlegen.'
				: (topic === 'suggest' ? 'Discovery erscheint als „KI-Vorschlag" im Auto-Sort-Tab UND im Pending-Tab.' : 'Discovery legt Folder + Rule sofort an, Mails werden ab nächstem Sync sortiert.');
		}
	}
}

async function saveModes() {
	const status = document.getElementById('modes-status');
	status.textContent = 'Speichere…';
	try {
		const payload = {
			autosort_move_mode:         document.getElementById('mode-move').value,
			autosort_create_topic_mode: document.getElementById('mode-topic').value,
			autosort_reply_mode:        document.getElementById('mode-reply').value,
		};
		const res = await api.modes.save(payload);
		// DA-Impl-Finding 3: Bestands-Pending unter altem Modus sichtbar
		// machen. Wenn z.B. von suggest auf auto gewechselt wird und 47
		// pending-suggest-Moves rumliegen, würden sie sonst „verloren" gehen.
		const ep = res?.existing_pending ?? {};
		if ((ep.total ?? 0) > 0) {
			status.textContent = `Gespeichert. Du hast noch ${ep.total} ausstehende Vorschläge unter dem alten Modus — bitte im Pending-Tab sichten.`;
		} else {
			status.textContent = 'Gespeichert.';
			setTimeout(() => { if (status) status.textContent = ''; }, 2000);
		}
	} catch (err) {
		status.textContent = '';
		handleError(err);
	}
}

// ----- Sprint 6c: Pending -----
let pendingLoading = false;

async function loadPending() {
	if (pendingLoading) return;
	pendingLoading = true;
	const status = document.getElementById('pending-status');
	status.textContent = 'Lade…';
	try {
		const res = await api.pending.list(null, null, 50);
		renderPending(res);
		status.textContent = '';
	} catch (err) {
		status.textContent = '';
		handleError(err);
	} finally {
		pendingLoading = false;
	}
}

// Cached items, damit der Filter-Click ohne Re-Fetch greift.
const pendingState = { items: [], filter: 'all' };

function renderPending(res) {
	const counts = res.counts ?? {};
	const total  = counts.total ?? 0;
	pendingState.items = res.items ?? [];

	// Header total + Hauptnav-Tab-Badge.
	const totalEl = document.getElementById('pending-total-count');
	if (totalEl) totalEl.textContent = String(total);
	const tabBadge = document.getElementById('pending-tab-count');
	if (tabBadge) {
		if (total > 0) {
			tabBadge.textContent = String(total);
			tabBadge.dataset.hidden = 'false';
		} else {
			tabBadge.textContent = '';
			tabBadge.dataset.hidden = 'true';
		}
	}

	// Filter-Chip-Counters.
	const filterCount = (kind) => document.querySelector(`[data-filter-count="${kind}"]`);
	if (filterCount('all'))             filterCount('all').textContent = String(total);
	if (filterCount('move'))            filterCount('move').textContent = String((counts.move ?? 0) + (counts.move_to_pending_topic ?? 0));
	if (filterCount('create_topic'))    filterCount('create_topic').textContent = String(counts.create_topic ?? 0);
	if (filterCount('rule_suggestion')) filterCount('rule_suggestion').textContent = String(counts.rule_suggestion ?? 0);
	if (filterCount('reply_draft'))     filterCount('reply_draft').textContent = String(counts.reply_draft ?? 0);

	// Banner
	const banner = document.getElementById('pending-banner');
	const b = res.banner ?? { level: 'none', total: 0 };
	if (b.level === 'none') {
		banner.dataset.hidden = 'true';
		banner.textContent = '';
	} else {
		banner.dataset.hidden = 'false';
		banner.dataset.level  = b.level;
		const msgs = {
			info:    `Du hast ${b.total} offene Vorschläge — alles im Rahmen.`,
			warning: `${b.total} offene Vorschläge — älteste werden in wenigen Tagen verworfen, bitte sichten.`,
			block:   `${b.total} offene Vorschläge — bitte erst bearbeiten oder Modi auf „Aus"/„Auto" setzen.`,
		};
		banner.textContent = msgs[b.level] ?? '';
	}

	renderPendingList();
}

function renderPendingList() {
	const list = document.getElementById('pending-list');
	if (!list) return;
	list.replaceChildren();

	const filtered = pendingState.items.filter((item) => {
		if (pendingState.filter === 'all') return true;
		if (pendingState.filter === 'move') {
			return item.kind === 'move' || item.kind === 'move_to_pending_topic';
		}
		return item.kind === pendingState.filter;
	});

	for (const item of filtered) {
		list.appendChild(buildPendingCard(item));
	}
	if (filtered.length === 0) {
		const li = document.createElement('li');
		li.className = 'mp-muted mp-pending-empty';
		li.textContent = pendingState.items.length === 0
			? 'Keine offenen Vorschläge.'
			: 'Keine Vorschläge in dieser Kategorie.';
		list.appendChild(li);
	}
}

function setPendingFilter(kind) {
	pendingState.filter = kind;
	document.querySelectorAll('.mp-pending-filter').forEach((btn) => {
		btn.classList.toggle('is-active', btn.dataset.filterKind === kind);
	});
	renderPendingList();
}

const PENDING_KIND_META = {
	move:                   { icon: '📁', label: 'Verschieben' },
	create_topic:           { icon: '🆕', label: 'Neuer Topic' },
	move_to_pending_topic:  { icon: '📁', label: 'Verschieben (wartet auf Topic)' },
	reply_draft:            { icon: '✉️', label: 'Reply-Draft' },
	rule_suggestion:        { icon: '⚙️', label: 'Regel-Vorschlag' },
};

function pendingTitle(item) {
	const p = item.payload ?? {};
	if (item.kind === 'rule_suggestion') {
		return p.sub_label || p.label || '(unbenannt)';
	}
	if (item.kind === 'create_topic') {
		return p.sub_label || p.primary || '(unbenannt)';
	}
	// move / move_to_pending_topic / reply_draft: Mail-Subject ist primär.
	// Fallback-Reihenfolge: subject > target_folder > sub_label > '(ohne Betreff)'.
	return p.subject || p.target_folder || p.folder_path || p.folder_name || p.sub_label || '(ohne Betreff)';
}

function pendingTarget(item) {
	const p = item.payload ?? {};
	return p.target_folder || p.folder_path || p.folder_name || '';
}

function pendingAffectedCount(item) {
	if (item.kind === 'rule_suggestion' && Array.isArray(item.payload?.affected_mail_ids)) {
		return item.payload.affected_mail_ids.length;
	}
	if (item.kind === 'create_topic') {
		return item.children_count ?? 0;
	}
	return 0;
}

function buildPendingCard(item) {
	const li = document.createElement('li');
	li.className = 'mp-pending-card-v2';
	li.dataset.kind = item.kind;
	li.dataset.itemId = item.id;
	if (item.last_error) li.dataset.hasError = 'true';

	const meta = PENDING_KIND_META[item.kind] ?? { icon: '•', label: item.kind };

	// Compact header (always visible)
	const header = document.createElement('div');
	header.className = 'mp-pending-card-head';

	const icon = document.createElement('span');
	icon.className = 'mp-pending-card-icon';
	icon.textContent = meta.icon;
	header.appendChild(icon);

	const title = document.createElement('div');
	title.className = 'mp-pending-card-title';
	const titleStrong = document.createElement('strong');
	titleStrong.textContent = pendingTitle(item);
	title.appendChild(titleStrong);
	const target = pendingTarget(item);
	if (target) {
		const arrow = document.createElement('span');
		arrow.className = 'mp-pending-card-target';
		arrow.textContent = ' → ' + target;
		title.appendChild(arrow);
	}
	header.appendChild(title);

	const affectedCount = pendingAffectedCount(item);
	if (affectedCount > 0) {
		const badge = document.createElement('span');
		badge.className = 'mp-pending-card-affected';
		badge.textContent = String(affectedCount);
		badge.title = `${affectedCount} Mails betroffen`;
		header.appendChild(badge);
	}

	const expandBtn = document.createElement('button');
	expandBtn.className = 'mp-pending-card-expand';
	expandBtn.setAttribute('aria-label', 'Details');
	expandBtn.textContent = '▾';
	header.appendChild(expandBtn);

	li.appendChild(header);

	// Expanded body
	const body = document.createElement('div');
	body.className = 'mp-pending-card-body';
	body.dataset.hidden = 'true';

	if (item.kind === 'rule_suggestion') {
		buildRuleSuggestionBody(body, item);
	} else if (item.kind === 'create_topic' && affectedCount > 0) {
		const note = document.createElement('p');
		note.className = 'mp-muted';
		note.textContent = `${affectedCount} Mails werden in den neuen Topic-Ordner verschoben.`;
		body.appendChild(note);
	} else if (item.kind === 'move' || item.kind === 'move_to_pending_topic') {
		// Move-Pendings: zeige Subject + From + Target explizit im Body.
		// Bisher war Target nur im Header-Pfeil; bei langem Subject wurde
		// es abgeschnitten. Plus From-Adresse ist Kontext den Marc oft
		// braucht zum schnellen Entscheiden.
		const p = item.payload ?? {};
		const dl = document.createElement('div');
		dl.className = 'mp-pending-move-summary';
		if (p.subject) {
			const r = document.createElement('div');
			r.innerHTML = '<span class="mp-pending-rule-label">Betreff:</span> ';
			const v = document.createElement('span'); v.textContent = p.subject;
			r.appendChild(v);
			dl.appendChild(r);
		}
		if (p.from) {
			const r = document.createElement('div');
			r.innerHTML = '<span class="mp-pending-rule-label">Von:</span> ';
			const v = document.createElement('span'); v.textContent = p.from;
			r.appendChild(v);
			dl.appendChild(r);
		}
		if (p.target_folder) {
			const r = document.createElement('div');
			r.innerHTML = '<span class="mp-pending-rule-label">Ziel-Ordner:</span> ';
			const v = document.createElement('code'); v.textContent = p.target_folder;
			r.appendChild(v);
			dl.appendChild(r);
		}
		body.appendChild(dl);
	}

	if (item.last_error) {
		const err = document.createElement('div');
		err.className = 'mp-error-text';
		err.textContent = 'Letzter Fehler: ' + item.last_error;
		body.appendChild(err);
	}

	// Actions (inside body)
	const actions = document.createElement('div');
	actions.className = 'mp-pending-card-actions';

	const ok = document.createElement('button');
	ok.className = 'mp-btn mp-btn-primary';
	ok.textContent = approveButtonText(item);
	ok.addEventListener('click', () => approvePending(item));
	actions.appendChild(ok);

	const no = document.createElement('button');
	no.className = 'mp-btn mp-btn-ghost';
	no.textContent = 'Ablehnen';
	no.addEventListener('click', () => rejectPending(item.id));
	actions.appendChild(no);

	body.appendChild(actions);
	li.appendChild(body);

	// Expand-Toggle
	expandBtn.addEventListener('click', (e) => {
		e.stopPropagation();
		const open = body.dataset.hidden !== 'false';
		body.dataset.hidden = open ? 'false' : 'true';
		expandBtn.textContent = open ? '▴' : '▾';
		li.classList.toggle('is-expanded', open);
	});
	// Click on header (außer Buttons) auch expand.
	header.addEventListener('click', (e) => {
		if (e.target.closest('button') !== expandBtn && e.target.closest('button') !== null) return;
		if (e.target === expandBtn) return; // schon gehandelt
		expandBtn.click();
	});

	return li;
}

function approveButtonText(item) {
	if (item.last_error) return 'Erneut versuchen';
	const count = pendingAffectedCount(item);
	if (item.kind === 'rule_suggestion') {
		return count > 0 ? `Regel anlegen + ${count} verschieben` : 'Regel anlegen';
	}
	if (item.kind === 'create_topic' && count > 0) {
		return `Anlegen + ${count} verschieben`;
	}
	return 'Bestätigen';
}

function buildRuleSuggestionBody(body, item) {
	const p = item.payload ?? {};

	// 1) Rule-Beschreibung: Folder + Match-Signals explizit anzeigen.
	//    Marc muss wissen WORÜBER die Regel matcht, nicht nur sub_label.
	const ruleBox = document.createElement('div');
	ruleBox.className = 'mp-pending-rule-summary';

	if (p.folder_name) {
		const row = document.createElement('div');
		row.innerHTML = '<span class="mp-pending-rule-label">Ziel-Ordner:</span> ';
		const v = document.createElement('code');
		v.textContent = p.folder_name;
		row.appendChild(v);
		ruleBox.appendChild(row);
	}
	if (Array.isArray(p.match_signals) && p.match_signals.length > 0) {
		const row = document.createElement('div');
		row.innerHTML = '<span class="mp-pending-rule-label">Match wenn:</span> ';
		p.match_signals.forEach((sig, idx) => {
			const tag = document.createElement('code');
			tag.className = 'mp-pending-rule-signal';
			tag.textContent = sig;
			row.appendChild(tag);
			if (idx < p.match_signals.length - 1) {
				row.appendChild(document.createTextNode(' '));
			}
		});
		ruleBox.appendChild(row);
	}
	if (ruleBox.children.length > 0) body.appendChild(ruleBox);

	// 2) KI-Konfidenz + Reasoning-Summary
	const conf = p.confidence;
	const summary = p.reasoning_summary;
	if (typeof conf === 'number' || summary) {
		const metaLine = document.createElement('div');
		metaLine.className = 'mp-pending-card-meta';
		if (typeof conf === 'number') {
			const c = document.createElement('span');
			c.className = 'mp-pending-conf';
			c.textContent = `KI ${conf}%`;
			metaLine.appendChild(c);
		}
		if (summary) {
			const s = document.createElement('span');
			s.className = 'mp-muted';
			s.textContent = summary;
			metaLine.appendChild(s);
		}
		body.appendChild(metaLine);
	}

	const affectedIds = Array.isArray(p.affected_mail_ids) ? p.affected_mail_ids : [];
	const subjects    = Array.isArray(p.affected_subjects) ? p.affected_subjects : [];

	if (affectedIds.length === 0) {
		const note = document.createElement('p');
		note.className = 'mp-muted';
		note.textContent = 'Nur Regel anlegen — keine bestehenden Mails betroffen.';
		body.appendChild(note);
		return;
	}

	// 3) Checkbox-Liste mit Toolbar (Alle / Keine).
	//    Backend liefert max. 10 Subjects (Payload-Cap). Bei mehr IDs
	//    fallen weitere auf ID-Display zurück — besser als leeres Label.
	const listHeader = document.createElement('div');
	listHeader.className = 'mp-pending-rule-list-header';
	const listTitle = document.createElement('span');
	const subjectCount = subjects.length;
	listTitle.textContent = subjectCount < affectedIds.length
		? `${affectedIds.length} Mails würden mitverschoben (erste ${subjectCount} mit Betreff):`
		: `${affectedIds.length} Mails würden mitverschoben:`;
	listHeader.appendChild(listTitle);
	const toolbar = document.createElement('span');
	toolbar.className = 'mp-pending-rule-toolbar';
	const allBtn = document.createElement('button');
	allBtn.className = 'mp-btn-link';
	allBtn.textContent = 'Alle';
	const noneBtn = document.createElement('button');
	noneBtn.className = 'mp-btn-link';
	noneBtn.textContent = 'Keine';
	toolbar.appendChild(allBtn);
	toolbar.appendChild(noneBtn);
	listHeader.appendChild(toolbar);
	body.appendChild(listHeader);

	const list = document.createElement('ul');
	list.className = 'mp-pending-rule-list';
	const checkboxes = [];
	affectedIds.forEach((mailId, i) => {
		const liEntry = document.createElement('li');
		const cb = document.createElement('input');
		cb.type = 'checkbox';
		cb.checked = true;
		cb.value = mailId;
		cb.id = `pending-mail-${item.id}-${i}`;
		// 2026-05-16 ROOT CAUSE: globaler `input { width: 100%; padding: 8px 10px }`
		// in taskpane.css:76 macht Checkboxen ~36px breit und schiebt das
		// Label aus dem Container. Inline-Reset killt das definitiv.
		cb.style.width = 'auto';
		cb.style.padding = '0';
		cb.style.margin = '0';
		cb.style.background = 'transparent';
		cb.style.border = '';
		cb.style.flexShrink = '0';
		checkboxes.push(cb);
		// 2026-05-16: Span statt label — label-Element rendert in manchen
		// Edge-Webview-Versionen seltsam mit checked-state. Plus Inline-
		// Style als Fail-Safe gegen unbekannte CSS-Overrides.
		const lbl = document.createElement('span');
		lbl.className = 'mp-pending-rule-label-text';
		lbl.style.flex = '1';
		lbl.style.minWidth = '0';
		lbl.style.overflow = 'hidden';
		lbl.style.textOverflow = 'ellipsis';
		lbl.style.whiteSpace = 'nowrap';
		lbl.style.color = 'inherit';
		lbl.style.fontSize = '12px';
		// Fallback-Kaskade: Subject > kurze Mail-ID > '(ohne Betreff)'.
		const text = subjects[i] || (mailId ? '(Mail ' + mailId.substring(0, 8) + '…)' : '(ohne Betreff)');
		lbl.textContent = text;
		// Klick auf den Span togglet die Checkbox — wie label[for=...].
		lbl.style.cursor = 'pointer';
		lbl.addEventListener('click', () => { cb.checked = !cb.checked; });
		liEntry.appendChild(cb);
		liEntry.appendChild(lbl);
		list.appendChild(liEntry);
	});
	body.appendChild(list);

	// Bind toolbar
	allBtn.addEventListener('click',  () => { checkboxes.forEach(cb => cb.checked = true); });
	noneBtn.addEventListener('click', () => { checkboxes.forEach(cb => cb.checked = false); });

	// Hänge die Checkboxes als Property direkt an das body-Element —
	// approvePending() greift später über li.querySelector('.mp-pending-card-body')
	// drauf zu (.__ruleCheckboxes).
	body.__ruleCheckboxes = checkboxes;
}

async function approvePending(item) {
	// Backward-compat: ältere Callers reichen noch eine ID rein.
	if (typeof item === 'string') {
		item = pendingState.items.find(i => i.id === item) ?? { id: item, kind: null };
	}
	const id = item.id;
	const kind = item.kind;
	const childrenCount = item.children_count ?? 0;

	// DA-Impl-Finding 2: bei create_topic mit Children Bulk-Move-Confirm
	// (PRD §3.1). Sonst sieht der User den Mail-Move nicht kommen.
	if (kind === 'create_topic' && childrenCount > 0) {
		const ok = await mpConfirm({
			title: 'Topic anlegen + Mails verschieben?',
			body: `Es werden ${childrenCount} Mail${childrenCount === 1 ? '' : 's'} in den neuen Topic-Ordner verschoben. Fortfahren?`,
			okLabel: 'Anlegen + verschieben',
		});
		if (!ok) return;
	}

	// Sprint 6g: bei rule_suggestion holen wir die gecheckten Mail-IDs
	// aus der Card-Body-Checkbox-Liste (kompakt-UI 2026-05-15).
	const body = {};
	if (kind === 'rule_suggestion') {
		const card = document.querySelector(`.mp-pending-card-v2[data-item-id="${id}"]`);
		const cardBody = card?.querySelector('.mp-pending-card-body');
		if (cardBody && Array.isArray(cardBody.__ruleCheckboxes)) {
			body.selected_mail_ids = cardBody.__ruleCheckboxes
				.filter(cb => cb.checked)
				.map(cb => cb.value);
		}
	}

	try {
		const res = await api.pending.approve(id, body);
		if (res?.result?.kind === 'create_topic') {
			const done   = res.result.moves_done   ?? 0;
			const failed = res.result.moves_failed ?? 0;
			setStatus(`Topic angelegt. ${done} verschoben, ${failed} Fehler.`);
		} else if (res?.result?.kind === 'rule_suggestion') {
			const done   = res.result.moves_done   ?? 0;
			const failed = res.result.moves_failed ?? 0;
			setStatus(`Regel angelegt. ${done} verschoben, ${failed} Fehler.`);
		}
		await loadPending();
	} catch (err) { handleError(err); }
}

async function rejectPending(id) {
	try { await api.pending.reject(id); await loadPending(); }
	catch (err) { handleError(err); }
}

async function bulkApproveAllPending() {
	const status = document.getElementById('pending-status');
	let totalDone = 0, totalFailed = 0, processed = 0;
	let cursor = null;
	try {
		while (true) {
			const res = await api.pending.bulkApprove({ after_id: cursor, limit: 25 });
			processed   += res.processed   ?? 0;
			totalDone   += res.succeeded   ?? 0;
			totalFailed += res.failed      ?? 0;
			status.textContent = `Verarbeite ${processed}…`;
			cursor = res.next_cursor;
			if (!cursor || res.processed === 0) break;
		}
		status.textContent = `Fertig: ${totalDone} erfolgreich, ${totalFailed} Fehler.`;
		await loadPending();
	} catch (err) {
		status.textContent = '';
		handleError(err);
	}
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
								setToken(res.token);
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
		if (!localStorage.getItem('mp_jwt')) return;
		const activeTab = document.querySelector('.mp-tab.is-active')?.dataset.tab;

		// Briefing-Counts nur refreshen wenn der User wirklich darauf
		// schaut (Liste ist groß, vermeidet Render-Flackern).
		if (activeTab === 'briefing' && state.filterLabel === null) {
			loadBriefing();
		}

		// Pending-Badge MUSS überall live sein — sonst sieht Marc nicht
		// dass eine neue rule_suggestion eingetroffen ist während er auf
		// „Heute" oder „Diese Mail" arbeitet.
		loadPending().catch(() => { /* silent */ });
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
	if (spec.confirm) {
		const ok = await mpConfirm({
			title: `${spec.label} — fuer alle gefilterten Mails?`,
			body: 'Diese Aktion wird auf alle aktuell gefilterten Mails angewendet und kann nicht rueckgaengig gemacht werden.',
			okLabel: spec.label,
			danger: true,
		});
		if (!ok) return;
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
			// Verhindert Frankenstein-Render: wenn die API 404 liefert,
			// zeigen wir keine alten Score-/Summary-Daten der vorherigen
			// Mail. Header (Subject/From kommen aus Office.js item) und
			// alle Content-Blocks ausblenden, Empty-State zeigen.
			state.currentMailData = null;
			toggle('current-header', false);
			toggle('current-content', false);
			toggle('current-empty', true);
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
	// Sprint 0.3: Settings-Overlay schließen, sonst versteckt es den
	// Login-Empty-State darunter — User sähe noch Settings und würde
	// in 401-Schleifen klicken.
	const overlay = document.getElementById('mp-settings-overlay');
	if (overlay) overlay.dataset.hidden = 'true';

	toggle('current-header', false);
	toggle('current-empty', true);
	document.querySelector('.mp-tab[data-tab="briefing"]')?.click();
}

function renderCurrentMail(row) {
	state.currentMailData = row;
	loadActiveDraft(row.id).catch(() => { /* silent — Draft-Box bleibt versteckt */ });
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

// Sprint 6a Alias-State. local hält die in-memory-Liste, die
// renderAliasChips() rendert; privacyAck wird vom Profile-Load
// gesetzt und vom Save-Pfad konsultiert, um den Disclaimer nur einmal
// pro User zu zeigen.
const aliasState = { local: [], privacyAck: null };

// Projekt-Stichworte (Marc-Bug-Fix 2026-05-14). Replace-Pattern,
// gespeichert via PATCH /settings/user mit project_keywords-Array.
const keywordState = { local: [] };

function renderKeywordChips() {
	const ul = document.getElementById('kw-list');
	if (!ul) return;
	ul.innerHTML = '';
	keywordState.local.forEach((kw, idx) => {
		const li = document.createElement('li');
		li.className = 'mp-chip';
		li.textContent = kw;
		const btn = document.createElement('button');
		btn.className = 'mp-chip-remove';
		btn.setAttribute('aria-label', `${kw} entfernen`);
		btn.textContent = '×';
		btn.addEventListener('click', () => {
			persistKeywords(keywordState.local.filter((_, i) => i !== idx));
		});
		li.appendChild(btn);
		ul.appendChild(li);
	});
}

async function persistKeywords(next) {
	const previous = [...keywordState.local];
	try {
		await api.settings.replaceKeywords(next);
		keywordState.local = next;
		renderKeywordChips();
	} catch (err) {
		keywordState.local = previous;
		renderKeywordChips();
		handleError(err);
	}
}

function renderAliasChips() {
	const ul = document.getElementById('alias-chips');
	if (!ul) return;
	ul.innerHTML = '';
	aliasState.local.forEach((name, idx) => {
		const li = document.createElement('li');
		li.className = 'mp-chip';
		li.textContent = name;
		const btn = document.createElement('button');
		btn.className = 'mp-chip-remove';
		btn.setAttribute('aria-label', `${name} entfernen`);
		btn.textContent = '×';
		btn.addEventListener('click', () => {
			// DA-Finding 2: keine Mutation vor persistAliases. Wir berechnen
			// die nächste Liste rein funktional und reichen sie weiter.
			const next = aliasState.local.filter((_, i) => i !== idx);
			persistAliases(next);
		});
		li.appendChild(btn);
		ul.appendChild(li);
	});
}

function renderAliasSuggestions(list) {
	const wrap = document.getElementById('alias-suggestions');
	const ul   = document.getElementById('alias-suggest-list');
	if (!wrap || !ul) return;
	ul.innerHTML = '';
	if (list.length === 0) {
		wrap.dataset.hidden = 'false';
		const li = document.createElement('li');
		li.className = 'mp-muted';
		li.textContent = 'Keine wiederkehrenden Anreden gefunden.';
		ul.appendChild(li);
		return;
	}
	list.forEach((s) => {
		const li = document.createElement('li');
		li.className = 'mp-chip mp-chip-suggest';
		// Plus-Icon vor dem Namen macht klar, dass Klick = Hinzufügen
		// (Marc-UX-Hinweis 2026-05-14: Bisher war nicht erkennbar, dass
		//  die Vorschläge erst durch Klick übernommen werden).
		li.textContent = `+ ${s.name} · ${s.count}×`;
		li.title = `Klick übernimmt „${s.name}" in die Alias-Liste`;
		li.addEventListener('click', () => {
			if (!aliasState.local.some(a => a.toLowerCase() === s.name.toLowerCase())
				&& aliasState.local.length < 30) {
				persistAliases([...aliasState.local, s.name]);
				setStatus(`„${s.name}" als Alias übernommen.`);
			}
			li.remove();
		});
		ul.appendChild(li);
	});
	wrap.dataset.hidden = 'false';
}

// DA-Finding 2: persistAliases nimmt jetzt die KANDIDATEN-Liste als Arg
// statt zu mutieren bevor der User zustimmt. Bei Cancel oder Fail bleibt
// aliasState.local unverändert, kein Phantom-Chip im UI.
async function persistAliases(candidates) {
	const previous = [...aliasState.local];
	if (aliasState.privacyAck === null) {
		const ok = await mpConfirm({
			title: 'Hinweis zum Datenschutz',
			body: 'Aliase enthalten manchmal Namen Dritter (Kollegen, Kunden). Diese Informationen bleiben auf deinem Server und werden nur als Kontext an die KI gesendet — nicht zur Weitergabe gespeichert. Akzeptierst du das?',
			okLabel: 'Akzeptieren',
		});
		if (!ok) return;
		try { await api.me.acknowledgePrivacy(); aliasState.privacyAck = new Date().toISOString(); }
		catch (err) { handleError(err); return; }
	}
	try {
		await api.me.saveAliases(candidates);
		aliasState.local = candidates;
		renderAliasChips();
	} catch (err) {
		// Rollback auf den State vor dem Save-Versuch — falls bereits
		// optimistisch gerendert wurde, korrigiert das die UI zurück.
		aliasState.local = previous;
		renderAliasChips();
		handleError(err);
	}
}

function initSettings() {
	// Sprint 6a Alias-Wireups
	document.getElementById('btn-add-alias')?.addEventListener('click', () => {
		const inp = document.getElementById('alias-input');
		const v = inp.value.trim();
		if (!v || aliasState.local.length >= 30) return;
		if (!aliasState.local.some(a => a.toLowerCase() === v.toLowerCase())) {
			persistAliases([...aliasState.local, v]);
		}
		inp.value = '';
	});
	document.getElementById('alias-input')?.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') { e.preventDefault(); document.getElementById('btn-add-alias').click(); }
	});
	document.getElementById('btn-scan-aliases')?.addEventListener('click', async () => {
		const btn = document.getElementById('btn-scan-aliases');
		btn.disabled = true;
		btn.textContent = 'Scanne…';
		try {
			const res = await api.me.scanAliases();
			renderAliasSuggestions(res.suggestions ?? []);
		} catch (err) {
			handleError(err);
		} finally {
			btn.disabled = false;
			btn.textContent = 'Vorschläge aus letzten 200 Mails';
		}
	});

	document.getElementById('btn-add-vip').addEventListener('click', async () => {
		const email = document.getElementById('vip-email').value.trim();
		if (!email) return;
		try {
			await api.settings.addVip(email, null);
			document.getElementById('vip-email').value = '';
			loadSettings();
		} catch (err) { handleError(err); }
	});

	// Projekt-Stichworte (Marc-Bug 2026-05-14: bisher toter Code, kein
	// Handler und kein API-Call). Replace-Pattern wie Aliases.
	document.getElementById('btn-add-kw')?.addEventListener('click', async () => {
		const inp = document.getElementById('kw-input');
		const v = (inp?.value ?? '').trim();
		if (!v) return;
		const next = [...keywordState.local];
		if (!next.some(k => k.toLowerCase() === v.toLowerCase())) {
			next.push(v);
		}
		await persistKeywords(next);
		if (inp) inp.value = '';
	});
	document.getElementById('kw-input')?.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') { e.preventDefault(); document.getElementById('btn-add-kw').click(); }
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

		const title = meta
			? `Unter-Kategorie "${labelText(meta.parent)} / ${meta.name}" löschen?`
			: 'Unter-Kategorie löschen?';
		let body = '';
		if (deps.length > 0) {
			const list = deps.map((r) => '• ' + r.folder_name).join('\n');
			body = `Diese ${deps.length} Auto-Sort-Sub-Regel(n) werden mitgelöscht:\n${list}`;
		}
		if (!await mpConfirm({ title, body, okLabel: 'Löschen', danger: true })) return;

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
		const ok = await mpConfirm({
			title: 'Sub-Regel entfernen?',
			body: `${label} / ${name}\n\nMails in diesem Topic werden nicht zurueckverschoben.`,
			okLabel: 'Entfernen',
			danger: true,
		});
		if (!ok) return;
		try {
			await api.settings.deleteAutoSortSub(label, name);
			loadSettings();
		} catch (err) { handleError(err); }
	});

	document.getElementById('btn-save-autosort')?.addEventListener('click', saveAutoSort);
	document.getElementById('btn-apply-autosort-now')?.addEventListener('click', applyAutoSortNow);
	// Sprint 6g — Rule-Inference Save
	document.getElementById('btn-save-rule-inference')?.addEventListener('click', saveRuleInference);
	// Sprint 6f — Auto-Reply-Drafts
	document.getElementById('btn-save-autoreply')?.addEventListener('click', saveAutoReplySettings);
	document.getElementById('btn-autoreply-backlog')?.addEventListener('click', includeAutoReplyBacklog);
	document.getElementById('btn-draft-open-outlook')?.addEventListener('click', openDraftInOutlook);
	document.getElementById('btn-draft-regenerate')?.addEventListener('click', regenerateDraft);
	document.getElementById('btn-draft-dismiss')?.addEventListener('click', dismissDraft);
	document.getElementById('btn-rescore-all')?.addEventListener('click', rescoreAll);

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
		const ok = await mpConfirm({
			title: 'Account und alle Daten löschen?',
			body: 'Diese Aktion kann nicht rueckgaengig gemacht werden. Alle Mails, Klassifizierungen, Aliase, Regeln und Korrektur-Logs werden entfernt.',
			okLabel: 'Endgueltig loeschen',
			danger: true,
		});
		if (!ok) return;
		try {
			await api.me.deleteAccount();
			clearToken();
			setStatus('Account gelöscht');
		} catch (err) { handleError(err); }
	});
}

// Generation token gegen Race beim Schnell-Reopen oder bei internen
// Re-Loads (CRUD-Handler rufen loadSettings selbst auf). Späte Response
// einer abgelösten Pipeline darf das frische Render nicht überschreiben.
let settingsGen = 0;

async function loadSettings() {
	const myGen = ++settingsGen;
	try {
		const [vip, red, autosort, subs, profile, userProfile] = await Promise.all([
			api.settings.listVip(),
			api.settings.listRedaction(),
			api.settings.listAutoSort(),
			api.settings.listSubLabels(),
			api.me.profile(),
			api.settings.getUser(),
		]);
		if (myGen !== settingsGen) return;
		renderList('vip-list', vip.items ?? [], (v) => `${escape(v.email)}`, 'deleteVip');
		renderList('red-list', red.items ?? [], (r) => `<code>${escape(r.pattern)}</code> — ${escape(r.description ?? '')}`, 'deleteRedaction');
		renderSubLabels(subs.items ?? []);
		renderAutoSortRules(autosort.rules ?? []);

		// Sprint 6a: Alias-Chips aus Profil rendern, Privacy-Ack-Status merken
		const user = profile?.user ?? {};
		aliasState.local = Array.isArray(user.aliases) ? [...user.aliases] : [];
		aliasState.privacyAck = user.privacy_acknowledged_at ?? null;
		renderAliasChips();

		// Marc-Bug-Fix 2026-05-14: Projekt-Stichworte aus /settings/user hydrieren.
		keywordState.local = Array.isArray(userProfile?.project_keywords)
			? [...userProfile.project_keywords]
			: [];
		renderKeywordChips();
	} catch (err) {
		// 401 darf nicht stumm im Overlay versanden — bounceToLogin
		// schließt das Overlay (siehe Sprint 0.3-Fix) und führt den
		// User zurück auf den Briefing-Empty-State.
		if (err instanceof ApiError && err.status === 401) {
			return bounceToLogin();
		}
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
		// Sprint 6b: KI-vorgeschlagene Rules bekommen einen Badge.
		// Carry-Over DA-Impl 6b-4: Touch-Devices zeigen kein title-
		// Tooltip. Statt cursor:help nutzen wir on-click ein Inline-
		// Popover (mp-badge-tip), das auf jedem Device erreichbar ist.
		if (r.created_by === 'ki') {
			const badge = document.createElement('button');
			badge.type = 'button';
			badge.className = 'mp-badge-ki';
			badge.textContent = 'KI-Vorschlag';
			badge.setAttribute('aria-label',
				'Die KI hat dieses Topic in deinen Mails entdeckt. Aktivieren = automatisch sortieren.');
			badge.addEventListener('click', (e) => {
				e.stopPropagation();
				const tip = badge.nextElementSibling;
				if (tip && tip.classList.contains('mp-badge-tip')) {
					tip.dataset.hidden = tip.dataset.hidden === 'true' ? 'false' : 'true';
				}
			});
			const tip = document.createElement('span');
			tip.className = 'mp-badge-tip';
			tip.dataset.hidden = 'true';
			tip.textContent = 'Die KI hat dieses Topic in deinen Mails entdeckt. Aktivieren = wird ab jetzt automatisch sortiert.';
			subTd.appendChild(document.createTextNode(' '));
			subTd.appendChild(badge);
			subTd.appendChild(tip);
		}
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

async function rescoreAll() {
	if (mpBusy !== null) return;
	const status = document.getElementById('rescore-status');

	const ok = await mpConfirm({
		title: 'Alle Mails neu klassifizieren?',
		body: 'Der KI-Cache wird geleert und alle bisher klassifizierten Mails werden beim naechsten Sync neu durch Claude geschickt. Deine manuellen Korrekturen bleiben erhalten. Das kann ein paar Minuten dauern und kostet Token-Budget.',
		okLabel: 'Neu klassifizieren',
	});
	if (!ok) return;

	setBusy('rescore');
	const progress = mpProgress({
		title: 'Mails werden neu klassifiziert',
		status: 'Cache wird geleert ...',
	});
	let cancelled = false;
	progress.waitForCancel().then(() => { cancelled = true; setBusy('canceling'); });

	try {
		// 1. Cache wipe + scores als veraltet markieren
		const res = await api.settings.rescoreAll();
		const total = res.scores_marked ?? 0;
		progress.update({ done: 0, total, status: `${total} Mails markiert. Sync wird gestartet ...` });

		// 2. Sync-Jobs anwerfen (eine pro Mailbox)
		const syncRes = await api.sync.trigger();
		const jobIds = (syncRes && syncRes.job_ids) || [];

		if (jobIds.length === 0) {
			// Kein Mailbox-Sync nötig (kein Postfach verknüpft)
			progress.update({ done: 0, total, status: `${total} Mails markiert. Kein Sync-Job — Postfach pruefen.` });
		} else {
			// 3. Polling: warte bis alle Jobs durch sind
			let done = 0;
			let allDone = false;
			let pollIterations = 0;
			const maxPoll = 600; // 600 × 2s = 20 Min hard-cap
			while (!allDone && !cancelled && pollIterations++ < maxPoll) {
				await new Promise((r) => setTimeout(r, 2000));
				if (cancelled) break;
				done = 0;
				allDone = true;
				for (const id of jobIds) {
					try {
						const s = await api.sync.status(id);
						if (s.status === 'queued' || s.status === 'running') allDone = false;
						done += parseInt(s.processed ?? 0, 10);
					} catch { /* transient, weiter */ }
				}
				progress.update({ done, total, status: `${done} / ${total} Mails neu klassifiziert ...` });
			}
		}

		progress.close();
		if (cancelled) {
			showToast('Abgebrochen — Worker laeuft im Hintergrund weiter', 'warn', 5000);
			if (status) status.textContent = 'Abgebrochen.';
		} else {
			showToast(`${total} Mails neu klassifiziert`, 'success', 4000);
			if (status) status.textContent = `Fertig: ${total} Mails neu klassifiziert. Cache geleert: ${res.cache_purged ?? 0} Eintraege.`;
		}
	} catch (err) {
		progress.close();
		if (status) status.textContent = '';
		handleError(err);
	} finally {
		clearBusy();
	}
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
	if (mpBusy !== null) return;
	const status = document.getElementById('autosort-status');

	const ok = await mpConfirm({
		title: 'Aktive Regeln auf bestehende Mails anwenden?',
		body: 'Alle bereits gescorten Mails werden in die konfigurierten Ordner verschoben. Mails mit hoher Prio (direct/action ab Prio 4) bleiben in der Inbox.',
		okLabel: 'Jetzt anwenden',
	});
	if (!ok) return;

	setBusy('autosort');
	const progress = mpProgress({
		title: 'Regeln werden angewendet',
		status: 'Sammle passende Mails ...',
	});
	let cancelled = false;
	progress.waitForCancel().then(() => { cancelled = true; setBusy('canceling'); });

	const totals = { processed: 0, moved: 0, protected: 0, errors: 0 };
	let total = null;
	let afterId = null;

	try {
		// Hard-Cap als Last-Resort gegen Backend-Bug oder bösen Cursor;
		// die echte Stopbedingung ist has_more=false plus Cursor-Advance.
		let safety = 1000;
		while (safety-- > 0) {
			if (cancelled) break;

			const res = await api.settings.applyAutoSortNow(50, afterId);
			if (res.total !== undefined && res.total !== null && total === null) {
				total = res.total;
			}

			totals.processed += res.processed ?? 0;
			totals.moved     += res.moved ?? 0;
			totals.protected += res.protected ?? 0;
			totals.errors    += res.errors ?? 0;

			const parts = [`${totals.moved} verschoben`];
			if (totals.protected) parts.push(`${totals.protected} geschuetzt`);
			if (totals.errors)    parts.push(`${totals.errors} Fehler`);
			progress.update({
				done: totals.processed,
				total: total ?? totals.processed,
				status: parts.join(' · '),
			});

			if (!res.has_more) break;
			// Cursor MUSS sich bewegen — wenn next_after_id wie vor dem
			// Aufruf bleibt, ist das ein Backend-Bug; lieber abbrechen
			// als endlos loopen wie vor Sprint 0.2.
			if (!res.next_after_id || res.next_after_id === afterId) break;
			afterId = res.next_after_id;
		}

		progress.close();
		const finalParts = [`${totals.moved} verschoben`];
		if (totals.protected) finalParts.push(`${totals.protected} geschuetzt (Prio>=4)`);
		if (totals.errors)    finalParts.push(`${totals.errors} Fehler`);
		const finalText = finalParts.join(' · ');
		if (cancelled) {
			showToast('Abgebrochen: ' + finalText, 'warn', 5000);
			if (status) status.textContent = 'Abgebrochen: ' + finalText;
		} else {
			showToast('Regeln angewendet: ' + finalText, totals.errors ? 'error' : 'success', 6000);
			if (status) status.textContent = `Fertig: ${finalText}.`;
		}
	} catch (err) {
		progress.close();
		handleError(err);
	} finally {
		clearBusy();
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
