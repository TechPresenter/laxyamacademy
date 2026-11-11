import { useState } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { api, formatDateTime } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { CategoryPill, DemoBadge, EmptyState, ErrorState, Icon, PageHeader, Pagination, Reveal, Skeleton, useApi, useCmsSeo } from '../js/components.jsx';
import { countdownText, useNow } from '../js/content/shared.jsx';

// [key, label, icon, short label for phones (keeps all three tabs visible at 360px)]
const TABS = [['live', 'Live now', 'video'], ['upcoming', 'Upcoming', 'calendar'], ['past', 'Past classes', 'history', 'Past']];

/** Minutes before the start when the join link opens (server setting live_join_window_minutes). */
export function joinWindowMinutes(data, sample) {
  if (Number.isFinite(Number(data?.join_window_minutes))) return Number(data.join_window_minutes);
  if (sample?.starts_at && sample?.join_opens_at) return Math.max(0, Math.round((new Date(sample.starts_at) - new Date(sample.join_opens_at)) / 60000));
  return null;
}

/** 15 -> "15 minutes", 60 -> "1 hour", 90 -> "1 h 30 min". */
export function minutesText(m) {
  if (m >= 60 && m % 60 === 0) return `${m / 60} hour${m === 60 ? '' : 's'}`;
  if (m > 60) return `${Math.floor(m / 60)} h ${m % 60} min`;
  return `${m} minute${m === 1 ? '' : 's'}`;
}

/** Client-side state that keeps moving while the page stays open (re-evaluated every 30s). */
export function liveState(lc, now) {
  if (lc.state === 'cancelled' || lc.state === 'completed') return lc.state;
  const start = new Date(lc.starts_at).getTime();
  const end = lc.ends_at ? new Date(lc.ends_at).getTime() : start + (lc.duration_minutes || 60) * 60000;
  if (now >= end) return 'completed';
  if (now >= start) return 'live';
  return 'upcoming';
}

/**
 * The one action a card offers right now. Same rules as class_cta() in api/live-classes.php (whose `cta` is a
 * snapshot taken at load time), re-evaluated against the clock so "Join" appears when join_opens_at passes:
 * join | login_to_join | remind | reminder_set | login_to_remind | enrol | recording | login_to_watch | ended | cancelled | none
 */
export function classCta(lc, now, { loggedIn, canRemind, reminderSet }) {
  const state = liveState(lc, now);
  const open = lc.access_type === 'public' || (loggedIn && !!lc.eligible);
  if (state === 'cancelled') return 'cancelled';
  if (state === 'completed') return !lc.has_recording ? 'ended' : open ? 'recording' : !loggedIn ? 'login_to_watch' : 'enrol';
  if (now >= new Date(lc.join_opens_at || lc.starts_at).getTime()) return open ? 'join' : !loggedIn ? 'login_to_join' : 'enrol';
  if (!loggedIn) return 'login_to_remind';
  if (lc.eligible === false) return 'enrol';
  if (!canRemind) return 'none'; // staff accounts cannot set student reminders
  return reminderSet ? 'reminder_set' : 'remind';
}

export default function LiveClasses() {
  useCmsSeo('/live-classes', { title: 'Live Classes - Laxyam Academy', description: 'Upcoming live classes and doubt sessions for UPSC, SSC, Banking and other exams.' });
  const { user } = useAuth();
  const now = useNow(30000);
  const [params, setParams] = useSearchParams();
  const tab = ['live', 'past'].includes(params.get('when')) ? params.get('when') : 'upcoming';
  const category = params.get('category') || '';
  const page = tab === 'live' ? 1 : Number(params.get('page') || 1);
  const { data, loading, error, reload } = useApi('live-classes.php', { action: 'list', when: tab === 'past' ? 'past' : 'upcoming', category, page, u: user?.id || 0 });
  const set = (c) => {
    const n = new URLSearchParams(params);
    Object.entries(c).forEach(([k, v]) => (v ? n.set(k, v) : n.delete(k)));
    setParams(n);
  };

  const items = data?.items || [];
  // "Live now" = classes from the upcoming list that are running right now (they sort first)
  const liveItems = tab === 'past' ? [] : items.filter((lc) => liveState(lc, now) === 'live');
  const shown = tab === 'live' ? liveItems : tab === 'upcoming' ? items.filter((lc) => liveState(lc, now) !== 'completed') : items;
  const windowMin = joinWindowMinutes(data, items[0]);
  const lead = Number(data?.reminder_lead_minutes) || 0; // reminders are emailed this many minutes before the start

  return (
    <>
      <PageHeader
        eyebrow={<><span className="relative flex size-2"><span className="absolute inline-flex size-full animate-ping rounded-full bg-red-500 opacity-75 motion-reduce:animate-none" /><span className="relative inline-flex size-2 rounded-full bg-red-600" /></span>Interactive sessions</>}
        title="Live"
        highlight="Classes"
        subtitle={`Learn live with expert faculty and clear your doubts in real time. Times are in Indian Standard Time (IST)${windowMin ? ` and the join button appears ${minutesText(windowMin)} before each class` : windowMin === 0 ? ' and the join button appears when each class starts' : ''}.`}
        crumbs={[{ to: '/', label: 'Home' }, { label: 'Live Classes' }]}
      />
      <section className="container-x py-8 sm:py-10">
        <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div role="tablist" aria-label="Class schedule" className="scrollbar-none flex max-w-full gap-1 overflow-x-auto rounded-2xl border border-line bg-white p-1 shadow-card">
            {TABS.map(([k, l, icon, short]) => (
              <button key={k} role="tab" aria-selected={tab === k} type="button" onClick={() => set({ when: k === 'upcoming' ? '' : k, page: '' })}
                className={'inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold transition sm:px-4 ' + (tab === k ? (k === 'live' ? 'bg-red-600 text-white shadow' : 'bg-primary text-white shadow') : 'text-ink hover:bg-surface')}>
                <Icon name={icon} className="size-4" />{short ? <><span className="sm:hidden">{short}</span><span className="hidden sm:inline">{l}</span></> : l}
                {k === 'live' && data && tab !== 'past' && <span className={'rounded-full px-1.5 text-[11px] font-bold ' + (tab === k ? 'bg-white/25' : 'bg-red-50 text-red-700')}>{liveItems.length}</span>}
              </button>
            ))}
          </div>
          <div className="flex items-center gap-2">
            <label htmlFor="lc-cat" className="text-sm font-medium text-muted">Exam</label>
            <select id="lc-cat" value={category} onChange={(e) => set({ category: e.target.value, page: '' })} className="input w-full py-2.5 sm:w-56">
              <option value="">All categories</option>
              {data?.categories?.map((c) => <option key={c.slug} value={c.slug}>{c.name}</option>)}
            </select>
          </div>
        </div>

        {!user && tab !== 'past' && (
          <div className="mb-6 flex flex-col gap-3 rounded-2xl border border-primary/20 bg-primary-soft/60 p-4 sm:flex-row sm:items-center sm:justify-between">
            <p className="flex items-center gap-2 text-sm text-navy"><Icon name="bell" className="size-5 shrink-0 text-primary" />Log in to set reminders - we will email you {lead ? `${minutesText(lead)} before` : 'before'} the class starts.</p>
            <Link to={`/login?next=${encodeURIComponent('/live-classes')}`} className="btn-primary btn-sm shrink-0">Log in</Link>
          </div>
        )}

        {error ? <ErrorState error={error} onRetry={reload} /> : loading && !data ? (
          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" aria-busy="true">{[0, 1, 2].map((i) => <li key={i} className="card overflow-hidden"><Skeleton className="aspect-[16/7]" rounded="rounded-none" /><div className="space-y-2 p-4"><Skeleton className="h-4 w-1/3" /><Skeleton className="h-4 w-2/3" /><Skeleton className="h-10 w-full" /></div></li>)}</ul>
        ) : shown.length === 0 ? (
          <EmptyState icon="video"
            title={tab === 'past' ? 'No past classes' : tab === 'live' ? 'No class is live right now' : 'No upcoming classes scheduled'}
            text={tab === 'live' ? 'See the upcoming schedule and set a reminder for the next class.' : 'Check back soon or explore our courses.'}
            action={tab === 'live' ? <button type="button" onClick={() => set({ when: '', page: '' })} className="btn-primary">View upcoming classes</button> : <Link to="/courses" className="btn-outline">Explore courses</Link>} />
        ) : (
          <>
            <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {shown.map((lc, i) => <LiveClassCard key={lc.id} lc={lc} viewer={data.viewer} reminderLead={lead} now={now} delay={(i % 3) * 60} />)}
            </ul>
            {tab !== 'live' && <Pagination page={page} totalPages={data.pagination?.total_pages || 1} onChange={(p) => set({ page: String(p) })} />}
          </>
        )}
      </section>
    </>
  );
}

const BANNERS = { live: 'from-[#7f1d1d] via-[#b91c1c] to-coral', upcoming: 'from-navy via-[#0b3a8c] to-primary', completed: 'from-[#1e293b] to-[#334155]', cancelled: 'from-[#475569] to-[#64748b]' };
const STATE_LABEL = { live: 'LIVE NOW', upcoming: 'UPCOMING', completed: 'COMPLETED', cancelled: 'CANCELLED' };

/**
 * Card with join / reminder / recording actions. Links come from the server only after an eligibility check.
 * viewer = the list/mine response's {logged_in, can_set_reminders}; reminderLead = its reminder_lead_minutes.
 */
export function LiveClassCard({ lc, viewer, reminderLead = 0, now: nowProp, delay = 0 }) {
  const { user } = useAuth();
  const location = useLocation();
  const ownNow = useNow(30000);
  const now = nowProp ?? ownNow;
  const [reminder, setReminder] = useState(!!lc.reminder_set);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(null);
  const [fallback, setFallback] = useState(null); // link shown when a popup blocker stops window.open
  const loginUrl = `/login?next=${encodeURIComponent(location.pathname + location.search)}`;

  const act = async (action, body) => {
    setBusy(true);
    setMsg(null);
    try {
      return await api.post('live-classes.php', body, { action });
    } catch (e) {
      setMsg(e.message);
      return null;
    } finally {
      setBusy(false);
    }
  };
  // Open the tab synchronously (inside the click) so iOS Safari does not block it, then point it at the URL.
  const openFromServer = async (action, key) => {
    setFallback(null);
    const win = window.open('', '_blank');
    if (win) win.opener = null;
    const r = await act(action, { id: lc.id });
    const url = r?.[key];
    if (url && win && !win.closed) win.location.href = url;
    else {
      win?.close();
      if (url) setFallback(url);
    }
  };
  const toggleReminder = async () => {
    const r = await act('remind', { id: lc.id, on: !reminder });
    if (r) setReminder(r.reminder_set);
  };

  const state = liveState(lc, now);
  const live = state === 'live';
  // The response's viewer matches its eligible / reminder_set flags; the auth context covers cards rendered without one
  const loggedIn = viewer ? !!viewer.logged_in : !!user;
  const canRemind = viewer ? !!viewer.can_set_reminders : user?.type === 'student';
  const cta = classCta(lc, now, { loggedIn, canRemind, reminderSet: reminder });
  const startsIn = new Date(lc.starts_at).getTime() - now;
  const opensIn = lc.join_opens_at ? new Date(lc.join_opens_at).getTime() - now : null;
  const course = lc.course ? `/courses/${lc.course.slug}` : null;
  const muted = 'w-full rounded-xl bg-surface py-2.5 text-center text-sm text-muted';

  return (
    <Reveal as="li" delay={delay} className="card card-hover flex flex-col overflow-hidden">
      <div className={'relative flex aspect-[16/7] flex-col justify-between bg-gradient-to-br p-4 ' + BANNERS[state]}>
        <div aria-hidden="true" className="dot-grid absolute inset-0 opacity-30" />
        <div className="relative flex items-start justify-between gap-2">
          <span className={'flex items-center gap-1.5 rounded-lg px-2 py-1 text-[11px] font-bold tracking-wide text-white ' + (live ? 'bg-red-600 shadow-lg ring-2 ring-white/30' : 'bg-white/20 backdrop-blur')}>
            {live && <span className="size-1.5 animate-pulse rounded-full bg-white motion-reduce:animate-none" />}
            {STATE_LABEL[state]}
          </span>
          {state === 'upcoming' && <span className="rounded-lg bg-white/90 px-2 py-1 text-[11px] font-bold text-navy" aria-live="polite">Starts in {countdownText(startsIn)}</span>}
        </div>
        <Icon name="video" className="absolute right-4 bottom-3 size-14 text-white/15" strokeWidth={1.5} />
        <p className="relative line-clamp-1 text-sm font-semibold text-white/85">{lc.category?.name || lc.course?.title || 'Live session'}{lc.instructor ? ` · ${lc.instructor}` : ''}</p>
      </div>
      <div className="flex flex-1 flex-col p-4 sm:p-5">
        <div className="flex flex-wrap gap-2"><CategoryPill category={lc.category} />{lc.access_type === 'public' && <span className="rounded bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Free for all</span>}{lc.is_demo && <DemoBadge />}</div>
        <h3 className="mt-2 font-display leading-snug font-bold text-navy">{lc.title}</h3>
        {lc.instructor && <p className="mt-2 flex items-center gap-2 text-sm text-muted"><Icon name="user" className="size-4" />{lc.instructor}</p>}
        <p className="mt-1.5 flex items-center gap-2 text-sm text-muted"><Icon name="calendar" className="size-4" /><time dateTime={lc.starts_at}>{formatDateTime(lc.starts_at)}</time> · {lc.duration_minutes} min</p>
        {lc.course && cta !== 'enrol' && <p className="mt-1.5 text-sm text-muted">Course: <Link to={`/courses/${lc.course.slug}`} className="font-medium text-primary hover:underline">{lc.course.title}</Link></p>}
        {lc.announcement && <p className="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-900">{lc.announcement}</p>}
        {cta === 'enrol' && <p className="mt-2 text-xs text-muted">For students enrolled in {lc.course ? <Link to={course} className="font-semibold text-primary">{lc.course.title}</Link> : 'the linked course'}.</p>}
        {['remind', 'reminder_set', 'login_to_remind', 'none'].includes(cta) && opensIn > 0 && <p className="mt-2 text-xs text-muted">Join button appears in {countdownText(opensIn)}.</p>}
        {cta === 'reminder_set' && reminderLead > 0 && <p className="mt-2 flex items-center gap-1.5 text-xs font-medium text-emerald-700"><Icon name="checkCircle" className="size-3.5 shrink-0" />We will email you {minutesText(reminderLead)} before it starts.</p>}
        {msg && <p role="alert" className="mt-2 text-xs text-red-600">{msg}</p>}
        {fallback && <a href={fallback} target="_blank" rel="noopener noreferrer" className="mt-2 text-xs font-semibold text-primary underline">Your browser blocked the new tab - open the class here</a>}
        <div className="mt-auto flex gap-2 pt-4">
          {cta === 'join' ? (
            <button type="button" disabled={busy} onClick={() => openFromServer('join', 'meeting_url')} className={(live ? 'btn-danger' : 'btn-primary') + ' w-full'}><Icon name="video" className="size-4" />{busy ? 'Opening…' : 'Join live'}</button>
          ) : cta === 'login_to_join' ? (
            <Link to={loginUrl} className="btn-primary w-full">Log in to join</Link>
          ) : cta === 'remind' || cta === 'reminder_set' ? (
            <button type="button" disabled={busy} onClick={toggleReminder} aria-pressed={reminder} className={(reminder ? 'btn-light' : 'btn-outline') + ' w-full'}>
              <Icon name="bell" className="size-4" /> {reminder ? 'Reminder set' : 'Set reminder'}
            </button>
          ) : cta === 'login_to_remind' ? (
            <Link to={loginUrl} className="btn-outline w-full">Log in to set a reminder</Link>
          ) : cta === 'recording' ? (
            <button type="button" disabled={busy} onClick={() => openFromServer('recording', 'recording_url')} className="btn-outline w-full"><Icon name="playCircle" className="size-4" />Watch recording</button>
          ) : cta === 'login_to_watch' ? (
            <Link to={loginUrl} className="btn-outline w-full">Log in to watch the recording</Link>
          ) : cta === 'enrol' ? (
            course ? <Link to={course} className="btn-outline w-full">{state === 'completed' ? 'Enrol to watch' : 'Enrol to join'} <Icon name="arrowRight" className="size-4" /></Link> : <span className={muted}>For enrolled students only</span>
          ) : cta === 'cancelled' ? (
            <span className={muted}>This class was cancelled</span>
          ) : cta === 'ended' ? (
            <span className={muted}>Recording not available</span>
          ) : (
            <span className={muted}>Reminders are for student accounts</span>
          )}
        </div>
      </div>
    </Reveal>
  );
}
