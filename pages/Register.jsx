import { useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { APP_BASE } from '../js/api.js';
import { safeNext, useAuth } from '../js/auth.jsx';
import { Alert, DevLink, Icon, useForm, useSeo, useSite } from '../js/components.jsx';
import AuthLayout, { Busy, IconField, PasswordField, PasswordStrength, passwordMeetsPolicy } from '../js/account/AuthLayout.jsx';
import { GoogleButton, OrDivider, useLoginOptions } from '../js/account/SocialLogin.jsx';

const MOBILE_RE = /^\+?\d{10,14}$/; // same rule as validate() 'phone' in includes/functions.php
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

/** Client-side checks for instant feedback. The server validates everything again. */
function validate(v) {
  const e = {};
  if (v.name.trim().length < 2) e.name = 'Enter your full name.';
  if (!EMAIL_RE.test(v.email.trim())) e.email = 'Enter a valid email address.';
  if (!MOBILE_RE.test(v.phone.replace(/[^\d+]/g, ''))) e.phone = 'Enter a valid 10-digit mobile number.';
  if (!passwordMeetsPolicy(v.password)) e.password = 'Use 8+ characters with at least one letter and one number.';
  if (!v.accept_terms) e.accept_terms = 'Please accept the terms to continue.';
  return e;
}

export default function Register() {
  useSeo({ title: 'Create Account | Laxyam Academy', robots: 'noindex,follow' });
  const { user, register } = useAuth();
  const { data: site } = useSite();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const next = safeNext(params.get('next'));
  const [done, setDone] = useState(null);
  const [local, setLocal] = useState({});
  // whatsapp_opt_in is never pre-ticked: WhatsApp updates need the student's own explicit consent.
  const form = useForm({ name: '', email: '', phone: '', password: '', accept_terms: false, whatsapp_opt_in: false, exam_preferences: [], website: '' });
  const [opts, reloadOpts] = useLoginOptions();

  if (user?.type === 'staff' && !done) {
    return (
      <AuthLayout title="You are signed in as staff" subtitle="Log out of the admin panel to create a student account." icon="shield">
        <a href={APP_BASE + 'admin/'} className="btn-primary w-full py-3">Open admin panel <Icon name="arrowRight" className="size-4" /></a>
      </AuthLayout>
    );
  }
  if (user && !done) return <Navigate to={next} replace />;

  if (done) {
    return (
      <AuthLayout title="Your account is ready" subtitle={`We sent a verification link to ${done.user.email}.`} icon="checkCircle">
        <div className="rounded-2xl border border-green-200 bg-green-50 p-4 text-sm text-green-800">
          <p className="font-semibold">Welcome to Laxyam Academy, {done.user.name.split(' ')[0]}!</p>
          <p className="mt-1">Verify your email to secure your account. You can start exploring right away.</p>
        </div>
        <DevLink data={done} />
        <button type="button" onClick={() => navigate(next, { replace: true })} className="btn-primary btn-lg mt-6 w-full">Continue <Icon name="arrowRight" className="size-4" /></button>
        <Link to="/courses" className="btn-light mt-3 w-full">Browse courses</Link>
      </AuthLayout>
    );
  }

  const v = form.values;
  const err = (k) => local[k] || form.errors[k];
  const change = (k) => (e) => {
    form.bind(k).onChange(e);
    if (local[k]) setLocal(({ [k]: _drop, ...rest }) => rest);
  };
  const togglePref = (slug) =>
    form.setValues((x) => ({ ...x, exam_preferences: x.exam_preferences.includes(slug) ? x.exam_preferences.filter((s) => s !== slug) : [...x.exam_preferences, slug] }));

  const onSubmit = (e) => {
    e.preventDefault();
    const errors = validate(v);
    setLocal(errors);
    if (Object.keys(errors).length) {
      document.getElementById(Object.keys(errors)[0])?.focus();
      return;
    }
    form.submit(async (values) => {
      const data = await register({ ...values, name: values.name.trim(), email: values.email.trim(), phone: values.phone.trim() });
      setDone(data);
    })();
  };
  const keepNext = params.get('next') ? `?next=${encodeURIComponent(next)}` : '';
  const cats = (site?.categories || []).filter((c) => !c.parent_id);

  return (
    <AuthLayout
      title="Create your free account"
      subtitle={next.startsWith('/checkout/') ? 'One step before you enrol: create your free account, then pay online for your course.' : 'Join Laxyam Academy and start preparing today.'}
      icon="sparkles"
      footer={<>Already have an account? <Link to={`/login${keepNext}`} className="font-semibold text-primary hover:underline">Log in</Link></>}
    >
      {(opts?.google || opts?.otp?.channels?.length > 0) && (
        <>
          {opts.google && <GoogleButton google={opts.google} text="signup_with" onRetry={reloadOpts} onDone={() => navigate(next, { replace: true })} />}
          {opts.otp?.channels?.length > 0 && (
            <Link to={`/login?method=otp${keepNext ? '&' + keepNext.slice(1) : ''}`} className="btn-light mt-3 w-full">
              <Icon name="phone" className="size-4" />Sign up with a mobile OTP
            </Link>
          )}
          {opts.google && <p className="mt-2 text-center text-xs text-muted">By continuing with Google you agree to the <Link to="/terms" className="font-semibold text-primary hover:underline">Terms</Link> and <Link to="/privacy-policy" className="font-semibold text-primary hover:underline">Privacy Policy</Link>.</p>}
          <OrDivider label="or sign up with email" />
        </>
      )}
      {form.message && <Alert className="mb-5">{form.message.text}</Alert>}
      <form onSubmit={onSubmit} noValidate className="space-y-5" aria-busy={form.busy}>
        {/* Honeypot: hidden from people, bots fill it */}
        <div aria-hidden="true" className="absolute -left-[9999px] size-px overflow-hidden">
          <label htmlFor="website">Website</label>
          <input id="website" name="website" tabIndex={-1} autoComplete="off" value={v.website} onChange={form.bind('website').onChange} />
        </div>
        <IconField id="name" label="Full name" icon="user" autoComplete="name" required maxLength={120} placeholder="As you want it on certificates" value={v.name} onChange={change('name')} error={err('name')} />
        <div className="grid gap-5 sm:grid-cols-2">
          <IconField id="email" label="Email address" icon="mail" type="email" inputMode="email" autoComplete="email" required placeholder="you@example.com" value={v.email} onChange={change('email')} error={err('email')} />
          <IconField id="phone" label="Mobile number" icon="phone" type="tel" inputMode="tel" autoComplete="tel" required maxLength={16} placeholder="10-digit mobile" value={v.phone} onChange={change('phone')} error={err('phone')} />
        </div>
        <div>
          <PasswordField id="password" label="Create password" autoComplete="new-password" required value={v.password} onChange={change('password')} error={err('password')} describedBy="pw-strength" />
          <PasswordStrength password={v.password} id="pw-strength" />
        </div>
        {cats.length > 0 && (
          <fieldset>
            <legend className="label">Preparing for <span className="font-normal text-muted">(optional)</span></legend>
            <div className="flex flex-wrap gap-2">
              {cats.map((c) => {
                const on = v.exam_preferences.includes(c.slug);
                return (
                  <button key={c.slug} type="button" aria-pressed={on} onClick={() => togglePref(c.slug)}
                    className={'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors ' + (on ? 'border-primary bg-primary text-white shadow-glow' : 'border-line bg-white text-ink hover:border-primary hover:text-primary')}>
                    {on && <Icon name="check" className="size-3.5" strokeWidth={3} />}{c.name}
                  </button>
                );
              })}
            </div>
          </fieldset>
        )}
        <div className="rounded-2xl border border-line bg-surface/70 p-3.5 transition-colors has-[:checked]:border-[#25D366]/40 has-[:checked]:bg-[#25D366]/5">
          <label className="flex cursor-pointer items-start gap-3 text-sm text-ink">
            <input id="whatsapp_opt_in" type="checkbox" checked={v.whatsapp_opt_in} onChange={change('whatsapp_opt_in')} aria-describedby="whatsapp_opt_in-hint" className="mt-0.5 size-4 shrink-0 accent-[#25D366]" />
            <span className="min-w-0">
              <span className="flex items-center gap-1.5 font-semibold text-navy"><Icon name="whatsapp" className="size-4 text-[#25D366]" /> Send me course updates on WhatsApp</span>
              <span id="whatsapp_opt_in-hint" className="mt-0.5 block text-xs leading-relaxed text-muted">
                Laxyam Academy will send account, payment, course and class updates on WhatsApp to the mobile number above. Optional. Reply STOP or switch it off in your profile anytime to opt out.
              </span>
            </span>
          </label>
        </div>
        <div>
          <label className="flex items-start gap-2.5 text-sm text-ink">
            <input id="accept_terms" type="checkbox" checked={v.accept_terms} onChange={change('accept_terms')} aria-invalid={!!err('accept_terms')} aria-describedby={err('accept_terms') ? 'accept_terms-err' : undefined} className="mt-0.5 size-4 shrink-0 accent-primary" />
            <span>I agree to the <Link to="/terms" className="font-semibold text-primary hover:underline">Terms</Link> and <Link to="/privacy-policy" className="font-semibold text-primary hover:underline">Privacy Policy</Link>.</span>
          </label>
          {err('accept_terms') && <p id="accept_terms-err" className="mt-1 text-xs font-medium text-red-600">{err('accept_terms')}</p>}
        </div>
        <button type="submit" disabled={form.busy} className="btn-primary btn-lg w-full">
          {form.busy ? <><Busy /> Creating account…</> : <>Create account <Icon name="arrowRight" className="size-4" /></>}
        </button>
      </form>
    </AuthLayout>
  );
}
