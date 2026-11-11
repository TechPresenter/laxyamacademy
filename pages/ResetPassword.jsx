import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../js/api.js';
import { Alert, Icon, useForm, useSeo } from '../js/components.jsx';
import AuthLayout, { Busy, PasswordField, PasswordStrength, passwordMeetsPolicy } from '../js/account/AuthLayout.jsx';

export default function ResetPassword() {
  useSeo({ title: 'Reset Password | Laxyam Academy', robots: 'noindex,nofollow' });
  const [params] = useSearchParams();
  const token = params.get('token') || '';
  const navigate = useNavigate();
  const form = useForm({ password: '', confirm: '' });

  const onSubmit = form.submit(async (v) => {
    const fields = {};
    if (!passwordMeetsPolicy(v.password)) fields.password = 'Use 8+ characters with at least one letter and one number.';
    if (v.password !== v.confirm) fields.confirm = 'Passwords do not match.';
    if (Object.keys(fields).length) throw Object.assign(new Error('Please correct the highlighted fields.'), { fields });
    await api.post('auth.php', { token, password: v.password }, { action: 'reset-password' });
    navigate('/login?reset=1', { replace: true });
  });
  const expired = /expired|invalid/i.test(form.message?.text || '');

  return (
    <AuthLayout
      title="Set a new password"
      subtitle="Choose a strong password. All other signed-in devices will be logged out."
      icon="lock"
      footer={<Link to="/login" className="inline-flex items-center gap-1.5 font-semibold text-primary hover:underline"><Icon name="chevronLeft" className="size-4" /> Back to login</Link>}
    >
      {!token ? (
        <Alert>This reset link is incomplete. Please request a new one from <Link to="/forgot-password" className="font-semibold underline">Forgot password</Link>.</Alert>
      ) : (
        <form onSubmit={onSubmit} noValidate className="space-y-5" aria-busy={form.busy}>
          {form.message && !Object.keys(form.errors).length && (
            <Alert>{form.message.text} {expired && <Link to="/forgot-password" className="font-semibold underline">Request a new link</Link>}</Alert>
          )}
          <div>
            <PasswordField id="password" label="New password" autoComplete="new-password" required value={form.values.password} onChange={form.bind('password').onChange} error={form.errors.password} describedBy="pw-strength" />
            <PasswordStrength password={form.values.password} id="pw-strength" />
          </div>
          <PasswordField id="confirm" label="Confirm new password" autoComplete="new-password" required value={form.values.confirm} onChange={form.bind('confirm').onChange} error={form.errors.confirm} />
          <button type="submit" disabled={form.busy} className="btn-primary btn-lg w-full">
            {form.busy ? <><Busy /> Saving…</> : <>Reset password <Icon name="check" className="size-4" /></>}
          </button>
        </form>
      )}
    </AuthLayout>
  );
}
