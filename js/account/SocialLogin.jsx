// Password-less sign-in: "Continue with Google" and a one-time code by email or on the student's mobile
// (WhatsApp or SMS). Each option appears only when the academy has switched it on (auth.php?action=options);
// the server verifies everything and the PHP session decides who is signed in.
import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, resetCsrf } from '../api.js';
import { useAuth } from '../auth.jsx';
import { Alert, Icon } from '../components.jsx';
import { Busy, IconField } from './AuthLayout.jsx';

/** Enabled sign-in methods: [options | null while loading, reload]. Reload gives the Google button a fresh one-time nonce. */
export function useLoginOptions() {
  const [opts, setOpts] = useState(null);
  const load = useCallback(() => {
    api.get('auth.php', { action: 'options' })
      .then(setOpts)
      .catch(() => setOpts({ google: null, otp: { channels: [] }, email_otp: false, phone: null }));
  }, []);
  useEffect(() => { load(); }, [load]);
  return [opts, load];
}

let gsiLoading = null;
function loadGoogle() {
  gsiLoading ??= new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://accounts.google.com/gsi/client';
    s.async = true;
    s.onload = () => resolve(window.google);
    s.onerror = () => { gsiLoading = null; reject(new Error('Google sign-in could not load.')); };
    document.head.appendChild(s);
  });
  return gsiLoading;
}

/** Google's own button (Google Identity Services). onDone({ user, created }) after the server has signed the student in. */
export function GoogleButton({ google, onDone, onRetry, text = 'continue_with' }) {
  const box = useRef(null);
  const { setUser } = useAuth();
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const done = useRef(onDone);
  done.current = onDone;

  useEffect(() => {
    if (!google) return undefined;
    let live = true;
    loadGoogle().then((g) => {
      if (!live || !box.current) return;
      g.accounts.id.initialize({
        client_id: google.client_id,
        nonce: google.nonce, // one-time, issued to this session; the server checks it
        ux_mode: 'popup',
        callback: async ({ credential }) => {
          setBusy(true);
          setError(null);
          try {
            const d = await api.post('auth.php', { credential }, { action: 'google' });
            resetCsrf(d.csrf);
            setUser(d.user);
            done.current?.(d);
          } catch (e) {
            setError(e.message || 'Google sign-in failed. Please try again.');
            onRetry?.(); // a new nonce for the next try
          } finally {
            setBusy(false);
          }
        },
      });
      box.current.replaceChildren();
      g.accounts.id.renderButton(box.current, { type: 'standard', theme: 'outline', size: 'large', text, shape: 'pill', logo_alignment: 'center', width: Math.min(400, box.current.offsetWidth || 320) });
    }).catch((e) => live && setError(e.message + ' Use another way to log in.'));
    return () => { live = false; };
  }, [google, text, setUser, onRetry]);

  if (!google) return null;
  return (
    <div>
      <div ref={box} className={'flex min-h-11 justify-center ' + (busy ? 'pointer-events-none opacity-60' : '')} aria-busy={busy} />
      {busy && <p className="mt-2 text-center text-xs text-muted" role="status">Signing you in…</p>}
      {error && <Alert className="mt-3">{error}</Alert>}
    </div>
  );
}

/** "or" between sign-in options. */
export function OrDivider({ label = 'or' }) {
  return (
    <div className="my-6 flex items-center gap-3 text-xs font-semibold tracking-wide text-muted uppercase" role="separator">
      <span className="h-px flex-1 bg-line" />{label}<span className="h-px flex-1 bg-line" />
    </div>
  );
}

const CHANNEL = {
  whatsapp: { label: 'WhatsApp', icon: 'whatsapp', cls: 'text-[#25D366]' },
  sms: { label: 'SMS', icon: 'phone', cls: 'text-primary' },
};

/** Six-digit code box shared by the email and mobile flows. */
function CodeInput({ inputRef, value, onChange }) {
  return (
    <div>
      <label htmlFor="otp-code" className="label">One-time code</label>
      <input id="otp-code" ref={inputRef} className="input text-center font-mono text-2xl tracking-[0.5em]" inputMode="numeric" autoComplete="one-time-code" maxLength={6}
        pattern="\d{6}" required value={value} onChange={(e) => onChange(e.target.value.replace(/\D/g, '').slice(0, 6))} aria-describedby="otp-help" />
      <p id="otp-help" className="mt-1 text-xs text-muted">The code works for 10 minutes. Never share it with anyone.</p>
    </div>
  );
}

/**
 * Email → one-time code → signed in. A new address gets a short sign-up form (name + terms) once the code
 * proves it. onDone(result) after the server has signed the student in.
 */
export function EmailOtpFlow({ initialEmail = '', onDone }) {
  const { setUser } = useAuth();
  const [step, setStep] = useState('email'); // email | code | signup
  const [email, setEmail] = useState(initialEmail);
  const [code, setCode] = useState('');
  const [masked, setMasked] = useState('');
  const [wait, setWait] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});
  const [signup, setSignup] = useState({ name: '', terms: false });
  const codeRef = useRef(null);

  useEffect(() => {
    if (wait <= 0) return undefined;
    const t = setTimeout(() => setWait((w) => w - 1), 1000);
    return () => clearTimeout(t);
  }, [wait]);
  useEffect(() => { if (step === 'code') codeRef.current?.focus(); }, [step]);

  const validEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());

  const send = async () => {
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const d = await api.post('auth.php', { email: email.trim() }, { action: 'email-otp-send' });
      setMasked(d.masked);
      setWait(d.resend_in || 45);
      setCode('');
      setStep('code');
    } catch (e) {
      if (e.fields?.resend_in) setWait(Number(e.fields.resend_in));
      setFieldErrors(e.fields?.email ? { email: e.fields.email } : {});
      setError(e.fields?.email ? null : e.message);
    } finally {
      setBusy(false);
    }
  };

  const verify = async (e) => {
    e?.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const d = await api.post('auth.php', { email: email.trim(), code }, { action: 'email-otp-verify' });
      if (d.user) {
        resetCsrf(d.csrf);
        setUser(d.user);
        onDone?.(d);
      } else if (d.needs_signup) {
        setStep('signup');
      }
    } catch (err) {
      setError(err.message);
      if (err.code === 'otp_expired' || err.code === 'otp_locked') setCode('');
    } finally {
      setBusy(false);
    }
  };

  const create = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const d = await api.post('auth.php', { name: signup.name.trim(), accept_terms: signup.terms }, { action: 'email-otp-signup' });
      resetCsrf(d.csrf);
      setUser(d.user);
      onDone?.({ ...d, created: true });
    } catch (err) {
      const f = err.fields || {}; // ApiError always has a fields object: show the message when no field is named
      setFieldErrors(f);
      setError(Object.keys(f).length ? null : err.message);
      if (err.code === 'otp_expired') setStep('email');
    } finally {
      setBusy(false);
    }
  };

  if (step === 'signup') {
    return (
      <form onSubmit={create} noValidate className="space-y-4" aria-busy={busy}>
        <p className="flex items-center gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"><Icon name="checkCircle" className="size-4 shrink-0" />Email {masked} confirmed. You are new here - just add your name to create your free account.</p>
        {error && <Alert>{error}</Alert>}
        <IconField id="eotp-name" label="Full name" icon="user" autoComplete="name" required value={signup.name} error={fieldErrors.name}
          onChange={(e) => setSignup((s) => ({ ...s, name: e.target.value }))} />
        <label className="flex items-start gap-2.5 text-sm text-ink">
          <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={signup.terms} aria-invalid={!!fieldErrors.accept_terms} onChange={(e) => setSignup((s) => ({ ...s, terms: e.target.checked }))} />
          <span>I agree to the <Link to="/terms" className="font-semibold text-primary hover:underline">Terms</Link> and <Link to="/privacy-policy" className="font-semibold text-primary hover:underline">Privacy Policy</Link>.</span>
        </label>
        {fieldErrors.accept_terms && <p className="-mt-2 text-xs font-medium text-red-600">{fieldErrors.accept_terms}</p>}
        <button type="submit" disabled={busy} className="btn-primary btn-lg w-full">{busy ? <><Busy /> Creating account…</> : <>Create account <Icon name="arrowRight" className="size-4" /></>}</button>
      </form>
    );
  }

  if (step === 'code') {
    return (
      <form onSubmit={verify} noValidate className="space-y-4" aria-busy={busy}>
        <p className="text-sm text-ink">
          Enter the 6-digit code we emailed to <strong className="text-navy">{masked}</strong>. Check the spam folder if it is not in your inbox.{' '}
          <button type="button" onClick={() => { setStep('email'); setError(null); }} className="font-semibold text-primary hover:underline">Change email</button>
        </p>
        {error && <Alert>{error}</Alert>}
        <CodeInput inputRef={codeRef} value={code} onChange={setCode} />
        <button type="submit" disabled={busy || code.length !== 6} className="btn-primary btn-lg w-full">
          {busy ? <><Busy /> Checking…</> : <>Log in <Icon name="arrowRight" className="size-4" /></>}
        </button>
        <div className="text-center text-sm">
          {wait > 0
            ? <span className="text-muted" aria-live="polite">Resend in {wait}s</span>
            : <button type="button" onClick={send} disabled={busy} className="font-semibold text-primary hover:underline">Resend code</button>}
        </div>
      </form>
    );
  }

  return (
    <form onSubmit={(e) => { e.preventDefault(); send(); }} noValidate className="space-y-4" aria-busy={busy}>
      {error && <Alert>{error}</Alert>}
      <IconField id="eotp-email" label="Email address" icon="mail" type="email" inputMode="email" autoComplete="email" required placeholder="you@example.com"
        value={email} onChange={(e) => setEmail(e.target.value)} error={fieldErrors.email} hint="We will email you a 6-digit login code - no password needed." />
      <button type="submit" disabled={busy || !validEmail || wait > 0} className="btn-primary btn-lg w-full">
        {busy ? <><Busy /> Sending code…</> : wait > 0 ? `Wait ${wait}s` : <>Send login code <Icon name="send" className="size-4" /></>}
      </button>
    </form>
  );
}

/**
 * Mobile number → one-time code → signed in. purpose 'login' signs in (or, for a new number, asks for
 * name + email); purpose 'verify' confirms the signed-in student's own number. onDone(result).
 */
export function OtpFlow({ channels, purpose = 'login', initialPhone = '', onDone }) {
  const { setUser } = useAuth();
  const [step, setStep] = useState('phone'); // phone | code | signup
  const [phone, setPhone] = useState(() => initialPhone.replace(/\D/g, '').slice(-10));
  const [channel, setChannel] = useState(channels[0]);
  const [code, setCode] = useState('');
  const [sent, setSent] = useState(null); // { masked, channel }
  const [wait, setWait] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [signup, setSignup] = useState({ name: '', email: '', terms: false, whatsapp: false, existing: false });
  const [fieldErrors, setFieldErrors] = useState({});
  const codeRef = useRef(null);

  useEffect(() => {
    if (wait <= 0) return undefined;
    const t = setTimeout(() => setWait((w) => w - 1), 1000);
    return () => clearTimeout(t);
  }, [wait]);
  useEffect(() => { if (step === 'code') codeRef.current?.focus(); }, [step]);

  const send = async (via = channel) => {
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const d = await api.post('auth.php', { phone, channel: via, purpose }, { action: 'otp-send' });
      setSent({ masked: d.masked, channel: via });
      setChannel(via);
      setWait(d.resend_in || 45);
      setCode('');
      setStep('code');
    } catch (e) {
      if (e.fields?.resend_in) setWait(Number(e.fields.resend_in));
      setFieldErrors(e.fields?.phone ? { phone: e.fields.phone } : {});
      setError(e.message);
    } finally {
      setBusy(false);
    }
  };

  const verify = async (e) => {
    e?.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const d = await api.post('auth.php', { phone, code, purpose }, { action: 'otp-verify' });
      if (d.user) {
        resetCsrf(d.csrf);
        setUser(d.user);
        onDone?.(d);
      } else if (d.verified) {
        onDone?.(d);
      } else if (d.needs_signup) {
        setSignup((s) => ({ ...s, existing: !!d.existing_unconfirmed }));
        setStep('signup');
      }
    } catch (err) {
      setError(err.message);
      if (err.code === 'otp_expired' || err.code === 'otp_locked') setCode('');
    } finally {
      setBusy(false);
    }
  };

  const create = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const d = await api.post('auth.php', { name: signup.name.trim(), email: signup.email.trim(), accept_terms: signup.terms, whatsapp_opt_in: signup.whatsapp }, { action: 'otp-signup' });
      resetCsrf(d.csrf);
      setUser(d.user);
      onDone?.({ ...d, created: true });
    } catch (err) {
      const f = err.fields || {}; // ApiError always has a fields object: show the message when no field is named
      setFieldErrors(f);
      setError(Object.keys(f).length ? null : err.message);
      if (err.code === 'otp_expired') setStep('phone');
    } finally {
      setBusy(false);
    }
  };

  if (step === 'signup') {
    return (
      <form onSubmit={create} noValidate className="space-y-4" aria-busy={busy}>
        <p className="flex items-center gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"><Icon name="checkCircle" className="size-4 shrink-0" />Mobile {sent?.masked} confirmed. Just add your name and email.</p>
        {signup.existing && (
          <Alert type="info">An account already uses this number, but it was never confirmed. To use that account, log in with its email or Google and verify the number in My Profile. Or create a new account below.</Alert>
        )}
        {error && <Alert>{error}</Alert>}
        <IconField id="otp-name" label="Full name" icon="user" autoComplete="name" required value={signup.name} error={fieldErrors.name}
          onChange={(e) => setSignup((s) => ({ ...s, name: e.target.value }))} />
        <IconField id="otp-email" label="Email address" icon="mail" type="email" inputMode="email" autoComplete="email" required value={signup.email} error={fieldErrors.email}
          hint="For receipts, certificates and password recovery." onChange={(e) => setSignup((s) => ({ ...s, email: e.target.value }))} />
        <label className="flex items-start gap-2.5 text-sm text-ink">
          <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={signup.whatsapp} onChange={(e) => setSignup((s) => ({ ...s, whatsapp: e.target.checked }))} />
          <span>Send me course and class updates on WhatsApp (optional)</span>
        </label>
        <label className="flex items-start gap-2.5 text-sm text-ink">
          <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={signup.terms} aria-invalid={!!fieldErrors.accept_terms} onChange={(e) => setSignup((s) => ({ ...s, terms: e.target.checked }))} />
          <span>I agree to the <Link to="/terms" className="font-semibold text-primary hover:underline">Terms</Link> and <Link to="/privacy-policy" className="font-semibold text-primary hover:underline">Privacy Policy</Link>.</span>
        </label>
        {fieldErrors.accept_terms && <p className="-mt-2 text-xs font-medium text-red-600">{fieldErrors.accept_terms}</p>}
        <button type="submit" disabled={busy} className="btn-primary btn-lg w-full">{busy ? <><Busy /> Creating account…</> : <>Create account <Icon name="arrowRight" className="size-4" /></>}</button>
      </form>
    );
  }

  if (step === 'code') {
    const other = channels.find((c) => c !== sent?.channel);
    return (
      <form onSubmit={verify} noValidate className="space-y-4" aria-busy={busy}>
        <p className="text-sm text-ink">
          Enter the 6-digit code sent {sent?.channel === 'whatsapp' ? 'on WhatsApp' : 'by SMS'} to <strong className="text-navy">{sent?.masked}</strong>.{' '}
          <button type="button" onClick={() => { setStep('phone'); setError(null); }} className="font-semibold text-primary hover:underline">Change number</button>
        </p>
        {error && <Alert>{error}</Alert>}
        <CodeInput inputRef={codeRef} value={code} onChange={setCode} />
        <button type="submit" disabled={busy || code.length !== 6} className="btn-primary btn-lg w-full">
          {busy ? <><Busy /> Checking…</> : purpose === 'verify' ? <>Verify number <Icon name="checkCircle" className="size-4" /></> : <>Continue <Icon name="arrowRight" className="size-4" /></>}
        </button>
        <div className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-sm">
          {wait > 0
            ? <span className="text-muted" aria-live="polite">Resend in {wait}s</span>
            : <button type="button" onClick={() => send(sent?.channel)} disabled={busy} className="font-semibold text-primary hover:underline">Resend code</button>}
          {other && wait <= 0 && <button type="button" onClick={() => send(other)} disabled={busy} className="font-semibold text-primary hover:underline">Send by {CHANNEL[other].label} instead</button>}
        </div>
      </form>
    );
  }

  return (
    <form onSubmit={(e) => { e.preventDefault(); send(); }} noValidate className="space-y-4" aria-busy={busy}>
      {error && <Alert>{error}</Alert>}
      <div>
        <label htmlFor="otp-phone" className="label">Mobile number</label>
        <div className="flex">
          <span className="inline-flex items-center rounded-l-xl border border-r-0 border-line bg-surface px-3 text-sm font-semibold text-navy">+91</span>
          <input id="otp-phone" className="input rounded-l-none" type="tel" inputMode="numeric" autoComplete="tel-national" maxLength={10} required placeholder="98765 43210"
            value={phone} onChange={(e) => setPhone(e.target.value.replace(/\D/g, '').slice(0, 10))} aria-invalid={!!fieldErrors.phone} aria-describedby={fieldErrors.phone ? 'otp-phone-err' : undefined} />
        </div>
        {fieldErrors.phone && <p id="otp-phone-err" className="mt-1 text-xs font-medium text-red-600">{fieldErrors.phone}</p>}
      </div>
      {channels.length > 1 && (
        <fieldset>
          <legend className="label">Send the code by</legend>
          <div className="grid grid-cols-2 gap-2">
            {channels.map((c) => (
              <label key={c} className={'flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 px-3 py-2.5 text-sm font-semibold transition-colors has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-primary/25 '
                + (channel === c ? 'border-primary bg-primary-soft text-navy' : 'border-line text-ink hover:border-primary/40')}>
                <input type="radio" name="otp-channel" value={c} checked={channel === c} onChange={() => setChannel(c)} className="sr-only" />
                <Icon name={CHANNEL[c].icon} className={'size-4 ' + CHANNEL[c].cls} />{CHANNEL[c].label}
              </label>
            ))}
          </div>
        </fieldset>
      )}
      <button type="submit" disabled={busy || phone.length !== 10 || wait > 0} className="btn-primary btn-lg w-full">
        {busy ? <><Busy /> Sending…</> : wait > 0 ? `Wait ${wait}s` : <>Send code{channels.length === 1 ? ` by ${CHANNEL[channels[0]].label}` : ''} <Icon name="send" className="size-4" /></>}
      </button>
    </form>
  );
}
