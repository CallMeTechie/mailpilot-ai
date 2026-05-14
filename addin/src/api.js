/**
 * MailPilot API client — talks to our PHP backend.
 * Stores JWT in localStorage (per-session, cleared on Outlook close).
 */

// The task pane is served by our own backend, so the API lives on the
// same origin. Avoids hardcoding a deployment-specific domain.
// An optional <meta name="mp-api-base" content="https://…/api/v1"> overrides
// this — useful for sideloaded dev where the taskpane and backend may
// run on different hosts.
const META_BASE = document.querySelector('meta[name="mp-api-base"]')?.content;
const BASE_URL = (META_BASE && META_BASE.length > 0)
	? META_BASE.replace(/\/$/, '')
	: `${window.location.origin}/api/v1`;
const TOKEN_KEY = 'mp_jwt';

export class ApiError extends Error {
	constructor(message, code, status) {
		super(message);
		this.code = code;
		this.status = status;
	}
}

function getToken() {
	return localStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
	localStorage.setItem(TOKEN_KEY, token);
	// Arm the pre-emptive refresh loop. If one is already running it's
	// re-started cleanly with a fresh interval.
	startTokenRefreshLoop();
}

export function clearToken() {
	localStorage.removeItem(TOKEN_KEY);
	stopTokenRefreshLoop();
}

/**
 * Dedupes concurrent refresh attempts — if 12 parallel requests hit
 * a 401 at the same time, we want exactly one /auth/refresh roundtrip,
 * not 12.
 */
let refreshInFlight = null;

async function tryRefreshToken() {
	if (refreshInFlight) return refreshInFlight;
	const dead = getToken();
	if (!dead) return null;
	refreshInFlight = (async () => {
		try {
			const res = await fetch(`${BASE_URL}/auth/refresh`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'Authorization': `Bearer ${dead}`,
				},
				body: JSON.stringify({ token: dead }),
			});
			if (!res.ok) return null;
			const data = await res.json().catch(() => null);
			if (data && typeof data.token === 'string' && data.token.length > 0) {
				setToken(data.token);
				return data.token;
			}
			return null;
		} catch {
			return null;
		}
	})();
	try { return await refreshInFlight; }
	finally { refreshInFlight = null; }
}

async function request(method, path, body = null, _retried = false) {
	const token = getToken();
	const headers = { 'Content-Type': 'application/json' };
	if (token) {
		headers['Authorization'] = `Bearer ${token}`;
	}

	const res = await fetch(`${BASE_URL}${path}`, {
		method,
		headers,
		body: body ? JSON.stringify(body) : null,
	});

	if (res.status === 204) return null;

	const data = await res.json().catch(() => ({}));

	if (!res.ok) {
		// 401-Recovery: one-shot refresh-then-retry. Only effective in the
		// <30s leeway window after expiry (the server's /auth/refresh
		// requires a still-decodable JWT) — for the long pause case the
		// pre-emptive refresh loop in taskpane.js keeps the token fresh.
		// The /auth/refresh path is exempt to avoid infinite recursion.
		if (res.status === 401 && !_retried && !path.startsWith('/auth/refresh')) {
			const fresh = await tryRefreshToken();
			if (fresh) {
				return request(method, path, body, true);
			}
		}
		if (res.status === 401) {
			clearToken();
		}
		const err = data.error ?? {};
		throw new ApiError(
			err.message ?? `HTTP ${res.status}`,
			err.code ?? 'UNKNOWN',
			res.status,
		);
	}

	return data;
}

/**
 * Pre-emptive token refresh: while the add-in is open, refresh the JWT
 * in the background every 30 minutes. Together with the 8h TTL this
 * means the user never sees a 401 unless the add-in was closed for
 * > 8h and reopened. Call once from taskpane.js after successful login.
 */
let refreshLoopId = null;
export function startTokenRefreshLoop(intervalMs = 30 * 60 * 1000) {
	stopTokenRefreshLoop();
	refreshLoopId = setInterval(() => {
		// Fire-and-forget; tryRefreshToken handles its own dedupe + error.
		tryRefreshToken();
	}, intervalMs);
}

export function stopTokenRefreshLoop() {
	if (refreshLoopId !== null) {
		clearInterval(refreshLoopId);
		refreshLoopId = null;
	}
}

export const api = {
	auth: {
		oauthStart:    ()      => request('POST', '/auth/oauth/start'),
		exchange:      (state) => request('GET',  `/auth/oauth/exchange?state=${encodeURIComponent(state)}`),
		refresh:       (t)     => request('POST', '/auth/refresh', { token: t }),
	},
	briefing: {
		today:         ()      => request('GET',  '/briefing/today'),
	},
	mails: {
		list:          (q='')  => request('GET',  `/mails${q}`),
		ensureScored:  (msId)  => request('POST', `/mails/by-graph-id/${encodeURIComponent(msId)}/ensure-scored`),
		summarize:     (id)    => request('POST', `/mails/${id}/summarize`),
		draftReply:    (id, i) => request('POST', `/mails/${id}/draft-reply`, { instruction: i ?? null }),
		rescore:       (id)    => request('POST', `/mails/${id}/rescore`),
		correctScore:  (id, payload) => request('POST', `/mails/${id}/correct-score`, payload),
		bulkAction:    (action, label, since) => request('POST',
			`/mails/bulk/${encodeURIComponent(action)}`,
			{ label, since: since ?? null }),
	},
	sync: {
		trigger:       ()      => request('POST', '/sync'),
		status:        (id)    => request('GET',  `/sync/status/${id}`),
	},
	settings: {
		getUser:       ()      => request('GET',  '/settings/user'),
		updateUser:    (p)     => request('PATCH','/settings/user', p),
		listVip:       ()      => request('GET',  '/settings/vip'),
		addVip:        (e, n)  => request('POST', '/settings/vip', { email: e, name: n }),
		deleteVip:     (id)    => request('DELETE', `/settings/vip/${id}`),
		listRedaction: ()      => request('GET',  '/settings/redaction'),
		addRedaction:  (p, d)  => request('POST', '/settings/redaction', { pattern: p, description: d }),
		listAutoSort:  ()      => request('GET',  '/settings/auto-sort'),
		updateAutoSort:(rules) => request('PATCH','/settings/auto-sort', { rules }),
		applyAutoSortNow: (limit = 50, afterId = null) => request('POST', '/settings/auto-sort/apply-now', { limit, after_id: afterId }),
		deleteAutoSortSub: (label, name) => request('DELETE',
			`/settings/auto-sort/sub/${encodeURIComponent(label)}/${encodeURIComponent(name)}`),
		rescoreAll:    ()      => request('POST', '/settings/rescore-all'),
		listSubLabels:   ()        => request('GET',    '/settings/sub-labels'),
		addSubLabel:     (payload) => request('POST',   '/settings/sub-labels', payload),
		updateSubLabel:  (id, p)   => request('PATCH',  `/settings/sub-labels/${id}`, p),
		deleteSubLabel:  (id)      => request('DELETE', `/settings/sub-labels/${id}`),
	},
	me: {
		export:        ()      => request('GET',    '/me/export'),
		deleteAccount: ()      => request('DELETE', '/me'),
		profile:       ()      => request('GET',    '/me/profile'),
		scanAliases:   ()      => request('POST',   '/me/aliases/scan'),
		saveAliases:   (list)  => request('POST',   '/me/aliases', { aliases: list }),
		acknowledgePrivacy: () => request('POST',   '/me/privacy-acknowledge'),
	},
};
