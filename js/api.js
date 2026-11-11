// Thin fetch wrapper for the PHP JSON API.
// The session cookie (HttpOnly) is the only source of truth for identity;
// nothing about auth, payments or permissions is stored in localStorage.

/* global __APP_BASE__ */
export const APP_BASE = __APP_BASE__; // "/laxyam/" when built for XAMPP, "/" in dev or on a domain root
export const API_BASE = APP_BASE + 'api/';

export class ApiError extends Error {
  constructor(message, status, code, fields) {
    super(message);
    this.status = status;
    this.code = code;
    this.fields = fields || {};
  }
}

let csrfToken = null;

// ---------- First-touch campaign attribution (UTM / referrer), kept for this browser session ----------
const ATTR_KEY = 'lx-attr';
export function captureAttribution() {
  try {
    if (sessionStorage.getItem(ATTR_KEY)) return;
    const q = new URLSearchParams(window.location.search);
    const ref = document.referrer ? new URL(document.referrer).hostname.replace(/^www\./, '') : '';
    const external = ref && ref !== window.location.hostname.replace(/^www\./, '');
    const a = {
      utm_source: q.get('utm_source') || (external ? ref : ''),
      utm_medium: q.get('utm_medium') || (external ? 'referral' : ''),
      utm_campaign: q.get('utm_campaign') || '',
      referrer_host: external ? ref : '',
      landing_path: window.location.pathname.slice(0, 250),
    };
    sessionStorage.setItem(ATTR_KEY, JSON.stringify(a));
  } catch { /* storage unavailable */ }
}
function attribution() {
  try { return JSON.parse(sessionStorage.getItem(ATTR_KEY) || 'null'); } catch { return null; }
}
const ATTRIBUTED = [/^enquiries\.php$/, /^chat\.php\?action=lead$/, /^auth\.php\?action=register$/, /^auth\.php\?action=email-otp-(send|signup)$/, /^orders\.php\?action=create$/];

async function fetchCsrf() {
  const data = await request('auth.php', { params: { action: 'csrf' } });
  csrfToken = data.token;
  return csrfToken;
}

export function resetCsrf(token = null) {
  csrfToken = token;
}

export async function request(path, { method = 'GET', params, body, signal, retry = true } = {}) {
  const url = new URL(API_BASE + path, window.location.origin);
  if (params) {
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)) continue;
      url.searchParams.set(k, Array.isArray(v) ? v.join(',') : String(v));
    }
  }

  const headers = { Accept: 'application/json' };
  const unsafe = method !== 'GET' && method !== 'HEAD';
  const key = path + (params?.action ? `?action=${params.action}` : '');
  if (unsafe && body && typeof body === 'object' && ATTRIBUTED.some((re) => re.test(key))) {
    const a = attribution();
    if (a) body = { ...body, attribution: a };
    const recaptcha = await recaptchaToken(key);
    if (recaptcha) body = { ...body, recaptcha };
  }
  if (unsafe) {
    headers['Content-Type'] = 'application/json';
    headers['X-CSRF-Token'] = csrfToken || (await fetchCsrf());
  }

  let res;
  try {
    res = await fetch(url, {
      method,
      headers,
      body: unsafe && body !== undefined ? JSON.stringify(body) : undefined,
      credentials: 'same-origin',
      signal,
    });
  } catch (err) {
    if (err.name === 'AbortError') throw err;
    throw new ApiError('Unable to reach the server. Check your connection and try again.', 0, 'network');
  }

  let payload = null;
  try {
    payload = await res.json();
  } catch {
    /* non-JSON response */
  }

  if (res.status === 403 && payload?.error?.code === 'csrf_failed' && unsafe && retry) {
    // Session or token expired: get a fresh token and retry once.
    csrfToken = null;
    return request(path, { method, params, body, signal, retry: false });
  }

  if (!res.ok || !payload || payload.ok !== true) {
    const e = payload?.error || {};
    throw new ApiError(e.message || 'Something went wrong. Please try again.', res.status, e.code, e.fields);
  }
  return payload.data;
}

/** Absolute URL to an API endpoint for plain links (downloads, receipts). */
/** reCAPTCHA v3 token when the admin enabled it (index.php loads the script), otherwise ''. */
export async function recaptchaToken(action) {
  if (!window.grecaptcha || !window.LX_RECAPTCHA_KEY) return '';
  try {
    return await window.grecaptcha.execute(window.LX_RECAPTCHA_KEY, { action: action.replace(/\W+/g, '_') });
  } catch {
    return ''; // verified server-side; a missing token is rejected there
  }
}

export function apiUrl(path, params = {}) {
  const url = new URL(API_BASE + path, window.location.origin);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  return url.toString();
}

export const api = {
  get: (path, params, opts) => request(path, { ...opts, params }),
  post: (path, body, params) => request(path, { method: 'POST', body, params }),
  /** multipart upload of a single File as field "file" */
  async upload(path, file, params = {}, fields = {}) {
    const token = csrfToken || (await fetchCsrf());
    const form = new FormData();
    form.append('file', file);
    Object.entries(fields).forEach(([k, v]) => form.append(k, v));
    const res = await fetch(apiUrl(path, params), { method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-CSRF-Token': token, Accept: 'application/json' } });
    const payload = await res.json().catch(() => null);
    if (!res.ok || !payload?.ok) {
      const e = payload?.error || {};
      throw new ApiError(e.message || 'Upload failed.', res.status, e.code, e.fields);
    }
    return payload.data;
  },
};

/** Format a rupee amount: 9999 -> "₹9,999" */
/** "₹4,499" for whole rupees, "₹4,499.10" when there are paise (always 2 decimals then, like invoices). */
export function formatPrice(amount) {
  if (amount === null || amount === undefined) return '';
  // Round to paise first so float noise (4499.0000001) never shows as "₹4,499.00".
  const n = Math.round(Number(amount) * 100) / 100;
  return '₹' + n.toLocaleString('en-IN', Number.isInteger(n) ? { maximumFractionDigits: 0 } : { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const IST = 'Asia/Kolkata';

/** "15 Feb 2026, 5:00 PM" in India time */
export function formatDateTime(iso) {
  if (!iso) return '';
  return new Intl.DateTimeFormat('en-IN', {
    day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true, timeZone: IST,
  }).format(new Date(iso));
}

export function formatDate(iso) {
  if (!iso) return '';
  return new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric', timeZone: IST }).format(new Date(iso));
}
