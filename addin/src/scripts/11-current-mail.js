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
	// Phase 5b (Marc 2026-05-19): Done-Button im DieseMail-Tab.
	document.getElementById('btn-current-done')?.addEventListener('click', markCurrentMailDone);
}

/**
 * Phase 5b / 9e — User klickt im DieseMail-Tab auf das Done-Icon rechts neben
 * dem Betreff. Spiegelt das Verhalten der Pin-Card-Done-Buttons im Briefing.
 */
async function markCurrentMailDone() {
	if (!state.currentMailData) return;
	const mailDbId = state.currentMailData.id;
	const btn = document.getElementById('btn-current-done');
	if (btn) { btn.disabled = true; btn.classList.add('is-busy'); }
	try {
		const res = await api.mails.done(mailDbId);
		if (res?.moved) {
			showToast(`Verschoben nach ${res.folder}`, 'success', 4000);
		} else {
			showToast('Als erledigt markiert — bleibt in Inbox (kein Ordner-Vorschlag).', 'info', 4500);
		}
		state.briefingLoaded = false;
	} catch (err) {
		if (btn) { btn.disabled = false; btn.classList.remove('is-busy'); }
		handleError(err);
	}
}

/**
 * Phase 9e — Done-Icon Sichtbarkeit/Disable-State setzen. Wird von
 * loadCurrentMailScore aufgerufen sobald ensureScored einen preview_path
 * liefert (oder null). Icon bleibt immer im DOM (konsistente UI-Position),
 * aber disabled + Tooltip wenn kein Vorschlag.
 */
function updateDoneIcon(previewPath) {
	const btn = document.getElementById('btn-current-done');
	if (!btn) return;
	btn.classList.remove('is-busy');
	if (previewPath) {
		btn.disabled = false;
		btn.title    = `Erledigt → ${previewPath}`;
	} else {
		btn.disabled = true;
		btn.title    = 'Kein Sortier-Vorschlag verfügbar';
	}
}

/**
 * Phase 9e — Topic-Vorschlaege fuer die datalist im „Klassifikation
 * korrigieren"-Form. Distinct letzte folder_segments-Eintraege bisheriger
 * Mails desselben Senders, sortiert nach Haeufigkeit.
 */
async function loadTopicSuggestions(fromEmail) {
	const dl = document.getElementById('correct-topic-suggestions');
	if (!dl) return;
	dl.replaceChildren();
	if (!fromEmail) return;
	try {
		const res = await api.senders.topicSuggestions(fromEmail);
		for (const topic of (res?.items ?? [])) {
			const opt = document.createElement('option');
			opt.value = topic;
			dl.appendChild(opt);
		}
	} catch { /* best-effort */ }
}

function openCorrectForm() {
	if (!state.currentMailData) return;
	const score = state.currentMailData.score ?? {};
	document.getElementById('correct-label').value     = score.label    ?? 'auto';
	document.getElementById('correct-priority').value  = String(score.priority ?? 3);
	document.getElementById('correct-action-required').checked = !!score.action_required;
	document.getElementById('correct-reasoning').value = '';
	document.getElementById('correct-topic').value     = '';
	loadTopicSuggestions(state.currentMailData.from_email || '');
	toggle('correct-section', true);
	document.getElementById('correct-reasoning').focus();
}

async function saveCorrection() {
	if (!state.currentMailData) return;
	const expectedMailId = state.currentMailId;
	const mailDbId = state.currentMailData.id;
	const reasoning = document.getElementById('correct-reasoning').value.trim();
	// Phase 9d (Marc 2026-05-19): Ohne Begruendung lernt die KI nichts.
	// Statt stillem Speichern explizit nachfragen, damit Marc nicht denkt,
	// die KI haette zugehoert. Cancel → return, OK → speichern ohne Inferenz.
	if (reasoning === '') {
		const proceed = window.confirm(
			'Ohne Begründung lernt MailPilot nicht für zukünftige Mails. '
			+ 'Nur diese eine Mail wird korrigiert.\n\nTrotzdem speichern?'
		);
		if (!proceed) {
			document.getElementById('correct-reasoning').focus();
			return;
		}
	}
	const topic   = document.getElementById('correct-topic').value.trim();
	const payload = {
		label:           document.getElementById('correct-label').value,
		priority:        parseInt(document.getElementById('correct-priority').value, 10),
		action_required: document.getElementById('correct-action-required').checked,
		reasoning:       reasoning || null,
		topic:           topic || null,
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
		// Phase 9c/9d: wenn die KI eine generelle Regel abgeleitet hat, Toast
		// nachschieben. Bei hoher Confidence (>= score_rule_auto_enable_threshold,
		// default 85) ist die Regel bereits aktiv; sonst muss der User sie im
		// Settings-Subtab „Regeln" aktivieren.
		if (res?.score_rule_inference?.action === 'created') {
			const sum = res.score_rule_inference.reasoning_summary || 'Regel erkannt';
			if (res.score_rule_inference.auto_enabled) {
				showToast(`✅ KI-Regel aktiv: „${sum}" — wirkt sofort auf neue Mails.`, 'success', 8000);
			} else {
				showToast(`⚙ KI-Vorschlag: „${sum}" — im Settings-Subtab „Regeln" aktivieren.`, 'info', 8000);
			}
		}
		// Phase 9e (Marc 2026-05-19): Topic-Korrektur-Feedback.
		if (res?.moved_to) {
			showToast(`📁 Verschoben nach ${res.moved_to}`, 'success', 5000);
		}
		if (res?.topic_rule_inference?.action === 'created') {
			const sum = res.topic_rule_inference.reasoning_summary || 'Topic-Pattern erkannt';
			if (res.topic_rule_inference.auto_enabled) {
				showToast(`✅ Topic-Regel aktiv: „${sum}" — wirkt sofort auf neue Mails.`, 'success', 8000);
			} else {
				showToast(`⚙ Topic-Vorschlag: „${sum}" — im Settings-Subtab „Regeln" aktivieren.`, 'info', 8000);
			}
		}
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

	// Phase 9e: Done-Icon rechts oben neben Betreff statt Section. Icon
	// bleibt immer im DOM (konsistente Position), aber disabled mit
	// Tooltip wenn keine Sortier-Empfehlung.
	updateDoneIcon(score.preview_path ?? null);

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

