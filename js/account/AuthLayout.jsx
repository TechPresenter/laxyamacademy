// Split-screen layout for login, register and password pages (account-fe track):
// a navy gradient-mesh brand panel with the real product benefits + the form card.
import { useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon, Logo, useSite } from '../components.jsx';

const BENEFITS = [
  ['video', 'Live & recorded classes', 'Join live sessions and revisit recordings any time.'],
  ['clipboard', 'Timed mock tests', 'Exam-like tests with scores, rank and topic analysis.'],
  ['book', 'Notes, PDFs & resources', 'Study material from your courses in one place.'],
  ['bell', 'Results & alerts', 'Test results, class reminders and announcements.'],
  ['badge', 'Course certificates', 'Earn a certificate when you complete a course.'],
];
const EXAMS = ['UPSC', 'SSC', 'Banking', 'State PSC', 'Railway', 'Defence', 'Teaching', 'Police'];

export default function AuthLayout({ title, subtitle, icon = 'user', children, footer, aside }) {
  const { data: site } = useSite();
  const cats = (site?.categories || []).filter((c) => !c.parent_id).slice(0, 8);
  return (
    <section className="relative isolate overflow-hidden bg-mesh">
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:radial-gradient(circle_at_70%_40%,black,transparent_70%)]" />
      <div className="container-x py-8 sm:py-12 lg:py-16">
        <div className="mx-auto grid max-w-6xl overflow-hidden rounded-[28px] border border-line bg-white shadow-card-hover lg:grid-cols-[1.05fr_1fr]">
          {/* Brand panel */}
          <aside className="relative isolate hidden overflow-hidden bg-navy-mesh p-10 text-white lg:flex lg:flex-col xl:p-12">
            <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40 [mask-image:linear-gradient(to_bottom,black,transparent)]" />
            <div aria-hidden="true" className="absolute -right-24 -bottom-24 -z-10 size-80 rounded-full bg-primary/30 blur-3xl motion-safe:animate-float" />
            <Link to="/" className="self-start" aria-label="Laxyam Academy home"><Logo variant="light" size="md" /></Link>
            <h2 className="mt-10 font-display text-3xl leading-tight font-extrabold xl:text-[2.4rem]">
              Learn. Prepare. <span className="text-gradient-gold">Achieve.</span>
            </h2>
            <p className="mt-3 max-w-md text-white/75">One account for your courses, live classes, mock tests and study material.</p>
            <ul className="mt-8 space-y-4">
              {BENEFITS.map(([ic, t, d], i) => (
                <li key={t} className="flex animate-fade-up items-start gap-3.5" style={{ animationDelay: `${i * 70}ms` }}>
                  <span className="glass grid size-10 shrink-0 place-items-center rounded-xl text-gold"><Icon name={ic} className="size-5" /></span>
                  <span>
                    <span className="block font-semibold">{t}</span>
                    <span className="block text-sm text-white/65">{d}</span>
                  </span>
                </li>
              ))}
            </ul>
            <div className="mt-auto pt-10">
              <p className="text-xs font-bold tracking-[0.16em] text-white/50 uppercase">Prepare for</p>
              <ul className="mt-3 flex flex-wrap gap-2">
                {(cats.length ? cats.map((c) => c.name) : EXAMS).map((n) => (
                  <li key={n} className="rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-white/85">{n}</li>
                ))}
              </ul>
            </div>
            {aside}
          </aside>

          {/* Form card */}
          <div className="min-w-0 p-6 sm:p-10 xl:p-12">
            <div className="mb-6 flex items-center gap-3 lg:hidden">
              <Link to="/" aria-label="Laxyam Academy home"><Logo size="sm" /></Link>
            </div>
            <span className="grid size-12 place-items-center rounded-2xl bg-gradient-to-br from-primary to-violet text-white shadow-glow"><Icon name={icon} className="size-6" /></span>
            <h1 className="mt-5 font-display text-2xl font-extrabold tracking-tight text-navy sm:text-3xl">{title}</h1>
            {subtitle && <p className="mt-1.5 text-sm text-muted sm:text-[15px]">{subtitle}</p>}
            <div className="mt-7">{children}</div>
            {footer && <div className="mt-8 border-t border-line pt-6 text-center text-sm text-muted">{footer}</div>}
          </div>
        </div>
      </div>
    </section>
  );
}

/** Password input with label, show/hide toggle, hint and server error (aria-invalid + aria-describedby). */
export function PasswordField({ id, label, value, onChange, error, hint, autoComplete = 'current-password', required, describedBy, onFocus }) {
  const [show, setShow] = useState(false);
  const ids = [error && `${id}-err`, hint && !error && `${id}-hint`, describedBy].filter(Boolean).join(' ') || undefined;
  return (
    <div className="min-w-0">
      <label htmlFor={id} className="label">{label}</label>
      <div className="relative">
        <Icon name="lock" className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" />
        <input id={id} name={id} type={show ? 'text' : 'password'} autoComplete={autoComplete} required={required} value={value} onChange={onChange} onFocus={onFocus}
          aria-invalid={!!error} aria-describedby={ids} className="input pr-12 pl-10" />
        <button type="button" onClick={() => setShow((s) => !s)} aria-label={show ? 'Hide password' : 'Show password'} aria-pressed={show} aria-controls={id}
          className="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-xl text-muted hover:text-primary focus-visible:text-primary focus-visible:outline-none">
          {show ? <EyeOff /> : <Icon name="eye" className="size-[18px]" />}
        </button>
      </div>
      {hint && !error && <p id={`${id}-hint`} className="mt-1 text-xs text-muted">{hint}</p>}
      {error && <p id={`${id}-err`} className="mt-1 text-xs font-medium text-red-600">{error}</p>}
    </div>
  );
}

/** Inline spinner for busy buttons. */
export function Busy() {
  return <span aria-hidden="true" className="size-4 animate-spin rounded-full border-2 border-current/30 border-t-current motion-reduce:animate-none" />;
}

// Not in the shared icon set (components.jsx belongs to shell-fe), so drawn here.
function EyeOff() {
  return (
    <svg viewBox="0 0 24 24" className="size-[18px]" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61M9.88 9.88a3 3 0 1 0 4.24 4.24M2 2l20 20" />
    </svg>
  );
}

/** Text input with a leading icon, label and server error. */
export function IconField({ id, label, icon, error, hint, className = '', ...props }) {
  const ids = [error && `${id}-err`, hint && !error && `${id}-hint`].filter(Boolean).join(' ') || undefined;
  return (
    <div className={'min-w-0 ' + className}>
      <label htmlFor={id} className="label">{label}</label>
      <div className="relative">
        <Icon name={icon} className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" />
        <input id={id} name={id} aria-invalid={!!error} aria-describedby={ids} className="input pl-10" {...props} />
      </div>
      {hint && !error && <p id={`${id}-hint`} className="mt-1 text-xs text-muted">{hint}</p>}
      {error && <p id={`${id}-err`} className="mt-1 text-xs font-medium text-red-600">{error}</p>}
    </div>
  );
}

// Mirrors password_policy_error() in includes/auth-check.php (8-128 chars, a letter and a number).
export const PASSWORD_RULES = [
  ['len', 'At least 8 characters', (p) => p.length >= 8 && p.length <= 128],
  ['letter', 'A letter (a-z)', (p) => /[A-Za-z]/.test(p)],
  ['number', 'A number (0-9)', (p) => /\d/.test(p)],
  ['mixed', 'Upper & lower case (stronger)', (p) => /[a-z]/.test(p) && /[A-Z]/.test(p), true],
  ['symbol', 'A symbol or 12+ characters (stronger)', (p) => /[^A-Za-z0-9]/.test(p) || p.length >= 12, true],
];
export const passwordMeetsPolicy = (p) => PASSWORD_RULES.filter((r) => !r[3]).every((r) => r[2](p));

const LEVELS = [
  ['Too weak', 'bg-red-500', 'text-red-600'],
  ['Weak', 'bg-orange-500', 'text-orange-600'],
  ['Fair', 'bg-amber-400', 'text-amber-700'],
  ['Good', 'bg-teal', 'text-teal'],
  ['Strong', 'bg-green-600', 'text-green-700'],
];

/** Strength meter + requirement checklist. Required rules must pass; the optional ones add strength. */
export function PasswordStrength({ password, id }) {
  const autoId = useId();
  const listId = id || autoId;
  const passed = PASSWORD_RULES.filter((r) => r[2](password)).length;
  const policy = passwordMeetsPolicy(password);
  const level = !password ? -1 : !policy ? Math.min(passed, 1) : Math.min(4, passed - 1);
  const [label, bar, text] = LEVELS[Math.max(0, level)];
  return (
    <div className="mt-2" id={listId}>
      <div className="flex items-center gap-3">
        <div className="grid flex-1 grid-cols-4 gap-1.5" aria-hidden="true">
          {[0, 1, 2, 3].map((i) => (
            <span key={i} className={'h-1.5 rounded-full transition-colors ' + (level >= i + 1 || (level === 0 && i === 0 && password) ? bar : 'bg-line')} />
          ))}
        </div>
        <span className={'w-16 text-right text-xs font-semibold ' + (password ? text : 'text-muted')} aria-live="polite">{password ? label : ''}</span>
      </div>
      <ul className="mt-2 grid gap-1 text-xs sm:grid-cols-2">
        {PASSWORD_RULES.map(([k, l, test]) => {
          const ok = test(password);
          return (
            <li key={k} className={'flex items-center gap-1.5 ' + (ok ? 'text-green-700' : 'text-muted')}>
              {ok ? <Icon name="checkCircle" className="size-3.5 shrink-0" /> : <span aria-hidden="true" className="size-3.5 shrink-0 rounded-full border-2 border-current opacity-60" />}
              <span>{l}<span className="sr-only">{ok ? ' - done' : ' - not yet'}</span></span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
