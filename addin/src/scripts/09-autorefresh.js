// ============================================================
// Auto-refresh — quietly reload briefing every 60 s while the user
// sitzt auf dem Briefing-Tab. Pausiert wenn das Dokument unsichtbar
// ist, damit wir keine API-Calls verbrennen.
// Phase 9p (Marc 2026-05-22): Filter-View entfernt — die separate
// state.filterLabel-Bedingung ist damit obsolet.
// ============================================================
let autoRefreshTimer = null;

function startAutoRefresh() {
	if (autoRefreshTimer !== null) return;
	autoRefreshTimer = setInterval(() => {
		if (document.hidden) return;
		if (!localStorage.getItem('mp_jwt')) return;
		const activeTab = document.querySelector('.mp-tab.is-active')?.dataset.tab;

		// Briefing nur refreshen wenn der User wirklich darauf schaut.
		if (activeTab === 'briefing') {
			loadBriefing();
		}

		// Pending-Badge MUSS überall live sein — sonst sieht Marc nicht
		// dass eine neue rule_suggestion eingetroffen ist während er auf
		// „Heute" oder „Diese Mail" arbeitet.
		loadPending().catch(() => { /* silent */ });
	}, 60 * 1000);
}

