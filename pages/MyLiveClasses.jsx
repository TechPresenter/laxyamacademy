import { useState } from 'react';
import { Link } from 'react-router-dom';
import { EmptyState, ErrorState, Icon, Skeleton, useApi } from '../js/components.jsx';
import { DashTitle } from '../js/account/ui.jsx';
import { useNow } from '../js/content/shared.jsx';
import { LiveClassCard, liveState, minutesText } from './LiveClasses.jsx';

/** Minutes before the start when "Join" opens: join_window_minutes from the API (or derived from a class's join_opens_at). */
function joinWindow(data) {
  if (Number.isFinite(data?.join_window_minutes)) return data.join_window_minutes;
  const lc = [...(data?.upcoming || []), ...(data?.past || [])].find((c) => c.join_opens_at && c.starts_at);
  return lc ? Math.round((new Date(lc.starts_at) - new Date(lc.join_opens_at)) / 60000) : null;
}

// GET live-classes.php?action=mine -> { upcoming, past, join_window_minutes, reminder_lead_minutes, viewer }
export default function MyLiveClasses() {
  const { data, loading, error, reload } = useApi('live-classes.php', { action: 'mine' });
  const [tab, setTab] = useState('upcoming');
  const now = useNow(30000);
  if (error && !data) return <ErrorState error={error} onRetry={reload} />;

  const upcoming = data?.upcoming || [];
  const past = data?.past || [];
  const live = upcoming.filter((c) => liveState(c, now) === 'live').length;
  const mins = joinWindow(data);
  const lead = Number(data?.reminder_lead_minutes) || 0;
  const list = tab === 'upcoming' ? upcoming : past;

  return (
    <>
      <DashTitle title="My Live Classes" icon="video" subtitle="Classes from your enrolled courses plus open sessions. All times are in IST."
        action={<Link to="/live-classes" className="btn-light"><Icon name="calendar" className="size-4" /> All live classes</Link>} />

      {data && (
        <div className="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-primary/15 bg-primary-soft/60 p-4 text-sm text-navy">
          <Icon name="info" className="size-5 shrink-0 text-primary" />
          <p className="min-w-0 flex-1">
            {mins > 0 ? <>The <strong>Join</strong> button opens {minutesText(mins)} before a class starts.</> : mins === 0 ? <>The <strong>Join</strong> button opens when a class starts.</> : <>The <strong>Join</strong> button appears shortly before a class starts.</>}
            {' '}{lead > 0 ? <>Set a reminder and we will email you {minutesText(lead)} before it begins.</> : 'Set a reminder to get an alert.'}
          </p>
          {live > 0 && <span className="inline-flex items-center gap-1.5 rounded-full bg-red-600 px-3 py-1 text-xs font-bold text-white"><span className="size-1.5 rounded-full bg-white motion-safe:animate-pulse" /> {live} live now</span>}
        </div>
      )}

      <div role="tablist" aria-label="Classes" className="mb-5 inline-flex rounded-xl border border-line bg-white p-1">
        {[['upcoming', 'Upcoming', upcoming.length], ['past', 'Past classes', past.length]].map(([k, l, n]) => (
          <button key={k} type="button" role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            className={'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-colors ' + (tab === k ? 'bg-primary text-white shadow-glow' : 'text-ink hover:text-primary')}>
            {l} {data && <span className={'rounded-full px-1.5 text-xs ' + (tab === k ? 'bg-white/20' : 'bg-surface text-muted')}>{n}</span>}
          </button>
        ))}
      </div>

      <div role="tabpanel">
        {loading && !data ? (
          <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">{[0, 1, 2].map((i) => <li key={i}><Skeleton className="h-64" rounded="rounded-2xl" /></li>)}</ul>
        ) : list.length ? (
          <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">{list.map((lc) => <LiveClassCard key={lc.id} lc={lc} viewer={data.viewer} reminderLead={lead} now={now} />)}</ul>
        ) : tab === 'upcoming' ? (
          <EmptyState icon="video" title="No upcoming classes" text="Live classes for your enrolled courses will appear here as soon as they are scheduled."
            action={<Link to="/live-classes" className="btn-primary">Browse live classes</Link>} />
        ) : (
          <EmptyState icon="history" title="No past classes yet" text="Classes you could attend will be listed here after they end, with recordings when available." />
        )}
      </div>
    </>
  );
}
