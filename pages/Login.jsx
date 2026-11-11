import { useState } from 'react';
import { Link, Navigate, useNavigate, useSearchParams } from 'react-router-dom';
import { APP_BASE } from '../js/api.js';
import { safeNext, useAuth } from '../js/auth.jsx';
import { Alert, Icon, useForm, useSeo } from '../js/components.jsx';
import AuthLayout, { Busy, IconField, PasswordField } from '../js/account/AuthLayout.jsx';
import { EmailOtpFlow, GoogleButton, OrDivider, OtpFlow, useLoginOptions } from '../js/account/SocialLogin.jsx';

const GRID = { 2: 'grid-cols-2', 3: 'grid-cols-3' };

export default function Login() {
  useSeo({ title: 'Login | Laxyam Academy', robots: 'noindex,follow' });
  const { user, login } = useAuth();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const next = safeNext(params.get('next'));
  const form = useForm({ email: '', password: '' });
  const [opts, reloadOpts] = useLoginOptions();
  const asked = params.get('method');
  const [chosen, setChosen] = useState(['code', 'password', 'otp'].includes(asked) ? asked : null);
  const channels = opts?.otp?.channels || [];

  // Staff share the PHP session with /admin: send them to the admin panel instead of the student area.
  if (user?.type === 'staff') {
    return (
      <AuthLayout title="You are signed in as staff" subtitle="Staff accounts use the admin panel. Student pages are for learner accounts." icon="shield">
        <a href={APP_BASE + 'admin/'} className="btn-primary w-full py-3">Open admin panel <Icon name="arrowRight" className="size-4" /></a>
      </AuthLayout>
    );
  }
  if (user) return <Navigate to={next} replace />;

  // Email code is the default way in when the academy has it switched on; the password is always available.
  const methods = opts ? [
    opts.email_otp && ['code', 'mail', 'Email OTP'],
    ['password', 'lock', 'Password'],
    channels.length > 0 && ['otp', 'phone', 'Mobile OTP'],
  ].filter(Boolean) : [];
  const method = methods.some(([k]) => k === chosen) ? chosen : (methods[0]?.[0] ?? null);
  const done = () => navigate(next, { replace: true });

  const onSubmit = form.submit(async (v) => {
    await login(v.email.trim(), v.password);
    done();
  });
  const keepNext = params.get('next') ? `?next=${encodeURIComponent(next)}` : '';

  return (
    <AuthLayout
      title="Welcome back"
      subtitle="Log in to continue your preparation."
      icon="user"
      footer={<>New to Laxyam Academy? <Link to={`/register${keepNext}`} className="font-semibold text-primary hover:underline">Create a free account</Link></>}
    >
      {params.get('reset') && <Alert type="success" className="mb-5">Your password has been reset. Please log in with the new password.</Alert>}
      {opts?.google && <><GoogleButton google={opts.google} onRetry={reloadOpts} onDone={done} /><OrDivider /></>}
      {!opts && <p className="flex items-center justify-center gap-2 py-10 text-sm text-muted" role="status"><Busy /> Loading…</p>}
      {methods.length > 1 && (
        <div className={'mb-5 grid gap-1 rounded-xl bg-surface p-1 ' + GRID[methods.length]} role="tablist" aria-label="How do you want to log in?">
          {methods.map(([k, ic, label]) => (
            <button key={k} type="button" role="tab" aria-selected={method === k} onClick={() => setChosen(k)}
              className={'inline-flex items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-sm font-semibold transition-colors ' + (method === k ? 'bg-white text-navy shadow-sm' : 'text-muted hover:text-navy')}>
              <Icon name={ic} className="size-4 shrink-0" />{label}
            </button>
          ))}
        </div>
      )}
      {method === 'code' && <EmailOtpFlow initialEmail={form.values.email} onDone={done} />}
      {method === 'otp' && <OtpFlow channels={channels} onDone={done} />}
      {method === 'password' && <>
        {form.message && (
          <Alert className="mb-5">
            {form.message.text}
            {form.message.code === 'staff_account' && (
              <a href={APP_BASE + 'admin/login.php'} className="btn-primary mt-3 w-full py-2.5">Open admin sign-in <Icon name="arrowRight" className="size-4" /></a>
            )}
          </Alert>
        )}
        <form onSubmit={onSubmit} noValidate className="space-y-5" aria-busy={form.busy}>
          <IconField id="email" label="Email address" icon="mail" type="email" inputMode="email" autoComplete="email" required placeholder="you@example.com"
            value={form.values.email} onChange={form.bind('email').onChange} error={form.errors.email} />
          <div>
            <PasswordField id="password" label="Password" autoComplete="current-password" required value={form.values.password} onChange={form.bind('password').onChange} error={form.errors.password} />
            <div className="mt-2 flex justify-end">
              <Link to="/forgot-password" className="text-sm font-semibold text-primary hover:underline">Forgot password?</Link>
            </div>
          </div>
          <button type="submit" disabled={form.busy} className="btn-primary btn-lg w-full">
            {form.busy ? <><Busy /> Logging in…</> : <>Log in <Icon name="arrowRight" className="size-4" /></>}
          </button>
        </form>
        {opts?.email_otp && (
          <p className="mt-4 text-center text-sm text-muted">
            No password? <button type="button" onClick={() => setChosen('code')} className="font-semibold text-primary hover:underline">Log in with an email code</button>
          </p>
        )}
      </>}
    </AuthLayout>
  );
}
