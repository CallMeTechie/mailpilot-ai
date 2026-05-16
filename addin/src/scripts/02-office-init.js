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
	window.addEventListener('message', (event) => {
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
});

