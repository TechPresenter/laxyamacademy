// Mock test & practice quiz catalogue (/mock-tests): search, mode tabs, category /
// subject / access filters, per-student progress on each card.
import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { api, formatDate, formatDateTime } from '../js/api.js';
import { CategoryPill, DemoBadge, EmptyState, ErrorState, Icon, PageHeader, Pagination, Reveal, Skeleton, useApi, useCmsSeo } from '../js/components.jsx';
import { DailyQuizCard } from '../js/exam/DailyQuizCard.jsx';
import { ACCESS, ModeBadge, WindowBadge, attemptMarks, fmtNum } from '../js/exam/shared.jsx';

const MODES = [['', 'All tests', 'grid'], ['exam', 'Mock Tests', 'clipboard'], ['practice', 'Practice Quizzes', 'target']];
const ACCESS_FILTERS = [['', 'Any access'], ['free', 'Free'], ['registered', 'Registered students'], ['enrolled', 'Enrolled students']];

export default function MockTests() {
  useCmsSeo('/mock-tests', { title: 'Mock Tests & Practice Quizzes - Laxyam Academy', description: 'Timed online mock tests and practice quizzes for UPSC, SSC, Banking, Railway and State PSC exams with instant scoring, negative marking and explanations.' });
  const [params, setParams] = useSearchParams();
  const q = params.get('q') || '';
  const mode = params.get('mode') || '';
  const category = params.get('category') || '';
  const subject = params.get('subject') || '';
  const access = params.get('access') || '';
  const page = Number(params.get('page') || 1);
  const { data, loading, error, reload } = useApi('mock-tests.php', { action: 'list', q, mode, category, subject, access, page });
  const counts = useCategoryCounts(data?.categories, mode);
  const [term, setTerm] = useState(q);
  useEffect(() => setTerm(q), [q]);

  const set = (changes) => {
    const n = new URLSearchParams(params);
    Object.entries({ page: '', ...changes }).forEach(([k, v]) => (v ? n.set(k, v) : n.delete(k)));
    setParams(n);
  };
  // Debounced search-as-you-type (Enter submits immediately)
  useEffect(() => {
    if (term.trim() === q) return undefined;
    const t = setTimeout(() => set({ q: term.trim() }), 400);
    return () => clearTimeout(t);
  }, [term]); // eslint-disable-line react-hooks/exhaustive-deps

  const filtered = !!(q || category || subject || access);
  const chips = (data?.categories || []).filter((c) => (counts ? (counts[c.slug] || 0) > 0 || c.slug === category : true));

  return (
    <>
      <PageHeader
        eyebrow="Online test series"
        title="Mock Tests &"
        highlight="Practice Quizzes"
        subtitle="Exam-like timed tests with negative marking, instant scorecards, all-India rank and detailed answer explanations."
        crumbs={[{ to: '/', label: 'Home' }, { label: 'Mock Tests' }]}
      >
        <form role="search" onSubmit={(e) => { e.preventDefault(); set({ q: term.trim() }); }} className="mt-7 flex max-w-2xl animate-fade-up items-center gap-2 rounded-2xl border border-line bg-white p-2 shadow-card [animation-delay:120ms] focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/10">
          <Icon name="search" className="ml-2 size-5 shrink-0 text-muted" />
          <label htmlFor="test-search" className="sr-only">Search tests</label>
          <input id="test-search" type="search" value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Search mock tests, e.g. SSC CGL, Polity, Reasoning…" className="min-w-0 flex-1 bg-transparent px-1 py-2 text-[15px] outline-none placeholder:text-muted/80" />
          <button type="submit" className="btn-primary shrink-0 px-5">Search</button>
        </form>
        <div role="group" aria-label="Test type" className="scrollbar-none -mx-4 mt-5 flex gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0">
          {MODES.map(([key, label, icon]) => (
            <button key={key || 'all'} type="button" aria-pressed={mode === key} onClick={() => set({ mode: key, category: '' })}
              className={'inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition-all ' + (mode === key ? 'bg-navy text-white shadow-lg shadow-navy/20' : 'bg-white/80 text-navy ring-1 ring-line hover:bg-white hover:ring-primary/40')}>
              <Icon name={icon} className="size-4" />{label}
            </button>
          ))}
        </div>
      </PageHeader>

      <section className="container-x py-8 sm:py-10">
        <DailyQuizCard className="mb-6" />
        <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div className="scrollbar-none -mx-4 flex min-w-0 gap-2 overflow-x-auto px-4 sm:mx-0 sm:flex-wrap sm:px-0" aria-label="Exam category">
            <button type="button" onClick={() => set({ category: '' })} aria-pressed={!category} className={chip(!category)}>All categories</button>
            {chips.map((c) => (
              <button key={c.slug} type="button" onClick={() => set({ category: c.slug })} aria-pressed={category === c.slug} className={chip(category === c.slug)}>
                {c.name}
                {counts && <span className={'rounded-full px-1.5 text-[11px] font-bold ' + (category === c.slug ? 'bg-white/20' : 'bg-surface text-muted')}>{counts[c.slug] || 0}</span>}
              </button>
            ))}
          </div>
          <div className="grid shrink-0 grid-cols-2 gap-2 sm:flex">
            {data?.subjects?.length > 0 && (
              <label className="min-w-0">
                <span className="sr-only">Subject</span>
                <select value={subject} onChange={(e) => set({ subject: e.target.value })} className="input h-10 py-0 text-sm sm:w-44">
                  <option value="">All subjects</option>
                  {data.subjects.map((s) => <option key={s} value={s}>{s}</option>)}
                </select>
              </label>
            )}
            <label className="min-w-0">
              <span className="sr-only">Who can attempt</span>
              <select value={access} onChange={(e) => set({ access: e.target.value })} className="input h-10 py-0 text-sm sm:w-48">
                {ACCESS_FILTERS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </label>
          </div>
        </div>

        {filtered && data && (
          <div className="mb-5 flex flex-wrap items-center gap-2 text-sm text-muted" aria-live="polite">
            <span><strong className="text-navy">{data.pagination?.total ?? data.items.length}</strong> {(data.pagination?.total ?? data.items.length) === 1 ? 'test' : 'tests'} found{q && <> for “<span className="font-semibold text-navy">{q}</span>”</>}</span>
            <button type="button" onClick={() => { setTerm(''); set({ q: '', category: '', subject: '', access: '' }); }} className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold text-primary hover:bg-primary-soft">
              <Icon name="x" className="size-3.5" />Clear filters
            </button>
          </div>
        )}

        {error ? <ErrorState error={error} onRetry={reload} /> : loading && !data ? (
          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" aria-busy="true" aria-label="Loading tests">
            {Array.from({ length: 6 }, (_, i) => <li key={i}><TestCardSkeleton /></li>)}
          </ul>
        ) : data.items.length === 0 ? (
          <EmptyState
            icon={mode === 'practice' ? 'target' : 'clipboard'}
            title={filtered ? 'No tests match your filters' : mode === 'practice' ? 'No practice quizzes yet' : 'No mock tests published yet'}
            text={filtered ? 'Try another category or search term.' : 'New tests are added regularly - check back soon.'}
            action={filtered && <button type="button" onClick={() => { setTerm(''); set({ q: '', category: '', subject: '', access: '' }); }} className="btn-primary">Clear filters</button>}
          />
        ) : (
          <>
            <ul className={'grid gap-5 sm:grid-cols-2 lg:grid-cols-3 ' + (loading ? 'opacity-60 transition-opacity' : '')}>
              {data.items.map((t, i) => (
                <Reveal as="li" key={t.id} delay={(i % 3) * 70} className="min-w-0"><TestCard t={t} /></Reveal>
              ))}
            </ul>
            <Pagination page={page} totalPages={data.pagination?.total_pages || 1} onChange={(p) => { set({ page: String(p) }); window.scrollTo({ top: 0, behavior: 'smooth' }); }} />
          </>
        )}
      </section>
    </>
  );
}

/**
 * Number of published tests per category (for the chips). Uses `count` from the API when
 * present; otherwise asks the list endpoint once per category for its total.
 */
function useCategoryCounts(categories, mode) {
  const [counts, setCounts] = useState(null);
  const slugs = useMemo(() => (categories || []).map((c) => c.slug).join(','), [categories]);
  useEffect(() => {
    if (!categories?.length) return undefined;
    if (categories.every((c) => typeof c.count === 'number')) {
      setCounts(Object.fromEntries(categories.map((c) => [c.slug, c.count])));
      return undefined;
    }
    const ctrl = new AbortController();
    Promise.all(categories.map((c) => api.get('mock-tests.php', { action: 'list', category: c.slug, mode, page: 1 }, { signal: ctrl.signal })
      .then((r) => [c.slug, r.pagination?.total ?? r.items?.length ?? 0])))
      .then((pairs) => setCounts(Object.fromEntries(pairs)))
      .catch(() => { /* chips simply show without counts */ });
    return () => ctrl.abort();
  }, [slugs, mode]); // eslint-disable-line react-hooks/exhaustive-deps
  return counts;
}

const chip = (on) => 'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-4 py-2 text-sm font-semibold transition-colors ' + (on ? 'border-primary bg-primary text-white shadow-glow' : 'border-line bg-white text-ink hover:border-primary hover:text-primary');

function TestCard({ t }) {
  const v = t.viewer;
  const practice = t.mode === 'practice';
  const neg = t.negative_marking ? (t.default_negative_marks > 0 ? `−${fmtNum(t.default_negative_marks)} per wrong` : 'Negative marking') : null;
  const exhausted = v && v.attempts_left === 0;
  const resultLink = v?.last_attempt_id && v.attempts_used > 0 ? `/mock-tests/${t.slug}/result/${v.last_attempt_id}` : null;
  const accent = practice ? 'from-teal to-primary' : 'from-primary to-violet';

  return (
    <article className="card card-hover group relative flex h-full flex-col overflow-hidden rounded-2xl">
      <span aria-hidden="true" className={'h-1.5 bg-gradient-to-r ' + accent} />
      <div className="flex flex-1 flex-col p-5">
        <div className="flex flex-wrap items-center gap-1.5">
          <ModeBadge mode={t.mode} />
          <CategoryPill category={t.category} />
          {t.window !== 'open' && <WindowBadge window={t.window} />}
          {t.is_demo && <DemoBadge />}
        </div>
        <h2 className="mt-3 font-display text-lg leading-snug font-bold text-navy">
          <Link to={`/mock-tests/${t.slug}`} className="after:absolute after:inset-0 hover:text-primary focus-visible:outline-none">{t.title}</Link>
        </h2>
        {t.subject && <p className="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-violet"><Icon name="book" className="size-3.5" />{t.subject}</p>}
        {t.description && <p className="mt-1.5 line-clamp-2 text-sm text-muted">{t.description}</p>}

        <ul className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
          <Stat icon="help" value={t.questions_count ?? t.question_count} label="Questions" />
          <Stat icon="clock" value={t.timed === false || practice ? 'Untimed' : `${t.duration_minutes}m`} label="Duration" />
          <Stat icon="trophy" value={t.marks_vary ? 'Varies' : fmtNum(attemptMarks(t))} label={t.marks_vary ? 'Marks by attempt' : 'Marks'} />
        </ul>

        <div className="mt-3 flex flex-wrap gap-1.5 text-[11px] font-semibold">
          {neg ? <span className="rounded-full bg-coral/10 px-2 py-0.5 text-coral">{neg}</span> : <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">No negative marking</span>}
          <span className="rounded-full bg-surface px-2 py-0.5 text-muted">{ACCESS[t.access_type] || t.access_type}</span>
          {t.max_attempts > 0 && <span className="rounded-full bg-surface px-2 py-0.5 text-muted">{t.max_attempts} {t.max_attempts === 1 ? 'attempt' : 'attempts'}</span>}
          {t.bilingual && <span className="rounded-full bg-primary-soft px-2 py-0.5 text-primary">English + हिंदी</span>}
          {t.sectional_timing && <span className="rounded-full bg-violet/10 px-2 py-0.5 text-violet">Sectional timing</span>}
        </div>

        {(t.window === 'upcoming' || (t.window === 'open' && t.ends_at)) && (
          <p className={'mt-3 inline-flex items-center gap-1.5 text-xs font-medium ' + (t.window === 'upcoming' ? 'text-amber-700' : 'text-muted')}>
            <Icon name="calendar" className="size-3.5" />
            {t.window === 'upcoming' ? `Opens ${formatDateTime(t.starts_at)}` : `Closes ${formatDateTime(t.ends_at)}`}
          </p>
        )}

        {v && v.attempts_used > 0 && (
          <dl className="mt-4 grid grid-cols-2 gap-2 rounded-xl bg-surface p-3 text-xs">
            <div><dt className="text-muted">Attempts used</dt><dd className="font-bold text-navy">{v.attempts_used}{v.attempts_left !== null && v.attempts_left !== undefined ? ` / ${v.attempts_used + v.attempts_left}` : ''}</dd></div>
            <div><dt className="text-muted">Best score</dt><dd className="font-bold text-navy">{v.best_score !== null && v.best_score !== undefined ? `${fmtNum(v.best_score)} / ${fmtNum(v.best_score_out_of ?? t.marks_per_attempt ?? t.total_marks)}` : 'Result pending'}</dd></div>
          </dl>
        )}

        {/* Actions sit above the stretched title link */}
        <div className="relative z-10 mt-auto flex gap-2 pt-5">
          {v?.in_progress_attempt_id ? (
            <Link to={`/mock-tests/${t.slug}/attempt/${v.in_progress_attempt_id}`} className="btn-gold flex-1"><Icon name="playCircle" className="size-4" />Resume</Link>
          ) : t.window === 'upcoming' ? (
            <button type="button" disabled className="btn flex-1 cursor-not-allowed border border-amber-200 bg-amber-50 text-amber-800" aria-disabled="true">
              <Icon name="lock" className="size-4" />Opens {formatDate(t.starts_at)}
            </button>
          ) : t.window === 'closed' ? (
            <>
              {resultLink && <Link to={resultLink} className="btn-primary flex-1">View result</Link>}
              <Link to={`/mock-tests/${t.slug}`} className={(resultLink ? 'btn-outline' : 'btn-outline flex-1') + ' px-4'}>Details</Link>
            </>
          ) : v?.attempts_used > 0 ? (
            <>
              {resultLink && <Link to={resultLink} className={exhausted ? 'btn-primary flex-1' : 'btn-outline flex-1'}>View result</Link>}
              {!exhausted && <Link to={`/mock-tests/${t.slug}`} className="btn-primary flex-1"><Icon name="refresh" className="size-4" />Retake</Link>}
            </>
          ) : (
            <Link to={`/mock-tests/${t.slug}`} className="btn-primary flex-1">{practice ? 'Start Practice' : 'Attempt Now'}<Icon name="arrowRight" className="size-4" /></Link>
          )}
        </div>
      </div>
    </article>
  );
}

function Stat({ icon, value, label }) {
  return (
    <li className="rounded-xl bg-surface px-2 py-2.5">
      <Icon name={icon} className="mx-auto size-4 text-primary" />
      <span className="mt-1 block font-bold text-navy">{value}</span>
      <span className="text-muted">{label}</span>
    </li>
  );
}

function TestCardSkeleton() {
  return (
    <div className="card overflow-hidden rounded-2xl" aria-hidden="true">
      <Skeleton className="h-1.5" rounded="rounded-none" />
      <div className="space-y-3 p-5">
        <div className="flex gap-2"><Skeleton className="h-5 w-24" rounded="rounded-full" /><Skeleton className="h-5 w-14" /></div>
        <Skeleton className="h-5 w-4/5" />
        <Skeleton className="h-3 w-full" />
        <div className="grid grid-cols-3 gap-2"><Skeleton className="h-16" rounded="rounded-xl" /><Skeleton className="h-16" rounded="rounded-xl" /><Skeleton className="h-16" rounded="rounded-xl" /></div>
        <div className="flex gap-2"><Skeleton className="h-4 w-28" rounded="rounded-full" /><Skeleton className="h-4 w-20" rounded="rounded-full" /></div>
        <Skeleton className="h-11" rounded="rounded-xl" />
      </div>
    </div>
  );
}
