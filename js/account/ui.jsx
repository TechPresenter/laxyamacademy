// Shared building blocks for the student dashboard pages (account-fe track).
// StudentDashboard.jsx re-exports DashTitle / Panel / RowLink / Toggle so older imports keep working.
import { Link } from 'react-router-dom';
import { Icon } from '../components.jsx';

/** Dashboard page heading: eyebrow icon, title, subtitle, breadcrumb and optional actions. */
export function DashTitle({ title, subtitle, action, icon }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div className="min-w-0">
        <nav aria-label="Breadcrumb" className="mb-2 text-xs font-medium text-muted">
          <ol className="flex flex-wrap items-center gap-1">
            <li><Link to="/dashboard" className="hover:text-primary">Dashboard</Link></li>
            <li aria-hidden="true"><Icon name="chevronRight" className="size-3" /></li>
            <li aria-current="page" className="text-ink">{title}</li>
          </ol>
        </nav>
        <h1 className="flex items-center gap-3 font-display text-2xl font-extrabold tracking-tight text-navy sm:text-[28px]">
          {icon && <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-primary to-violet text-white shadow-glow"><Icon name={icon} className="size-5" /></span>}
          <span className="min-w-0">{title}</span>
        </h1>
        {subtitle && <p className="mt-1.5 max-w-2xl text-sm text-muted sm:text-[15px]">{subtitle}</p>}
      </div>
      {action && <div className="flex flex-wrap items-center gap-3">{action}</div>}
    </div>
  );
}

/** Card with an icon heading, optional subtitle and header action / link. */
export function Panel({ title, icon, subtitle, link, action, children, className = '', id }) {
  return (
    <section id={id} className={'card min-w-0 p-5 sm:p-6 ' + className}>
      {(title || action || link) && (
        <div className="mb-4 flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-start gap-3">
            {icon && <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary ring-1 ring-primary/10"><Icon name={icon} className="size-5" /></span>}
            <div className="min-w-0">
              <h2 className="font-display text-lg font-bold text-navy">{title}</h2>
              {subtitle && <p className="text-xs text-muted sm:text-sm">{subtitle}</p>}
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            {action}
            {link && (
              <Link to={link[0]} className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline">
                {link[1]} <Icon name="arrowRight" className="size-3.5" />
              </Link>
            )}
          </div>
        </div>
      )}
      {children}
    </section>
  );
}

/** List row link used in "quick links" style cards. */
export function RowLink({ to, href, icon, label, value, danger, onClick, external }) {
  const cls = 'group flex w-full items-center gap-3 border-b border-line py-3 text-left text-sm last:border-0 transition-colors ' + (danger ? 'text-red-600 hover:text-red-700' : 'text-ink hover:text-primary');
  const inner = (
    <>
      <span className={'grid size-8 shrink-0 place-items-center rounded-lg ' + (danger ? 'bg-red-50 text-red-500' : 'bg-primary-soft text-primary')}><Icon name={icon} className="size-4" /></span>
      <span className="min-w-0 flex-1 font-medium">{label}</span>
      {value != null && value !== false && <span className="rounded-full bg-surface px-2 py-0.5 text-xs font-semibold text-muted">{value}</span>}
      <Icon name={external ? 'externalLink' : 'chevronRight'} className="size-4 text-muted transition-transform group-hover:translate-x-0.5" />
    </>
  );
  if (onClick) return <button type="button" onClick={onClick} className={cls}>{inner}</button>;
  if (href) return <a href={href} className={cls} {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>{inner}</a>;
  return <Link to={to} className={cls}>{inner}</Link>;
}

/** Accessible switch. */
export function Toggle({ checked, onChange, label, disabled }) {
  return (
    <button type="button" role="switch" aria-checked={checked} aria-label={label} disabled={disabled} onClick={() => onChange(!checked)}
      className={'relative h-6 w-11 shrink-0 rounded-full transition-colors focus-visible:ring-4 focus-visible:ring-primary/20 focus-visible:outline-none disabled:opacity-50 ' + (checked ? 'bg-primary' : 'bg-slate-300')}>
      <span className={'absolute top-0.5 size-5 rounded-full bg-white shadow transition-all motion-reduce:transition-none ' + (checked ? 'left-[22px]' : 'left-0.5')} />
    </button>
  );
}

/** Small status pill. tone: green | amber | red | blue | violet | slate */
const TONE = {
  green: 'bg-green-50 text-green-700 ring-green-600/15',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/20',
  red: 'bg-red-50 text-red-700 ring-red-600/15',
  blue: 'bg-primary-soft text-primary ring-primary/15',
  violet: 'bg-violet/10 text-violet ring-violet/20',
  slate: 'bg-surface text-muted ring-line',
};
export function Pill({ tone = 'slate', children, className = '' }) {
  return <span className={'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ' + (TONE[tone] || TONE.slate) + ' ' + className}>{children}</span>;
}

/** Score → tone + percentage. */
export function scoreTone(score, total) {
  if (score == null || !total) return { pct: null, tone: 'slate' };
  const pct = Math.max(0, Math.min(100, (Number(score) / Number(total)) * 100));
  return { pct, tone: pct >= 60 ? 'green' : pct >= 40 ? 'amber' : 'red' };
}

// ------------------------------------------------------------------
// Notifications: real types written by notify() / send_template() (template key = type).
// `group` mirrors api/students.php notification_groups(); everything not listed there is "other".
// ------------------------------------------------------------------
export const NOTIFICATION_TYPES = {
  // payments
  payment_success: { icon: 'receipt', tone: 'green', group: 'payments' },
  payment_failed: { icon: 'creditCard', tone: 'red', group: 'payments' },
  refund_processed: { icon: 'refresh', tone: 'green', group: 'payments' },
  // learning
  enrolment: { icon: 'book', tone: 'blue', group: 'learning' },
  course_completed: { icon: 'trophy', tone: 'amber', group: 'learning' },
  certificate_issued: { icon: 'badge', tone: 'amber', group: 'learning' },
  class_reminder: { icon: 'video', tone: 'red', group: 'learning' },
  // tests
  test_result: { icon: 'clipboard', tone: 'violet', group: 'tests' },
  // account
  welcome: { icon: 'sparkles', tone: 'blue', group: 'account' },
  password_changed: { icon: 'lock', tone: 'slate', group: 'account' },
  // other (live-class reminder / reschedule / cancellation, admin announcements, documents, support, careers)
  reminder: { icon: 'video', tone: 'red', group: 'other' },
  announcement: { icon: 'megaphone', tone: 'violet', group: 'other' },
  document: { icon: 'file', tone: 'slate', group: 'other' },
  ticket_reply: { icon: 'messages', tone: 'blue', group: 'other' },
  enquiry_autoreply: { icon: 'messages', tone: 'slate', group: 'other' },
  application_ack: { icon: 'briefcase', tone: 'blue', group: 'other' },
  application_shortlisted: { icon: 'briefcase', tone: 'green', group: 'other' },
  application_hired: { icon: 'briefcase', tone: 'green', group: 'other' },
  application_rejected: { icon: 'briefcase', tone: 'slate', group: 'other' },
  interview_scheduled: { icon: 'calendar', tone: 'violet', group: 'other' },
};

/** Inbox filters, in display order. Keys are the server-side `type` filter values (api/students.php). */
export const NOTIFICATION_GROUPS = [
  ['payments', 'Payments', 'receipt'],
  ['learning', 'Learning', 'book'],
  ['tests', 'Tests', 'clipboard'],
  ['account', 'Account', 'user'],
  ['other', 'Other', 'megaphone'],
];
const ICON_TONE = {
  green: 'bg-green-50 text-green-600 ring-green-600/15',
  amber: 'bg-amber-50 text-amber-600 ring-amber-500/20',
  red: 'bg-red-50 text-red-600 ring-red-600/15',
  blue: 'bg-primary-soft text-primary ring-primary/15',
  violet: 'bg-violet/10 text-violet ring-violet/20',
  slate: 'bg-surface text-slate-600 ring-line',
};
export const notificationMeta = (type) => NOTIFICATION_TYPES[type] || { icon: 'bell', tone: 'slate', group: 'other' };

export function NotificationIcon({ type, className = 'size-10' }) {
  const m = notificationMeta(type);
  return <span className={'grid shrink-0 place-items-center rounded-xl ring-1 ring-inset ' + ICON_TONE[m.tone] + ' ' + className}><Icon name={m.icon} className="size-5" /></span>;
}

/** 'internal' for app paths, 'external' for https URLs, null for anything else (not rendered as a link). */
export const linkKind = (link) => (!link ? null : link.startsWith('/') && !link.startsWith('//') ? 'internal' : /^https:\/\//i.test(link) ? 'external' : null);

/** Internal links use the router, https links open in a new tab, anything else renders nothing. */
export function NotificationLink({ link, children, className, onOpen }) {
  const kind = linkKind(link);
  if (kind === 'internal') {
    return <Link to={link} onClick={onOpen} className={className}>{children}</Link>;
  }
  if (kind === 'external') {
    return <a href={link} target="_blank" rel="noopener noreferrer" onClick={onOpen} className={className}>{children}</a>;
  }
  return null;
}
