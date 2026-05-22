// ============================================================
// Briefing Render — Pin-Liste + Header-Stats
// ============================================================
//
// Phase 9p (Marc 2026-05-22): Counter-Karten + Filter-View komplett
// entfernt. Pin-Liste ist der einzige Inbox-Indikator — die alten Label-
// Karten zaehlten wegsortierte Mails (Ghost-Counts) nach der Sender-
// Folder-Architektur-Umstellung in Phase 9m. Diese Datei behielt den
// Namen aus historischen Gruenden; sie rendert jetzt nur noch die Pin-
// Sektion + Footer-Stats.

function renderBriefing(data) {
	renderFooterStats(data);

	// Phase 9p: keine Label-Aggregate mehr in der UI. Subtitle zeigt nur
	// noch die Pin-Anzahl, gerendert in renderPinnedList.
	const pinCount = (data.pinned ?? []).length;
	document.getElementById('briefing-subtitle').textContent =
		pinCount === 0
			? 'Posteingang aufgeraeumt — keine offenen Mails.'
			: `${pinCount} Mail${pinCount === 1 ? '' : 's'} in deiner Inbox`;

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
	// Phase 9n (Marc 2026-05-21): Priority-Balken links via CSS-Klasse.
	const prio = Math.max(1, Math.min(5, Number(m.priority) || 0));
	li.classList.add(`mp-prio-${prio}`);
	li.dataset.mailId = m.mail_id;

	// Head: Sender + Priority-Pill + Action-Icons (Öffnen, Done). Kompakt.
	const head = document.createElement('div');
	head.className = 'mp-pin-head';

	const prioBadge = document.createElement('span');
	prioBadge.className = 'mp-pin-prio';
	prioBadge.textContent = `P${prio}`;
	prioBadge.title = 'Priorität (1=ignorierbar, 5=sofort)';
	head.appendChild(prioBadge);

	if (m.spoof_suspect) {
		const spoof = document.createElement('span');
		spoof.className = 'mp-pin-spoof';
		spoof.textContent = '⚠';
		spoof.title = 'Lookalike-Domain — könnte Phishing sein';
		head.appendChild(spoof);
	}

	const from = document.createElement('span');
	from.className = 'mp-pin-from';
	from.textContent = m.sender_display_name || m.from_name || m.from_email;
	head.appendChild(from);

	// Action-Icons rechts: Öffnen + Done. Beide reine Icons (Marc-Wunsch).
	const openIcon = document.createElement('button');
	openIcon.type = 'button';
	openIcon.className = 'mp-action-icon mp-pin-open';
	openIcon.setAttribute('aria-label', 'In Outlook öffnen');
	openIcon.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
	openIcon.title = 'In Outlook öffnen';
	openIcon.addEventListener('click', () => openMailInOutlook(m.ms_message_id || m.mail_id));
	head.appendChild(openIcon);

	const doneIcon = document.createElement('button');
	doneIcon.type = 'button';
	doneIcon.className = 'mp-done-icon mp-pin-done';
	doneIcon.setAttribute('aria-label', m.preview_path
		? `Erledigt — verschiebe nach ${m.preview_path}`
		: 'Als erledigt markieren');
	doneIcon.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
	doneIcon.title = m.preview_path
		? `Erledigt → ${m.preview_path}`
		: 'Als erledigt markieren (bleibt in Inbox)';
	doneIcon.addEventListener('click', () => markPinnedDone(m.mail_id, li));
	head.appendChild(doneIcon);

	li.appendChild(head);

	// Subject
	const subj = document.createElement('div');
	subj.className = 'mp-pin-subject';
	subj.textContent = m.subject || '(ohne Betreff)';
	li.appendChild(subj);

	// Phase 9n: Pfad-Vorschau als sichtbarer Chip (statt nur Tooltip).
	// Zeigt dem User wohin die Mail bei „Erledigt" geht.
	const path = document.createElement('div');
	if (m.preview_path) {
		path.className = 'mp-pin-pathchip';
		path.innerHTML = `<span class="mp-pin-pathicon">→</span><span>${escape(m.preview_path)}</span>`;
		path.title = `Bei „Erledigt" → ${m.preview_path}`;
	} else {
		path.className = 'mp-pin-pathchip is-empty';
		path.textContent = '↺ Bleibt in Inbox (kein Sortier-Vorschlag)';
	}
	li.appendChild(path);

	// Phase 9n: KI-Empfehlung — kompakte 1-Zeilen-Zusammenfassung warum
	// diese Mail Aufmerksamkeit braucht. summary kommt aus mail_scores.summary
	// (vom P-SCORE-Prompt erstellt, max 160 chars).
	if (m.summary) {
		const reco = document.createElement('div');
		reco.className = 'mp-pin-reco';
		let prefix = '';
		if (m.action_required && m.action_owner === 'user') {
			prefix = '✋ ';
		}
		reco.textContent = prefix + m.summary;
		li.appendChild(reco);
	}

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

