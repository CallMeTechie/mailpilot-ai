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

