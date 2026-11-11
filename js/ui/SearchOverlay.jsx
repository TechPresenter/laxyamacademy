// Site-wide search overlay (header search icon, drawer, Ctrl/Cmd + K or "/").
// Live grouped results from search.php (courses, mock tests, resources, jobs, blog) with
// keyboard navigation; Enter without a highlighted result opens /courses?q=….
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, formatDate, formatPrice } from '../api.js';
import { Icon, MicButton, useSite } from '../components.jsx';
import { useFocusTrap, useMediaQuery, useScrollLock } from './overlay.js';
import { JOB_TYPES, jobAlertPath } from './shell-data.js';

const RECENT_KEY = 'lx-recent-searches';
const readRecent = () => {
  try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').filter((s) => typeof s === 'string').slice(0, 6); } catch { return []; }
};
const saveRecent = (term) => {
  const t = term.trim();
  if (t.length < 2) return;
  try { localStorage.setItem(RECENT_KEY, JSON.stringify([t, ...readRecent().filter((x) => x.toLowerCase() !== t.toLowerCase())].slice(0, 6))); } catch { /* storage unavailable */ }
};

const GROUPS = [
  ['courses', 'Courses', 'graduationCap', (c) => `/courses/${c.slug}`],
  ['tests', 'Mock Tests', 'clipboard', (t) => `/mock-tests/${t.slug}`],
  ['resources', 'Free Resources', 'book', (r) => `/resources/${r.slug}`],
  ['jobs', 'Govt Jobs', 'briefcase', (j) => jobAlertPath(j)],
  ['blog', 'Blog', 'newspaper', (b) => `/blog/${b.slug}`],
];

const QUICK = [['/mock-tests', 'Mock Tests', 'clipboard'], ['/resources', 'Free Resources', 'book'], ['/jobs', 'Latest Govt Jobs', 'briefcase'], ['/live-classes', 'Live Classes', 'video']];

function ResultRow({ group, item }) {
  if (group === 'courses') {
    const color = item.category?.color || '#0B63F6';
    return (
      <>
        {item.thumbnail_url
          ? <img src={item.thumbnail_url} alt="" loading="lazy" className="size-11 shrink-0 rounded-xl object-cover" />
          : <span className="grid size-11 shrink-0 place-items-center rounded-xl text-white" style={{ background: `linear-gradient(135deg, ${color}, color-mix(in srgb, ${color} 45%, #08264D))` }}><Icon name={item.category?.icon || 'graduationCap'} className="size-5" /></span>}
        <span className="min-w-0 flex-1">
          <span className="block truncate font-semibold text-ink">{item.title}</span>
          <span className="block truncate text-xs text-muted">{[item.category?.name, item.subtitle].filter(Boolean).join(' · ')}</span>
        </span>
        <span className={'shrink-0 text-sm font-extrabold ' + (item.is_free ? 'text-emerald-600' : 'text-navy')}>{item.is_free ? 'Free' : formatPrice(item.price)}</span>
      </>
    );
  }
  const [, label, icon] = GROUPS.find((g) => g[0] === group);
  let meta = '';
  let chip = null;
  if (group === 'tests') {
    meta = [item.questions && `${item.questions} questions`, item.duration_minutes && `${item.duration_minutes} min`].filter(Boolean).join(' · ');
    if (item.mode) chip = <span className={'rounded-full px-2 py-px text-[10px] font-bold ' + (item.mode === 'practice' ? 'bg-teal/10 text-teal' : 'bg-primary-soft text-primary')}>{item.mode === 'practice' ? 'Practice' : 'Exam'}</span>;
  } else if (group === 'resources') {
    meta = item.file_type ? String(item.file_type).toUpperCase() : 'Study material';
  } else if (group === 'jobs') {
    meta = [item.organization, item.last_date && `Last date ${formatDate(item.last_date)}`].filter(Boolean).join(' · ');
    const [typeLabel, cls] = JOB_TYPES[item.type] || [item.type_label || 'Update', 'bg-slate-100 text-slate-700'];
    chip = <span className={'rounded-full px-2 py-px text-[10px] font-bold ' + cls}>{typeLabel}</span>;
  } else {
    meta = label;
  }
  return (
    <>
      <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-surface text-primary ring-1 ring-line"><Icon name={icon} className="size-5" /></span>
      <span className="min-w-0 flex-1">
        <span className="block truncate font-semibold text-ink">{item.title}</span>
        {meta && <span className="block truncate text-xs text-muted">{meta}</span>}
      </span>
      {chip}
    </>
  );
}

export default function SearchOverlay({ onClose }) {
  const navigate = useNavigate();
  const { data } = useSite();
  const wide = useMediaQuery('(min-width: 640px)');
  const [q, setQ] = useState('');
  const [res, setRes] = useState(null);
  const [loading, setLoading] = useState(false);
  const [active, setActive] = useState(-1);
  const [recent, setRecent] = useState(readRecent);
  const panel = useRef(null);
  const input = useRef(null);
  const listRef = useRef(null);
  useScrollLock(true);
  useFocusTrap(panel, true, { initialFocus: input, onEscape: onClose });

  const term = q.trim();
  useEffect(() => {
    if (term.length < 2) {
      setRes(null);
      setLoading(false);
      return undefined;
    }
    const ctrl = new AbortController();
    setLoading(true);
    const t = setTimeout(async () => {
      try {
        setRes({ ...(await api.get('search.php', { q: term }, { signal: ctrl.signal })), term });
      } catch (err) {
        if (err.name === 'AbortError') return;
        // Global search unavailable: fall back to the course catalogue so search still works
        try {
          const d = await api.get('courses.php', { action: 'list', q: term, per_page: 5 }, { signal: ctrl.signal });
          setRes({ term, courses: d.items });
        } catch (err2) {
          if (err2.name !== 'AbortError') setRes({ term, error: err2.message });
        }
      } finally {
        if (!ctrl.signal.aborted) setLoading(false);
      }
      setActive(-1);
    }, 220);
    return () => { clearTimeout(t); ctrl.abort(); };
  }, [term]);

  // Flat list for keyboard navigation
  const flat = useMemo(() => {
    if (!res) return [];
    return GROUPS.flatMap(([key, , , path]) => (Array.isArray(res[key]) ? res[key].slice(0, 5).map((item) => ({ key, item, to: path(item) })) : []));
  }, [res]);

  useEffect(() => {
    if (active < 0) return;
    listRef.current?.querySelector(`#search-opt-${active}`)?.scrollIntoView({ block: 'nearest' });
  }, [active]);

  const go = (to, remember = term) => {
    saveRecent(remember);
    onClose();
    navigate(to);
  };
  const submit = (e) => {
    e?.preventDefault();
    if (active >= 0 && flat[active]) return go(flat[active].to);
    if (!term) return undefined;
    return go(`/courses?q=${encodeURIComponent(term)}`);
  };
  const onKey = (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(flat.length - 1, a + 1)); }
    if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(-1, a - 1)); }
  };
  const clearRecent = () => {
    try { localStorage.removeItem(RECENT_KEY); } catch { /* ignore */ }
    setRecent([]);
  };

  const popular = (data?.mega || []).slice(0, 8);
  const showResults = term.length >= 2 && res && res.term === term;
  let index = -1;

  return (
    <div className="fixed inset-0 z-[60] flex justify-center sm:items-start sm:px-4 sm:pt-[9vh]">
      <div aria-hidden="true" onClick={onClose} className="absolute inset-0 animate-fade-in bg-navy-deep/50 backdrop-blur-md" />
      <div ref={panel} role="dialog" aria-modal="true" aria-label="Search Laxyam Academy"
        className="glass-light relative flex max-h-[100dvh] w-full max-w-2xl animate-slide-down flex-col overflow-hidden rounded-b-3xl shadow-[0_30px_80px_-20px_rgb(4_19_48/0.55)] sm:max-h-[80vh] sm:rounded-3xl">
        <form onSubmit={submit} role="search" className="shrink-0 p-3 sm:p-4">
          <div className="flex items-center gap-2 rounded-2xl border-2 border-primary/25 bg-white pr-1.5 pl-3.5 shadow-sm transition-colors focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/10">
            <Icon name="search" className="size-5 shrink-0 text-primary" />
            <label htmlFor="site-search" className="sr-only">Search courses, mock tests, resources, jobs and blog</label>
            <input ref={input} id="site-search" type="search" value={q} autoComplete="off" enterKeyHint="search"
              role="combobox" aria-expanded={flat.length > 0} aria-controls="search-results" aria-autocomplete="list" aria-activedescendant={active >= 0 ? `search-opt-${active}` : undefined}
              onChange={(e) => setQ(e.target.value)} onKeyDown={onKey}
              placeholder={wide ? 'Search courses, mock tests, jobs…' : 'Search courses, tests…'}
              className="h-14 w-0 min-w-0 flex-1 bg-transparent text-base text-ink placeholder:text-muted/80 focus:outline-none [&::-webkit-search-cancel-button]:hidden" />
            {q && (
              <button type="button" onClick={() => { setQ(''); input.current?.focus(); }} aria-label="Clear search" className="grid size-8 shrink-0 place-items-center rounded-full text-muted hover:bg-surface hover:text-navy">
                <Icon name="x" className="size-4" />
              </button>
            )}
            <MicButton compact={!wide} onText={(text, final) => { setQ(text); if (final && text.trim()) setTimeout(() => go(`/courses?q=${encodeURIComponent(text.trim())}`, text), 450); }} />
            <button type="button" onClick={onClose} className="hidden h-8 shrink-0 items-center rounded-lg border border-line px-2 text-[11px] font-bold text-muted hover:text-navy sm:flex">Esc</button>
            <button type="button" onClick={onClose} aria-label="Close search" className="grid size-9 shrink-0 place-items-center rounded-full bg-surface text-navy sm:hidden"><Icon name="x" className="size-4" /></button>
          </div>
        </form>

        <div ref={listRef} className="scrollbar-thin min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 pb-4 sm:px-4">
          {term.length < 2 && (
            <div className="space-y-5 px-1 pt-1">
              {recent.length > 0 && (
                <div>
                  <div className="mb-2 flex items-center justify-between">
                    <p className="flex items-center gap-1.5 text-xs font-bold tracking-wider text-muted uppercase"><Icon name="history" className="size-3.5" />Recent searches</p>
                    <button type="button" onClick={clearRecent} className="text-xs font-semibold text-primary hover:underline">Clear</button>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {recent.map((r) => (
                      <button key={r} type="button" onClick={() => { setQ(r); input.current?.focus(); }} className="rounded-full border border-line bg-white px-3 py-1.5 text-sm font-medium text-ink transition-colors hover:border-primary hover:text-primary">{r}</button>
                    ))}
                  </div>
                </div>
              )}
              {popular.length > 0 && (
                <div>
                  <p className="mb-2 flex items-center gap-1.5 text-xs font-bold tracking-wider text-muted uppercase"><Icon name="trendingUp" className="size-3.5" />Popular exams</p>
                  <div className="flex flex-wrap gap-2">
                    {popular.map((c) => (
                      <Link key={c.slug} to={`/courses?category=${c.slug}`} onClick={onClose} className="flex items-center gap-2 rounded-full border border-line bg-white py-1.5 pr-3 pl-1.5 text-sm font-semibold text-ink transition-all hover:-translate-y-0.5 hover:border-primary hover:shadow-card">
                        <span className="grid size-6 place-items-center rounded-full" style={{ backgroundColor: (c.color || '#0B63F6') + '1A', color: c.color || '#0B63F6' }}><Icon name={c.icon} className="size-3.5" /></span>{c.name}
                      </Link>
                    ))}
                  </div>
                </div>
              )}
              <div>
                <p className="mb-2 text-xs font-bold tracking-wider text-muted uppercase">Quick links</p>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                  {QUICK.map(([to, label, icon]) => (
                    <Link key={to} to={to} onClick={onClose} className="flex flex-col items-center gap-1.5 rounded-2xl border border-line bg-white p-3 text-center text-xs font-semibold text-navy transition-all hover:-translate-y-0.5 hover:border-primary hover:shadow-card">
                      <span className="grid size-9 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name={icon} className="size-[18px]" /></span>{label}
                    </Link>
                  ))}
                </div>
              </div>
            </div>
          )}

          {term.length >= 2 && loading && !showResults && (
            <div role="status" className="space-y-2 px-1 pt-1">
              {[0, 1, 2].map((i) => <div key={i} className="flex items-center gap-3 rounded-xl p-2"><div className="skeleton size-11 rounded-xl" /><div className="flex-1 space-y-2"><div className="skeleton h-3.5 w-2/3 rounded" /><div className="skeleton h-3 w-1/3 rounded" /></div></div>)}
              <span className="sr-only">Searching…</span>
            </div>
          )}

          {showResults && (
            res.error ? (
              <p role="alert" className="px-2 py-6 text-center text-sm text-danger">{res.error}</p>
            ) : flat.length === 0 ? (
              <div className="px-2 py-8 text-center">
                <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-surface text-muted"><Icon name="search" className="size-6" /></span>
                <p className="mt-3 font-display font-bold text-navy">No results for “{term}”</p>
                <p className="mt-1 text-sm text-muted">Try another exam name, or ask our counsellors for help.</p>
                <div className="mt-4 flex flex-wrap justify-center gap-2">
                  <Link to="/courses" onClick={onClose} className="btn-light btn-sm">Browse all courses</Link>
                  <Link to="/contact" onClick={onClose} className="btn-primary btn-sm">Talk to a counsellor</Link>
                </div>
              </div>
            ) : (
              <div id="search-results" role="listbox" aria-label="Search results" className="space-y-3">
                {GROUPS.map(([key, label, icon]) => {
                  const items = Array.isArray(res[key]) ? res[key].slice(0, 5) : [];
                  if (!items.length) return null;
                  return (
                    <div key={key} role="group" aria-label={label}>
                      <p className="mb-1 flex items-center gap-1.5 px-2 pt-1 text-[11px] font-bold tracking-wider text-muted uppercase"><Icon name={icon} className="size-3.5" />{label}</p>
                      <ul role="presentation">
                        {items.map((item) => {
                          index += 1;
                          const i = index;
                          const to = flat[i]?.to;
                          return (
                            <li key={`${key}-${item.slug || i}`} id={`search-opt-${i}`} role="option" aria-selected={i === active}>
                              <button type="button" tabIndex={-1} onMouseEnter={() => setActive(i)} onClick={() => go(to)}
                                className={'flex w-full items-center gap-3 rounded-2xl px-2 py-2 text-left text-sm transition-colors ' + (i === active ? 'bg-primary-soft ring-1 ring-primary/20' : 'hover:bg-white')}>
                                <ResultRow group={key} item={item} />
                              </button>
                            </li>
                          );
                        })}
                      </ul>
                    </div>
                  );
                })}
                <button type="button" onClick={() => go(`/courses?q=${encodeURIComponent(term)}`)} className="flex w-full items-center justify-between rounded-2xl bg-white px-4 py-3 text-sm font-bold text-primary ring-1 ring-line transition-colors hover:ring-primary">
                  See all course results for “{term}”<Icon name="arrowRight" className="size-4" />
                </button>
              </div>
            )
          )}
        </div>
        <p className="hidden shrink-0 items-center gap-4 border-t border-line/70 px-5 py-2.5 text-[11px] text-muted sm:flex">
          <span><kbd className="rounded border border-line bg-white px-1 font-sans">↑</kbd> <kbd className="rounded border border-line bg-white px-1 font-sans">↓</kbd> to navigate</span>
          <span><kbd className="rounded border border-line bg-white px-1 font-sans">Enter</kbd> to open</span>
          <span><kbd className="rounded border border-line bg-white px-1 font-sans">Esc</kbd> to close</span>
        </p>
      </div>
    </div>
  );
}
