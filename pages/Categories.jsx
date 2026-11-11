// Exam categories: top-level categories with their subcategories, course counts and a quick filter.
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { EmptyState, ErrorState, Icon, PageHeader, Reveal, Skeleton, useApi, useSeo } from '../js/components.jsx';

const courses = (n) => `${n} ${n === 1 ? 'course' : 'courses'}`;

export default function Categories() {
  useSeo({ title: 'Exam Categories - Laxyam Academy', description: 'Browse courses by exam: Government Job Foundation, Railway, SSC, Banking, Police, State Government exams and subject foundation courses.' });
  const { data, loading, error, reload } = useApi('courses.php', { action: 'categories' });
  const [q, setQ] = useState('');

  // Only categories with published courses (like the menus); empty ones stay for mock tests and job alerts.
  const groups = useMemo(() => {
    const all = (Array.isArray(data) ? data : []).filter((c) => Number(c.course_count) > 0);
    return all.filter((c) => !c.parent_id).map((c) => ({ ...c, children: all.filter((s) => s.parent_id === c.id) }));
  }, [data]);

  const term = q.trim().toLowerCase();
  const shown = term
    ? groups.filter((c) => [c.name, c.short_name, c.description, ...c.children.map((s) => s.name)].some((t) => String(t || '').toLowerCase().includes(term)))
    : groups;
  const total = groups.reduce((n, c) => n + Number(c.course_count || 0), 0);

  return (
    <>
      <PageHeader eyebrow={<><Icon name="target" className="size-3.5" />Exams</>} title="Exam" highlight="Categories"
        subtitle="Choose your target exam to see the matching courses, mock tests and study material."
        crumbs={[{ to: '/', label: 'Home' }, { label: 'Categories' }]}>
        <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center">
          <div className="relative w-full max-w-md min-w-0">
            <Icon name="search" className="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-muted" />
            <label htmlFor="cat-filter" className="sr-only">Filter exam categories</label>
            <input id="cat-filter" type="search" value={q} onChange={(e) => setQ(e.target.value)} maxLength={60} autoComplete="off"
              placeholder="Filter exams, e.g. SSC, Banking…" className="input h-12 pl-11 shadow-card" />
          </div>
          {!loading && !error && groups.length > 0 && (
            <p className="text-sm font-medium text-muted">{groups.length} exam categories · {courses(total)}</p>
          )}
        </div>
      </PageHeader>

      <section className="container-x py-10 sm:py-14">
        {error ? <ErrorState error={error} onRetry={reload} /> : loading ? (
          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" aria-hidden="true">
            {Array.from({ length: 6 }, (_, i) => (
              <li key={i} className="card space-y-4 p-6"><Skeleton className="size-14" rounded="rounded-2xl" /><Skeleton className="h-5 w-1/2" /><Skeleton className="h-3 w-full" /><Skeleton className="h-3 w-2/3" /></li>
            ))}
          </ul>
        ) : !shown.length ? (
          <EmptyState icon="search" title={term ? `No exam matches “${q.trim()}”` : 'No exam categories yet'}
            text={term ? 'Try a shorter word or browse all courses.' : 'Categories appear here as soon as courses are published.'}
            action={term ? <button type="button" onClick={() => setQ('')} className="btn-light">Clear filter</button> : <Link to="/courses" className="btn-primary">Browse courses</Link>} />
        ) : (
          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {shown.map((c, i) => <CategoryCard key={c.slug} c={c} delay={(i % 3) * 70} />)}
          </ul>
        )}
      </section>
    </>
  );
}

function CategoryCard({ c, delay }) {
  const color = c.color || '#0B63F6';
  const n = Number(c.course_count || 0);
  const url = `/courses?category=${encodeURIComponent(c.slug)}`;
  return (
    <Reveal as="li" delay={delay} className="min-w-0">
      <article className="group relative flex h-full flex-col overflow-hidden rounded-2xl border border-line bg-white p-6 shadow-card transition-all duration-300 hover:-translate-y-1 hover:shadow-card-hover">
        <span aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 h-1" style={{ background: `linear-gradient(90deg, ${color}, color-mix(in srgb, ${color} 40%, #6D4AFF))` }} />
        <span aria-hidden="true" className="pointer-events-none absolute -top-16 -right-16 size-40 rounded-full opacity-[0.07] transition-opacity duration-500 group-hover:opacity-20" style={{ backgroundColor: color }} />
        <div className="flex items-start gap-4">
          <span className="grid size-14 shrink-0 place-items-center rounded-2xl text-white shadow-md transition-transform duration-300 group-hover:scale-105 group-hover:-rotate-3"
            style={{ background: `linear-gradient(135deg, ${color}, color-mix(in srgb, ${color} 55%, #08264D))` }}>
            <Icon name={c.icon} className="size-7" />
          </span>
          <div className="min-w-0 flex-1">
            <h2 className="font-display text-xl font-extrabold text-navy">
              {/* Stretched link: the whole card opens the category; subcategory chips sit above it */}
              <Link to={url} className="after:absolute after:inset-0 after:content-[''] focus-visible:outline-none group-hover:text-primary">{c.name}</Link>
            </h2>
            <span className={'mt-1.5 inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold ' + (n ? '' : 'bg-surface text-muted')} style={n ? { backgroundColor: color + '1A', color } : undefined}>
              {n ? courses(n) : 'Coming soon'}
            </span>
          </div>
        </div>
        {c.description && <p className="mt-4 text-sm leading-relaxed text-muted">{c.description}</p>}
        {c.children.length > 0 && (
          <ul className="relative z-10 mt-4 flex flex-wrap gap-1.5" aria-label={`${c.name} sub-categories`}>
            {c.children.map((s) => (
              <li key={s.slug}>
                <Link to={`/courses?category=${encodeURIComponent(s.slug)}`}
                  className="inline-flex items-center gap-1 rounded-full border border-line bg-surface px-2.5 py-1 text-xs font-semibold text-ink transition-colors hover:border-primary hover:bg-primary-soft hover:text-primary">
                  {s.name}{Number(s.course_count) > 0 && <span className="text-muted">· {s.course_count}</span>}
                </Link>
              </li>
            ))}
          </ul>
        )}
        <span aria-hidden="true" className="mt-auto inline-flex items-center gap-1.5 pt-5 text-sm font-bold text-primary">
          View {n ? 'courses' : 'category'} <Icon name="arrowRight" className="size-4 transition-transform group-hover:translate-x-1" />
        </span>
        {/* Visible focus ring for the stretched link */}
        <span aria-hidden="true" className="pointer-events-none absolute inset-0 rounded-2xl ring-primary group-has-[a:focus-visible]:ring-2" />
      </article>
    </Reveal>
  );
}
