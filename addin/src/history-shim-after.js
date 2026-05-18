// Phase-H9 (CSP-clean) — Part 2: restore window.history.replaceState +
// pushState aus _historyCache, NACHDEM office.js sie auf null gesetzt hat.
// Muss SYNCHRON nach office.js geladen werden.
window.history.replaceState = window._historyCache.replaceState;
window.history.pushState    = window._historyCache.pushState;
