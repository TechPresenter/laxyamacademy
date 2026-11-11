// Referral links (?ref=CODE): the code is remembered on this device for 30 days so the checkout can
// suggest it. Convenience only - the server decides at checkout whether the code applies.
const KEY = 'lx-ref';
const DAYS = 30;

export function captureReferral(search) {
  const code = new URLSearchParams(search || '').get('ref');
  if (!code || !/^[A-Za-z0-9-]{4,40}$/.test(code)) return;
  try { localStorage.setItem(KEY, JSON.stringify({ code: code.toUpperCase(), at: Date.now() })); } catch { /* storage unavailable */ }
}

export function referralCode() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || 'null');
    return v && typeof v.code === 'string' && Date.now() - Number(v.at) < DAYS * 864e5 ? v.code : '';
  } catch {
    return '';
  }
}

export function forgetReferral() {
  try { localStorage.removeItem(KEY); } catch { /* storage unavailable */ }
}
