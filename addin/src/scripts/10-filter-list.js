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

	// Phase 5 (Marc 2026-05-18): Pin-Liste oben im Briefing. Mails mit
	// hohem inbox_score warten auf User-Done-Klick.
	renderPinnedList(data.pinned ?? []);
}

/**
 * Phase 5 — Inbox-Pin-Cards. Pro Card: Score-Badge, Spoof-Badge,
 * From, Subject, Done-Button mit Pfad-Vorschau-Chip.
 */
function renderPinnedList(items) {
	const root = document.getElementById('pinned-list');
	if (!root) return;  // HTML-Element kommt in Phase 5c — kein Crash bei alter UI
	root.replaceChildren();

	const spoofCount = items.filter((m) => m.spoof_suspect).length;
	const banner = document.getElementById('pinned-spoof-banner');
	if (banner) {
		banner.dataset.hidden = spoofCount === 0 ? 'true' : 'false';
		banner.textContent = spoofCount === 0
			? ''
			: `⚠ ${spoofCount} verdächtige${spoofCount === 1 ? 'r' : ''} Absender in der Inbox — Lookalike-Domains`;
	}

	const countEl = document.getElementById('pinned-count');
	if (countEl) countEl.textContent = String(items.length);

	if (items.length === 0) {
		const empty = document.createElement('li');
		empty.className = 'mp-muted';
		empty.textContent = 'Keine wichtigen Mails in deiner Inbox — schöner Posteingang.';
		root.appendChild(empty);
		return;
	}

	for (const m of items) {
		root.appendChild(buildPinnedCard(m));
	}
}

function buildPinnedCard(m) {
	const li = document.createElement('li');
	li.className = 'mp-pin-card';
	if (m.spoof_suspect) li.classList.add('is-spoof');
	li.dataset.mailId = m.mail_id;

	// Head: Score + Sender + Spoof-Badge
	const head = document.createElement('div');
	head.className = 'mp-pin-head';
	const score = document.createElement('span');
	score.className = 'mp-pin-score';
	score.textContent = `${m.inbox_score}`;
	score.title = 'Inbox-Wichtigkeits-Score';
	head.appendChild(score);
	if (m.spoof_suspect) {
		const spoof = document.createElement('span');
		spoof.className = 'mp-pin-spoof';
		spoof.textContent = '⚠ Verdächtig';
		spoof.title = 'Lookalike-Domain — könnte Phishing sein';
		head.appendChild(spoof);
	}
	const from = document.createElement('span');
	from.className = 'mp-pin-from';
	from.textContent = m.sender_display_name || m.from_name || m.from_email;
	head.appendChild(from);

	// Phase 9e (Marc 2026-05-19): Done-Icon rechts oben in der Card, konsistent
	// mit current-mail-Header. Tooltip zeigt Pfad-Vorschau; disabled wenn die
	// KI keinen Sortier-Vorschlag geliefert hat.
	const doneIcon = document.createElement('button');
	doneIcon.type = 'button';
	doneIcon.className = 'mp-done-icon mp-pin-done';
	doneIcon.setAttribute('aria-label', 'Erledigt — verschieben');
	doneIcon.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
	doneIcon.title = m.preview_path
		? `Erledigt → ${m.preview_path}`
		: 'Als erledigt markieren (kein Sortier-Vorschlag — bleibt in Inbox)';
	doneIcon.addEventListener('click', () => markPinnedDone(m.mail_id, li));
	head.appendChild(doneIcon);

	li.appendChild(head);

	// Subject
	const subj = document.createElement('div');
	subj.className = 'mp-pin-subject';
	subj.textContent = m.subject || '(ohne Betreff)';
	li.appendChild(subj);

	// Aktionen — nur noch Oeffnen (Done ist als Icon im Head).
	const actions = document.createElement('div');
	actions.className = 'mp-pin-actions';

	const openBtn = document.createElement('button');
	openBtn.className = 'mp-btn mp-btn-ghost';
	openBtn.textContent = 'Öffnen';
	openBtn.addEventListener('click', () => openMailInOutlook(m.ms_message_id || m.mail_id));
	actions.appendChild(openBtn);

	li.appendChild(actions);
	return li;
}

async function markPinnedDone(mailId, cardEl) {
	const btn = cardEl?.querySelector('.mp-pin-done');
	if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
	try {
		const res = await api.mails.done(mailId);
		cardEl?.classList.add('is-fading');
		setTimeout(() => cardEl?.remove(), 250);
		if (res?.moved) {
			showToast(`Verschoben nach ${res.folder}`, 'success', 4000);
		} else {
			showToast('Als erledigt markiert — bleibt in Inbox (kein Ordner-Vorschlag).', 'info', 4500);
		}
		state.briefingLoaded = false;
		loadBriefing();
	} catch (err) {
		if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); }
		handleError(err);
	}
}

