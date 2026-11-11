import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../js/api.js';
import { Alert, DevLink, Icon, useForm, useSeo } from '../js/components.jsx';
import AuthLayout, { Busy, IconField } from '../js/account/AuthLayout.jsx';

export default function ForgotPassword() {
  useSeo({ title: 'Forgot Password | Laxyam Academy', robots: 'noindex,follow' });
  const [sent, setSent] = useState(null);
  const form = useForm({ email: '' });

  const onSubmit = form.submit(async (v) => {
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.email.trim())) {
      throw Object.assign(new Error('Please correct the highlighted field.'), { fields: { email: 'Enter a valid email address.' } });
    }
    setSent({ ...(await api.post('auth.php', { email: v.email.trim() }, { action: 'forgot-password' })), email: v.email.trim() });
  });

  return (
    <AuthLayout
      title={sent ? 'Check your inbox' : 'Forgot your password?'}
      subtitle={sent ? undefined : 'Enter your registered email and we will send you a reset link.'}
      icon={sent ? 'mail' : 'lock'}
      footer={<Link to="/login" className="inline-flex items-center gap-1.5 font-semibold text-primary hover:underline"><Icon name="chevronLeft" className="size-4" /> Back to login</Link>}
    >
      {sent ? (
        <>
          <Alert type="success">{sent.message} The link is valid for 60 minutes.</Alert>
          <ul className="mt-5 space-y-2 text-sm text-muted">
            <li className="flex gap-2"><Icon name="info" className="mt-0.5 size-4 shrink-0 text-primary" /> Can’t find it? Check your spam or promotions folder.</li>
            <li className="flex gap-2"><Icon name="refresh" className="mt-0.5 size-4 shrink-0 text-primary" /> Wrong address? <button type="button" onClick={() => { setSent(null); form.setValues({ email: '' }); }} className="font-semibold text-primary hover:underline">Try another email</button></li>
          </ul>
          <DevLink data={sent} />
        </>
      ) : (
        <form onSubmit={onSubmit} noValidate className="space-y-5" aria-busy={form.busy}>
          {form.message && !form.errors.email && <Alert>{form.message.text}</Alert>}
          <IconField id="email" label="Email address" icon="mail" type="email" inputMode="email" autoComplete="email" required placeholder="you@example.com"
            value={form.values.email} onChange={form.bind('email').onChange} error={form.errors.email} />
          <button type="submit" disabled={form.busy} className="btn-primary btn-lg w-full">
            {form.busy ? <><Busy /> Sending…</> : <>Send reset link <Icon name="send" className="size-4" /></>}
          </button>
        </form>
      )}
    </AuthLayout>
  );
}
