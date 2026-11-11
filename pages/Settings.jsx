import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { api, apiUrl, formatDateTime } from '../js/api.js';
import { Alert, ErrorState, Field, Icon, Skeleton, useApi, useForm } from '../js/components.jsx';
import { Busy, PasswordField, PasswordStrength, passwordMeetsPolicy } from '../js/account/AuthLayout.jsx';
import { DashTitle, Panel, Pill, RowLink } from '../js/account/ui.jsx';

const SECTIONS = [['password', 'Password', 'lock'], ['documents', 'Documents', 'file'], ['devices', 'Devices', 'monitor'], ['data', 'Your data', 'inbox'], ['delete', 'Delete account', 'trash']];

/** Account settings: password, documents, signed-in devices, data export, account deletion. */
export default function Settings() {
  const { hash } = useLocation();
  const profile = useApi('students.php', { action: 'profile' });
  useEffect(() => {
    if (hash) setTimeout(() => document.getElementById(hash.slice(1))?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
  }, [hash]);
  return (
    <>
      <DashTitle title="Settings" icon="settings" subtitle="Password, documents, devices and account privacy." />
      <nav aria-label="Settings sections" className="scrollbar-none -mx-4 mb-5 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0">
        {SECTIONS.map(([id, label, icon]) => (
          <Link key={id} to={{ hash: `#${id}` }} replace onClick={() => hash === `#${id}` && document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
            aria-current={hash === `#${id}` ? 'location' : undefined}
            className={'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-semibold transition-colors ' + (hash === `#${id}` ? 'border-primary bg-primary-soft text-primary' : 'border-line bg-white text-ink hover:border-primary hover:text-primary')}>
            <Icon name={icon} className="size-4" /> {label}
          </Link>
        ))}
      </nav>
      <div className="space-y-5">
        <ChangePassword />
        <MyDocuments />
        <Sessions />
        <section id="data" className="scroll-mt-28">
          <Panel title="Your data" icon="file" subtitle="Download a copy of your account information (JSON).">
            <RowLink href={apiUrl('students.php', { action: 'export' })} icon="file" label="Download my data" />
          </Panel>
        </section>
        <DeleteAccount requestedAt={profile.data?.deletion_requested_at} />
      </div>
    </>
  );
}

function ChangePassword() {
  const form = useForm({ current_password: '', password: '', confirm: '' });
  const onSubmit = form.submit(async (v) => {
    const fields = {};
    if (!v.current_password) fields.current_password = 'Enter your current password.';
    if (!passwordMeetsPolicy(v.password)) fields.password = 'Use 8+ characters with at least one letter and one number.';
    if (v.password !== v.confirm) fields.confirm = 'Passwords do not match.';
    if (Object.keys(fields).length) throw Object.assign(new Error('Please correct the highlighted fields.'), { fields });
    await api.post('auth.php', { current_password: v.current_password, password: v.password }, { action: 'change-password' });
    form.setValues({ current_password: '', password: '', confirm: '' });
    form.setMessage({ type: 'success', text: 'Password changed. Other devices have been signed out.' });
  });
  const bind = (k) => ({ value: form.values[k], onChange: form.bind(k).onChange, error: form.errors[k] });
  return (
    <section id="password" className="scroll-mt-28">
      <Panel title="Change password" icon="lock" subtitle="Changing your password signs out every other device.">
        {form.message && <Alert type={form.message.type} className="mb-4">{form.message.text}</Alert>}
        <form onSubmit={onSubmit} noValidate className="grid gap-4 md:grid-cols-3" aria-busy={form.busy}>
          <PasswordField id="current_password" label="Current password" autoComplete="current-password" {...bind('current_password')} />
          <div className="min-w-0">
            <PasswordField id="new_password" label="New password" autoComplete="new-password" describedBy="pw-strength" {...bind('password')} />
            {form.values.password && <PasswordStrength password={form.values.password} id="pw-strength" />}
          </div>
          <PasswordField id="confirm_password" label="Confirm new password" autoComplete="new-password" {...bind('confirm')} />
          <div className="md:col-span-3"><button disabled={form.busy} className="btn-primary">{form.busy ? <><Busy /> Updating…</> : <><Icon name="check" className="size-4" /> Update password</>}</button></div>
        </form>
      </Panel>
    </section>
  );
}

const DOC_STATUS = { pending: 'amber', verified: 'green', rejected: 'red' };

function MyDocuments() {
  const { data, loading, error, reload } = useApi('students.php', { action: 'documents' });
  const [type, setType] = useState('');
  const [title, setTitle] = useState('');
  const [file, setFile] = useState(null);
  const [msg, setMsg] = useState(null);
  const [busy, setBusy] = useState(false);
  const [removing, setRemoving] = useState(null);
  const types = data?.types || {};
  const items = Array.isArray(data?.items) ? data.items : [];

  const upload = async (e) => {
    e.preventDefault();
    setMsg(null);
    if (file && file.size > 5 * 1024 * 1024) {
      setMsg({ type: 'error', text: 'The file is larger than 5 MB.' });
      return;
    }
    setBusy(true);
    try {
      await api.upload('students.php', file, { action: 'documents' }, { doc_type: type, title });
      setTitle('');
      setType('');
      setFile(null);
      e.target.reset();
      setMsg({ type: 'success', text: 'Uploaded. Our team will verify it.' });
      reload();
    } catch (err) {
      setMsg({ type: 'error', text: err.fields?.file || err.fields?.title || err.fields?.doc_type || err.message });
    } finally {
      setBusy(false);
    }
  };
  const remove = async (d) => {
    if (!window.confirm(`Remove "${d.title}"?`)) return;
    setRemoving(d.id);
    setMsg(null);
    try {
      await api.post('students.php', { id: d.id }, { action: 'document-delete' });
      setMsg({ type: 'success', text: 'Document removed.' });
      reload();
    } catch (err) {
      setMsg({ type: 'error', text: err.message });
    } finally {
      setRemoving(null);
    }
  };
  return (
    <section id="documents" className="scroll-mt-28">
      <Panel title="My documents" icon="file" subtitle="ID proof, marksheets and photos requested by the academy. PDF, JPG or PNG up to 5 MB. Stored privately.">
        {msg && <Alert type={msg.type} className="mb-4">{msg.text}</Alert>}
        {error && !data ? <ErrorState error={error} onRetry={reload} title="We couldn’t load your documents." /> : (
          <>
            <form onSubmit={upload} className="grid gap-3 rounded-2xl border border-dashed border-line bg-surface/60 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1.2fr_auto] lg:items-end">
              <Field id="doc-type" label="Type" className="min-w-0">
                <select id="doc-type" className="input" required value={type} onChange={(e) => setType(e.target.value)} disabled={loading && !data}>
                  <option value="">Choose…</option>
                  {Object.entries(types).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
                </select>
              </Field>
              <Field id="doc-title" label="Title" className="min-w-0" value={title} onChange={(e) => setTitle(e.target.value)} required maxLength={160} placeholder="e.g. Class 12 marksheet" />
              <Field id="doc-file" label="File" className="min-w-0"><input id="doc-file" type="file" required accept="application/pdf,image/jpeg,image/png" onChange={(e) => setFile(e.target.files?.[0] || null)} className="input py-2 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-soft file:px-3 file:py-1 file:text-xs file:font-semibold file:text-primary" /></Field>
              <button disabled={busy || !file || !type || !title.trim()} className="btn-primary">{busy ? <><Busy /> Uploading…</> : <><Icon name="upload" className="size-4" /> Upload</>}</button>
            </form>
            {loading && !data ? <div className="mt-5 space-y-3"><Skeleton className="h-12" /><Skeleton className="h-12" /></div> : items.length > 0 ? (
              <ul className="mt-5 divide-y divide-line">
                {items.map((d) => (
                  <li key={d.id} className="flex flex-wrap items-center gap-3 py-3 text-sm">
                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-surface text-xs font-bold text-muted uppercase">{d.file_type || 'file'}</span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate font-semibold text-ink">{d.title}</span>
                      <span className="block text-xs text-muted">{types[d.type] || d.type} · {formatDateTime(d.uploaded_at)}{d.note && ` · ${d.note}`}</span>
                    </span>
                    <Pill tone={DOC_STATUS[d.status] || 'slate'} className="capitalize">{d.status}</Pill>
                    <a href={apiUrl('students.php', { action: 'document', id: d.id })} target="_blank" rel="noopener" className="font-semibold text-primary hover:underline">View</a>
                    {d.status !== 'verified' && <button type="button" onClick={() => remove(d)} disabled={removing === d.id} className="font-semibold text-red-600 hover:underline disabled:opacity-50">{removing === d.id ? 'Removing…' : 'Remove'}</button>}
                  </li>
                ))}
              </ul>
            ) : <p className="mt-4 text-sm text-muted">No documents uploaded yet.</p>}
          </>
        )}
      </Panel>
    </section>
  );
}

function deviceName(ua = '') {
  ua = ua || '';
  const browser = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Chrome\//.test(ua) ? 'Chrome' : /Firefox\//.test(ua) ? 'Firefox' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
  const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows' : /Mac OS/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : '';
  return `${browser}${os ? ` on ${os}` : ''}`;
}
const deviceIcon = (ua = '') => (/Android|iPhone|iPad|Mobile/.test(ua || '') ? 'phone' : 'monitor');

function Sessions() {
  const { data, loading, error, reload } = useApi('students.php', { action: 'sessions' });
  const [msg, setMsg] = useState(null);
  const [revoking, setRevoking] = useState(null);
  const list = Array.isArray(data) ? data : [];
  const revoke = async (s) => {
    setRevoking(s.id);
    setMsg(null);
    try {
      await api.post('students.php', { id: s.id }, { action: 'revoke-session' });
      setMsg({ type: 'success', text: `${deviceName(s.device)} has been signed out.` });
      reload();
    } catch (err) {
      setMsg({ type: 'error', text: err.message || 'Could not sign out that device. Please try again.' });
    } finally {
      setRevoking(null);
    }
  };
  return (
    <section id="devices" className="scroll-mt-28">
      <Panel title="Login devices" icon="monitor" subtitle="Devices signed in during the last 30 days. Sign out of any you don’t recognise.">
        {msg && <Alert type={msg.type} className="mb-4">{msg.text}</Alert>}
        {error && !data ? <ErrorState error={error} onRetry={reload} title="We couldn’t load your devices." /> : loading && !data ? (
          <div className="space-y-3"><Skeleton className="h-14" /><Skeleton className="h-14" /></div>
        ) : list.length === 0 ? <p className="text-sm text-muted">No other active sessions.</p> : (
          <ul className="divide-y divide-line">
            {list.map((s) => (
              <li key={s.id} className="flex flex-wrap items-center gap-3 py-3 text-sm">
                <span className={'grid size-10 shrink-0 place-items-center rounded-xl ' + (s.current ? 'bg-green-50 text-green-600' : 'bg-surface text-muted')}><Icon name={deviceIcon(s.device)} className="size-5" /></span>
                <div className="min-w-0 flex-1">
                  <p className="flex flex-wrap items-center gap-2 font-semibold text-ink">{deviceName(s.device)} {s.current && <Pill tone="green">This device</Pill>}</p>
                  <p className="text-xs text-muted">{s.ip_address || 'Unknown IP'} · last active {formatDateTime(s.last_seen_at)}</p>
                </div>
                {!s.current && (
                  <button type="button" onClick={() => revoke(s)} disabled={revoking === s.id} className="btn-sm btn border border-red-200 bg-white text-red-600 hover:bg-red-50">
                    {revoking === s.id ? <><Busy /> Signing out…</> : <><Icon name="logout" className="size-4" /> Sign out</>}
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Panel>
    </section>
  );
}

function DeleteAccount({ requestedAt }) {
  const [open, setOpen] = useState(false);
  const [requested, setRequested] = useState(requestedAt);
  const form = useForm({ password: '' });
  useEffect(() => setRequested(requestedAt), [requestedAt]);
  const onSubmit = form.submit(async (v) => {
    if (!v.password) throw Object.assign(new Error('Enter your password to confirm.'), { fields: { password: 'Enter your password to confirm.' } });
    await api.post('students.php', v, { action: 'delete-request' });
    setRequested(new Date().toISOString());
    setOpen(false);
  });
  return (
    <section id="delete" className="scroll-mt-28">
      <Panel title="Delete account" icon="trash" className="border-red-100">
        {requested ? (
          <Alert type="info">Deletion requested on {formatDateTime(requested)}. Our team will process it (records required for tax/accounting are retained).</Alert>
        ) : open ? (
          <form onSubmit={onSubmit} noValidate className="max-w-sm space-y-3">
            {form.message && !form.errors.password && <Alert>{form.message.text}</Alert>}
            <p className="text-sm text-muted">Confirm with your password. Course access ends when the request is processed.</p>
            <PasswordField id="delete_password" label="Password" autoComplete="current-password" value={form.values.password} onChange={form.bind('password').onChange} error={form.errors.password} />
            <div className="flex flex-wrap gap-3">
              <button disabled={form.busy} className="btn-danger">{form.busy ? <><Busy /> Sending…</> : 'Request deletion'}</button>
              <button type="button" onClick={() => setOpen(false)} className="btn-light">Cancel</button>
            </div>
          </form>
        ) : (
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-muted">Permanently delete your account and personal data.</p>
            <button type="button" onClick={() => setOpen(true)} className="btn border border-red-300 bg-white text-red-600 hover:bg-red-50">Delete my account</button>
          </div>
        )}
      </Panel>
    </section>
  );
}
