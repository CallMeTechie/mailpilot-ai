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
			// Phase 6a — Sender-Liste laden beim Tab-Switch.
			if (name === 'senders') loadSenders();
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

