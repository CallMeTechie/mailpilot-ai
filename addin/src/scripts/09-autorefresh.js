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

