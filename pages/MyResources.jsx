import { useState } from 'react';
import { Link } from 'react-router-dom';
import { EmptyState, ErrorState, Icon, Skeleton, useApi } from '../js/components.jsx';
import { DashTitle } from '../js/account/ui.jsx';
import { ResourceCard } from './Resources.jsx';

export default function MyResources() {
  const { data, loading, error, reload } = useApi('resources.php', { action: 'mine' });
  const [q, setQ] = useState('');
  if (error && !data) return <ErrorState error={error} onRetry={reload} />;

  const term = q.trim().toLowerCase();
  const match = (r) => !term || [r.title, r.description, r.category?.name].some((s) => String(s || '').toLowerCase().includes(term));
  const course = (data?.course_resources || []).filter(match);
  const recent = (data?.recent_downloads || []).filter(match);
  const total = (data?.course_resources?.length || 0) + (data?.recent_downloads?.length || 0);

  return (
    <>
      <DashTitle title="My Resources" icon="file" subtitle="Notes, PDFs and material from your enrolled courses, plus what you opened recently."
        action={<Link to="/resources" className="btn-light"><Icon name="book" className="size-4" /> Resource library</Link>} />

      {total > 0 && (
        <div className="relative mb-6 max-w-md">
          <label htmlFor="my-res-q" className="sr-only">Search my resources</label>
          <Icon name="search" className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" />
          <input id="my-res-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search my resources…" className="input pl-10" />
        </div>
      )}

      {loading && !data ? (
        <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">{[0, 1, 2].map((i) => <li key={i}><Skeleton className="h-72" rounded="rounded-2xl" /></li>)}</ul>
      ) : (
        <div className="space-y-8">
          <section aria-labelledby="res-course">
            <h2 id="res-course" className="mb-4 flex items-center gap-2 font-display text-lg font-bold text-navy">
              <Icon name="layers" className="size-5 text-primary" /> From your courses
              {course.length > 0 && <span className="rounded-full bg-primary-soft px-2 py-0.5 text-xs text-primary">{course.length}</span>}
            </h2>
            {course.length ? (
              <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">{course.map((r, i) => <ResourceCard key={r.id} r={r} delay={(i % 3) * 60} />)}</ul>
            ) : term ? <p className="text-sm text-muted">No course resources match “{q}”.</p> : (
              <EmptyState icon="book" title="No course resources yet" text="Material attached to your enrolled courses will appear here."
                action={<Link to="/resources" className="btn-primary">Explore free resources</Link>} />
            )}
          </section>
          {(recent.length > 0 || (term && data?.recent_downloads?.length > 0)) && (
            <section aria-labelledby="res-recent">
              <h2 id="res-recent" className="mb-4 flex items-center gap-2 font-display text-lg font-bold text-navy"><Icon name="history" className="size-5 text-primary" /> Recently accessed</h2>
              {recent.length ? <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">{recent.map((r, i) => <ResourceCard key={r.id} r={r} delay={(i % 3) * 60} />)}</ul>
                : <p className="text-sm text-muted">No recent items match “{q}”.</p>}
            </section>
          )}
        </div>
      )}
    </>
  );
}
