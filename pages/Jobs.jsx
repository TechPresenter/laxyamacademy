// Govt job alerts (/jobs): recruitment notices, admit cards, results, answer keys and syllabus,
// each linking to its detail page (/jobs/:slug) and the official website.
import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CategoryPill, DemoBadge, EmptyState, ErrorState, Icon, MicButton, PageHeader, Pagination, Reveal, Skeleton, useApi, useSeo, useSite } from '../js/components.jsx';
import { DeadlineChip, JOB_TYPE, JOB_TYPES, JobTypeBadge, NewBadge, formatDay, postedLabel } from '../js/content/shared.jsx';

export default function Jobs() {
  useSeo({ title: 'Latest Govt Jobs & Exam Notifications | Laxyam Academy', description: 'Latest UPSC, SSC, Banking, Railway and State PSC recruitment notifications, admit cards, results, answer keys and syllabus with links to official websites.' });
  const { data: site } = useSite();
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState(params.get('q') || '');
  const category = params.get('category') || '';
  const type = JOB_TYPE[params.get('type')] ? params.get('type') : '';
  const page = Number(params.get('page') || 1);
  const hideClosed = params.get('active') === '1'; // jobs.php active=1 drops alerts whose last date has passed
  const { data, loading, error, reload } = useApi('jobs.php', { action: 'alerts', category, type, q: params.get('q') || '', active: hideClosed ? 1 : '', page });
  useEffect(() => setQ(params.get('q') || ''), [params]);
  const set = (c) => {
    const n = new URLSearchParams(params);
    Object.entries(c).forEach(([k, v]) => (v ? n.set(k, v) : n.delete(k)));
    if (!('page' in c)) n.delete('page');
    setParams(n);
  };

  // Per-type counts: from the list response when the API sends them, else from the layout jobs menu
  const counts = data?.counts || site?.jobs_menu?.counts || null;
  const total = counts ? JOB_TYPES.reduce((s, t) => s + (Number(counts[t.key]) || 0), 0) : null;
  const activeCategory = site?.categories?.find((c) => c.slug === category);
  const filtered = !!(type || category || params.get('q') || hideClosed);

  return (
    <>
      <PageHeader
        eyebrow={<><Icon name="bell" className="size-3.5" />Sarkari exam updates</>}
        title="Latest Govt Jobs &"
        highlight="Notifications"
        subtitle="Recruitment notices, admit cards, results, answer keys and syllabus - with direct links to the official websites."
        crumbs={[{ to: '/', label: 'Home' }, { label: 'Govt Jobs' }]}
      >
        <form role="search" onSubmit={(e) => { e.preventDefault(); set({ q: q.trim() }); }} className="mt-7 flex max-w-2xl flex-col gap-2 sm:flex-row">
          <div className="flex min-w-0 flex-1 items-center rounded-2xl border border-line bg-white pr-1.5 shadow-card transition focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15">
            <Icon name="search" className="ml-4 size-5 shrink-0 text-muted" />
            <label htmlFor="job-q" className="sr-only">Search job alerts</label>
            <input id="job-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search exam or organisation" className="min-w-0 flex-1 bg-transparent px-3 py-3.5 text-sm focus:outline-none" />
            <MicButton compact className="sm:hidden" onText={(t, final) => { setQ(t); if (final) set({ q: t }); }} />
            <MicButton className="hidden sm:flex" onText={(t, final) => { setQ(t); if (final) set({ q: t }); }} />
          </div>
          <button className="btn-primary btn-lg">Search</button>
        </form>
      </PageHeader>

      <section className="container-x py-8 sm:py-10">
        {/* Type tabs (scrollable with fade edge on phones) + exam category */}
        {/* Tabs get their own row (all six fit from lg up); exam filter + "Hide closed" sit beside them only on very wide screens */}
        <div className="mb-6 flex flex-col gap-3 2xl:flex-row 2xl:items-center">
          <div className="relative min-w-0 flex-1">
            <div className="scrollbar-none -mx-4 flex snap-x gap-2 overflow-x-auto px-4 pb-1 [mask-image:linear-gradient(to_right,black_85%,transparent)] sm:mx-0 sm:px-0 lg:[mask-image:none]" role="group" aria-label="Filter by update type">
              <TypeTab active={!type} onClick={() => set({ type: '' })} label="All updates" count={total} icon="layers" on="bg-navy text-white ring-navy" />
              {JOB_TYPES.map((t) => (
                <TypeTab key={t.key} active={type === t.key} onClick={() => set({ type: t.key })} label={t.plural} count={counts ? Number(counts[t.key]) || 0 : null} icon={t.icon} on={t.on} dot={t.dot} />
              ))}
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <label htmlFor="job-cat" className="text-sm font-medium text-muted">Exam</label>
            <select id="job-cat" value={category} onChange={(e) => set({ category: e.target.value })} className="input min-w-0 flex-1 py-2.5 sm:w-56 sm:flex-none">
              <option value="">All exams</option>
              {site?.categories?.map((c) => <option key={c.slug} value={c.slug}>{c.name}</option>)}
            </select>
            <label className="inline-flex shrink-0 cursor-pointer items-center gap-2 rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-medium text-ink transition hover:border-primary/40 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-primary/20">
              <input type="checkbox" checked={hideClosed} onChange={(e) => set({ active: e.target.checked ? '1' : '' })} className="size-4 accent-[var(--color-primary)]" />
              Hide closed
            </label>
          </div>
        </div>

        <p className="mb-6 flex items-start gap-2.5 rounded-2xl border border-amber-200 bg-amber-50/80 p-3.5 text-sm text-amber-900">
          <Icon name="shield" className="mt-0.5 size-4 shrink-0" />
          <span>Always read the official notification before applying. Laxyam Academy is not a recruiting body and never collects application fees. <Link to="/disclaimer" className="font-semibold underline underline-offset-2">Disclaimer</Link></span>
        </p>

        {error ? <ErrorState error={error} onRetry={reload} /> : loading && !data ? (
          <ul className="space-y-4" aria-busy="true" aria-label="Loading job alerts">
            {[0, 1, 2, 3].map((i) => <li key={i} className="card space-y-3 p-5"><Skeleton className="h-5 w-40" /><Skeleton className="h-6 w-3/4" /><Skeleton className="h-4 w-1/2" /><Skeleton className="h-4 w-2/3" /></li>)}
          </ul>
        ) : data.items.length === 0 ? (
          <EmptyState
            icon="bell"
            title={filtered ? 'No updates match your filters' : 'No job alerts right now'}
            text={filtered ? 'Try another type, exam or search term.' : 'New recruitment notices, admit cards and results appear here as soon as they are published.'}
            action={filtered ? <button type="button" onClick={() => setParams({})} className="btn-outline">Clear filters</button> : <NewsletterJump />}
          />
        ) : (
          <>
            <p className="mb-4 text-sm text-muted" aria-live="polite">
              {data.pagination?.total ?? data.items.length} update{(data.pagination?.total ?? data.items.length) === 1 ? '' : 's'}
              {type && <> in <strong className="text-ink">{JOB_TYPE[type].plural}</strong></>}
              {activeCategory && <> for <strong className="text-ink">{activeCategory.name}</strong></>}
              {params.get('q') && <> matching “<strong className="text-ink">{params.get('q')}</strong>”</>}
              {hideClosed && <> · closed alerts hidden</>}
            </p>
            <ul className="space-y-4">
              {data.items.map((a, i) => <JobCard key={a.id} a={a} delay={Math.min(i, 5) * 50} />)}
            </ul>
            <Pagination page={page} totalPages={data.pagination?.total_pages || 1} onChange={(p) => { set({ page: String(p) }); window.scrollTo({ top: 0, behavior: 'smooth' }); }} />
          </>
        )}

        <PrepareCta category={activeCategory} />
      </section>
    </>
  );
}

function TypeTab({ active, onClick, label, count, icon, on, dot }) {
  return (
    <button type="button" onClick={onClick} aria-pressed={active}
      className={'inline-flex shrink-0 snap-start items-center gap-2 rounded-full px-4 py-2.5 text-sm font-semibold ring-1 transition-all duration-200 ring-inset focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary/30 ' + (active ? on + ' shadow-md' : 'bg-white text-ink ring-line hover:-translate-y-0.5 hover:ring-primary/40')}>
      {dot && !active ? <span aria-hidden="true" className={'size-2 rounded-full ' + dot} /> : <Icon name={icon} className="size-4" />}
      {label}
      {count !== null && count !== undefined && (
        <span className={'min-w-6 rounded-full px-1.5 py-0.5 text-center text-[11px] font-bold ' + (active ? 'bg-white/25 text-current' : 'bg-surface text-muted')}>{count}</span>
      )}
    </button>
  );
}

/** One alert: colour-coded type, NEW / closing-soon chips, key facts, detail + official links. */
export function JobCard({ a, delay = 0 }) {
  const t = JOB_TYPE[a.type] || JOB_TYPE.notification;
  const posted = postedLabel(a.published_at);
  // is_new comes from the API; older API versions only send published_at (NEW = posted within 7 days)
  const isNew = a.is_new ?? (!!a.published_at && Date.now() - new Date(a.published_at).getTime() < 7 * 86400000);
  return (
    <Reveal as="li" delay={delay} className="card card-hover group relative overflow-hidden">
      <span aria-hidden="true" className={'absolute inset-y-0 left-0 w-1.5 ' + t.dot} />
      <div className="flex flex-col gap-4 p-5 pl-6 md:flex-row md:items-center md:gap-6">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <JobTypeBadge type={a.type} label={a.type_label} />
            {isNew && <NewBadge />}
            <DeadlineChip lastDate={a.last_date} closed={a.closed} closingSoon={a.closing_soon} daysLeft={a.days_left} />
            <CategoryPill category={a.category} />
            {a.is_demo && <DemoBadge />}
          </div>
          <h2 className="mt-2.5 font-display text-lg leading-snug font-bold text-navy">
            {a.slug ? <Link to={`/jobs/${a.slug}`} className="after:absolute after:inset-0 hover:text-primary focus-visible:outline-none">{a.title}</Link> : a.title}
          </h2>
          {a.organization && <p className="mt-1 flex items-center gap-1.5 text-sm font-medium text-ink"><Icon name="landmark" className="size-4 shrink-0 text-muted" />{a.organization}</p>}
          {a.summary && <p className="mt-1.5 line-clamp-2 text-sm text-muted">{a.summary}</p>}
          <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1.5 text-sm">
            {a.vacancies && <Fact label="Posts" value={a.vacancies} />}
            {a.qualification && <Fact label="Eligibility" value={a.qualification} />}
            {a.apply_start && <Fact label="Apply from" value={formatDay(a.apply_start)} />}
            {a.last_date && <Fact label="Last date" value={formatDay(a.last_date)} className={a.closed ? 'text-muted line-through' : a.closing_soon ? 'text-red-600' : ''} />}
            {posted && <Fact label="Posted" value={posted} />}
          </dl>
        </div>
        <div className="relative z-10 flex shrink-0 flex-col gap-2 sm:flex-row md:w-48 md:flex-col">
          {a.slug && <Link to={`/jobs/${a.slug}`} className="btn-primary flex-1">View details <Icon name="arrowRight" className="size-4" /></Link>}
          {a.official_url && <a href={a.official_url} target="_blank" rel="noopener noreferrer" className="btn-light flex-1">Official link <Icon name="externalLink" className="size-4" /></a>}
        </div>
      </div>
    </Reveal>
  );
}

function Fact({ label, value, className = '' }) {
  return <div className="min-w-0"><dt className="inline text-muted">{label}: </dt><dd className={'inline font-semibold ' + (className || 'text-ink')}>{value}</dd></div>;
}

/** Scrolls to the footer newsletter field when the footer renders one. */
function NewsletterJump() {
  const [has, setHas] = useState(false);
  useEffect(() => setHas(!!document.getElementById('nl-email')), []);
  if (!has) return <Link to="/courses" className="btn-outline">Explore courses</Link>;
  const go = () => {
    const el = document.getElementById('nl-email');
    el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el?.focus({ preventScroll: true });
  };
  return <button type="button" onClick={go} className="btn-primary"><Icon name="mail" className="size-4" />Get alerts by email</button>;
}

/** "Prepare with Laxyam" band shown under job lists and on alert pages. */
export function PrepareCta({ category, title }) {
  const courses = category?.slug ? `/courses?category=${encodeURIComponent(category.slug)}` : '/courses';
  return (
    <Reveal className="relative isolate mt-12 overflow-hidden rounded-3xl bg-navy-mesh p-6 text-white shadow-card-hover sm:p-10">
      <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40 [mask-image:linear-gradient(to_left,black,transparent_70%)]" />
      <div aria-hidden="true" className="absolute -top-16 -right-10 -z-10 size-56 rounded-full bg-gold/25 blur-3xl" />
      <div className="grid items-center gap-6 lg:grid-cols-[1fr_auto]">
        <div className="min-w-0">
          <p className="font-script text-2xl text-gold">Don’t just apply - get selected</p>
          <h2 className="mt-1 font-display text-2xl font-extrabold sm:text-3xl">{title || <>Prepare with Laxyam{category?.name ? <> for <span className="text-gradient-gold">{category.name}</span></> : ''}</>}</h2>
          <p className="mt-2 max-w-2xl text-sm text-white/80 sm:text-base">Structured courses, live classes and timed mock tests built around the latest exam pattern.</p>
        </div>
        <div className="flex flex-col gap-3 sm:flex-row">
          <Link to={courses} className="btn-gold btn-lg">{category?.name ? `${category.name} courses` : 'Explore courses'} <Icon name="arrowRight" className="size-4" /></Link>
          <Link to="/mock-tests" className="btn-ghost-light btn-lg">Free mock tests</Link>
        </div>
      </div>
    </Reveal>
  );
}
