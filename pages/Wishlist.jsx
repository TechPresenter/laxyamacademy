import { useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../js/auth.jsx';
import { CourseCard, CourseCardSkeleton, EmptyState, ErrorState, Icon, Reveal, useApi } from '../js/components.jsx';
import { DashHeader } from '../js/course/ui.jsx';

export default function Wishlist() {
  const { summary } = useAuth();
  // GET students.php?action=wishlist → published course cards (format_course_card), newest first
  const { data, loading, error, reload } = useApi('students.php', { action: 'wishlist' });
  const saved = summary?.wishlist || [];
  // Ids seen in the header summary at some point: only those can have been "un-hearted" since. This keeps the
  // list visible when the page loads before the summary does (direct visit), instead of flashing an empty state.
  const seen = useRef(new Set());
  saved.forEach((id) => seen.current.add(id));
  // Removing a heart elsewhere should drop the card here too; a new heart elsewhere reloads the list.
  useEffect(() => {
    if (data && saved.some((id) => !data.some((c) => c.id === id))) reload();
  }, [saved.join(',')]); // eslint-disable-line react-hooks/exhaustive-deps
  const items = (data || []).filter((c) => saved.includes(c.id) || !seen.current.has(c.id));
  const popular = useApi(data && items.length === 0 ? 'courses.php' : null, { action: 'list', sort: 'popular', per_page: 3 });

  if (error) return <ErrorState error={error} onRetry={reload} />;

  return (
    <>
      <DashHeader icon="heart" title="Wishlist"
        subtitle={data ? (items.length ? `${items.length} saved ${items.length === 1 ? 'course' : 'courses'} - enrol when you are ready.` : 'Courses you save appear here.') : 'Courses you have saved for later.'}
        action={<Link to="/courses" className="btn-light btn-sm"><Icon name="search" className="size-3.5" />Browse courses</Link>} />
      {loading && !data ? (
        <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">{Array.from({ length: 3 }, (_, i) => <li key={i}><CourseCardSkeleton /></li>)}</ul>
      ) : items.length === 0 ? (
        <div className="space-y-8">
          <EmptyState icon="heart" title="Your wishlist is empty" text="Tap the heart on any course to save it here and compare later."
            action={<Link to="/courses" className="btn-primary">Explore courses<Icon name="arrowRight" className="size-4" /></Link>} />
          {popular.data?.items?.length > 0 && (
            <div>
              <h2 className="mb-4 font-display text-lg font-bold text-navy">Popular with students</h2>
              <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">{popular.data.items.map((c) => <li key={c.id} className="min-w-0"><CourseCard course={c} compact /></li>)}</ul>
            </div>
          )}
        </div>
      ) : (
        <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {items.map((c, i) => <Reveal as="li" key={c.id} delay={(i % 3) * 60} className="min-w-0"><CourseCard course={c} /></Reveal>)}
        </ul>
      )}
    </>
  );
}
