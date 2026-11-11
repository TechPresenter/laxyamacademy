import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatDateTime } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { Alert, EmptyState, ErrorState, Icon, Pagination, Skeleton, useApi } from '../js/components.jsx';
import { DashTitle, linkKind, NOTIFICATION_GROUPS, NotificationIcon, NotificationLink } from '../js/account/ui.jsx';

const GROUP_KEYS = new Set(NOTIFICATION_GROUPS.map(([k]) => k));
const GROUP_LABEL = Object.fromEntries(NOTIFICATION_GROUPS.map(([k, label]) => [k, label]));

/**
 * Notification inbox. Filtering and paging happen on the server
 * (students.php?action=notifications&type=<group>&page=N), so every page and every
 * category shows the student's complete history, not just the 20 rows already loaded.
 * The filter and page live in the URL (?type=payments&page=2) so Back and shared links work.
 */
export default function Notifications() {
  const [params, setParams] = useSearchParams();
  const rawType = params.get('type') || '';
  const type = GROUP_KEYS.has(rawType) ? rawType : '';
  const page = Math.max(1, Number.parseInt(params.get('page') || '1', 10) || 1);
  const [readIds, setReadIds] = useState(() => new Set()); // optimistic "read" state
  const [allRead, setAllRead] = useState(false);
  const [msg, setMsg] = useState(null);
  const [busy, setBusy] = useState(false);
  const { refreshSummary, summary } = useAuth();
  const { data, loading, error, reload } = useApi('students.php', { action: 'notifications', page, type });

  const go = (nextType, nextPage = 1) => {
    const q = new URLSearchParams();
    if (nextType) q.set('type', nextType);
    if (nextPage > 1) q.set('page', String(nextPage));
    setParams(q);
    setMsg(null);
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  };

  const markRead = async (id) => {
    setMsg(null);
    if (id) setReadIds((s) => new Set(s).add(id));
    else setBusy(true);
    try {
      await api.post('students.php', id ? { id } : {}, { action: 'notifications-read' });
      if (!id) setAllRead(true);
      refreshSummary();
    } catch (e) {
      if (id) setReadIds((s) => { const n = new Set(s); n.delete(id); return n; });
      setMsg(e.message);
    } finally {
      setBusy(false);
    }
  };

  if (error && !data) return <ErrorState error={error} onRetry={reload} />;
  if (!data) return <ListSkeleton />;

  // The groups the server knows about ("other" = every type not listed in a group).
  const serverGroups = new Set([...Object.keys(data.groups || {}), 'other']);
  const chips = [['', 'All', 'bell'], ...NOTIFICATION_GROUPS.filter(([k]) => serverGroups.has(k))];
  const current = (data.type || '') === type; // false while a new filter is loading (old rows still shown)
  const total = data.pagination?.total ?? data.items.length;
  const items = data.items.map((n) => (allRead || readIds.has(n.id) ? { ...n, read: true } : n));
  const unreadHere = items.filter((n) => !n.read).length;
  const unreadAll = allRead ? 0 : Math.max(Number(summary?.unread_notifications || 0), unreadHere);
  const label = type ? GROUP_LABEL[type] : '';

  return (
    <>
      <DashTitle title="Notifications" icon="bell" subtitle="Payments, enrolments, test results, class reminders and announcements from the academy."
        action={unreadAll > 0 && (
          <button type="button" onClick={() => markRead()} disabled={busy} className="btn-light">
            <Icon name="checkCircle" className="size-4" /> {busy ? 'Marking…' : `Mark all as read (${unreadAll > 99 ? '99+' : unreadAll})`}
          </button>
        )} />
      {msg && <Alert className="mb-4">{msg}</Alert>}
      {error && <Alert className="mb-4">{error.message} <button type="button" onClick={reload} className="font-semibold underline">Retry</button></Alert>}

      <div role="toolbar" aria-label="Filter notifications" className="scrollbar-none -mx-4 mb-5 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0">
        {chips.map(([k, chipLabel, icon]) => {
          const on = type === k;
          return (
            <button key={k || 'all'} type="button" aria-pressed={on} onClick={() => go(k)}
              className={'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none ' + (on ? 'border-primary bg-primary text-white shadow-glow' : 'border-line bg-white text-ink hover:border-primary hover:text-primary')}>
              <Icon name={icon} className="size-4" /> {chipLabel}
              {on && current && <span className="rounded-full bg-white/20 px-1.5 text-xs">{total > 999 ? '999+' : total}</span>}
            </button>
          );
        })}
      </div>

      {items.length === 0 ? (
        page > 1 ? (
          <EmptyState icon="inbox" title="No notifications on this page" text="This page is empty now - newer notifications may have moved it."
            action={<button type="button" onClick={() => go(type)} className="btn-light">Go to the first page</button>} />
        ) : type ? (
          <EmptyState icon="checkCircle" title={`No ${label.toLowerCase()} notifications`} text="Nothing in this category yet. Try another filter."
            action={<button type="button" onClick={() => go('')} className="btn-light">Show all</button>} />
        ) : (
          <EmptyState icon="inbox" title="No notifications yet" text="Payment receipts, test results and class reminders will appear here." />
        )
      ) : (
        <ul className={'card divide-y divide-line overflow-hidden transition-opacity ' + (loading ? 'opacity-60' : '')} aria-busy={loading}>
          {items.map((n) => (
            <li key={n.id} className={'relative flex gap-3 p-4 transition-colors sm:gap-4 sm:p-5 ' + (n.read ? '' : 'bg-primary-soft/40')}>
              {!n.read && <span aria-hidden="true" className="absolute inset-y-3 left-0 w-1 rounded-r bg-primary" />}
              <NotificationIcon type={n.type} />
              <div className="min-w-0 flex-1">
                <p className={'break-words ' + (n.read ? 'font-semibold text-ink' : 'font-bold text-navy')}>
                  {n.title} {!n.read && <span className="sr-only">(unread)</span>}
                </p>
                {n.message && <p className="mt-0.5 text-sm break-words whitespace-pre-line text-muted">{n.message}</p>}
                <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
                  <span className="inline-flex items-center gap-1 text-xs text-muted"><Icon name="clock" className="size-3.5" /> {formatDateTime(n.created_at)}</span>
                  <NotificationLink link={n.link} onOpen={() => !n.read && markRead(n.id)} className="inline-flex items-center gap-1 font-semibold text-primary hover:underline">
                    {linkKind(n.link) === 'external' ? <>Open link <Icon name="externalLink" className="size-3.5" /><span className="sr-only">(opens in a new tab)</span></> : <>Open <Icon name="arrowRight" className="size-3.5" /></>}
                  </NotificationLink>
                  {!n.read && <button type="button" onClick={() => markRead(n.id)} className="text-xs font-semibold text-muted hover:text-primary">Mark as read</button>}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
      {current && <Pagination page={page} totalPages={data.pagination?.total_pages || 1} onChange={(p) => go(type, p)} />}
    </>
  );
}

function ListSkeleton() {
  return (
    <div aria-busy="true" className="space-y-4">
      <span className="sr-only" role="status">Loading notifications…</span>
      <Skeleton className="h-12 w-64" />
      <div className="flex gap-2">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-8 w-24" rounded="rounded-full" />)}</div>
      <div className="card divide-y divide-line">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="flex gap-4 p-5"><Skeleton className="size-10" rounded="rounded-xl" /><div className="flex-1 space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/3" /></div></div>
        ))}
      </div>
    </div>
  );
}
