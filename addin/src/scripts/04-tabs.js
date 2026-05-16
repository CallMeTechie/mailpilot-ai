// ============================================================
// Tabs
// ============================================================
function initTabs() {
	document.querySelectorAll('.mp-tab').forEach((tab) => {
		tab.addEventListener('click', () => {
			const name = tab.dataset.tab;
			document.querySelectorAll('.mp-tab').forEach(t => t.classList.remove('is-active'));
			document.querySelectorAll('.mp-panel').forEach(p => p.classList.remove('is-active'));
			tab.classList.add('is-active');
			document.querySelector(`.mp-panel[data-panel="${name}"]`).classList.add('is-active');

			if (name === 'briefing') {
				loadBriefing();
			}
			if (name === 'today') {
				loadToday();
			}
			if (name === 'current') {
				onItemChanged();
			}
			if (name === 'pending') {
				loadPending();
			}
			// Bei jedem Tab-Switch: Pending-Badge silent refresh — Marc
			// soll auch in „Diese Mail" sehen, wenn ein neuer Vorschlag
			// reinkommt, ohne erst auf Pending oder Briefing wechseln.
			if (name !== 'pending') {
				loadPending().catch(() => { /* badge stays stale, harmless */ });
			}
		});
	});
}

