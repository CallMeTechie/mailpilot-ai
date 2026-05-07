/**
 * MailPilot API client — talks to our PHP backend.
 * Stores JWT in sessionStorage (per-session, cleared on Outlook close).
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
	return sessionStorage.getItem(TOKEN_KEY);
}

export function setToken(token) {
	sessionStorage.setItem(TOKEN_KEY, token);
}

export function clearToken() {
	sessionStorage.removeItem(TOKEN_KEY);
}

async function request(method, path, body = null) {
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
		const err = data.error ?? {};
		throw new ApiError(
			err.message ?? `HTTP ${res.status}`,
			err.code ?? 'UNKNOWN',
			res.status,
		);
	}

	return data;
}

export const api = {
	auth: {
		oauthStart:    ()      => request('POST', '/auth/oauth/start'),
		refresh:       (t)     => request('POST', '/auth/refresh', { token: t }),
	},
	briefing: {
		today:         ()      => request('GET',  '/briefing/today'),
	},
	mails: {
		list:          (q='')  => request('GET',  `/mails${q}`),
		summarize:     (id)    => request('POST', `/mails/${id}/summarize`),
		draftReply:    (id, i) => request('POST', `/mails/${id}/draft-reply`, { instruction: i ?? null }),
		rescore:       (id)    => request('POST', `/mails/${id}/rescore`),
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
	},
	me: {
		export:        ()      => request('GET',    '/me/export'),
		deleteAccount: ()      => request('DELETE', '/me'),
	},
};
