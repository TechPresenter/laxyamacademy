import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { api, APP_BASE, formatDate, formatDateTime } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { Alert, CategoryPill, CourseThumb, DevLink, EmptyState, ErrorState, Icon, Reveal, Skeleton, useApi, useSeo } from '../js/components.jsx';
import { linkKind, NotificationIcon, NotificationLink, Panel, Pill, scoreTone } from '../js/account/ui.jsx';
import { DailyQuizCard } from '../js/exam/DailyQuizCard.jsx';

// Older pages import these helpers from here; they now live in js/account/ui.jsx.
export { DashTitle, Panel, RowLink, Toggle } from '../js/account/ui.jsx';

// [path, label, icon, end, badge key, short label for the mobile chips]
const LEARNING = [
  ['/dashboard', 'Overview', 'layoutDashboard', true, null, 'Home'],
  ['/dashboard/courses', 'My Courses', 'layers', false, null, 'Courses'],
  ['/dashboard/tests', 'Mock Tests', 'clipboard', false, null, 'Tests'],
  ['/dashboard/doubts', 'My Doubts', 'messages', false, 'unread_doubts', 'Doubts'],
  ['/dashboard/live-classes', 'Live Classes', 'video', false, null, 'Live'],
  ['/dashboard/resources', 'My Resources', 'file', false, null, 'Resources'],
  ['/dashboard/certificates', 'Certificates', 'badge', false, null, 'Certificates'],
  ['/dashboard/wishlist', 'Wishlist', 'heart', false, null, 'Wishlist'],
];
const ACCOUNT = [
  ['/dashboard/notifications', 'Notifications', 'bell', false, 'unread_notifications', 'Alerts'],
  ['/dashboard/purchases', 'Purchases & Invoices', 'receipt', false, null, 'Purchases'],
  ['/dashboard/refer', 'Refer & Earn', 'gift', false, null, 'Refer'],
  ['/dashboard/profile', 'My Profile', 'user', false, null, 'Profile'],
  ['/dashboard/settings', 'Settings', 'settings', false, null, 'Settings'],
  ['/dashboard/support', 'Help & Support', 'help', false, 'open_tickets', 'Support'],
];
const MENU = [...LEARNING, ...ACCOUNT];
/** Refer & Earn is listed only while the academy runs the referral program. */
const shown = (items, summary) => items.filter(([to]) => to !== '/dashboard/refer' || summary?.referral_enabled);

const IST = 'Asia/Kolkata';
const istHour = () => Number(new Intl.DateTimeFormat('en-IN', { hour: 'numeric', hourCycle: 'h23', timeZone: IST }).format(new Date()));

/** Dashboard shell: profile card + side nav (desktop) / scrollable icon chips (mobile) + nested page. */
export default function StudentDashboard() {
  useSeo({ title: 'My Dashboard | Laxyam Academy', robots: 'noindex,nofollow' });
  const { user, logout, summary } = useAuth();
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);

  // Staff logged into /admin share the session; students.php rejects them, so point them to the admin panel.
  if (user.type === 'staff') {
    return (
      <div className="bg-surface">
        <div className="container-x py-16">
          <EmptyState icon="shield" title="This area is for student accounts" text={`You are signed in as ${user.name} (staff). Use the admin panel to manage the academy.`}
            action={<a href={APP_BASE + 'admin/'} className="btn-primary">Open admin panel <Icon name="arrowRight" className="size-4" /></a>} />
        </div>
      </div>
    );
  }

  const signOut = async () => {
    setBusy(true);
    try {
      await logout();
    } finally {
      navigate('/');
    }
  };

  return (
    <div className="relative isolate bg-surface">
      <div aria-hidden="true" className="absolute inset-x-0 top-0 -z-10 h-72 bg-mesh [mask-image:linear-gradient(to_bottom,black,transparent)]" />
      <div className="container-x grid grid-cols-[minmax(0,1fr)] gap-6 py-5 sm:py-6 lg:grid-cols-[272px_minmax(0,1fr)] lg:gap-8 lg:py-8">
        <aside className="min-w-0 lg:sticky lg:top-24 lg:h-fit">
          <ProfileCard user={user} />
          <MobileNav summary={summary} />
          <nav aria-label="Dashboard" className="card mt-4 hidden p-3 lg:block">
            <NavGroup title="Learning" items={LEARNING} summary={summary} />
            <NavGroup title="Account" items={shown(ACCOUNT, summary)} summary={summary} className="mt-3 border-t border-line pt-3" />
            <button type="button" onClick={signOut} disabled={busy} className="mt-3 flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-red-600 transition-colors hover:bg-red-50 disabled:opacity-60">
              <Icon name="logout" className="size-5" /> {busy ? 'Logging out…' : 'Log out'}
            </button>
          </nav>
        </aside>
        <div className="min-w-0">
          <VerifyBanner />
          <Outlet />
        </div>
      </div>
    </div>
  );
}

function ProfileCard({ user }) {
  return (
    <div className="card relative overflow-hidden">
      <div aria-hidden="true" className="h-16 bg-navy-mesh lg:h-20"><div className="dot-grid h-full opacity-40" /></div>
      <div className="flex items-end gap-3 px-4 pb-4 lg:block lg:px-5 lg:pb-5">
        <Link to="/dashboard/profile" className="relative -mt-8 shrink-0 rounded-full ring-4 ring-white lg:-mt-10 lg:inline-block" aria-label="Edit profile and photo">
          {user.avatar_url ? (
            <img src={user.avatar_url} alt="" width="72" height="72" className="size-16 rounded-full object-cover lg:size-[72px]" />
          ) : (
            <span className="grid size-16 place-items-center rounded-full bg-gradient-to-br from-primary to-violet font-display text-2xl font-extrabold text-white lg:size-[72px]">{(user.name || '?')[0].toUpperCase()}</span>
          )}
          <span className="absolute -right-0.5 -bottom-0.5 grid size-6 place-items-center rounded-full border-2 border-white bg-primary text-white"><Icon name="camera" className="size-3" /></span>
        </Link>
        <div className="min-w-0 flex-1 lg:mt-3">
          <p className="truncate font-display text-lg font-bold text-navy">{user.name}</p>
          <p className="truncate text-xs text-muted">{user.email}</p>
          <div className="mt-1.5 flex flex-wrap gap-1.5">
            <Pill tone="blue"><Icon name="graduationCap" className="size-3.5" /> Learner</Pill>
            {user.email_verified ? <Pill tone="green"><Icon name="checkCircle" className="size-3.5" /> Verified</Pill> : <Pill tone="amber">Email not verified</Pill>}
          </div>
        </div>
      </div>
    </div>
  );
}

function NavGroup({ title, items, summary, className = '' }) {
  return (
    <div className={className}>
      <p className="px-3 pb-1.5 text-[11px] font-bold tracking-[0.14em] text-muted uppercase">{title}</p>
      <ul className="space-y-0.5">
        {items.map(([to, label, icon, end, badge]) => (
          <li key={to}>
            <NavLink to={to} end={end}
              className={({ isActive }) => 'group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors ' + (isActive ? 'bg-gradient-to-r from-primary-soft to-primary-soft/40 font-semibold text-primary before:absolute before:inset-y-2 before:left-0 before:w-1 before:rounded-r before:bg-primary' : 'text-ink hover:bg-surface hover:text-primary')}>
              {({ isActive }) => (
                <>
                  <span className={'grid size-8 place-items-center rounded-lg transition-colors ' + (isActive ? 'bg-white text-primary shadow-card' : 'text-slate-500 group-hover:text-primary')}><Icon name={icon} className="size-[18px]" /></span>
                  <span className="flex-1">{label}</span>
                  <Count n={badge ? summary[badge] : 0} />
                </>
              )}
            </NavLink>
          </li>
        ))}
      </ul>
    </div>
  );
}

function Count({ n, className = '' }) {
  if (!n) return null;
  return <span className={'grid h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1.5 text-[11px] font-bold text-white ' + className} aria-label={`${n} new`}>{n > 99 ? '99+' : n}</span>;
}

/** Phones/tablets: a horizontally scrollable row of icon chips (active chip scrolled into view). */
function MobileNav({ summary }) {
  const ref = useRef(null);
  const { pathname } = useLocation();
  useEffect(() => {
    const el = ref.current?.querySelector('[aria-current="page"]');
    if (el && ref.current) {
      const box = ref.current;
      box.scrollTo({ left: el.offsetLeft - box.clientWidth / 2 + el.clientWidth / 2, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }
  }, [pathname]);
  return (
    <nav aria-label="Dashboard" className="relative mt-4 lg:hidden">
      <ul ref={ref} className="scrollbar-none -mx-4 flex snap-x gap-2 overflow-x-auto px-4 pt-2 pb-1 sm:mx-0 sm:px-0">
        {shown(MENU, summary).map(([to, , icon, end, badge, short]) => (
          <li key={to} className="snap-start">
            <NavLink to={to} end={end}
              className={({ isActive }) => 'relative flex w-[76px] flex-col items-center gap-1.5 rounded-2xl border px-2 py-2.5 text-center text-[11px] font-semibold transition-colors ' + (isActive ? 'border-primary bg-primary text-white shadow-glow' : 'border-line bg-white text-ink hover:border-primary hover:text-primary')}>
              <Icon name={icon} className="size-5" />
              <span className="w-full truncate">{short}</span>
              <Count n={badge ? summary[badge] : 0} className="absolute -top-1.5 right-1 ring-2 ring-white" />
            </NavLink>
          </li>
        ))}
      </ul>
      <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 right-[-16px] w-10 bg-gradient-to-l from-surface to-transparent sm:right-0" />
    </nav>
  );
}

function VerifyBanner() {
  const { user } = useAuth();
  const [state, setState] = useState(null);
  if (user.email_verified) return null;
  const resend = async () => {
    setState({ type: 'busy' });
    try {
      setState({ type: 'success', data: await api.post('auth.php', {}, { action: 'resend-verification' }) });
    } catch (e) {
      setState({ type: 'error', message: e.message });
    }
  };
  return (
    <Alert type="warning" className="mb-5">
      {/* Message first, the action/status beside it (wrapping below on narrow screens), then the dev-only link on its own row */}
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <Icon name="mail" className="size-5 shrink-0" />
        <span className="min-w-[14rem] flex-1">Please verify your email address (<span className="break-all">{user.email}</span>) to secure your account.</span>
        {(!state || state.type === 'busy') && <button type="button" onClick={resend} disabled={state?.type === 'busy'} className="btn-sm btn shrink-0 bg-amber-500 text-white hover:bg-amber-600">{state?.type === 'busy' ? 'Sending…' : 'Resend link'}</button>}
        {state?.type === 'success' && <span className="inline-flex shrink-0 items-center gap-1.5 font-semibold text-green-700"><Icon name="checkCircle" className="size-4" />Verification email sent.</span>}
        {state?.type === 'error' && <span className="shrink-0 font-semibold text-red-700">{state.message}</span>}
      </div>
      <DevLink data={state?.data} />
    </Alert>
  );
}

// ------------------------------------------------------------------
// /dashboard index
// ------------------------------------------------------------------
export function DashboardOverview() {
  const { data, loading, error, reload } = useApi('students.php', { action: 'dashboard' });
  const all = useApi('students.php', { action: 'courses' }); // full list for the average progress KPI
  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading && !data) return <OverviewSkeleton />;

  const s = data.stats;
  const active = (all.data || data.courses).filter((c) => c.enrolment.status === 'active');
  const avg = active.length ? Math.round(active.reduce((t, c) => t + Number(c.enrolment.progress || 0), 0) / active.length) : 0;
  const first = (data.user.name || '').split(' ')[0];
  const h = istHour();
  const greet = h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
  const today = new Intl.DateTimeFormat('en-IN', { weekday: 'long', day: 'numeric', month: 'long', timeZone: IST }).format(new Date());
  const continueCourses = [...data.courses].sort((a, b) => (a.enrolment.status === 'expired') - (b.enrolment.status === 'expired'));

  return (
    <div className="space-y-6">
      {/* Welcome hero */}
      <section className="relative isolate overflow-hidden rounded-3xl bg-navy-mesh p-6 text-white shadow-card-hover sm:p-8">
        <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40 [mask-image:linear-gradient(to_left,black,transparent_70%)]" />
        <div aria-hidden="true" className="absolute -top-20 -right-16 -z-10 size-64 rounded-full bg-violet/40 blur-3xl" />
        <div className="flex flex-wrap items-end justify-between gap-6">
          <div className="min-w-0 max-w-xl">
            <p className="flex items-center gap-2 text-xs font-semibold tracking-wide text-white/70 uppercase"><Icon name="calendar" className="size-4" /> {today}</p>
            <h1 className="mt-2 font-display text-2xl leading-tight font-extrabold sm:text-[32px]">{greet}, <span className="text-gradient-gold">{first}</span></h1>
            <p className="mt-2 text-sm text-white/75 sm:text-base">
              {s.courses ? `You have ${s.courses} active course${s.courses > 1 ? 's' : ''}. Pick up where you left off and keep your streak going.` : 'Start your preparation: explore courses or take a free mock test.'}
            </p>
            <div className="mt-5 flex flex-wrap gap-3">
              {continueCourses[0] && continueCourses[0].enrolment.status === 'active'
                ? <Link to={learnUrl(continueCourses[0])} className="btn-gold"><Icon name="playCircle" className="size-4" /> Resume learning</Link>
                : <Link to="/courses" className="btn-gold"><Icon name="search" className="size-4" /> Explore courses</Link>}
              <Link to="/mock-tests" className="btn-ghost-light"><Icon name="clipboard" className="size-4" /> Mock tests</Link>
            </div>
          </div>
          {active.length > 0 && <ProgressRing value={avg} label="Average progress" />}
        </div>
      </section>

      {/* KPIs */}
      <ul className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <Kpi to="/dashboard/courses" icon="layers" tone="from-primary to-[#3b82f6]" value={s.courses} label="Active courses" note={s.completed ? `${s.completed} completed` : null} />
        <Kpi to="/dashboard/courses" icon="trendingUp" tone="from-teal to-[#14b8a6]" value={active.length ? `${avg}%` : '-'} label="Avg. progress" bar={active.length ? avg : null} />
        <Kpi to="/dashboard/tests" icon="clipboard" tone="from-violet to-[#8b5cf6]" value={s.tests_taken} label="Tests taken" />
        <Kpi to="/dashboard/certificates" icon="badge" tone="from-gold-dark to-coral" value={data.certificates.length} label="Certificates" />
      </ul>

      <DailyQuizCard />

      {/* Continue learning */}
      <Reveal className="min-w-0">
        <Panel title="Continue learning" icon="playCircle" link={['/dashboard/courses', 'All courses']}>
          {continueCourses.length ? (
            <ul className="grid gap-4 sm:grid-cols-2">
              {continueCourses.map((c) => <EnrolledCourse key={c.id} course={c} />)}
            </ul>
          ) : (
            <EmptyState icon="book" title="You have not enrolled in a course yet" text="Browse expert-led courses for UPSC, SSC, Banking and more." className="shadow-none" action={<Link to="/courses" className="btn-primary">Browse courses</Link>} />
          )}
        </Panel>
      </Reveal>

      {/* Grid children get min-w-0 / minmax(0,…) tracks so long, non-wrapping (truncated) rows
          can never widen the page beyond a phone screen. */}
      <div className="grid grid-cols-[minmax(0,1fr)] gap-6 xl:grid-cols-2">
        <Reveal className="min-w-0">
          <Panel title="Upcoming live classes" icon="video" link={['/dashboard/live-classes', 'View all']} className="h-full">
            {data.live_classes.length ? (
              <ul className="space-y-3">
                {data.live_classes.map((lc) => <LiveRow key={lc.id} lc={lc} />)}
              </ul>
            ) : <Empty icon="video" text="No upcoming classes right now." action={<Link to="/live-classes" className="font-semibold text-primary hover:underline">Browse live classes</Link>} />}
          </Panel>
        </Reveal>

        <Reveal delay={80} className="min-w-0">
          <Panel title="Recent test results" icon="trophy" link={['/dashboard/tests', 'View all']} className="h-full">
            {data.recent_results.length ? (
              <ul className="space-y-3">
                {data.recent_results.map((r) => <ResultRow key={r.attempt_id} r={r} />)}
              </ul>
            ) : <Empty icon="clipboard" text="No tests attempted yet." action={<Link to="/mock-tests" className="font-semibold text-primary hover:underline">Try a mock test</Link>} />}
          </Panel>
        </Reveal>
      </div>

      <div className="grid grid-cols-[minmax(0,1fr)] gap-6 xl:grid-cols-[minmax(0,1.25fr)_minmax(0,1fr)]">
        <Reveal className="min-w-0">
          <Panel title="Notifications" icon="bell" link={['/dashboard/notifications', 'View all']} className="h-full">
            {data.notifications.length ? (
              <ul className="-mx-2 space-y-1">
                {data.notifications.map((n) => <NotificationRow key={n.id} n={n} />)}
              </ul>
            ) : <Empty icon="inbox" text="You are all caught up." />}
          </Panel>
        </Reveal>
        <div className="min-w-0 space-y-6">
          <Reveal delay={80} className="min-w-0">
            <Panel title="Certificates" icon="badge" link={data.certificates.length ? ['/dashboard/certificates', 'View all'] : null}>
              {data.certificates.length ? (
                <ul className="divide-y divide-line">
                  {data.certificates.slice(0, 3).map((c) => (
                    <li key={c.certificate_no}>
                      <Link to="/dashboard/certificates" className="flex items-center gap-3 py-2.5 text-sm hover:text-primary">
                        <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-600"><Icon name="badge" className="size-[18px]" /></span>
                        <span className="min-w-0 flex-1"><span className="block truncate font-semibold">{c.title}</span><span className="text-xs text-muted">{c.certificate_no} · {formatDate(c.issued_at)}</span></span>
                      </Link>
                    </li>
                  ))}
                </ul>
              ) : s.completed > 0
                ? <Empty icon="clock" text="Course completed! Your certificate will appear here once the academy issues it." action={<Link to="/dashboard/certificates" className="font-semibold text-primary hover:underline">View status</Link>} />
                : <Empty icon="badge" text="Complete a course to earn your certificate." />}
            </Panel>
          </Reveal>
          <Reveal delay={120} className="min-w-0">
            <Panel title="Quick links" icon="grid">
              <ul className="grid grid-cols-3 gap-2">
                {[['/dashboard/purchases', 'receipt', 'Invoices'], ['/dashboard/resources', 'file', 'Resources'], ['/dashboard/wishlist', 'heart', 'Wishlist'],
                  ['/dashboard/profile', 'user', 'Profile'], ['/dashboard/settings', 'settings', 'Settings'], ['/dashboard/support', 'headset', 'Support']].map(([to, ic, l]) => (
                  <li key={to}>
                    <Link to={to} className="flex flex-col items-center gap-1.5 rounded-xl border border-line px-2 py-3 text-xs font-semibold text-ink transition-all hover:-translate-y-0.5 hover:border-primary hover:text-primary hover:shadow-card">
                      <Icon name={ic} className="size-5 text-primary" /> {l}
                    </Link>
                  </li>
                ))}
              </ul>
            </Panel>
          </Reveal>
        </div>
      </div>
    </div>
  );
}

const learnUrl = (course) => `/learn/${course.slug}${course.enrolment.next_lesson_id ? `/${course.enrolment.next_lesson_id}` : ''}`;

function Kpi({ to, icon, tone, value, label, note, bar }) {
  return (
    <li className="min-w-0">
      <Link to={to} className="card card-hover flex h-full flex-col gap-3 p-4 sm:p-5">
        <span className="flex items-center justify-between">
          <span className={'grid size-10 place-items-center rounded-xl bg-gradient-to-br text-white shadow-card ' + tone}><Icon name={icon} className="size-5" /></span>
          <Icon name="arrowRight" className="size-4 text-muted" />
        </span>
        <span>
          <span className="block font-display text-2xl font-extrabold text-navy sm:text-[28px]">{value}</span>
          <span className="block text-xs font-medium text-muted sm:text-sm">{label}</span>
        </span>
        {bar != null && <span className="block h-1.5 overflow-hidden rounded-full bg-surface"><span className="block h-full rounded-full bg-teal" style={{ width: `${bar}%` }} /></span>}
        {note && <span className="text-xs font-semibold text-green-700">{note}</span>}
      </Link>
    </li>
  );
}

function ProgressRing({ value, label }) {
  const r = 34;
  const c = 2 * Math.PI * r;
  return (
    <div className="glass flex items-center gap-4 rounded-2xl p-4" role="img" aria-label={`${label}: ${value}%`}>
      <svg viewBox="0 0 80 80" className="size-20 -rotate-90" aria-hidden="true">
        <circle cx="40" cy="40" r={r} fill="none" stroke="rgb(255 255 255 / 0.15)" strokeWidth="8" />
        <circle cx="40" cy="40" r={r} fill="none" stroke="#FFC928" strokeWidth="8" strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c - (c * value) / 100} className="transition-all duration-700" />
      </svg>
      <div>
        <p className="font-display text-2xl font-extrabold">{value}%</p>
        <p className="text-xs text-white/70">{label}</p>
      </div>
    </div>
  );
}

function Empty({ icon, text, action }) {
  return (
    <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-line bg-surface/60 px-4 py-8 text-center text-sm text-muted">
      <Icon name={icon} className="size-6 text-primary/60" />
      <p>{text}</p>
      {action}
    </div>
  );
}

const dayFmt = new Intl.DateTimeFormat('en-IN', { day: '2-digit', timeZone: IST });
const monFmt = new Intl.DateTimeFormat('en-IN', { month: 'short', timeZone: IST });

function LiveRow({ lc }) {
  const d = new Date(lc.starts_at);
  const live = lc.state === 'live';
  return (
    <li>
      <Link to="/dashboard/live-classes" className="flex items-center gap-3 rounded-xl border border-line p-3 transition-colors hover:border-primary">
        <span className={'grid w-12 shrink-0 place-items-center rounded-xl py-1.5 text-center leading-tight ' + (live ? 'bg-red-600 text-white' : 'bg-primary-soft text-primary')}>
          <span className="font-display text-lg font-extrabold">{dayFmt.format(d)}</span>
          <span className="text-[10px] font-bold uppercase">{monFmt.format(d)}</span>
        </span>
        <span className="min-w-0 flex-1">
          <span className="block truncate font-semibold text-ink">{lc.title}</span>
          <span className="block truncate text-xs text-muted">{formatDateTime(lc.starts_at)}{lc.instructor && ` · ${lc.instructor}`}</span>
        </span>
        {live ? <Pill tone="red" className="animate-pulse motion-reduce:animate-none">LIVE</Pill> : <CategoryPill category={lc.category} />}
      </Link>
    </li>
  );
}

function ResultRow({ r }) {
  // Unreleased exam results come back with results_visible=false and null score/counts.
  const visible = r.results_visible !== false && r.score != null;
  const { pct, tone } = visible ? scoreTone(r.score, r.total_marks) : { pct: null, tone: 'slate' };
  const bar = { green: 'bg-green-500', amber: 'bg-amber-400', red: 'bg-red-500', slate: 'bg-line' }[tone];
  const score = visible ? `${+Number(r.score).toFixed(2)} / ${+Number(r.total_marks).toFixed(2)}` : null;
  return (
    <li>
      <Link to={`/mock-tests/${r.slug}/result/${r.attempt_id}`} className="block rounded-xl border border-line p-3 transition-colors hover:border-primary">
        <span className="flex items-center gap-3">
          <span className="min-w-0 flex-1">
            <span className="block truncate font-semibold text-ink">{r.title}</span>
            <span className="text-xs text-muted">{formatDate(r.submitted_at)}{r.mode === 'practice' ? ' · Practice' : ''}</span>
          </span>
          {visible ? <Pill tone={tone}>{score}</Pill> : <Pill tone="slate"><Icon name="clock" className="size-3.5" /> Result pending</Pill>}
        </span>
        {!visible && <span className="mt-1.5 block text-xs text-muted">Your score appears here as soon as the academy releases the result.</span>}
        {pct !== null && (
          <span className="mt-2 flex items-center gap-2">
            <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface"><span className={'block h-full rounded-full ' + bar} style={{ width: `${pct}%` }} /></span>
            <span className="w-10 text-right text-xs font-semibold text-ink">{Math.round(pct)}%</span>
          </span>
        )}
      </Link>
    </li>
  );
}

function NotificationRow({ n }) {
  const { refreshSummary } = useAuth();
  // Opening an unread notification marks it read (fire-and-forget; the list page shows the result).
  const open = () => {
    if (!n.read) api.post('students.php', { id: n.id }, { action: 'notifications-read' }).then(refreshSummary, () => {});
  };
  const body = (
    <>
      <NotificationIcon type={n.type} className="size-9" />
      <span className="min-w-0 flex-1">
        <span className={'block truncate text-sm ' + (n.read ? 'font-medium text-ink' : 'font-bold text-navy')}>{n.title}</span>
        <span className="block text-xs text-muted">{formatDateTime(n.created_at)}</span>
      </span>
      {!n.read && <span className="size-2 shrink-0 rounded-full bg-primary" aria-label="Unread" />}
    </>
  );
  const cls = 'flex items-center gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-surface';
  return (
    <li>
      <NotificationLink link={n.link} className={cls} onOpen={open}>{body}</NotificationLink>
      {!linkKind(n.link) && <Link to="/dashboard/notifications" className={cls}>{body}</Link>}
    </li>
  );
}

function OverviewSkeleton() {
  return (
    <div className="space-y-6" aria-busy="true">
      <span className="sr-only" role="status">Loading your dashboard…</span>
      <Skeleton className="h-48" rounded="rounded-3xl" />
      <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-32" rounded="rounded-2xl" />)}</div>
      <Skeleton className="h-72" rounded="rounded-2xl" />
      <div className="grid gap-6 xl:grid-cols-2"><Skeleton className="h-56" rounded="rounded-2xl" /><Skeleton className="h-56" rounded="rounded-2xl" /></div>
    </div>
  );
}

/** Enrolled course card (also used by pages/MyCourses.jsx). Renders an <li>. */
export function EnrolledCourse({ course }) {
  const e = course.enrolment;
  const expired = e.status === 'expired';
  const done = Number(e.progress) >= 100;
  return (
    <li className="group flex min-w-0 flex-col overflow-hidden rounded-2xl border border-line bg-white transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-card-hover">
      <div className="relative">
        <CourseThumb course={course} showBadge={false} className="aspect-[16/7]" />
        {expired && <span className="absolute top-3 left-3"><Pill tone="red">Access expired</Pill></span>}
        {done && !expired && <span className="absolute top-3 left-3"><Pill tone="green"><Icon name="checkCircle" className="size-3.5" /> Completed</Pill></span>}
      </div>
      <div className="flex flex-1 flex-col p-4">
        <p className="line-clamp-2 font-semibold text-navy">{course.title}</p>
        <div className="mt-3 flex items-center gap-3">
          <div className="h-2 flex-1 overflow-hidden rounded-full bg-surface" role="progressbar" aria-valuenow={Math.round(e.progress)} aria-valuemin={0} aria-valuemax={100} aria-label="Course progress">
            <div className={'h-full rounded-full ' + (done ? 'bg-green-500' : 'bg-gradient-to-r from-primary to-violet')} style={{ width: `${Math.min(100, e.progress)}%` }} />
          </div>
          <span className="text-xs font-bold text-ink">{Math.round(e.progress)}%</span>
        </div>
        <p className="mt-2 text-xs text-muted">
          {expired ? 'Renew to continue learning' : e.expires_at ? `Access until ${formatDate(e.expires_at)}` : 'Lifetime access'}
        </p>
        <div className="mt-auto pt-3">
          {expired ? (
            <Link to={`/courses/${course.slug}`} className="btn-outline w-full py-2">Renew access</Link>
          ) : (
            <Link to={learnUrl(course)} className="btn-primary w-full py-2">{done ? 'Review course' : e.progress > 0 ? 'Continue' : 'Start learning'} <Icon name="arrowRight" className="size-4" /></Link>
          )}
        </div>
      </div>
    </li>
  );
}
