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

