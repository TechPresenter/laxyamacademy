import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiUrl, recaptchaToken, resetCsrf } from '../js/api.js';
import { Alert, DemoBadge, ErrorState, Field, Icon, MicButton, PageHeader, Reveal, ShareButtons, Skeleton, useApi, useSeo } from '../js/components.jsx';
import { formatDay } from '../js/content/shared.jsx';
import { ApplicationForm, ResumeInput, SUBJECT_ICONS, resumeError } from './Careers.jsx';

export default function CareerDetails() {
  const { slug } = useParams();
  const { data: o, loading, error, reload } = useApi('jobs.php', { action: 'opening', slug });
  useSeo({ title: o ? `${o.title} - Apply Online | Laxyam Academy` : 'Careers | Laxyam Academy', description: o?.description || undefined });

  if (error) {
    return error.code === 'not_found' || error.status === 404 ? (
      <section className="bg-mesh">
        <div className="container-x py-20 text-center">
          <span className="mx-auto grid size-16 place-items-center rounded-2xl bg-white text-primary shadow-card"><Icon name="briefcase" className="size-8" /></span>
          <h1 className="heading-lg mt-5">This position is no longer open</h1>
          <p className="mx-auto mt-3 max-w-md text-muted">It may have been filled or closed. See the positions that are open now.</p>
          <Link to="/careers" className="btn-primary mt-7">View open positions</Link>
        </div>
      </section>
    ) : <ErrorState error={error} onRetry={reload} />;
  }
  if (loading && !o) {
    return (
      <div aria-busy="true" aria-label="Loading position">
        <div className="bg-mesh"><div className="container-x space-y-4 py-12"><Skeleton className="h-4 w-48" /><Skeleton className="h-9 w-2/3" /><Skeleton className="h-8 w-80 max-w-full" rounded="rounded-full" /></div></div>
        <div className="container-x grid gap-8 py-10 lg:grid-cols-[1fr_440px]"><div className="space-y-6"><Skeleton className="h-48" rounded="rounded-2xl" /><Skeleton className="h-40" rounded="rounded-2xl" /></div><Skeleton className="h-96" rounded="rounded-2xl" /></div>
      </div>
    );
  }

  return (
    <>
      <PageHeader
        eyebrow={<><Icon name={o.is_faculty ? 'presentation' : 'briefcase'} className="size-3.5" />{o.is_faculty ? 'Faculty position' : o.department || 'Open position'}</>}
        title={o.title}
        crumbs={[{ to: '/', label: 'Home' }, { to: '/careers', label: 'Careers' }, { label: o.title }]}
      >
        <ul className="mt-5 flex flex-wrap gap-2 text-sm">
          {o.subject_label && <li className="flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 font-medium shadow-sm"><Icon name={SUBJECT_ICONS[o.subject] || 'presentation'} className="size-4 text-primary" />{o.subject_label}</li>}
          {o.employment_types.map((t) => <li key={t.value} className="rounded-full bg-primary-soft px-3 py-1.5 font-semibold text-primary">{t.label}</li>)}
          {o.location && <li className="flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 shadow-sm"><Icon name="mapPin" className="size-4 text-primary" />{o.location}</li>}
          {o.closes_at && <li className="flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1.5 font-semibold text-red-700 ring-1 ring-red-200"><Icon name="clock" className="size-4" />Apply by {formatDay(o.closes_at)}</li>}
          {o.is_demo && <li><DemoBadge /></li>}
        </ul>
        <a href="#apply" className="btn-primary btn-lg mt-6 lg:hidden">Apply now <Icon name="arrowRight" className="size-4" /></a>
      </PageHeader>

      <section className="container-x grid gap-8 py-10 lg:grid-cols-[minmax(0,1fr)_440px]">
        <div className="min-w-0 space-y-6">
          {(o.description || o.experience || o.salary_range || o.closes_at) && (
            <Reveal className="card p-6">
              <h2 className="font-display text-xl font-bold text-navy">About the role</h2>
              {o.description && <p className="mt-2 leading-relaxed text-muted">{o.description}</p>}
              <dl className="mt-5 grid gap-3 text-sm sm:grid-cols-3">
                {o.experience && <RoleFact icon="trophy" label="Experience" value={o.experience} />}
                {o.salary_range && <RoleFact icon="star" label="Salary" value={o.salary_range} />}
                {/* closes_at is a calendar date ('YYYY-MM-DD'): formatted without a UTC shift */}
                {o.closes_at && <RoleFact icon="calendar" label="Apply by" value={formatDay(o.closes_at, { day: 'numeric', month: 'long', year: 'numeric' })} />}
              </dl>
            </Reveal>
          )}
          {o.responsibilities?.length > 0 && <ListCard title="What you will do" icon="checkCircle" items={o.responsibilities} />}
          {o.requirements?.length > 0 && <ListCard title="Skills & qualifications" icon="badge" items={o.requirements} />}
          <Reveal className="card p-6">
            <h2 className="font-display text-xl font-bold text-navy">How hiring works</h2>
            <ol className="mt-4 grid gap-4 sm:grid-cols-2">
              {[
                ['file', 'Apply online', 'Fill in the form and upload your resume.'],
                ['users', 'Review & shortlisting', 'Our team reviews every application.'],
                ['calendar', 'Interview', 'Shortlisted candidates are contacted by phone or email to schedule an interview.'],
                ['checkCircle', 'Offer & joining', 'Selected candidates receive the joining details.'],
              ].map(([icon, title, text], i) => (
                <li key={title} className="flex gap-3 rounded-xl bg-surface/70 p-3">
                  <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-primary to-violet text-white"><Icon name={icon} className="size-5" /></span>
                  <div><p className="font-semibold text-navy">{i + 1}. {title}</p><p className="text-sm text-muted">{text}</p></div>
                </li>
              ))}
            </ol>
          </Reveal>
          <div className="card p-5">
            <ShareButtons title={`${o.title} - Laxyam Academy is hiring`} text={`Laxyam Academy is hiring: ${o.title}`} label="Share this job:" size="sm" />
          </div>
        </div>
        <div id="apply" className="min-w-0 scroll-mt-28">
          {o.is_faculty
            ? <FacultyApplicationForm o={o} />
            : <ApplicationForm openings={[o]} general={false} selected={o.slug} onSelect={() => {}} />}
        </div>
      </section>
    </>
  );
}

function RoleFact({ icon, label, value }) {
  return (
    <div className="rounded-xl bg-surface/80 p-3">
      <dt className="flex items-center gap-1.5 text-xs font-semibold text-muted"><Icon name={icon} className="size-3.5" />{label}</dt>
      <dd className="mt-0.5 font-semibold text-navy">{value}</dd>
    </div>
  );
}

function ListCard({ title, icon, items }) {
  return (
    <Reveal className="card p-6">
      <h2 className="font-display text-xl font-bold text-navy">{title}</h2>
      <ul className="mt-4 space-y-3">
        {items.map((item) => (
          <li key={item} className="flex gap-3 text-sm text-ink"><Icon name={icon} className="mt-0.5 size-5 shrink-0 text-primary" />{item}</li>
        ))}
      </ul>
    </Reveal>
  );
}

const EMPTY = { name: '', phone: '', email: '', qualifications: '', experience_years: '', english_level: '', hindi_level: '', expected_salary: '', message: '', accept_terms: false, whatsapp_consent: false, website: '' };

/** Faculty application: subject, Full-time/Part-time, qualifications, experience, languages, salary, resume. */
function FacultyApplicationForm({ o }) {
  const [values, setValues] = useState({ ...EMPTY, subject: o.subject, employment_type: o.employment_types.length === 1 ? o.employment_types[0].value : '' });
  const [file, setFile] = useState(null);
  const [errors, setErrors] = useState({});
  const [status, setStatus] = useState({ state: 'idle' });
  const set = (k) => (e) => setValues((v) => ({ ...v, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));
  const focusFirstError = () => requestAnimationFrame(() => document.querySelector('#apply [aria-invalid="true"]')?.focus());

  const submit = async (e) => {
    e.preventDefault();
    // Type/size checked here; "required" is reported by the server together with the other fields
    const fileErr = resumeError(file);
    if (fileErr) {
      setErrors({ resume: fileErr });
      focusFirstError();
      return;
    }
    setStatus({ state: 'busy' });
    setErrors({});
    try {
      const tokenRes = await fetch(apiUrl('auth.php', { action: 'csrf' }), { credentials: 'same-origin' }).then((r) => r.json());
      resetCsrf(tokenRes.data.token);
      const form = new FormData();
      Object.entries(values).forEach(([k, v]) => form.append(k, typeof v === 'boolean' ? (v ? '1' : '') : v));
      form.append('opening_slug', o.slug);
      if (file) form.append('resume', file);
      const recaptcha = await recaptchaToken('careers_apply');
      if (recaptcha) form.append('recaptcha', recaptcha);
      const res = await fetch(apiUrl('jobs.php', { action: 'apply' }), { method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-CSRF-Token': tokenRes.data.token, Accept: 'application/json' } });
      const payload = await res.json().catch(() => null);
      if (!payload?.ok) {
        setErrors(payload?.error?.fields || {});
        throw new Error(payload?.error?.message || 'Something went wrong. Please try again.');
      }
      setStatus({ state: 'done', reference: payload.data.reference });
      document.getElementById('apply')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (err) {
      setStatus({ state: 'error', msg: err.message });
      focusFirstError();
    }
  };

  if (status.state === 'done') {
    return (
      <div className="card p-8 text-center" role="status">
        <span className="mx-auto grid size-16 animate-pop place-items-center rounded-full bg-emerald-50 text-emerald-600 ring-8 ring-emerald-50/60"><Icon name="checkCircle" className="size-8" /></span>
        <h2 className="mt-4 font-display text-xl font-bold text-navy">Application submitted</h2>
        <p className="mt-2 text-sm text-muted">
          Thank you, {values.name}.{status.reference && <> Your reference number is <strong className="text-ink">{status.reference}</strong>.</>}
          Our team reviews every application and will contact you by phone or email if you are shortlisted.
        </p>
        <Link to="/careers" className="btn-outline mt-6">Back to careers</Link>
      </div>
    );
  }

  const select = (id, label, options, placeholder) => (
    <Field id={id} label={label} error={errors[id]}>
      <select id={id} name={id} value={values[id]} onChange={set(id)} className="input" aria-invalid={!!errors[id]} aria-describedby={errors[id] ? `${id}-err` : undefined}>
        <option value="">{placeholder}</option>
        {options.map((opt) => <option key={opt.value} value={opt.value}>{opt.label}</option>)}
      </select>
    </Field>
  );

  return (
    <form onSubmit={submit} noValidate className="card space-y-4 p-6 lg:sticky lg:top-24" aria-labelledby="apply-heading">
      <div>
        <h2 id="apply-heading" className="text-xl font-bold">Apply for this position</h2>
        <p className="text-sm text-muted">Fields marked * are required.</p>
      </div>
      {status.state === 'error' && <Alert>{status.msg}</Alert>}
      <Field id="name" label="Full name *" value={values.name} onChange={set('name')} error={errors.name} autoComplete="name" maxLength={120} />
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="phone" label="Mobile number *" type="tel" inputMode="tel" value={values.phone} onChange={set('phone')} error={errors.phone} autoComplete="tel" placeholder="10-digit mobile" />
        <Field id="email" label="Email address *" type="email" value={values.email} onChange={set('email')} error={errors.email} autoComplete="email" />
      </div>
      {select('subject', 'Preferred subject *', o.subjects, 'Choose a subject…')}
      <fieldset>
        <legend className="label">Employment type *</legend>
        <div className="grid grid-cols-2 gap-3">
          {o.employment_types.map((t) => (
            <label key={t.value} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-sm font-medium transition ${values.employment_type === t.value ? 'border-primary bg-primary-soft text-primary' : 'border-line hover:border-primary'}`}>
              <input type="radio" name="employment_type" value={t.value} checked={values.employment_type === t.value} onChange={set('employment_type')} className="accent-[var(--color-primary)]" aria-invalid={!!errors.employment_type} />
              {t.label}
            </label>
          ))}
        </div>
        {errors.employment_type && <p className="mt-1 text-xs text-red-600">{errors.employment_type}</p>}
      </fieldset>
      <Field id="qualifications" label="Qualifications *" value={values.qualifications} onChange={set('qualifications')} error={errors.qualifications} maxLength={500} placeholder="e.g. M.Sc. Mathematics, B.Ed." />
      <div className="grid gap-4 sm:grid-cols-2">
        <Field id="experience_years" label="Teaching experience (years) *" type="number" min="0" max="60" inputMode="numeric" value={values.experience_years} onChange={set('experience_years')} error={errors.experience_years} hint="Enter 0 if you are a fresher." />
        <Field id="expected_salary" label="Expected salary (₹ per month) *" value={values.expected_salary} onChange={set('expected_salary')} error={errors.expected_salary} maxLength={60} placeholder="e.g. 35,000 or Negotiable" />
      </div>
      <fieldset>
        <legend className="label">Language proficiency *</legend>
        <div className="grid gap-4 sm:grid-cols-2">
          {select('english_level', 'English', o.language_levels, 'Choose…')}
          {select('hindi_level', 'Hindi', o.language_levels, 'Choose…')}
        </div>
      </fieldset>
      <div>
        <div className="flex items-center justify-between gap-2">
          <label htmlFor="message" className="label">Teaching experience details</label>
          <MicButton onText={(text, final) => final && setValues((v) => ({ ...v, message: (v.message ? v.message + ' ' : '') + text }))} />
        </div>
        <textarea id="message" name="message" rows={3} maxLength={3000} value={values.message} onChange={set('message')} className="input" placeholder="Where have you taught? Competitive exams, coaching, online classes…" aria-invalid={!!errors.message} />
        {errors.message && <p className="mt-1 text-xs text-red-600">{errors.message}</p>}
      </div>
      <ResumeInput file={file} onChange={(f) => { setFile(f); setErrors((x) => ({ ...x, resume: (f && resumeError(f)) || undefined })); }} error={errors.resume} required />
      <div>
        <label className="flex items-start gap-2 text-sm">
          <input type="checkbox" checked={values.accept_terms} onChange={set('accept_terms')} className="mt-1 accent-[var(--color-primary)]" aria-invalid={!!errors.accept_terms} />
          <span>I confirm these details are correct and agree that Laxyam Academy may store my application for recruitment, as described in the <Link to="/privacy-policy" className="text-primary underline">Privacy Policy</Link>. *</span>
        </label>
        {errors.accept_terms && <p className="mt-1 text-xs text-red-600">{errors.accept_terms}</p>}
      </div>
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" name="whatsapp_consent" checked={values.whatsapp_consent} onChange={set('whatsapp_consent')} className="mt-1 accent-[var(--color-primary)]" />
        <span>Send me updates about my application on WhatsApp (optional). We only message you about this application, and you can reply STOP at any time.</span>
      </label>
      <input aria-hidden="true" tabIndex={-1} autoComplete="off" value={values.website} onChange={set('website')} className="hidden" />
      <button disabled={status.state === 'busy'} className="btn-primary w-full py-3">{status.state === 'busy' ? 'Submitting…' : 'Submit Application'}</button>
      <p className="text-xs text-muted">Your resume and details are stored privately and seen only by our hiring team.</p>
    </form>
  );
}
