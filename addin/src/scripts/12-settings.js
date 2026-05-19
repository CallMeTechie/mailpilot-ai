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
// Phase 6a — Sender-Verwaltung (Marc 2026-05-19)
// ============================================================

async function loadSenders() {
	try {
		const res = await api.settings.listSenders();
		renderSenders(res?.items ?? []);
	} catch (err) {
		handleError(err);
	}
}

function renderSenders(items) {
	const root = document.getElementById('senders-list');
	if (!root) return;
	root.replaceChildren();

	if (items.length === 0) {
		const empty = document.createElement('li');
		empty.className = 'mp-muted';
		empty.textContent = 'Noch keine Absender erkannt — kommt nach dem ersten Sync.';
		root.appendChild(empty);
		return;
	}

	const trustOptions = [
		{ v: 'trusted',         t: 'Vertraut' },
		{ v: 'unknown',         t: 'Unbekannt' },
		{ v: 'suspected_spoof', t: 'Verdächtig' },
	];

	for (const s of items) {
		const li = document.createElement('li');
		li.className = 'mp-sender-row';
		li.dataset.senderId = s.id;
		if (s.trust_status === 'suspected_spoof') li.classList.add('is-spoof');

		// Stem-Chip (read-only)
		const stem = document.createElement('span');
		stem.className = 'mp-sender-stem';
		stem.textContent = s.sender_key;
		stem.title = (s.registrable_domains || []).join(', ');

		// Display-Name input
		const nameInput = document.createElement('input');
		nameInput.type = 'text';
		nameInput.className = 'mp-sender-name';
		nameInput.value = s.display_name;
		nameInput.maxLength = 120;
		nameInput.placeholder = 'Anzeige-Name';

		// Root-folder input
		const folderInput = document.createElement('input');
		folderInput.type = 'text';
		folderInput.className = 'mp-sender-folder';
		folderInput.value = s.root_folder_name;
		folderInput.maxLength = 120;
		folderInput.placeholder = 'Wurzelordner';

		// Trust-Status select
		const trustSel = document.createElement('select');
		trustSel.className = 'mp-sender-trust';
		for (const o of trustOptions) {
			const opt = document.createElement('option');
			opt.value = o.v;
			opt.textContent = o.t;
			if (s.trust_status === o.v) opt.selected = true;
			trustSel.appendChild(opt);
		}

		// Save-Button
		const saveBtn = document.createElement('button');
		saveBtn.className = 'mp-btn mp-btn-secondary mp-sender-save';
		saveBtn.textContent = 'Speichern';
		saveBtn.addEventListener('click', () => saveSender(s.id, li));

		li.append(stem, nameInput, folderInput, trustSel, saveBtn);
		root.appendChild(li);
	}
}

async function saveSender(senderId, rowEl) {
	const name   = rowEl.querySelector('.mp-sender-name')?.value.trim()   || '';
	const folder = rowEl.querySelector('.mp-sender-folder')?.value.trim() || '';
	const trust  = rowEl.querySelector('.mp-sender-trust')?.value         || 'unknown';
	const btn    = rowEl.querySelector('.mp-sender-save');

	if (btn) { btn.disabled = true; btn.textContent = '…'; }
	try {
		await api.settings.updateSender(senderId, {
			display_name:     name,
			root_folder_name: folder,
			trust_status:     trust,
		});
		showToast('Absender gespeichert.', 'success', 3000);
	} catch (err) {
		handleError(err);
	} finally {
		if (btn) { btn.disabled = false; btn.textContent = 'Speichern'; }
	}
}

