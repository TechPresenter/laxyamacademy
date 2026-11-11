import { useEffect, useRef, useState } from 'react';
import { api, apiUrl, formatDate } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { Alert, ErrorState, Field, Icon, Skeleton, Spinner, useApi, useSite } from '../js/components.jsx';
import { Busy } from '../js/account/AuthLayout.jsx';
import { OtpFlow, useLoginOptions } from '../js/account/SocialLogin.jsx';
import { DashTitle, Panel, Pill, RowLink, Toggle } from '../js/account/ui.jsx';
import { Modal } from '../js/home/media.jsx';

const GOALS = ['Clear prelims this year', 'Build strong basics', 'Improve mock test scores', 'Crack the interview', 'Revise current affairs'];
// [key, label, icon, what it controls]. Saved with POST students.php?action=preferences:
// class_reminders → notify_class_reminders (its own switch, not affected by "Email updates");
// the rest → notify_prefs.<key>, which the mailer checks for these templates (includes/notify.php):
// course_updates = course_completed + certificate_issued, test_results = test_result,
// announcements = admin announcements to all students / a course batch.
const NOTIFY = [
  ['course_updates', 'Course updates', 'book', 'Email when you complete a course and when your certificate is issued.'],
  ['class_reminders', 'Live class reminders', 'video', 'Email shortly before a live class you registered for starts. Works even when email updates are off.'],
  ['test_results', 'Mock test results', 'clipboard', 'Email with your score as soon as a mock test result is released.'],
  ['announcements', 'Important announcements', 'megaphone', 'Notices the academy sends to all students or to your course batch.'],
  ['offers', 'Offers & discounts', 'tag', 'Opt in to receive discount codes and promotions by email.'],
  ['study_tips', 'Weekly study tips', 'star', 'Opt in to receive preparation tips and strategy by email.'],
];

export default function MyProfile() {
  const { data: loaded, loading, error, reload } = useApi('students.php', { action: 'profile' });
  const purchases = useApi('students.php', { action: 'purchases' });
  // Local copy of the profile: every save replaces it with the server's fresh response, so one
  // panel never re-sends another panel's stale values (the profile POST rewrites every column).
  // The full-page skeleton shows only on the first load, so panels (and their "saved" messages) stay mounted.
  const [fresh, setFresh] = useState(null);
  const data = fresh || loaded;

  if (error && !data) return <ErrorState error={error} onRetry={reload} />;
  if (!data) return loading ? <ProfileSkeleton /> : <Spinner />;
  const onSaved = (next) => {
    if (next?.profile) setFresh(next);
    else {
      setFresh(null);
      reload();
    }
  };

  return (
    <>
      <DashTitle title="My Profile" icon="user" subtitle="Manage your details, exam preferences and notifications." />
      <div className="grid gap-5 xl:grid-cols-[1.05fr_1fr]">
        <ProfileInfo data={data} onSaved={onSaved} />
        <ExamPreferences data={data} onSaved={onSaved} />
      </div>
      <div className="mt-5 grid gap-5 lg:grid-cols-2 xl:grid-cols-[1.15fr_1fr_0.9fr]">
        <NotificationPrefs data={data} onSaved={onSaved} />
        <RecentPurchases state={purchases} />
        <QuickLinks stats={data.stats} />
      </div>
      <div className="mt-5 grid gap-5 lg:grid-cols-3">
        <Panel title="Account" icon="settings">
          <RowLink to="/dashboard/settings#password" icon="lock" label="Change password" />
          <RowLink to="/dashboard/support?new=Update%20email%20address" icon="mail" label="Update email address" />
          <RowLink icon="phone" label="Update phone number" onClick={() => document.getElementById('edit-profile')?.click()} />
          <RowLink to="/dashboard/settings#delete" icon="trash" label="Delete account" danger />
        </Panel>
        <Panel title="Privacy & security" icon="shield">
          <RowLink to="/dashboard/settings#devices" icon="monitor" label="Manage login devices" />
          <RowLink to="/dashboard/settings#documents" icon="upload" label="My documents" />
          <RowLink href={apiUrl('students.php', { action: 'export' })} icon="file" label="Download my data" />
          <RowLink to="/privacy-policy" icon="shield" label="Privacy policy" />
        </Panel>
        <HelpPanel />
      </div>
    </>
  );
}

function ProfileSkeleton() {
  return (
    <div aria-busy="true" className="space-y-5">
      <span className="sr-only" role="status">Loading your profile…</span>
      <Skeleton className="h-16 w-72" />
      <div className="grid gap-5 xl:grid-cols-2"><Skeleton className="h-64" rounded="rounded-2xl" /><Skeleton className="h-64" rounded="rounded-2xl" /></div>
      <div className="grid gap-5 lg:grid-cols-3"><Skeleton className="h-72" rounded="rounded-2xl" /><Skeleton className="h-72" rounded="rounded-2xl" /><Skeleton className="h-72" rounded="rounded-2xl" /></div>
    </div>
  );
}

/** The profile endpoint saves everything at once; fill unchanged values from the latest profile. */
function fullPayload(data, overrides = {}) {
  const p = data.profile;
  return {
    name: data.user.name, phone: data.user.phone || '', date_of_birth: p.date_of_birth || '', gender: p.gender || '', city: p.city || '', state: p.state || '',
    exam_preferences: p.exam_preferences, preferred_language: p.preferred_language, study_goal: p.study_goal || '',
    notify_email: p.notify_email, notify_sms: p.notify_sms, notify_class_reminders: p.notify_class_reminders, notify_prefs: p.notify_prefs,
    ...overrides,
  };
}

function ProfileInfo({ data, onSaved }) {
  const { setUser } = useAuth();
  const [editing, setEditing] = useState(false);
  const [avatar, setAvatar] = useState(data.user.avatar_url);
  const [avatarMsg, setAvatarMsg] = useState(null);
  const [uploading, setUploading] = useState(false);
  const [form, setForm] = useState(null);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);
  const fileRef = useRef(null);
  const panelRef = useRef(null);
  const p = data.profile;
  const u = data.user;

  const start = () => {
    setForm({ name: u.name, phone: u.phone || '', date_of_birth: p.date_of_birth || '', gender: p.gender || '', city: p.city || '', state: p.state || '' });
    setErrors({});
    setSaved(false);
    setEditing(true);
    setTimeout(() => {
      panelRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      document.getElementById('pf-name')?.focus({ preventScroll: true });
    }, 50);
  };
  const save = async (e) => {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const r = await api.post('students.php', fullPayload(data, form), { action: 'profile' });
      setUser((x) => ({ ...x, ...r.user }));
      setEditing(false);
      setSaved(true);
      onSaved(r);
    } catch (err) {
      setErrors({ ...(err.fields || {}), _: err.message });
    } finally {
      setBusy(false);
    }
  };
  const upload = async (e) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    setAvatarMsg(null);
    if (file.size > 2 * 1024 * 1024) {
      setAvatarMsg('Please choose an image under 2 MB.');
      return;
    }
    setUploading(true);
    try {
      const r = await api.upload('upload.php', file, { type: 'avatar' });
      setAvatar(r.url);
      setUser((x) => ({ ...x, avatar_url: r.url }));
    } catch (err) {
      setAvatarMsg(err.message);
    } finally {
      setUploading(false);
    }
  };
  const set = (k) => (e) => setForm((f) => ({ ...f, [k]: e.target.value }));
  const gender = { female: 'Female', male: 'Male', other: 'Other' }[p.gender] || '—';

  return (
    <div ref={panelRef} className="scroll-mt-28">
      <Panel title="Profile information" icon="user" className="h-full"
        action={!editing && <button id="edit-profile" type="button" onClick={start} className="btn-light btn-sm"><Icon name="edit" className="size-4" /> Edit</button>}>
        {saved && <Alert type="success" className="mb-4">Profile updated.</Alert>}
        {/* At xl the card sits in the narrower half of a 2-column grid: stack the photo above the
            details (photo + caption side by side) so the details keep a readable width. */}
        <div className="flex flex-col gap-6 sm:flex-row xl:flex-col xl:gap-5">
          <div className="shrink-0 text-center xl:flex xl:items-center xl:gap-4 xl:text-left">
            <button type="button" onClick={() => fileRef.current?.click()} disabled={uploading} className="group relative mx-auto block shrink-0 rounded-full focus-visible:ring-4 focus-visible:ring-primary/25 focus-visible:outline-none xl:mx-0" aria-label="Change profile photo">
              {avatar ? <img src={avatar} alt="" width="112" height="112" className="size-28 rounded-full object-cover ring-4 ring-primary-soft xl:size-20" /> : <span className="grid size-28 place-items-center rounded-full bg-gradient-to-br from-primary to-violet font-display text-4xl font-extrabold text-white xl:size-20 xl:text-3xl">{(u.name || '?')[0].toUpperCase()}</span>}
              <span className="absolute right-0.5 bottom-0.5 grid size-9 place-items-center rounded-full border-2 border-white bg-primary text-white transition-transform group-hover:scale-110 xl:-right-1 xl:-bottom-1 xl:size-8">{uploading ? <Busy /> : <Icon name="camera" className="size-4" />}</span>
            </button>
            <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" onChange={upload} className="hidden" />
            <div className="min-w-0">
              <p className="mt-2 text-xs text-muted xl:mt-0">JPG, PNG or WebP</p>
              {avatarMsg && <p role="alert" className="mx-auto mt-1 max-w-36 text-xs text-red-600 xl:mx-0 xl:max-w-none">{avatarMsg}</p>}
            </div>
          </div>
          {editing ? (
            <form onSubmit={save} noValidate className="grid min-w-0 flex-1 gap-3 sm:grid-cols-2">
              {errors._ && <Alert className="sm:col-span-2">{errors._}</Alert>}
              <Field id="pf-name" label="Full name" value={form.name} onChange={set('name')} error={errors.name} autoComplete="name" maxLength={120} />
              <Field id="pf-phone" label="Mobile number" type="tel" inputMode="tel" value={form.phone} onChange={set('phone')} error={errors.phone} autoComplete="tel" maxLength={16} />
              <Field id="pf-dob" label="Date of birth" type="date" value={form.date_of_birth} onChange={set('date_of_birth')} error={errors.date_of_birth} max={new Date().toISOString().slice(0, 10)} />
              <Field id="pf-gender" label="Gender">
                <select id="pf-gender" className="input" value={form.gender} onChange={set('gender')}>
                  <option value="">Prefer not to say</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option>
                </select>
              </Field>
              <Field id="pf-city" label="City" value={form.city} onChange={set('city')} error={errors.city} autoComplete="address-level2" maxLength={100} />
              <Field id="pf-state" label="State" value={form.state} onChange={set('state')} error={errors.state} autoComplete="address-level1" maxLength={100} />
              <div className="flex flex-wrap gap-2 sm:col-span-2">
                <button disabled={busy} className="btn-primary">{busy ? <><Busy /> Saving…</> : <><Icon name="check" className="size-4" /> Save changes</>}</button>
                <button type="button" onClick={() => setEditing(false)} className="btn-light">Cancel</button>
              </div>
            </form>
          ) : (
            <dl className="grid min-w-0 flex-1 gap-x-6 gap-y-4 sm:grid-cols-2">
              <Info icon="user" k="Name" v={u.name} />
              <Info icon="phone" k="Phone" v={<PhoneStatus phone={u.phone} onVerified={() => onSaved(null)} />} />
              {/* Email gets the full row so it never breaks mid-address in a narrow column */}
              <Info icon="mail" k="Email" className="sm:col-span-2" v={<span className="flex flex-wrap items-center gap-1.5"><span className="min-w-0 break-words">{u.email}</span>{u.email_verified ? <Pill tone="green">Verified</Pill> : <Pill tone="amber">Not verified</Pill>}</span>} />
              <Info icon="calendar" k="Date of birth" v={p.date_of_birth ? formatDate(p.date_of_birth) : '—'} />
              <Info icon="mapPin" k="Location" v={[p.city, p.state].filter(Boolean).join(', ') || '—'} />
              <Info icon="users" k="Gender" v={gender} />
            </dl>
          )}
        </div>
      </Panel>
    </div>
  );
}

/** Mobile number + whether it was confirmed by OTP (needed to log in with a mobile code). */
function PhoneStatus({ phone, onVerified }) {
  const [opts, reload] = useLoginOptions();
  const [open, setOpen] = useState(false);
  const channels = opts?.otp?.channels || [];
  if (!phone) return '—';
  return (
    <span className="flex flex-wrap items-center gap-1.5">
      <span className="break-words">{phone}</span>
      {opts?.phone && (opts.phone.verified ? <Pill tone="green">Verified</Pill> : channels.length > 0 && (
        <>
          <Pill tone="amber">Not verified</Pill>
          <button type="button" onClick={() => setOpen(true)} className="text-xs font-semibold text-primary hover:underline">Verify for OTP login</button>
        </>
      ))}
      <Modal open={open} onClose={() => setOpen(false)} title="Verify your mobile number" className="max-w-md">
        <div className="card rounded-2xl p-6">
          <p className="mb-4 text-sm text-muted">We send a one-time code to this number. Once it is verified you can log in with a code instead of your password.</p>
          <OtpFlow channels={channels} purpose="verify" initialPhone={phone} onDone={() => { setOpen(false); reload(); onVerified?.(); }} />
        </div>
      </Modal>
    </span>
  );
}

function Info({ icon, k, v, className = '' }) {
  return (
    <div className={'flex min-w-0 gap-3 ' + className}>
      <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-surface text-muted"><Icon name={icon} className="size-4" /></span>
      <div className="min-w-0"><dt className="text-xs font-medium text-muted">{k}</dt><dd className="mt-0.5 font-medium break-words text-ink">{v}</dd></div>
    </div>
  );
}

function ExamPreferences({ data, onSaved }) {
  const p = data.profile;
  const [prefs, setPrefs] = useState(p.exam_preferences);
  const [lang, setLang] = useState(p.preferred_language);
  const [goal, setGoal] = useState(p.study_goal || '');
  const [state, setState] = useState(null);
  const dirty = JSON.stringify([...prefs].sort()) !== JSON.stringify([...p.exam_preferences].sort()) || lang !== p.preferred_language || goal !== (p.study_goal || '');
  const toggle = (slug) => { setState(null); setPrefs((x) => (x.includes(slug) ? x.filter((s) => s !== slug) : [...x, slug])); };
  const save = async () => {
    setState('saving');
    try {
      const r = await api.post('students.php', fullPayload(data, { exam_preferences: prefs, preferred_language: lang, study_goal: goal }), { action: 'profile' });
      setState('saved');
      onSaved(r);
    } catch (e) {
      // The profile save also re-validates name and phone; point to the field that blocks it.
      const f = e.fields || {};
      const blocker = f.phone ? `Mobile number: ${f.phone} Update it under Profile information, then save again.`
        : f.name ? `Name: ${f.name} Update it under Profile information, then save again.`
          : f.exam_preferences || f.preferred_language || f.study_goal;
      setState(blocker || e.message || 'error');
    }
  };
  return (
    <Panel title="Exam preferences" icon="target" subtitle="Used to personalise courses, tests and alerts for you." className="h-full"
      action={dirty && <button type="button" onClick={save} disabled={state === 'saving'} className="btn-primary btn-sm">{state === 'saving' ? <><Busy /> Saving…</> : 'Save'}</button>}>
      {state === 'saved' && !dirty && <Alert type="success" className="mb-3">Preferences saved.</Alert>}
      {state && !['saving', 'saved'].includes(state) && <Alert className="mb-3">{state === 'error' ? 'Could not save. Please try again.' : state}</Alert>}
      <div className="grid gap-5 sm:grid-cols-[1.6fr_1fr] xl:grid-cols-1 2xl:grid-cols-[1.6fr_1fr]">
        <fieldset className="min-w-0">
          <legend className="mb-2 text-sm font-semibold text-ink">Preparing for</legend>
          {data.categories.length ? (
            <div className="flex flex-wrap gap-2">
              {data.categories.map((c) => {
                const on = prefs.includes(c.slug);
                return (
                  <button key={c.slug} type="button" onClick={() => toggle(c.slug)} aria-pressed={on}
                    className={'flex items-center gap-2 rounded-xl border px-3 py-2 text-sm transition-colors ' + (on ? 'border-primary bg-primary-soft font-semibold text-primary' : 'border-line bg-white text-ink hover:border-primary')}>
                    <span className={'grid size-4 place-items-center rounded-full ' + (on ? 'bg-primary text-white' : 'border border-line')}>{on && <Icon name="check" className="size-3" strokeWidth={3} />}</span>
                    {c.name}
                  </button>
                );
              })}
            </div>
          ) : <p className="text-sm text-muted">Exam categories will appear here once the academy publishes them.</p>}
        </fieldset>
        <div className="min-w-0 space-y-5 sm:border-l sm:border-line sm:pl-5 xl:border-0 xl:pl-0 2xl:border-l 2xl:pl-5">
          <fieldset>
            <legend className="mb-2 text-sm font-semibold text-ink">Preferred language</legend>
            <div className="flex gap-2">
              {[['english', 'English'], ['hindi', 'हिन्दी Hindi']].map(([v, l]) => (
                <label key={v} className={'flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm transition-colors has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-primary/20 ' + (lang === v ? 'border-primary bg-primary-soft font-semibold text-primary' : 'border-line hover:border-primary')}>
                  <input type="radio" name="pref-lang" checked={lang === v} onChange={() => { setState(null); setLang(v); }} className="size-4 accent-primary" />{l}
                </label>
              ))}
            </div>
          </fieldset>
          <div>
            <label htmlFor="goal" className="mb-2 block text-sm font-semibold text-ink">Study goal</label>
            <select id="goal" value={goal} onChange={(e) => { setState(null); setGoal(e.target.value); }} className="input">
              <option value="">Select your goal</option>
              {GOALS.map((g) => <option key={g} value={g}>{g}</option>)}
              {goal && !GOALS.includes(goal) && <option value={goal}>{goal}</option>}
            </select>
          </div>
        </div>
      </div>
    </Panel>
  );
}

/** Toggle key → partial body for POST students.php?action=preferences (only the changed field is sent). */
function prefPatch(key, on) {
  if (key === 'whatsapp') return { whatsapp_opt_in: on };
  if (key === 'email') return { notify_email: on };
  if (key === 'class_reminders') return { notify_class_reminders: on };
  return { notify_prefs: { [key]: on } };
}

function NotificationPrefs({ data, onSaved }) {
  const p = data.profile;
  const fromProfile = () => ({ ...p.notify_prefs, class_reminders: p.notify_class_reminders, email: p.notify_email, whatsapp: !!p.whatsapp_opt_in });
  const [values, setValues] = useState(fromProfile);
  const [saving, setSaving] = useState(null);
  const [msg, setMsg] = useState(null);
  // Keep in sync when another panel saved (fresh profile from the server).
  useEffect(() => setValues(fromProfile()), [p.notify_prefs, p.notify_class_reminders, p.notify_email, p.whatsapp_opt_in]); // eslint-disable-line react-hooks/exhaustive-deps
  const phone = data.user.phone || '';

  const change = async (key, on) => {
    const prev = values;
    setValues({ ...values, [key]: on });
    setSaving(key);
    setMsg(null);
    try {
      // Partial update: the server keeps every other preference and profile field as stored,
      // and answers with the full profile payload (same shape as GET action=profile).
      const r = await api.post('students.php', prefPatch(key, on), { action: 'preferences' });
      onSaved(r); // the parent now holds the fresh notify_* values, so later saves elsewhere keep them
      setMsg({ type: 'success', text: 'Saved.' });
    } catch (e) {
      setValues(prev);
      const field = e.fields && (e.fields.whatsapp_opt_in || e.fields.notify_prefs || e.fields.notify_email || e.fields.notify_class_reminders);
      setMsg({ type: 'error', text: field || e.message || 'Could not save. Please try again.' });
    } finally {
      setSaving(null);
    }
  };
  const master = !!values.email;

  return (
    <Panel title="Notification preferences" icon="bell" subtitle="Choose how we keep you updated by email and WhatsApp. Every update still appears in your Notifications inbox." className="h-full">
      {/* WhatsApp: the student's own opt-in, tied to the mobile number on the profile (a new number needs a new opt-in). */}
      <div className={'mb-3 flex items-center gap-3 rounded-xl border p-3 transition-colors ' + (values.whatsapp ? 'border-[#25D366]/35 bg-[#25D366]/5' : 'border-line bg-surface/60')}>
        <span className={'grid size-9 shrink-0 place-items-center rounded-lg text-white ' + (values.whatsapp ? 'bg-[#25D366]' : 'bg-slate-400')}><Icon name="whatsapp" className="size-[18px]" /></span>
        <span className="min-w-0 flex-1">
          <span className="block text-sm font-semibold text-navy">WhatsApp updates</span>
          <span id="np-whatsapp-hint" className="block text-xs text-muted">
            {!phone
              ? 'Add your mobile number under Profile information to get updates on WhatsApp.'
              : values.whatsapp
                ? `On for ${phone}. Payment, course, result and class updates also come on WhatsApp. Reply STOP or switch off anytime.`
                : p.whatsapp_number_changed
                  ? `Your mobile number changed. Switch on to get WhatsApp updates on ${phone}.`
                  : `Laxyam Academy will send account, payment, course and class updates on WhatsApp to ${phone}. Reply STOP or switch off anytime to opt out.`}
          </span>
        </span>
        {saving === 'whatsapp' && <span className="text-primary"><Busy /></span>}
        <Toggle checked={!!values.whatsapp} onChange={(on) => change('whatsapp', on)} label="WhatsApp updates" disabled={!phone || !!saving} />
      </div>
      <div className={'mb-3 flex items-center gap-3 rounded-xl border p-3 ' + (master ? 'border-primary/20 bg-primary-soft/60' : 'border-amber-200 bg-amber-50')}>
        <span className={'grid size-9 shrink-0 place-items-center rounded-lg ' + (master ? 'bg-primary text-white' : 'bg-amber-500 text-white')}><Icon name="mail" className="size-[18px]" /></span>
        <span className="min-w-0 flex-1">
          <span id="np-email" className="block text-sm font-semibold text-navy">Email updates</span>
          <span className="block text-xs text-muted">
            {master
              ? 'On. Choose the topics below.'
              : 'Off. No course, result or announcement emails. Payment, enrolment, account and support emails (and class reminders, if on) are still sent.'}
          </span>
        </span>
        {saving === 'email' && <span className="text-primary"><Busy /></span>}
        <Toggle checked={master} onChange={(on) => change('email', on)} label="Email updates" disabled={!!saving} />
      </div>
      <ul>
        {NOTIFY.map(([k, label, icon, desc]) => {
          const off = !master && k !== 'class_reminders'; // class reminders have their own switch on the server
          return (
            <li key={k} className={'flex items-center gap-3 border-b border-line py-2.5 last:border-0 ' + (off ? 'opacity-50' : '')}>
              <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-surface text-primary"><Icon name={icon} className="size-4" /></span>
              <span className="min-w-0 flex-1">
                <span className="block text-sm font-medium text-ink">{label}</span>
                <span className="block text-xs text-muted">{desc}</span>
              </span>
              {saving === k ? <span className="text-primary"><Busy /></span> : null}
              <Toggle checked={!!values[k] && !off} onChange={(on) => change(k, on)} label={label} disabled={off || !!saving} />
            </li>
          );
        })}
      </ul>
      <p className="mt-3 text-xs text-muted" aria-live="polite">
        {msg ? <span className={msg.type === 'error' ? 'font-semibold text-red-600' : 'font-semibold text-green-700'}>{msg.text}</span> : 'Payment, enrolment, account, security and support emails are always sent.'}
      </p>
    </Panel>
  );
}

function RecentPurchases({ state }) {
  const paid = (Array.isArray(state.data) ? state.data : []).filter((o) => o.status === 'paid').slice(0, 3);
  return (
    <Panel title="My purchases" icon="receipt" link={['/dashboard/purchases', 'View all']} className="h-full">
      {state.loading && !state.data ? <div className="space-y-3">{[0, 1].map((i) => <Skeleton key={i} className="h-20" rounded="rounded-xl" />)}</div>
        : state.error ? <p className="text-sm text-muted">Could not load purchases. <button type="button" onClick={state.reload} className="font-semibold text-primary hover:underline">Retry</button></p>
          : paid.length === 0 ? <p className="text-sm text-muted">No purchases yet.</p> : (
            <ul className="space-y-3">
              {paid.map((o) => {
                // GST invoice PDF when one was issued, else the printable receipt (e.g. 100%-coupon orders).
                const href = o.invoice?.invoice_number ? (o.invoice.url || apiUrl('orders.php', { action: 'invoice', order: o.order_number })) : apiUrl('orders.php', { action: 'receipt', order: o.order_number });
                return (
                  <li key={o.order_number}>
                    <a href={href} target="_blank" rel="noopener" className="flex items-center gap-3 rounded-xl border border-line p-2.5 transition-colors hover:border-primary">
                      <span className="grid size-14 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-primary to-navy text-white"><Icon name="book" className="size-6" /></span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate font-semibold text-navy">{(o.items || []).map((i) => i.title).join(', ') || o.order_number}</span>
                        <span className="block text-xs text-muted">Paid on {formatDate(o.paid_at)}</span>
                        <span className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-primary"><Icon name="file" className="size-3.5" /> {o.invoice ? `Invoice ${o.invoice.invoice_number}` : 'Receipt'}</span>
                      </span>
                      <Icon name="externalLink" className="size-4 text-muted" />
                    </a>
                  </li>
                );
              })}
            </ul>
          )}
    </Panel>
  );
}

function QuickLinks({ stats }) {
  return (
    <Panel title="Quick links" icon="grid" className="h-full">
      <RowLink to="/dashboard/courses" icon="layers" label="My courses" value={stats.courses || null} />
      <RowLink to="/dashboard/certificates" icon="badge" label="My certificates" value={stats.certificates || null} />
      <RowLink to="/dashboard/tests" icon="trophy" label="My test results" value={stats.tests || null} />
      <RowLink to="/dashboard/purchases" icon="receipt" label="Invoices & payments" />
      <RowLink to="/dashboard/wishlist" icon="heart" label="Wishlist" />
    </Panel>
  );
}

export function HelpPanel() {
  const { data: site } = useSite();
  const phone = site?.site?.phone;
  return (
    <Panel title="Help & support" icon="headset">
      <RowLink to="/faq" icon="help" label="FAQs" />
      <RowLink to="/dashboard/support?new=1" icon="ticket" label="Raise a ticket" />
      <RowLink to="/contact" icon="messages" label="Contact us" />
      {phone && <RowLink href={`tel:${phone.replace(/[^\d+]/g, '')}`} icon="phone" label={`Call ${phone}`} />}
    </Panel>
  );
}
