// Newsletter sign-up logic shared by the homepage band and the page-builder block.
// POST enquiries.php?action=newsletter { email, name?, source, consent?, website }.
// Submitting the form is consent for a new subscriber, so `consent` is only sent once the visitor
// ticks the box. The server answers 422 code "consent_required" for an address that unsubscribed
// earlier; the form then shows the consent checkbox and the visitor confirms explicitly.
import { useState } from 'react';
import { api } from '../api.js';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/** source: one of the API's NEWSLETTER_SOURCES (footer, home, page, popup, blog, course_page, newsletter_block, other). */
export function useNewsletterSignup(source) {
  const [state, setState] = useState({ status: 'idle', message: '', fields: {} });
  const [consentNeeded, setConsentNeeded] = useState(false);
  const [consent, setConsentValue] = useState(false);

  const setConsent = (value) => {
    setConsentValue(value);
    if (value) setState((s) => (s.fields.consent ? { ...s, fields: { ...s.fields, consent: undefined } } : s));
  };

  /** Returns true on success. */
  const submit = async ({ email, name = '', website = '' }) => {
    const clean = String(email || '').trim();
    if (!EMAIL_RE.test(clean)) {
      setState({ status: 'error', message: '', fields: { email: 'Enter a valid email address.' } });
      return false;
    }
    if (consentNeeded && !consent) {
      setState({ status: 'error', message: '', fields: { consent: 'Please tick the box to confirm you want our emails again.' } });
      return false;
    }
    setState({ status: 'busy', message: '', fields: {} });
    try {
      const res = await api.post('enquiries.php', {
        email: clean,
        name: name.trim() || undefined,
        source,
        ...(consentNeeded && consent ? { consent: true } : {}),
        website,
      }, { action: 'newsletter' });
      setState({ status: 'ok', message: res?.message || 'You are subscribed. Watch your inbox for exam updates.', fields: {} });
      return true;
    } catch (err) {
      if (err.code === 'consent_required') {
        setConsentNeeded(true);
        setConsentValue(false);
      }
      setState({ status: 'error', message: err.message, fields: err.fields || {} });
      return false;
    }
  };

  return { state, submit, consentNeeded, consent, setConsent };
}

/** Consent checkbox for a coloured (dark) newsletter panel; rendered only after the server asked for it. */
export function NewsletterConsent({ id, checked, onChange, error }) {
  return (
    <div className="text-left">
      <label htmlFor={id} className="flex cursor-pointer items-start gap-2.5 text-sm text-white/90">
        <input id={id} type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)}
          aria-invalid={!!error} aria-describedby={error ? `${id}-err` : undefined}
          className="mt-0.5 size-4 shrink-0 rounded border-white/40 accent-gold" />
        <span>Yes, subscribe me again - I want to receive exam updates and offers by email. I can unsubscribe at any time.</span>
      </label>
      {error && <p id={`${id}-err`} className="mt-1 text-sm font-semibold text-gold">{error}</p>}
    </div>
  );
}
