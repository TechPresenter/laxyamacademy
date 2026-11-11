import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { Alert, DevLink, Icon, Spinner, useSeo } from '../js/components.jsx';
import AuthLayout, { Busy } from '../js/account/AuthLayout.jsx';

export default function VerifyEmail() {
  useSeo({ title: 'Verify Email | Laxyam Academy', robots: 'noindex,nofollow' });
  const [params] = useSearchParams();
  const { user, refresh } = useAuth();
  const [state, setState] = useState({ status: 'pending', message: '' });
  const [resend, setResend] = useState(null);
  const started = useRef(false);
  const token = params.get('token') || '';

  useEffect(() => {
    if (started.current) return; // tokens are single-use; avoid StrictMode double calls
    started.current = true;
    if (!token) {
      setState({ status: 'error', message: 'This verification link is incomplete. Please use the full link from the email.' });
      return;
    }
    api
      .post('auth.php', { token }, { action: 'verify-email' })
      .then(() => {
        setState({ status: 'ok', message: 'Your email address has been verified. Thank you!' });
        refresh();
      })
      .catch((e) => setState({ status: 'error', message: e.message }));
  }, [token, refresh]);

  const sendAgain = async () => {
    setResend({ busy: true });
    try {
      setResend({ ok: true, data: await api.post('auth.php', {}, { action: 'resend-verification' }) });
    } catch (e) {
      setResend({ error: e.message });
    }
  };

  const ok = state.status === 'ok';
  return (
    <AuthLayout
      title={state.status === 'pending' ? 'Verifying your email…' : ok ? 'Email verified' : 'Verification failed'}
      icon={ok ? 'checkCircle' : state.status === 'pending' ? 'mail' : 'alert'}
    >
      {state.status === 'pending' ? <Spinner label="Verifying…" className="py-8" /> : <Alert type={ok ? 'success' : 'error'}>{state.message}</Alert>}
      {state.status === 'error' && user && !user.email_verified && (
        <div className="mt-4">
          {resend?.ok ? <Alert type="success">A new verification link is on its way to {user.email}.</Alert> : (
            <button type="button" onClick={sendAgain} disabled={resend?.busy} className="btn-light w-full">
              {resend?.busy ? <><Busy /> Sending…</> : <><Icon name="refresh" className="size-4" /> Send a new link</>}
            </button>
          )}
          {resend?.error && <Alert className="mt-3">{resend.error}</Alert>}
          <DevLink data={resend?.data} />
        </div>
      )}
      {state.status !== 'pending' && (
        user
          ? <Link to="/dashboard" className="btn-primary btn-lg mt-6 w-full">Go to my dashboard <Icon name="arrowRight" className="size-4" /></Link>
          : <Link to="/login?next=%2Fdashboard" className="btn-primary btn-lg mt-6 w-full">Log in <Icon name="arrowRight" className="size-4" /></Link>
      )}
    </AuthLayout>
  );
}
