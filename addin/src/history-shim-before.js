// Phase-H9 (CSP-clean) — Part 1: cache window.history.replaceState +
// pushState BEVOR office.js sie mit null ueberschreibt.
// Muss SYNCHRON (kein async, kein defer) vor office.js geladen werden.
// Doku: learn.microsoft.com/de-de/office/dev/add-ins/develop/connect-to-javascript-frameworks
window._historyCache = {
	replaceState: window.history.replaceState,
	pushState: window.history.pushState
};
