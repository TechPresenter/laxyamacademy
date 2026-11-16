// Analytics events for GA4 (gtag.js) and Google Tag Manager (dataLayer), both configured in admin →
// Integrations. Nothing is sent when no tag is set up, and no personal data (names, phone numbers, emails)
// is ever put into an event. Names follow GA4's recommended events where one exists:
//   generate_lead       an enquiry, admission (Apply Now), callback or chatbot lead form was sent   (form)
//   sign_up             a student account was created                                             (method)
//   begin_checkout      an online course order was created
//   purchase            a course payment succeeded (PaymentResult) - kept apart from enquiries
//   phone_click         a tel: link            whatsapp_click   a WhatsApp link      email_click   a mailto: link
//   enroll_click / apply_click   admission buttons (data-track="…" on the element)

/** Sends one event; analytics must never break the page. */
export function track(name, params = {}) {
  const p = { page_path: window.location.pathname, ...params };
  try {
    if (typeof window.gtag === 'function') window.gtag('event', name, p);
    if (window.LX_GTM && Array.isArray(window.dataLayer)) window.dataLayer.push({ event: name, ...p });
  } catch {
    /* ignore */
  }
}

const WHATSAPP = /^(https?:)?\/\/(wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com|(www\.)?whatsapp\.com)\b/i;
let installed = false;

/** One document-level listener for call / WhatsApp / email links and [data-track] buttons (main.jsx). */
export function installClickTracking() {
  if (installed || typeof document === 'undefined') return;
  installed = true;
  document.addEventListener('click', (e) => {
    const el = e.target instanceof Element ? e.target.closest('[data-track], a[href]') : null;
    if (!el) return;
    const custom = el.getAttribute('data-track');
    if (custom) {
      track(custom, { label: (el.getAttribute('data-track-label') || el.textContent || '').trim().slice(0, 80) });
      return;
    }
    const href = el.getAttribute('href') || '';
    if (href.startsWith('tel:')) track('phone_click', { link_url: href });
    else if (WHATSAPP.test(href)) track('whatsapp_click', { link_url: href.split('?')[0] });
    else if (href.startsWith('mailto:')) track('email_click');
  }, { capture: true });
}

/** Events for successful API calls (js/api.js): leads, sign-ups, checkout. */
export function trackApiSuccess(key, body) {
  if (/^enquiries\.php/.test(key)) track('generate_lead', { form: String(body?.source || 'enquiry').slice(0, 40), course: body?.course_slug || undefined });
  else if (key === 'chat.php?action=lead') track('generate_lead', { form: 'chatbot' });
  else if (key === 'auth.php?action=register') track('sign_up', { method: 'password' });
  else if (key === 'auth.php?action=email-otp-signup') track('sign_up', { method: 'email_otp' });
  else if (key === 'orders.php?action=create') track('begin_checkout', { currency: 'INR' });
}
