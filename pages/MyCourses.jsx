import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiUrl, formatDate } from '../js/api.js';
import { CategoryPill, CourseThumb, EmptyState, ErrorState, Icon, Reveal, Skeleton, useApi } from '../js/components.jsx';
import { DashHeader, ProgressBar, ProgressRing } from '../js/course/ui.jsx';

const isCompleted = (c) => c.enrolment.status !== 'expired' && (!!c.enrolment.completed_at || c.enrolment.progress >= 100);
const TABS = [
  ['progress', 'In progress', (c) => c.enrolment.status !== 'expired' && !isCompleted(c), 'playCircle'],
  ['completed', 'Completed', isCompleted, 'trophy'],
  ['expired', 'Expired', (c) => c.enrolment.status === 'expired', 'history'],
];

export default function MyCourses() {
  const { data, loading, error, reload } = useApi('students.php', { action: 'courses' });
  const certs = useApi('students.php', { action: 'certificates' });
  const [tab, setTab] = useState(null);
  const [q, setQ] = useState('');

  const groups = useMemo(() => Object.fromEntries(TABS.map(([key, , test]) => [key, (data || []).filter(test)])), [data]);
  const certBySlug = useMemo(() => Object.fromEntries((certs.data || []).map((c) => [c.slug, c])), [certs.data]);

  if (error) return <ErrorState error={error} onRetry={reload} />;

  // Default to the first tab that has courses
  const active = tab || TABS.find(([key]) => groups[key]?.length)?.[0] || 'progress';
  const needle = q.trim().toLowerCase();
  const shown = (groups[active] || []).filter((c) => !needle || c.title.toLowerCase().includes(needle) || c.category?.name?.toLowerCase().includes(needle));

  return (
    <>
      <DashHeader icon="book" title="My courses" subtitle="Pick up where you left off, revisit completed courses or renew access."
        action={<Link to="/courses" className="btn-light btn-sm"><Icon name="search" className="size-3.5" />Browse courses</Link>} />

      {loading && !data ? (
        <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 3 }, (_, i) => <li key={i}><Skeleton className="h-80" rounded="rounded-2xl" /></li>)}</ul>
      ) : data.length === 0 ? (
        <EmptyState icon="book" title="You have not enrolled in a course yet" text="Explore structured courses for your target exam - many include free preview lessons."
          action={<Link to="/courses" className="btn-primary">Explore courses<Icon name="arrowRight" className="size-4" /></Link>} />
      ) : (
        <>
          <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div role="tablist" aria-label="Course status" className="scrollbar-none flex max-w-full gap-1 overflow-x-auto rounded-2xl border border-line bg-white p-1">
              {TABS.map(([key, label, , icon]) => (
                <button key={key} type="button" role="tab" id={`tab-${key}`} aria-selected={active === key} aria-controls="mc-panel" onClick={() => setTab(key)}
                  className={'flex shrink-0 items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition-all ' + (active === key ? 'bg-primary text-white shadow-[0_6px_18px_-8px_rgb(11_99_246/0.8)]' : 'text-ink hover:bg-surface')}>
                  <Icon name={icon} className="size-4" />{label}
                  <span className={'rounded-full px-2 py-px text-xs ' + (active === key ? 'bg-white/20' : 'bg-surface text-muted')}>{groups[key].length}</span>
                </button>
              ))}
            </div>
            {data.length > 3 && (
              <div className="relative w-full min-w-0 sm:w-64">
                <label htmlFor="mc-q" className="sr-only">Search my courses</label>
                <Icon name="search" className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
                <input id="mc-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search my courses" className="input pl-9" />
              </div>
            )}
          </div>

          <div id="mc-panel" role="tabpanel" aria-labelledby={`tab-${active}`}>
            {shown.length === 0 ? (
              <EmptyState icon={TABS.find((t) => t[0] === active)[3]} title={needle ? 'No courses match your search' : EMPTY[active][0]} text={needle ? 'Try a different word.' : EMPTY[active][1]} />
            ) : (
              <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                {shown.map((c, i) => <Reveal as="li" key={c.id} delay={(i % 3) * 60} className="min-w-0"><MyCourseCard course={c} cert={certBySlug[c.slug]} /></Reveal>)}
              </ul>
            )}
          </div>
        </>
      )}
    </>
  );
}

const EMPTY = {
  progress: ['Nothing in progress', 'Courses you are studying will show up here.'],
  completed: ['No completed courses yet', 'Finish every lesson of a course to see it here.'],
  expired: ['No expired courses', 'Courses whose access period has ended appear here for renewal.'],
};

function MyCourseCard({ course, cert }) {
  const e = course.enrolment;
  const expired = e.status === 'expired';
  const done = isCompleted(course);
  const target = `/learn/${course.slug}${e.next_lesson_id ? `/${e.next_lesson_id}` : ''}`;
  return (
    <article className="group card card-hover flex h-full flex-col overflow-hidden">
      <Link to={expired ? `/courses/${course.slug}` : target} tabIndex={-1} aria-hidden="true" className="relative block overflow-hidden">
        <CourseThumb course={course} showBadge={false} className="aspect-[16/8]" imgClassName="transition-transform duration-700 group-hover:scale-[1.05]" />
        {done && <span className="absolute top-3 left-3 flex items-center gap-1 rounded-full bg-emerald-600 px-2.5 py-1 text-[11px] font-bold text-white shadow"><Icon name="trophy" className="size-3.5" />Completed</span>}
        {expired && <span className="absolute top-3 left-3 rounded-full bg-navy px-2.5 py-1 text-[11px] font-bold text-white shadow">Access expired</span>}
      </Link>
      <div className="flex flex-1 flex-col p-4 sm:p-5">
        <div className="flex items-start gap-3">
          <div className="min-w-0 flex-1">
            <CategoryPill category={course.category} />
            <h3 className="mt-1.5 line-clamp-2 leading-snug font-bold text-navy"><Link to={`/courses/${course.slug}`} className="hover:text-primary">{course.title}</Link></h3>
          </div>
          <ProgressRing value={e.progress} size={52} stroke={5} tone={done ? 'text-emerald-500' : 'text-primary'} />
        </div>
        <ProgressBar value={e.progress} className="mt-3" tone={done ? 'from-emerald-500 to-teal' : 'from-primary to-violet'} />
        <p className="mt-2 text-xs text-muted">
          {expired ? `Access ended ${formatDate(e.expires_at)}` : done && e.completed_at ? `Completed on ${formatDate(e.completed_at)}` : e.expires_at ? `Access until ${formatDate(e.expires_at)}` : 'Lifetime access'}
          {e.reference ? ` · ${e.reference}` : ''}
        </p>
        <div className="mt-auto grid gap-2 pt-4">
          {expired ? (
            <Link to={course.is_free ? `/courses/${course.slug}` : `/checkout/${course.slug}`} className="btn-gold w-full"><Icon name="refresh" className="size-4" />Renew access</Link>
          ) : done ? (
            <>
              {cert ? (
                <a href={apiUrl('students.php', { action: 'certificate', no: cert.certificate_no })} target="_blank" rel="noopener noreferrer" className="btn-primary w-full"><Icon name="badge" className="size-4" />View certificate</a>
              ) : (
                <Link to="/dashboard/certificates" className="btn-light w-full"><Icon name="badge" className="size-4" />Certificate status</Link>
              )}
              <Link to={`/learn/${course.slug}`} className="btn-light w-full">Revisit lessons</Link>
            </>
          ) : (
            <Link to={target} className="btn-primary w-full"><Icon name="playCircle" className="size-4" />{e.progress > 0 ? 'Continue learning' : 'Start learning'}</Link>
          )}
        </div>
      </div>
    </article>
  );
}
