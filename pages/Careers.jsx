import { useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiUrl, recaptchaToken, resetCsrf } from '../js/api.js';
import { Alert, DemoBadge, ErrorState, Field, Icon, PageHeader, Reveal, Skeleton, useApi, useSeo } from '../js/components.jsx';
import { formatDay } from '../js/content/shared.jsx';

/** Icon per faculty subject (subjects added later in admin get a generic icon). */
export const SUBJECT_ICONS = { quantitative_aptitude: 'calculator', reasoning: 'puzzle', general_studies: 'globe', science_technology: 'atom', english: 'languages' };
const SUBJECT_TONES = ['from-primary to-[#2f7bff]', 'from-violet to-[#8f74ff]', 'from-teal to-[#22c3c1]', 'from-coral to-[#ff7a45]', 'from-[#f2b200] to-gold'];

/** What the academy expects from every faculty member. */
const FACULTY_SKILLS = [
  ['languages', 'Comfortable speaking English and Hindi'],
  ['users', 'Skilled in managing students'],
  ['file', 'Prepares notes, questions, mock tests and assignments'],
  ['presentation', 'Explains complex concepts simply'],
  ['help', 'Clarifies doubts and guides students'],
  ['trophy', 'Competitive-exam or online teaching experience is a plus'],
];

export const RESUME_MAX_BYTES = 5 * 1024 * 1024;
const RESUME_TYPES = /\.(pdf|docx?)$/i;

/** Client-side resume check (the server validates again): PDF/DOC/DOCX up to 5 MB. */
export function resumeError(file, required = false) {
  if (!file) return required ? 'Please upload your resume.' : null;
  if (!RESUME_TYPES.test(file.name)) return 'Upload a PDF, DOC or DOCX file.';
  if (file.size > RESUME_MAX_BYTES) return 'The file is larger than 5 MB. Please upload a smaller file.';
  return null;
}

export default function Careers() {
  useSeo({ title: 'Careers & Faculty Recruitment | Laxyam Academy', description: 'Join Laxyam Academy as full-time or part-time faculty, or in our team. See open positions and apply online.' });
  const { data, loading, error, reload } = useApi('jobs.php', { action: 'openings' });
  const [params, setParams] = useSearchParams();
  const selected = params.get('apply');
  const formRef = useRef(null);
  const faculty = data?.items?.filter((o) => o.is_faculty) || [];
  const others = data?.items?.filter((o) => !o.is_faculty) || [];
  const showOthers = others.length > 0 || data?.general_applications !== false;
  const openCount = data?.items?.length || 0;

  const applyFor = (slug) => {
    setParams(slug ? { apply: slug } : {}, { replace: true });
    setTimeout(() => formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
  };

  const pending = loading && !data;
  return (
    <>
      <PageHeader
        eyebrow={<><Icon name="briefcase" className="size-3.5" />Careers at Laxyam</>}
        title={pending ? <><span className="sr-only">Careers</span><span aria-hidden="true" className="skeleton inline-block h-9 w-72 max-w-full rounded-lg align-middle" /></> : (data?.page?.title || 'Careers')}
        subtitle={pending ? null : data?.page?.intro || 'Teach thousands of aspirants preparing for UPSC, SSC, Banking and State exams - or help us build India’s friendliest exam-prep academy.'}
        crumbs={[{ to: '/', label: 'Home' }, { label: 'Careers' }]}
        actions={data && openCount > 0 ? (
          <a href="#faculty-heading" className="glass-light flex items-center gap-3 rounded-2xl px-5 py-3 shadow-card transition hover:-translate-y-0.5">
            <span className="grid size-11 place-items-center rounded-xl bg-gradient-to-br from-primary to-violet text-white"><Icon name="briefcase" className="size-5" /></span>
            <span><strong className="block font-display text-2xl leading-none text-navy">{openCount}</strong><span className="text-xs font-semibold text-muted">open position{openCount === 1 ? '' : 's'}</span></span>
          </a>
        ) : null}
      >
        {pending && <div className="mt-3 max-w-2xl space-y-2" aria-hidden="true"><Skeleton className="h-4 w-full" /><Skeleton className="h-4 w-2/3" /></div>}
      </PageHeader>

      {data?.page?.sections?.length > 0 && (
        <section className="container-x pt-10" aria-label="Why work with us">
          <ul className="grid gap-5 md:grid-cols-2">
            {data.page.sections.map((s, i) => (
              <Reveal as="li" key={s.heading || i} delay={i * 60} className="card card-hover flex gap-4 p-6">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-primary-soft to-white text-primary ring-1 ring-primary/10"><Icon name={s.icon || 'star'} className="size-6" /></span>
                <div className="min-w-0"><h2 className="font-display text-lg font-bold text-navy">{s.heading}</h2><div className="prose-lite mt-1 text-sm text-muted" dangerouslySetInnerHTML={{ __html: s.html }} /></div>
              </Reveal>
            ))}
          </ul>
        </section>
      )}

      {error ? <ErrorState error={error} onRetry={reload} /> : pending ? (
        <div className="container-x grid gap-5 py-10 sm:grid-cols-2 lg:grid-cols-3" aria-busy="true">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-64" rounded="rounded-2xl" />)}</div>
      ) : (
        <>
          <section className="container-x py-12" aria-labelledby="faculty-heading">
            <span className="eyebrow">We are hiring</span>
            <h2 id="faculty-heading" className="heading-lg mt-3 scroll-mt-28">Faculty <span className="text-gradient">Recruitment</span></h2>
            <p className="mt-2 max-w-2xl text-muted">Choose your subject and apply for a full-time or part-time teaching position. Every subject has its own application form.</p>
            {faculty.length === 0 ? (
              <div className="card mt-6 flex items-center gap-4 p-6 text-muted"><Icon name="calendar" className="size-6 shrink-0 text-primary" />No faculty positions are open right now. Please check back soon.</div>
            ) : (
              <ul className="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {faculty.map((o, i) => <FacultyCard key={o.slug} o={o} index={i} />)}
              </ul>
            )}
            <Reveal className="card mt-10 overflow-hidden">
              <div className="border-b border-line bg-surface/70 px-6 py-4"><h3 className="font-display text-lg font-bold text-navy">What we look for in every faculty member</h3></div>
              <ul className="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-3">
                {FACULTY_SKILLS.map(([icon, text]) => (
                  <li key={text} className="flex items-center gap-3 text-sm text-ink">
                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name={icon} className="size-5" /></span>
                    {text}
                  </li>
                ))}
              </ul>
            </Reveal>
          </section>

          {showOthers && (
            <section className="border-t border-line bg-surface">
              <div className="container-x grid gap-8 py-12 lg:grid-cols-[minmax(0,1fr)_400px]">
                <div className="min-w-0">
                  <h2 className="heading-lg mb-6">Other <span className="text-gradient">Openings</span></h2>
                  {others.length === 0 ? (
                    <p className="card p-6 text-muted">There are no other open positions right now. You can still send us your resume using the form.</p>
                  ) : (
                    <ul className="space-y-4">
                      {others.map((o) => (
                        <li key={o.slug} className="card overflow-hidden">
                          <details className="group" open={selected === o.slug}>
                            <summary className="flex cursor-pointer list-none flex-wrap items-start justify-between gap-3 p-5 transition hover:bg-surface/60 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary/20 [&::-webkit-details-marker]:hidden">
                              <div className="min-w-0">
                                <h3 className="flex flex-wrap items-center gap-2 font-display text-lg font-bold text-navy">{o.title} {o.is_demo && <DemoBadge />}</h3>
                                <ul className="mt-2 flex flex-wrap gap-2 text-xs font-semibold text-muted">
                                  <li className="flex items-center gap-1.5 rounded-full bg-surface px-2.5 py-1"><Icon name="briefcase" className="size-3.5" />{o.employment_type_label}</li>
                                  {o.location && <li className="flex items-center gap-1.5 rounded-full bg-surface px-2.5 py-1"><Icon name="mapPin" className="size-3.5" />{o.location}</li>}
                                  {o.experience && <li className="flex items-center gap-1.5 rounded-full bg-surface px-2.5 py-1"><Icon name="trophy" className="size-3.5" />{o.experience}</li>}
                                  {o.salary_range && <li className="flex items-center gap-1.5 rounded-full bg-surface px-2.5 py-1"><Icon name="star" className="size-3.5" />{o.salary_range}</li>}
                                  {o.closes_at && <li className="flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-red-700"><Icon name="clock" className="size-3.5" />Apply by {formatDay(o.closes_at)}</li>}
                                </ul>
                              </div>
                              <span className="flex items-center gap-1 text-sm font-semibold text-primary">Details <Icon name="chevronDown" className="size-4 transition-transform group-open:rotate-180" /></span>
                            </summary>
                            <div className="border-t border-line p-5 text-sm">
                              {o.description && <p className="text-ink">{o.description}</p>}
                              {o.responsibilities?.length > 0 && <><h4 className="mt-4 font-semibold text-navy">Responsibilities</h4><ul className="mt-1 list-disc space-y-1 pl-5 text-muted">{o.responsibilities.map((r) => <li key={r}>{r}</li>)}</ul></>}
                              {o.requirements?.length > 0 && <><h4 className="mt-4 font-semibold text-navy">Requirements</h4><ul className="mt-1 list-disc space-y-1 pl-5 text-muted">{o.requirements.map((r) => <li key={r}>{r}</li>)}</ul></>}
                              <div className="mt-5 flex flex-wrap gap-3">
                                <button type="button" onClick={() => applyFor(o.slug)} className="btn-primary">Apply for this role</button>
                                <Link to={`/careers/${o.slug}`} className="btn-light">Open full page</Link>
                              </div>
                            </div>
                          </details>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
                <div ref={formRef} className="min-w-0 scroll-mt-28">
                  <ApplicationForm openings={others} general={data.general_applications !== false} selected={selected} onSelect={applyFor} />
                </div>
              </div>
            </section>
          )}
        </>
      )}
    </>
  );
}

function FacultyCard({ o, index }) {
  return (
    <Reveal as="li" delay={(index % 3) * 70} className="card card-hover group relative flex flex-col overflow-hidden p-6">
      <div aria-hidden="true" className="absolute -top-12 -right-12 size-32 rounded-full bg-primary/5 transition-transform duration-500 group-hover:scale-150" />
      <span className={'relative grid size-14 place-items-center rounded-2xl bg-gradient-to-br text-white shadow-md ' + SUBJECT_TONES[index % SUBJECT_TONES.length]}><Icon name={SUBJECT_ICONS[o.subject] || 'presentation'} className="size-7" /></span>
      <h3 className="relative mt-4 flex flex-wrap items-center gap-2 font-display text-lg font-bold text-navy">{o.title} {o.is_demo && <DemoBadge />}</h3>
      {o.description && <p className="relative mt-1 line-clamp-2 text-sm text-muted">{o.description}</p>}
      <ul className="relative mt-3 flex flex-wrap gap-2" aria-label="Employment types">
        {o.employment_types.map((t) => <li key={t.value} className="rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">{t.label}</li>)}
      </ul>
      {o.location && <p className="relative mt-3 flex items-center gap-1.5 text-sm text-muted"><Icon name="mapPin" className="size-4 shrink-0" />{o.location}</p>}
      {o.closes_at && <p className="relative mt-1 flex items-center gap-1.5 text-sm font-medium text-red-600"><Icon name="clock" className="size-4" />Apply by {formatDay(o.closes_at)}</p>}
      <div className="relative mt-auto pt-5">
        <Link to={`/careers/${o.slug}`} className="btn-primary w-full" aria-label={`View and apply: ${o.title}`}>
          View &amp; Apply <Icon name="arrowRight" className="size-4" />
        </Link>
      </div>
    </Reveal>
  );
}

const EMPTY_GENERAL = { name: '', email: '', phone: '', experience_years: '', subjects: '', message: '', whatsapp_consent: false, website: '' };

/** Short application for non-faculty roles (and general applications). */
export function ApplicationForm({ openings, general, selected, onSelect }) {
  const [values, setValues] = useState(EMPTY_GENERAL);
  const [file, setFile] = useState(null);
  const [errors, setErrors] = useState({});
  const [status, setStatus] = useState({ state: 'idle', msg: '' });
  const formEl = useRef(null);
  const set = (k) => (e) => setValues((v) => ({ ...v, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));
  const focusFirstError = () => requestAnimationFrame(() => formEl.current?.querySelector('[aria-invalid="true"]')?.focus());

  const pickFile = (f) => {
    setFile(f);
    setErrors((x) => ({ ...x, resume: resumeError(f) || undefined }));
  };

  const submit = async (e) => {
    e.preventDefault();
    const fileErr = resumeError(file);
    if (fileErr) {
      setErrors({ resume: fileErr });
      setStatus({ state: 'idle', msg: '' });
      focusFirstError();
      return;
    }
    setStatus({ state: 'busy', msg: '' });
    setErrors({});
    try {
      const tokenRes = await fetch(apiUrl('auth.php', { action: 'csrf' }), { credentials: 'same-origin' }).then((r) => r.json());
      resetCsrf(tokenRes.data.token);
      const form = new FormData();
      Object.entries(values).forEach(([k, v]) => form.append(k, typeof v === 'boolean' ? (v ? '1' : '') : v));
      if (selected) form.append('opening_slug', selected);
      if (file) form.append('resume', file);
      const recaptcha = await recaptchaToken('careers_apply');
      if (recaptcha) form.append('recaptcha', recaptcha);
      const res = await fetch(apiUrl('jobs.php', { action: 'apply' }), { method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-CSRF-Token': tokenRes.data.token, Accept: 'application/json' } });
      const payload = await res.json().catch(() => null);
      if (!payload?.ok) {
        setErrors(payload?.error?.fields || {});
        throw new Error(payload?.error?.message || 'Something went wrong. Please try again.');
      }
      setStatus({ state: 'done', msg: `Thank you! Your application has been received${payload.data?.reference ? ` (reference ${payload.data.reference})` : ''}. Our team will contact you if your profile matches.` });
      setValues(EMPTY_GENERAL);
      setFile(null);
    } catch (err) {
      setStatus({ state: 'error', msg: err.message });
      focusFirstError();
    }
  };

  return (
    <form ref={formEl} onSubmit={submit} noValidate className="card space-y-4 p-6 lg:sticky lg:top-24" aria-labelledby="general-apply-heading">
      <div>
        <h2 id="general-apply-heading" className="font-display text-xl font-bold text-navy">Apply Online</h2>
        <p className="text-sm text-muted">Fields marked * are required.</p>
      </div>
      {status.state === 'done' && <Alert type="success">{status.msg}</Alert>}
      {status.state === 'error' && <Alert>{status.msg}</Alert>}
      <div>
        <label htmlFor="opening" className="label">Position</label>
        <select id="opening" value={selected || ''} onChange={(e) => onSelect(e.target.value)} className="input" aria-invalid={!!errors.opening_slug} aria-describedby={errors.opening_slug ? 'opening-err' : undefined}>
          {general ? <option value="">General application</option> : <option value="">Choose a position…</option>}
          {openings.map((o) => <option key={o.slug} value={o.slug}>{o.title}</option>)}
        </select>
        {errors.opening_slug && <p id="opening-err" className="mt-1 text-xs text-red-600">{errors.opening_slug}</p>}
      </div>
      <Field id="name" label="Full name *" value={values.name} onChange={set('name')} error={errors.name} autoComplete="name" maxLength={120} />
      <div className="grid gap-4 sm:grid-cols-2">
        <Field className="min-w-0" id="email" label="Email *" type="email" value={values.email} onChange={set('email')} error={errors.email} autoComplete="email" />
        <Field className="min-w-0" id="phone" label="Phone *" type="tel" inputMode="tel" value={values.phone} onChange={set('phone')} error={errors.phone} autoComplete="tel" />
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field className="min-w-0" id="experience_years" label="Experience (years)" type="number" min="0" max="60" inputMode="numeric" value={values.experience_years} onChange={set('experience_years')} error={errors.experience_years} />
        <Field className="min-w-0" id="subjects" label="Subjects / skills" value={values.subjects} onChange={set('subjects')} error={errors.subjects} maxLength={200} placeholder="e.g. Counselling, Excel" />
      </div>
      <Field id="message" label="About you" as="textarea" rows={3} maxLength={3000} value={values.message} onChange={set('message')} error={errors.message} />
      <ResumeInput file={file} onChange={pickFile} error={errors.resume} />
      <label className="flex items-start gap-2 text-sm">
        <input type="checkbox" name="whatsapp_consent" checked={values.whatsapp_consent} onChange={set('whatsapp_consent')} className="mt-1 accent-[var(--color-primary)]" />
        <span>Send me updates about my application on WhatsApp (optional). We only message you about this application, and you can reply STOP at any time.</span>
      </label>
      <input aria-hidden="true" tabIndex={-1} autoComplete="off" name="website" value={values.website} onChange={set('website')} className="hidden" />
      <button disabled={status.state === 'busy'} className="btn-primary btn-lg w-full">{status.state === 'busy' ? 'Submitting…' : 'Submit Application'}</button>
      <p className="flex items-center gap-1.5 text-xs text-muted"><Icon name="lock" className="size-3.5" />Your resume is stored privately and seen only by our hiring team.</p>
    </form>
  );
}

export function ResumeInput({ file, onChange, error, required = false }) {
  return (
    <div>
      <label htmlFor="resume" className="label">Resume (PDF, DOC or DOCX, max 5 MB){required ? ' *' : ''}</label>
      <label htmlFor="resume" className={`flex cursor-pointer items-center gap-3 rounded-xl border-2 border-dashed px-4 py-3.5 text-sm transition hover:border-primary hover:bg-primary-soft/40 ${error ? 'border-red-400 bg-red-50/50' : file ? 'border-emerald-300 bg-emerald-50/50' : 'border-line'}`}>
        <span className={'grid size-9 shrink-0 place-items-center rounded-lg ' + (file && !error ? 'bg-emerald-100 text-emerald-700' : 'bg-primary-soft text-primary')}><Icon name={file && !error ? 'checkCircle' : 'upload'} className="size-5" /></span>
        <span className="min-w-0 flex-1 truncate text-muted">{file ? file.name : 'Choose file…'}</span>
        {file && <span className="shrink-0 text-xs text-muted">{(file.size / 1024 / 1024).toFixed(1)} MB</span>}
      </label>
      <input id="resume" type="file" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" onChange={(e) => onChange(e.target.files?.[0] || null)} className="sr-only" aria-invalid={!!error} aria-describedby={error ? 'resume-err' : undefined} />
      {error && <p id="resume-err" className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}
