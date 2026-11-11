import { useEffect, useMemo, useState } from 'react';
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { api, formatDate, formatDateTime, formatPrice } from '../js/api.js';
import {
  Alert, CourseCard, CourseThumb, DemoBadge, EnquiryForm, ErrorState, Icon, Price, Reveal, ShareButtons, Skeleton, Stars, WishlistButton,
  courseTypeLabel, discountPercent, formatOfferDate, languageLabel, levelLabel, useApi, useSeo,
} from '../js/components.jsx';
import { CourseVideos, LESSON_ICON, ProgressBar, PromoBanners, formatMinutes, lessonTypeLabel, plural, validityLabel } from '../js/course/ui.jsx';
import NotFound from './NotFound.jsx';

export default function CourseDetails() {
  const { slug } = useParams();
  // Admin "Preview as student" opens /courses/<slug>?as=student: the API then computes the lock state of a
  // non-enrolled student for staff (ignored for everyone else). It is passed on to the player links too.
  const [search] = useSearchParams();
  const asStudent = search.get('as') === 'student';
  const { data, loading, error, reload } = useApi('courses.php', { action: 'detail', slug, ...(asStudent ? { as: 'student' } : {}) });
  const course = data?.course;
  const viewer = data?.viewer || course?.viewer;
  useSeo({ title: course?.seo?.title, description: course?.seo?.description, image: course?.thumbnail_url, robots: viewer?.preview_mode ? 'noindex,nofollow' : undefined });

  if (error?.status === 404) return <NotFound />;
  if (error) return <div className="container-x"><ErrorState error={error} onRetry={reload} /></div>;
  if (loading || !course) return <DetailSkeleton />;
  return <CoursePage key={course.slug} course={course} viewer={viewer || {}} related={data.related || []} asStudent={asStudent && !!viewer?.is_staff} />;
}

function CoursePage({ course, viewer: initialViewer, related, asStudent }) {
  const [viewer, setViewer] = useState(initialViewer);
  useEffect(() => setViewer(initialViewer), [initialViewer]);
  const enrol = useEnrol(course, viewer, asStudent);
  const c = course.counts || {};
  const sections = course.curriculum || [];
  // Staff see every lesson unlocked unless they preview as a student (drafts stay unlocked for staff).
  const unlockedAll = viewer.enrolled || (viewer.is_staff && !asStudent);
  const learnSuffix = asStudent ? '?as=student' : '';
  // Enrolled students: lesson completion ticks come from the player outline
  const outline = useApi(viewer.enrolled ? 'lessons.php' : null, { action: 'player', course: course.slug });
  const completed = useMemo(() => {
    const set = new Set();
    outline.data?.sections?.forEach((s) => s.lessons.forEach((l) => l.completed && set.add(l.id)));
    return set;
  }, [outline.data]);
  const firstPreview = sections.flatMap((s) => s.lessons).find((l) => l.is_preview);
  const syllabus = course.syllabus || [];
  // Classroom courses show the subject-wise syllabus; the online curriculum only when it has sections.
  const showCurriculum = sections.length > 0 || !syllabus.length;
  // admission: online = Enroll Now; both = Enroll Now + the Apply Now form; enquiry = Apply Now form only.
  const applyForm = course.admission === 'enquiry' || course.admission === 'both';
  const showFees = !course.is_free && (applyForm || course.fee_items?.length > 0);
  useHashScroll();

  const tabs = [
    ['overview', 'Overview'],
    syllabus.length > 0 && ['syllabus', 'Syllabus'],
    showFees && ['fees', 'Fees'],
    showCurriculum && ['curriculum', 'Curriculum'],
    course.instructor && ['instructor', 'Instructor'],
    ['reviews', 'Reviews'],
    course.faqs?.length > 0 && ['faqs', 'FAQs'],
    ['enquire', applyForm ? 'Apply Now' : 'Enquire'],
  ].filter(Boolean);

  return (
    <div className="bg-surface pb-16">
      {viewer.preview_mode && (
        <div className="bg-amber-50 px-4 py-2 text-center text-sm font-semibold text-amber-900">
          Staff preview{asStudent ? ' · shown as a student who is not enrolled sees it' : ''}
          {course.status === 'draft' ? ' · this course is a draft and not visible to students' : ''}
          {course.status === 'archived' ? ' · this batch is archived: hidden from new students, enrolled students keep access' : ''}
        </div>
      )}
      {!viewer.preview_mode && course.status === 'archived' && (
        <div className="bg-primary-soft px-4 py-2 text-center text-sm font-semibold text-navy">
          This batch is closed for new admissions. You can keep learning until your access ends.
        </div>
      )}
      <Hero course={course} viewer={viewer} enrol={enrol} firstPreview={firstPreview} learnSuffix={learnSuffix} />

      {course.highlights?.length > 0 && (
        <div className="container-x relative z-10 -mt-7">
          <ul className="scrollbar-none flex gap-3 overflow-x-auto rounded-2xl border border-line bg-white p-3 shadow-card-hover sm:grid sm:grid-cols-2 sm:overflow-visible lg:grid-cols-4">
            {course.highlights.map((h, i) => (
              <li key={i} className="flex min-w-[220px] items-center gap-3 rounded-xl px-2 py-1.5 sm:min-w-0">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-primary-soft to-white text-primary ring-1 ring-primary/10"><Icon name={h.icon || 'sparkles'} className="size-5" /></span>
                <span className="text-sm leading-snug font-semibold text-navy">{h.text}</span>
              </li>
            ))}
          </ul>
        </div>
      )}

      <SectionNav tabs={tabs} />

      <div className="container-x grid gap-8 pt-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="min-w-0 space-y-6">
          <Overview course={course} />

          {syllabus.length > 0 && <Syllabus subjects={syllabus} />}

          {showFees && <Fees course={course} />}

          {(course.info_sections || []).map((s, i) => <InfoSection key={i} index={i} section={s} />)}

          {showCurriculum && <Block id="curriculum" icon="layers" title="Course curriculum"
            aside={[plural(sections.length, 'section'), plural(c.lessons || 0, 'lesson'), formatMinutes(c.minutes)].filter(Boolean).join(' · ')}>
            {viewer.enrolled && (
              <div className="mb-4 flex items-center gap-3 rounded-xl bg-surface p-3">
                <span className="text-sm font-semibold text-navy">Your progress</span>
                <ProgressBar value={viewer.progress} className="flex-1" tone="from-emerald-500 to-teal" />
                <span className="text-sm font-bold text-navy">{Math.round(viewer.progress || 0)}%</span>
              </div>
            )}
            {!unlockedAll && c.preview > 0 && (
              <p className="mb-4 flex items-start gap-2 rounded-xl bg-primary-soft/60 p-3 text-sm text-navy">
                <Icon name="playCircle" className="mt-0.5 size-4 shrink-0 text-primary" />
                {plural(c.preview, 'lesson is', 'lessons are')} free to preview - no sign-up needed. Enrol to unlock the remaining {plural(c.locked ?? Math.max(0, (c.lessons || 0) - c.preview), 'lesson')}.
              </p>
            )}
            {sections.length ? (
              <Curriculum slug={course.slug} sections={sections} unlockedAll={unlockedAll} completed={completed} learnSuffix={learnSuffix} />
            ) : <p className="text-sm text-muted">The detailed curriculum is being prepared and will appear here soon.</p>}
          </Block>}

          <PromoBanners placement="course_detail" />

          {course.upcoming_live_classes?.length > 0 && (
            <Block id="live" icon="video" title="Upcoming live classes">
              <ul className="divide-y divide-line">
                {course.upcoming_live_classes.map((lc) => (
                  <li key={lc.id} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                    <span className="flex min-w-0 items-center gap-2 font-semibold text-ink"><span className="size-2 shrink-0 rounded-full bg-coral" aria-hidden="true" />{lc.title}</span>
                    <time dateTime={lc.starts_at} className="text-muted">{formatDateTime(lc.starts_at)}</time>
                  </li>
                ))}
              </ul>
            </Block>
          )}

          {course.instructor && <Instructor instructor={course.instructor} />}

          <Reviews course={course} viewer={viewer} onReview={(review) => setViewer((v) => ({ ...v, review }))} enrol={enrol} />

          <CourseVideos placement="course" course={course.slug} title="Course videos" className="card p-5 sm:p-6" />

          <Faqs course={course} />
        </div>

        <aside className="hidden min-w-0 lg:block" aria-label="Enrolment">
          <div className="sticky top-24 space-y-4">
            <EnrolCard course={course} viewer={viewer} enrol={enrol} />
          </div>
        </aside>
      </div>

      {related.length > 0 && (
        <Reveal as="section" className="container-x mt-14">
          <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
              <span className="eyebrow mb-2">Keep exploring</span>
              <h2 className="font-display text-2xl font-extrabold text-navy sm:text-3xl">Related courses</h2>
            </div>
            <Link to={`/courses?category=${course.category?.slug || ''}`} className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">More {course.category?.name} courses<Icon name="arrowRight" className="size-4" /></Link>
          </div>
          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">{related.map((r) => <li key={r.id} className="min-w-0"><CourseCard course={r} compact /></li>)}</ul>
        </Reveal>
      )}

      <MobileEnrolBar course={course} viewer={viewer} enrol={enrol} />
    </div>
  );
}

// ------------------------------------------------------------------
// Enrolment: one place that decides the CTA for every viewer state
// ------------------------------------------------------------------
function useEnrol(course, viewer, asStudent = false) {
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const slug = course.slug;
  const expired = viewer.enrolment_status === 'expired';
  const prereq = !viewer.enrolled && !viewer.is_staff ? viewer.prerequisite_unmet : null;
  const loginHref = `/login?next=${encodeURIComponent(`/courses/${slug}`)}`;

  // POST lessons.php?action=enrol-free {slug} → {enrolled, already_enrolled, reference, next_lesson_id};
  // the congratulations page (/enrolled/<slug>) loads the enrolment itself.
  const enrolFree = async () => {
    setBusy(true);
    setError(null);
    try {
      await api.post('lessons.php', { slug }, { action: 'enrol-free' });
      navigate(`/enrolled/${slug}`);
    } catch (e) {
      if (e.status === 401) { navigate(loginHref); return; } // session ended in another tab
      setError(e.message);
      setBusy(false);
    }
  };

  let cta;
  if (viewer.enrolled) {
    const next = viewer.next_lesson_id;
    cta = { label: viewer.progress > 0 ? 'Continue learning' : 'Start learning', to: `/learn/${slug}${next ? `/${next}` : ''}`, icon: 'playCircle' };
  } else if (viewer.is_staff) {
    cta = { label: asStudent ? 'Open player as a student' : 'Open course player', to: `/learn/${slug}${asStudent ? '?as=student' : ''}`, icon: 'eye' };
  } else if (course.admission === 'enquiry') {
    // Classroom admission: the Apply Now form at the end of the page (no online checkout).
    cta = { label: 'Apply Now', onClick: () => openEnquiry(), icon: 'arrowRight' };
  } else if (prereq) {
    cta = { label: course.is_free ? 'Enroll Free' : 'Enroll Now', disabled: true };
  } else if (course.is_free) {
    cta = viewer.logged_in
      ? { label: expired ? 'Re-enrol for free' : 'Enroll Now - Free', onClick: enrolFree, icon: 'sparkles' }
      : { label: 'Enroll Now - Free', to: loginHref, icon: 'sparkles' };
  } else {
    cta = { label: expired ? 'Renew access' : 'Enroll Now', to: `/checkout/${slug}`, icon: 'arrowRight' };
  }
  // Enroll Now + Apply Now courses: a second button opens the Apply Now form (free counselling call).
  const secondary = course.admission === 'both' && !viewer.enrolled && !viewer.is_staff
    ? { label: 'Apply Now - free counselling', onClick: () => openEnquiry(), icon: 'phoneCall' } : null;
  return { cta, secondary, busy, error, prereq, expired, asStudent };
}

/** Scroll to the Apply Now / enquiry form and put the cursor in its first field. */
function openEnquiry() {
  const el = document.getElementById('enquire');
  if (!el) return;
  el.scrollIntoView({ behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  history.replaceState(null, '', '#enquire');
  setTimeout(() => el.querySelector('input:not([type=hidden]):not([tabindex="-1"])')?.focus({ preventScroll: true }), 400);
}

/** Links such as /courses/<slug>#enquire (course card "Enquire Now") open at that section once the page has rendered. */
function useHashScroll() {
  const { hash } = useLocation();
  useEffect(() => {
    const id = hash.slice(1);
    if (!/^[a-z-]+$/.test(id)) return undefined;
    const t = setTimeout(() => (id === 'enquire' ? openEnquiry() : document.getElementById(id)?.scrollIntoView({ block: 'start' })), 150);
    return () => clearTimeout(t);
  }, [hash]);
}

function CtaButton({ enrol, compact = false }) {
  const { cta, busy } = enrol;
  const cls = 'btn-primary w-full ' + (compact ? 'px-5 py-3' : 'btn-lg');
  const inner = busy ? <><span className="size-4 animate-spin rounded-full border-2 border-white border-t-transparent" aria-hidden="true" />Enrolling…</> : <>{cta.label}{cta.icon && <Icon name={cta.icon} className="size-[18px]" />}</>;
  if (cta.to && !cta.disabled) return <Link to={cta.to} className={cls}>{inner}</Link>;
  return <button type="button" onClick={cta.onClick} disabled={cta.disabled || busy} aria-disabled={cta.disabled || undefined} className={cls}>{inner}</button>;
}

/** "Apply Now - free counselling" under Enroll Now (admission "both"). */
function SecondaryCta({ enrol, className = '' }) {
  if (!enrol.secondary) return null;
  return (
    <button type="button" onClick={enrol.secondary.onClick} className={'btn-light w-full py-3 ' + className}>
      <Icon name={enrol.secondary.icon} className="size-4" />{enrol.secondary.label}
    </button>
  );
}

function PrereqNote({ course, enrol }) {
  if (!enrol.prereq) return null;
  return (
    <Alert type="warning" className="mt-3">
      Complete <strong>{course.prerequisite?.title || enrol.prereq}</strong> first - it is required before you can enrol.
      {course.prerequisite?.slug && <> <Link to={`/courses/${course.prerequisite.slug}`} className="font-semibold underline">View that course</Link></>}
    </Alert>
  );
}

/** Online access period ("Lifetime access") - not shown for classroom courses. */
const accessLabel = (course) => (course.course_type === 'offline' ? null : course.validity_days ? `${validityLabel(course.validity_days)} access` : 'Lifetime access');

function EnrolCard({ course, viewer, enrol }) {
  const c = course.counts || {};
  const off = discountPercent(course);
  const enquiry = course.admission === 'enquiry';
  const access = accessLabel(course);
  const includes = [
    course.course_type === 'offline' && ['presentation', 'Offline classroom classes'],
    course.duration && enquiry && ['clock', `Duration: ${course.duration}`],
    c.videos > 0 && ['playCircle', plural(c.videos, 'video lesson')],
    c.minutes > 0 && ['clock', `${formatMinutes(c.minutes)} of content`],
    c.live_classes > 0 && ['video', plural(c.live_classes, 'live class', 'live classes')],
    c.documents > 0 && ['file', plural(c.documents, 'downloadable note', 'downloadable notes')],
    c.quizzes > 0 && ['clipboard', plural(c.quizzes, 'practice test')],
    access && ['calendar', access],
    ...(course.includes || []).map((t) => ['checkCircle', t]),
  ].filter(Boolean);
  return (
    <div className="card overflow-hidden">
      <div className="relative bg-gradient-to-br from-primary-soft via-white to-white p-5">
        {viewer.enrolled ? (
          <div>
            <p className="flex items-center gap-2 text-sm font-bold text-emerald-700"><Icon name="checkCircle" className="size-5" />You are enrolled</p>
            <div className="mt-3 flex items-center gap-3">
              <ProgressBar value={viewer.progress} className="flex-1" tone="from-emerald-500 to-teal" />
              <span className="text-sm font-bold text-navy">{Math.round(viewer.progress || 0)}%</span>
            </div>
            {viewer.expires_at && <p className="mt-2 text-xs text-muted">Access until {formatDate(viewer.expires_at)}</p>}
            {viewer.enrolment_reference && <p className="mt-1 text-xs text-muted">Enrolment ID {viewer.enrolment_reference}</p>}
          </div>
        ) : (
          <>
            <Price course={course} size="lg" />
            {off > 0 && <p className="mt-1 text-sm font-semibold text-coral">You save {off}%{course.offer?.ends_at ? ` · offer till ${formatOfferDate(course.offer.ends_at)}` : ' today'}</p>}
            {access && <p className="mt-1 text-sm text-muted">{access}</p>}
            {enquiry && <p className="mt-1 text-sm text-muted">Admission through our admissions desk{course.fee_items?.length ? ' · see all fee options below' : ''}</p>}
          </>
        )}
        <div className="mt-4"><CtaButton enrol={enrol} /></div>
        <SecondaryCta enrol={enrol} className="mt-2.5" />
        {enrol.error && <Alert className="mt-3">{enrol.error}</Alert>}
        <PrereqNote course={course} enrol={enrol} />
        {viewer.is_staff && !viewer.enrolled && (
          <p className="mt-2 text-center text-xs text-muted">{enrol.asStudent ? 'Preview as student: locks are shown as a non-enrolled student sees them.' : 'Staff view: all lessons are unlocked for you.'}</p>
        )}
      </div>
      <div className="border-t border-line p-5">
        <h2 className="font-display text-sm font-bold tracking-wide text-navy uppercase">This course includes</h2>
        <ul className="mt-3 space-y-2.5">{includes.map(([icon, text]) => <Inc key={text} icon={icon}>{text}</Inc>)}</ul>
        <p className="mt-4 flex items-center gap-2 rounded-xl bg-surface p-3 text-xs text-muted">
          <Icon name="shield" className="size-4 shrink-0 text-emerald-600" />
          {enquiry ? 'Submit the Apply Now form - our admission counsellor calls you back with batch timings and fee details. No payment online.'
            : course.is_free ? 'Free course - instant access, no payment needed.'
              : course.admission === 'both' ? 'Enroll Now: create a free account and pay securely online (UPI, card, net banking). Prefer to talk first? Apply Now and our counsellor calls you.'
                : 'Secure checkout. Access is activated as soon as your payment is verified.'}
        </p>
        {course.admission === 'online' && (
          <a href="#enquire" onClick={scrollTo('enquire')} className="mt-3 flex items-center justify-center gap-1.5 text-sm font-semibold text-primary hover:underline">
            <Icon name="phoneCall" className="size-4" />Have questions? Get a free callback
          </a>
        )}
      </div>
    </div>
  );
}

function MobileEnrolBar({ course, viewer, enrol }) {
  // While this bar is on screen (below lg) the floating WhatsApp/chat buttons are lifted above it
  // (body.has-sticky-cta raises --fab-bottom in css/style.css).
  useEffect(() => {
    document.body.classList.add('has-sticky-cta');
    return () => document.body.classList.remove('has-sticky-cta');
  }, []);
  return (
    <>
      <div aria-hidden="true" className="h-20 lg:hidden" />
      <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white/95 px-4 py-3 shadow-[0_-10px_30px_-12px_rgb(8_38_77/0.35)] backdrop-blur lg:hidden [.has-tabbar_&]:bottom-[calc(64px+env(safe-area-inset-bottom))]">
        <div className="mx-auto flex max-w-3xl items-center gap-3">
          <div className="min-w-0 flex-1">
            {viewer.enrolled ? (
              <>
                <p className="truncate text-xs font-semibold text-muted">{course.title}</p>
                <p className="text-sm font-bold text-emerald-700">{Math.round(viewer.progress || 0)}% complete</p>
              </>
            ) : <Price course={course} showOff />}
          </div>
          <div className="shrink-0"><CtaButton enrol={enrol} compact /></div>
        </div>
        {enrol.error && <p role="alert" className="mx-auto mt-1 max-w-3xl text-xs font-medium text-danger">{enrol.error}</p>}
      </div>
    </>
  );
}

// ------------------------------------------------------------------
// Hero
// ------------------------------------------------------------------
function Hero({ course, viewer, enrol, firstPreview, learnSuffix = '' }) {
  const c = course.counts || {};
  const meta = [
    course.duration && ['clock', course.duration, 'Duration'],
    c.lessons > 0 && ['playCircle', plural(c.lessons, 'lesson'), formatMinutes(c.minutes) || 'Lessons'],
    course.level && ['barChart', levelLabel(course.level), 'Level'],
    course.language && ['languages', languageLabel(course.language), 'Language'],
    course.course_type === 'offline' ? ['presentation', 'Classroom', 'Mode'] : ['calendar', course.validity_days ? validityLabel(course.validity_days) : 'Lifetime', 'Validity'],
  ].filter(Boolean);
  const color = course.category?.color || '#0B63F6';
  return (
    <section className="relative isolate overflow-hidden bg-navy-mesh text-white">
      <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40 [mask-image:linear-gradient(to_bottom,black,transparent)]" />
      <div aria-hidden="true" className="absolute -top-32 -right-24 -z-10 size-96 rounded-full bg-violet/30 blur-3xl" />
      <div className={'container-x grid gap-8 pt-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,460px)] lg:items-center lg:gap-12 ' + (course.highlights?.length ? 'pb-16' : 'pb-10')}>
        <div className="min-w-0">
          <nav aria-label="Breadcrumb" className="text-sm text-white/70">
            <ol className="flex flex-wrap items-center gap-1.5">
              <li><Link to="/" className="hover:text-white">Home</Link></li>
              <li aria-hidden="true"><Icon name="chevronRight" className="size-3.5" /></li>
              <li><Link to="/courses" className="hover:text-white">Courses</Link></li>
              {course.category && <><li aria-hidden="true"><Icon name="chevronRight" className="size-3.5" /></li><li><Link to={`/courses?category=${course.category.slug}`} className="hover:text-white">{course.category.name}</Link></li></>}
            </ol>
          </nav>
          <div className="mt-5 flex flex-wrap items-center gap-2">
            {course.category && (
              <Link to={`/courses?category=${course.category.slug}`} className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold text-white ring-1 ring-white/25" style={{ backgroundColor: `color-mix(in srgb, ${color} 70%, transparent)` }}>
                <Icon name={course.category.icon} className="size-3.5" />{course.category.name}
              </Link>
            )}
            <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/90 ring-1 ring-white/15">{courseTypeLabel(course.course_type)}</span>
            {course.badge && <span className="rounded-full bg-gold px-3 py-1 text-xs font-bold text-navy">{course.badge}</span>}
            {course.batch && <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/90 ring-1 ring-white/15">Batch {course.batch}</span>}
            {course.is_demo && <DemoBadge />}
          </div>
          <h1 className="mt-4 animate-fade-up font-display text-3xl leading-tight font-extrabold tracking-tight text-white sm:text-4xl lg:text-[2.75rem]">{course.title}</h1>
          {course.subtitle && <p className="mt-2 animate-fade-up text-lg font-semibold text-gold [animation-delay:60ms]">{course.subtitle}</p>}
          {course.short_description && <p className="mt-3 max-w-2xl animate-fade-up leading-relaxed text-white/80 [animation-delay:120ms]">{course.short_description}</p>}

          <div className="mt-5 flex flex-wrap items-center gap-x-5 gap-y-3 text-sm">
            {course.rating ? (
              <a href="#reviews" onClick={scrollTo('reviews')} className="flex items-center gap-2 hover:underline">
                <span className="font-display text-lg font-extrabold text-gold">{course.rating.average.toFixed(1)}</span>
                <Stars value={course.rating.average} />
                <span className="text-white/75">({plural(course.rating.count, 'review')})</span>
              </a>
            ) : <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold">New course</span>}
            {course.instructor && (
              <a href="#instructor" onClick={scrollTo('instructor')} className="flex items-center gap-2 hover:underline">
                {course.instructor.photo_url
                  ? <img src={course.instructor.photo_url} alt="" className="size-8 rounded-full object-cover ring-2 ring-white/30" />
                  : <span className="grid size-8 place-items-center rounded-full bg-white/15 text-xs font-bold">{course.instructor.name?.[0]}</span>}
                <span>By <strong>{course.instructor.name}</strong></span>
              </a>
            )}
          </div>

          {/* Chips size to their content and wrap onto more rows instead of truncating (e.g. "Hindi & English") */}
          <ul className="mt-6 grid grid-cols-2 gap-2.5 sm:flex sm:flex-wrap">
            {meta.map(([icon, top, bottom]) => (
              <li key={bottom + top} className="glass flex min-w-0 items-center gap-2.5 rounded-xl px-3 py-2.5 sm:flex-auto">
                <Icon name={icon} className="size-5 shrink-0 text-gold" />
                <span className="min-w-0"><span className="block text-sm leading-snug font-bold [overflow-wrap:anywhere]">{top}</span><span className="block text-[11px] text-white/65">{bottom}</span></span>
              </li>
            ))}
          </ul>

          {/* Price + CTA on phones and tablets (desktop uses the sticky card) */}
          <div className="mt-6 rounded-2xl bg-white p-4 text-ink shadow-card-hover lg:hidden">
            {!viewer.enrolled && <div className="mb-3"><Price course={course} size="lg" /></div>}
            <CtaButton enrol={enrol} />
            <SecondaryCta enrol={enrol} className="mt-2.5" />
            {enrol.error && <Alert className="mt-3">{enrol.error}</Alert>}
            <PrereqNote course={course} enrol={enrol} />
          </div>

          <div className="mt-6 flex flex-wrap items-center gap-3">
            <ShareButtons url={course.share_url || `/courses/${course.slug}`} title={course.title} text={course.short_description || course.subtitle || ''} label={null} size="sm" />
            <WishlistButton course={course} className="ring-1 ring-white/30" />
          </div>
        </div>

        <div className="min-w-0">
          <div className="overflow-hidden rounded-2xl shadow-[0_30px_70px_-25px_rgb(0_0_0/0.6)] ring-1 ring-white/15">
            <HeroMedia course={course} firstPreview={firstPreview} learnSuffix={learnSuffix} />
          </div>
        </div>
      </div>
    </section>
  );
}

/** YouTube / Vimeo id from a promo URL (watch, youtu.be, embed, shorts, live). */
function videoEmbed(url) {
  if (!url) return null;
  const yt = url.match(/(?:youtu\.be\/|[?&]v=|\/embed\/|\/shorts\/|\/live\/)([\w-]{11})/)?.[1];
  if (yt) return `https://www.youtube-nocookie.com/embed/${yt}?autoplay=1&rel=0`;
  const vimeo = url.match(/vimeo\.com\/(?:video\/)?(\d+)/)?.[1];
  if (vimeo) return `https://player.vimeo.com/video/${vimeo}?autoplay=1`;
  return null;
}

/** Click-to-load promo video (no third-party requests until asked); otherwise the thumbnail + free preview link. */
function HeroMedia({ course, firstPreview, learnSuffix = '' }) {
  const [playing, setPlaying] = useState(false);
  const embed = videoEmbed(course.promo_video_url);
  if (embed && playing) {
    return <iframe className="aspect-video w-full bg-black" src={embed} title={`${course.title} - introduction`} allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen />;
  }
  const overlay = (label) => (
    <span className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-navy-deep/35 transition-colors group-hover:bg-navy-deep/45">
      <span className="relative grid size-16 place-items-center rounded-full bg-white text-primary shadow-xl transition-transform group-hover:scale-110 sm:size-20">
        <span className="absolute inset-0 animate-pulse-ring rounded-full" aria-hidden="true" />
        <Icon name="play" className="ml-1 size-7 sm:size-8" />
      </span>
      <span className="rounded-full bg-navy-deep/60 px-3 py-1 text-xs font-bold text-white backdrop-blur">{label}</span>
    </span>
  );
  if (embed) {
    return (
      <button type="button" onClick={() => setPlaying(true)} className="group relative block w-full" aria-label="Play course introduction video">
        <CourseThumb course={course} showBadge={false} />
        {overlay('Watch introduction')}
      </button>
    );
  }
  if (firstPreview) {
    return (
      <Link to={`/learn/${course.slug}/${firstPreview.id}${learnSuffix}`} className="group relative block" aria-label={`Watch free preview: ${firstPreview.title}`}>
        <CourseThumb course={course} showBadge={false} />
        {overlay('Watch free preview')}
      </Link>
    );
  }
  return <CourseThumb course={course} showBadge={false} />;
}

// ------------------------------------------------------------------
// Sticky in-page section nav with active highlight
// ------------------------------------------------------------------
function scrollTo(id) {
  return (e) => {
    const el = document.getElementById(id);
    if (!el) return;
    e.preventDefault();
    el.scrollIntoView({ behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
    history.replaceState(null, '', `#${id}`);
  };
}

function SectionNav({ tabs }) {
  const [active, setActive] = useState(tabs[0][0]);
  const ids = tabs.map((t) => t[0]).join(',');
  useEffect(() => {
    // Active = the last section whose top has passed just below the sticky header + this nav
    let frame = 0;
    const update = () => {
      frame = 0;
      const els = ids.split(',').map((id) => document.getElementById(id)).filter(Boolean);
      let current = els[0]?.id;
      els.forEach((el) => { if (el.getBoundingClientRect().top <= 170) current = el.id; });
      if (current) setActive(current);
    };
    const onScroll = () => { if (!frame) frame = requestAnimationFrame(update); };
    update();
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    return () => {
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
      if (frame) cancelAnimationFrame(frame);
    };
  }, [ids]);
  return (
    <nav aria-label="Course sections" className="sticky top-[72px] z-20 mt-6 border-y border-line bg-white/90 backdrop-blur">
      <div className="container-x">
        <ul className="scrollbar-none flex gap-1 overflow-x-auto py-2">
          {tabs.map(([id, label]) => (
            <li key={id} className="shrink-0">
              <a href={`#${id}`} onClick={scrollTo(id)} aria-current={active === id ? 'true' : undefined}
                className={'relative block rounded-xl px-4 py-2 text-sm font-semibold transition-colors ' + (active === id ? 'bg-primary text-white shadow-[0_6px_18px_-8px_rgb(11_99_246/0.8)]' : 'text-ink hover:bg-primary-soft hover:text-primary')}>
                {label}
              </a>
            </li>
          ))}
        </ul>
      </div>
    </nav>
  );
}

// ------------------------------------------------------------------
// Content blocks
// ------------------------------------------------------------------
function Block({ id, icon, title, aside, children, className = '' }) {
  return (
    <Reveal as="section" id={id} className={'card min-w-0 scroll-mt-36 p-5 sm:p-7 ' + className}>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2.5 font-display text-xl font-extrabold text-navy sm:text-2xl">
          {icon && <span className="grid size-9 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name={icon} className="size-5" /></span>}
          {title}
        </h2>
        {aside && <span className="text-sm font-medium text-muted">{aside}</span>}
      </div>
      {children}
    </Reveal>
  );
}

function Inc({ icon, children, tone = 'text-primary' }) {
  return <li className="flex items-start gap-2.5 text-sm text-ink"><Icon name={icon} className={'mt-0.5 size-4 shrink-0 ' + tone} /><span className="min-w-0">{children}</span></li>;
}

function Overview({ course }) {
  const paragraphs = (course.description || '').split(/\n{2,}/).map((p) => p.trim()).filter(Boolean);
  return (
    <Block id="overview" icon="book" title="About this course">
      {paragraphs.length > 0 && <div className="prose-lite text-[15px] text-ink/90">{paragraphs.map((p, i) => <p key={i}>{p}</p>)}</div>}
      {course.learning_outcomes?.length > 0 && (
        <div className="mt-6 rounded-2xl border border-emerald-100 bg-gradient-to-br from-emerald-50/70 to-white p-5">
          <h3 className="mb-3 font-display font-bold text-navy">What you will learn</h3>
          <ul className="grid gap-3 sm:grid-cols-2">{course.learning_outcomes.map((o) => <Inc key={o} icon="checkCircle" tone="text-emerald-600">{o}</Inc>)}</ul>
        </div>
      )}
      {/* The sticky enrolment card lists the same items from lg up, so this copy is for phones and tablets */}
      {course.includes?.length > 0 && (
        <div className="mt-6 lg:hidden">
          <h3 className="mb-3 font-display font-bold text-navy">What is included</h3>
          <ul className="grid gap-3 sm:grid-cols-2">
            {course.includes.map((t) => (
              <li key={t} className="flex items-center gap-3 rounded-xl border border-line p-3 text-sm text-ink">
                <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary"><Icon name="sparkles" className="size-4" /></span>{t}
              </li>
            ))}
          </ul>
        </div>
      )}
      {course.requirements?.length > 0 && (
        <div className="mt-6">
          <h3 className="mb-3 font-display font-bold text-navy">Requirements</h3>
          <ul className="space-y-2">{course.requirements.map((r) => <Inc key={r} icon="arrowRight" tone="text-coral">{r}</Inc>)}</ul>
        </div>
      )}
    </Block>
  );
}

/** Subject-wise syllabus (courses.syllabus): one expandable panel per subject with its numbered topics. */
function Syllabus({ subjects }) {
  const [open, setOpen] = useState(() => new Set([0]));
  const allOpen = open.size === subjects.length;
  const toggle = (i) => setOpen((s) => { const n = new Set(s); if (n.has(i)) n.delete(i); else n.add(i); return n; });
  const topics = subjects.reduce((n, s) => n + s.topics.length, 0);
  return (
    <Block id="syllabus" icon="layers" title="Detailed syllabus" aside={`${plural(subjects.length, 'subject')} · ${plural(topics, 'topic')}`}>
      {subjects.length > 1 && (
        <div className="mb-3 flex justify-end">
          <button type="button" onClick={() => setOpen(allOpen ? new Set() : new Set(subjects.map((_, i) => i)))} className="text-sm font-semibold text-primary hover:underline">
            {allOpen ? 'Collapse all' : 'Expand all'}
          </button>
        </div>
      )}
      <div className="space-y-3">
        {subjects.map((s, i) => {
          const isOpen = open.has(i);
          const panel = `syllabus-${i}`;
          return (
            <div key={s.title + i} className={'overflow-hidden rounded-2xl border transition-colors ' + (isOpen ? 'border-primary/30 shadow-card' : 'border-line')}>
              <button type="button" onClick={() => toggle(i)} aria-expanded={isOpen} aria-controls={panel}
                className={'flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left transition-colors sm:px-5 ' + (isOpen ? 'bg-primary-soft/60' : 'bg-surface hover:bg-primary-soft/40')}>
                <span className="flex min-w-0 items-center gap-3">
                  <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-white font-display text-sm font-extrabold text-primary shadow-sm">{i + 1}</span>
                  <span className="min-w-0 font-display font-bold text-navy">{s.title}</span>
                </span>
                <span className="flex shrink-0 items-center gap-3 text-xs font-medium text-muted">
                  <span>{plural(s.topics.length, 'topic')}</span>
                  <Icon name="chevronDown" className={'size-5 transition-transform duration-300 ' + (isOpen ? 'rotate-180 text-primary' : '')} />
                </span>
              </button>
              {isOpen && (
                <ol id={panel} className="grid animate-fade-in gap-x-6 gap-y-2.5 bg-white px-4 py-4 sm:grid-cols-2 sm:px-5">
                  {s.topics.map((t, ti) => (
                    <li key={ti} className="flex items-start gap-2.5 text-sm text-ink">
                      <span className="mt-px grid size-5 shrink-0 place-items-center rounded-full bg-primary-soft text-[11px] font-bold text-primary" aria-hidden="true">{ti + 1}</span>
                      <span className="min-w-0">{t}</span>
                    </li>
                  ))}
                </ol>
              )}
            </div>
          );
        })}
      </div>
    </Block>
  );
}

/**
 * Course fee, offer and the fee items (admission, registration, other options). Every item is shown on its
 * own line and never added up: which options apply is confirmed by the admissions desk.
 */
function Fees({ course }) {
  const off = discountPercent(course);
  const offer = course.offer;
  const groups = [];
  (course.fee_items || []).forEach((f) => {
    let g = groups.find((x) => x.name === f.group);
    if (!g) groups.push((g = { name: f.group, items: [] }));
    g.items.push(f);
  });
  const tiles = [
    ['receipt', 'Course fee', <Price key="p" course={course} size="md" showOff />],
    course.duration && ['clock', 'Duration', <span key="d" className="font-display text-lg font-extrabold text-navy">{course.duration}</span>],
    off > 0
      ? ['tag', 'Special offer', <span key="o" className="font-display text-lg font-extrabold text-coral">{off}% off{offer?.ends_at ? <span className="block text-xs font-semibold text-muted">till {formatOfferDate(offer.ends_at)}</span> : null}</span>]
      : course.course_type === 'offline' && ['presentation', 'Mode', <span key="m" className="font-display text-lg font-extrabold text-navy">Offline classes</span>],
  ].filter(Boolean);
  return (
    <Block id="fees" icon="receipt" title="Fees & admission">
      <ul className={'grid gap-3 ' + (tiles.length > 2 ? 'sm:grid-cols-3' : 'sm:grid-cols-2')}>
        {tiles.map(([icon, label, value]) => (
          <li key={label} className="flex min-w-0 items-start gap-3 rounded-2xl border border-line bg-surface p-4">
            <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-white text-primary shadow-sm"><Icon name={icon} className="size-5" /></span>
            <span className="min-w-0"><span className="block text-xs font-semibold tracking-wide text-muted uppercase">{label}</span>{value}</span>
          </li>
        ))}
      </ul>
      {groups.map((g) => (
        <div key={g.name} className="mt-6">
          <h3 className="mb-2 font-display font-bold text-navy">{g.name}</h3>
          <ul className="divide-y divide-line overflow-hidden rounded-2xl border border-line">
            {g.items.map((f, i) => {
              const free = f.offer_free && !!offer;
              return (
                <li key={f.label + i} className="flex items-start justify-between gap-4 bg-white px-4 py-3 sm:px-5">
                  <span className="min-w-0">
                    <span className="block text-sm font-semibold text-ink">{f.label}</span>
                    {f.note && <span className="mt-0.5 block text-xs text-muted">{f.note}</span>}
                  </span>
                  <span className="shrink-0 text-right">
                    {free ? (
                      <>
                        <s className="mr-1.5 text-sm text-muted">{formatPrice(f.amount)}</s>
                        <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-extrabold text-emerald-700 ring-1 ring-emerald-100">FREE</span>
                        {offer.ends_at && <span className="mt-0.5 block text-[11px] font-semibold text-muted">till {formatOfferDate(offer.ends_at)}</span>}
                      </>
                    ) : <span className="font-display font-extrabold text-navy">{formatPrice(f.amount)}</span>}
                  </span>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
      <p className="mt-5 flex items-start gap-2 rounded-xl bg-primary-soft/60 p-3 text-sm text-navy">
        <Icon name="info" className="mt-0.5 size-4 shrink-0 text-primary" />
        <span className="min-w-0">
          {course.admission === 'enquiry'
            ? (groups.length ? 'Each fee is listed separately and the amounts are not added together. Our admissions desk confirms which fee option applies to you.' : 'Our admissions desk will confirm the batch timings and the complete fee details.')
            : (groups.length ? 'Enroll Now pays the course fee shown above online. The other fees are listed separately and are not added together - our admissions desk confirms which of them apply to you.' : 'Enroll Now pays the course fee shown above online. Our admissions desk confirms the batch timings.')}
          {course.admission !== 'online' && <> <a href="#enquire" onClick={(e) => { e.preventDefault(); openEnquiry(); }} className="font-semibold text-primary underline-offset-2 hover:underline">Apply now</a></>}
        </span>
      </p>
    </Block>
  );
}

/** Text of an extra page section: blank line = new paragraph, "- " lines = bullet points. */
function infoBlocks(body) {
  const blocks = [];
  let cur = null;
  body.split('\n').forEach((raw) => {
    const line = raw.trim();
    if (!line) { cur = null; return; }
    const bullet = line.match(/^[-•*]\s+(.+)/);
    if (bullet) {
      if (cur?.type !== 'list') blocks.push((cur = { type: 'list', items: [] }));
      cur.items.push(bullet[1]);
    } else if (cur?.type === 'p') {
      cur.text += ' ' + line;
    } else {
      blocks.push((cur = { type: 'p', text: line }));
    }
  });
  return blocks;
}

/** Extra course page section (courses.info_sections): examination details, preparation strategy, who should join… */
function InfoSection({ section, index }) {
  const t = section.title.toLowerCase();
  const steps = /strateg|plan|how to/.test(t);
  const icon = steps ? 'target' : /exam|pattern|eligib/.test(t) ? 'clipboard' : /who|join/.test(t) ? 'users' : 'info';
  return (
    <Block id={`section-${index + 1}`} icon={icon} title={section.title}>
      <div className="space-y-3 text-[15px] leading-relaxed text-ink/90">
        {infoBlocks(section.body).map((b, i) => (b.type === 'p' ? <p key={i}>{b.text}</p> : steps ? (
          <ol key={i} className="space-y-2.5">
            {b.items.map((item, n) => (
              <li key={n} className="flex items-start gap-3 text-sm text-ink">
                <span className="mt-px grid size-6 shrink-0 place-items-center rounded-full bg-gradient-to-br from-primary to-violet text-xs font-bold text-white" aria-hidden="true">{n + 1}</span>
                <span className="min-w-0">{item}</span>
              </li>
            ))}
          </ol>
        ) : (
          <ul key={i} className="space-y-2">{b.items.map((item, n) => <Inc key={n} icon="checkCircle" tone="text-emerald-600">{item}</Inc>)}</ul>
        )))}
      </div>
    </Block>
  );
}

function Curriculum({ slug, sections, unlockedAll, completed, learnSuffix = '' }) {
  const [open, setOpen] = useState(() => new Set(sections.length <= 2 ? sections.map((s) => s.id) : [sections[0]?.id]));
  const allOpen = open.size === sections.length;
  const toggle = (id) => setOpen((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n; });
  return (
    <div>
      {sections.length > 1 && (
        <div className="mb-3 flex justify-end">
          <button type="button" onClick={() => setOpen(allOpen ? new Set() : new Set(sections.map((s) => s.id)))} className="text-sm font-semibold text-primary hover:underline">
            {allOpen ? 'Collapse all' : 'Expand all'}
          </button>
        </div>
      )}
      <div className="space-y-3">
        {sections.map((s, si) => {
          const isOpen = open.has(s.id);
          const mins = s.lessons.reduce((a, l) => a + (Number(l.duration_minutes) || 0), 0);
          const panel = `sec-${s.id}`;
          return (
            <div key={s.id} className={'overflow-hidden rounded-2xl border transition-colors ' + (isOpen ? 'border-primary/30 shadow-card' : 'border-line')}>
              <button type="button" onClick={() => toggle(s.id)} aria-expanded={isOpen} aria-controls={panel}
                className={'flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left transition-colors sm:px-5 ' + (isOpen ? 'bg-primary-soft/60' : 'bg-surface hover:bg-primary-soft/40')}>
                <span className="flex min-w-0 items-center gap-3">
                  <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-white font-display text-sm font-extrabold text-primary shadow-sm">{si + 1}</span>
                  <span className="min-w-0 font-display font-bold text-navy">{s.title}</span>
                </span>
                <span className="flex shrink-0 items-center gap-3 text-xs font-medium text-muted">
                  <span className="hidden sm:inline">{[plural(s.lessons.length, 'lesson'), formatMinutes(mins)].filter(Boolean).join(' · ')}</span>
                  <Icon name="chevronDown" className={'size-5 transition-transform duration-300 ' + (isOpen ? 'rotate-180 text-primary' : '')} />
                </span>
              </button>
              {isOpen && (
                <ul id={panel} className="animate-fade-in divide-y divide-line bg-white">
                  {s.lessons.length === 0 && <li className="px-5 py-3 text-sm text-muted">Lessons for this section are coming soon.</li>}
                  {s.lessons.map((l) => <LessonRow key={l.id} slug={slug} lesson={l} unlockedAll={unlockedAll} done={completed.has(l.id)} learnSuffix={learnSuffix} />)}
                </ul>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

function LessonRow({ slug, lesson: l, unlockedAll, done, learnSuffix = '' }) {
  // `locked` comes from the API (true when this viewer cannot open the lesson)
  const locked = unlockedAll ? false : (l.locked ?? !l.is_preview);
  const dur = formatMinutes(l.duration_minutes);
  const to = `/learn/${slug}/${l.id}${learnSuffix}`;
  const icon = (
    <span className={'grid size-8 shrink-0 place-items-center rounded-lg ' + (done ? 'bg-emerald-50 text-emerald-600' : locked ? 'bg-surface text-muted' : 'bg-primary-soft text-primary')}>
      <Icon name={done ? 'checkCircle' : LESSON_ICON[l.type] || 'file'} className="size-4" />
    </span>
  );
  const text = (
    <span className="min-w-0 flex-1">
      <span className={'block text-sm font-medium ' + (locked ? 'text-ink/80' : 'text-ink')}>{l.title}</span>
      <span className="text-xs text-muted">{lessonTypeLabel(l.type)}{dur ? ` · ${dur}` : ''}</span>
    </span>
  );
  if (!locked) {
    return (
      <li>
        <Link to={to} className="group flex items-center gap-3 px-4 py-3 transition-colors hover:bg-surface sm:px-5">
          {icon}{text}
          {!unlockedAll && l.is_preview
            ? <span className="flex shrink-0 items-center gap-1 rounded-full bg-gradient-to-r from-primary to-violet px-3 py-1 text-xs font-bold text-white shadow-sm transition-transform group-hover:scale-105"><Icon name="play" className="size-3" />Preview</span>
            : <Icon name="arrowRight" className="size-4 shrink-0 text-muted transition-transform group-hover:translate-x-0.5 group-hover:text-primary" />}
        </Link>
      </li>
    );
  }
  return (
    <li className="flex items-center gap-3 px-4 py-3 sm:px-5">
      {icon}{text}
      <span className="group/lock relative shrink-0" tabIndex={0} aria-label="Locked - enrol to unlock">
        <span className="grid size-8 place-items-center rounded-full bg-amber-50 text-amber-600 ring-1 ring-amber-100"><Icon name="lock" className="size-4" /></span>
        <span role="tooltip" className="pointer-events-none absolute right-0 bottom-full mb-2 w-max rounded-lg bg-navy px-2.5 py-1.5 text-xs font-semibold text-white opacity-0 shadow-lg transition-opacity group-hover/lock:opacity-100 group-focus/lock:opacity-100">
          Enrol to unlock
        </span>
      </span>
    </li>
  );
}

function Instructor({ instructor: ins }) {
  const highlights = (ins.highlights || []).filter((h) => typeof h === 'string' && h.trim());
  return (
    <Block id="instructor" icon="user" title="Meet your instructor">
      <div className="flex flex-col gap-5 sm:flex-row">
        {ins.photo_url ? (
          <img src={ins.photo_url} alt={ins.name} loading="lazy" width="128" height="128" className="size-28 shrink-0 rounded-2xl object-cover shadow-card sm:size-32" />
        ) : (
          <span className="grid size-28 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-primary to-violet font-display text-4xl font-extrabold text-white shadow-glow sm:size-32">{ins.name?.[0]}</span>
        )}
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2 font-display text-xl font-bold text-navy">{ins.name} {ins.is_demo && <DemoBadge />}</p>
          {ins.designation && <p className="text-sm font-semibold text-primary">{ins.designation}</p>}
          {ins.experience_years > 0 && <p className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-gold/20 px-3 py-1 text-xs font-bold text-navy"><Icon name="trophy" className="size-3.5" />{plural(ins.experience_years, 'year')} of teaching experience</p>}
          {ins.bio && <p className="mt-3 text-sm leading-relaxed text-muted">{ins.bio}</p>}
          {highlights.length > 0 && <ul className="mt-3 space-y-1.5">{highlights.map((h) => <Inc key={h} icon="checkCircle" tone="text-emerald-600">{h}</Inc>)}</ul>}
        </div>
      </div>
    </Block>
  );
}

// ------------------------------------------------------------------
// Reviews: summary, distribution, list and the enrolled student's own review
// ------------------------------------------------------------------
function Reviews({ course, viewer, onReview, enrol }) {
  const reviews = course.reviews || [];
  const rating = course.rating;
  // Exact distribution from the API when provided, otherwise from the reviews on this page
  const dist = useMemo(() => {
    if (course.rating_breakdown) return { counts: course.rating_breakdown, exact: true };
    const counts = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    reviews.forEach((r) => { counts[r.rating] = (counts[r.rating] || 0) + 1; });
    return { counts, exact: !rating || reviews.length >= rating.count };
  }, [course.rating_breakdown, reviews, rating]);
  const distTotal = Object.values(dist.counts).reduce((a, b) => a + Number(b || 0), 0);

  return (
    <Block id="reviews" icon="star" title="Student reviews">
      {rating ? (
        <div className="grid gap-6 rounded-2xl bg-surface p-5 sm:grid-cols-[180px_minmax(0,1fr)] sm:items-center">
          <div className="text-center">
            <p className="font-display text-5xl font-extrabold text-navy">{rating.average.toFixed(1)}</p>
            <div className="mt-1 flex justify-center"><Stars value={rating.average} className="size-5" /></div>
            <p className="mt-1 text-sm text-muted">{plural(rating.count, 'rating')}</p>
          </div>
          {distTotal > 0 && (
            <div>
              <ul className="space-y-1.5">
                {[5, 4, 3, 2, 1].map((n) => {
                  const count = Number(dist.counts[n] || 0);
                  const pct = distTotal ? Math.round((count / distTotal) * 100) : 0;
                  return (
                    <li key={n} className="flex items-center gap-3 text-sm">
                      <span className="flex w-8 shrink-0 items-center gap-0.5 font-semibold text-ink">{n}<Icon name="star" className="size-3.5 text-gold" /></span>
                      <span className="h-2 flex-1 overflow-hidden rounded-full bg-line"><span className="block h-full rounded-full bg-gradient-to-r from-gold to-[#ffb020]" style={{ width: `${pct}%` }} /></span>
                      <span className="w-10 shrink-0 text-right text-xs text-muted">{pct}%</span>
                    </li>
                  );
                })}
              </ul>
              {!dist.exact && <p className="mt-2 text-xs text-muted">Breakdown of the {plural(reviews.length, 'latest review')}.</p>}
            </div>
          )}
        </div>
      ) : (
        <p className="rounded-2xl bg-surface p-4 text-sm text-muted">This course has no ratings yet{viewer.enrolled ? ' - yours could be the first.' : '.'}</p>
      )}

      <ReviewComposer course={course} viewer={viewer} onReview={onReview} enrol={enrol} />

      {reviews.length > 0 && (
        <ul className="mt-6 space-y-4">
          {reviews.map((r, i) => (
            <li key={i} className="rounded-2xl border border-line p-4">
              <div className="flex flex-wrap items-center gap-3">
                <span className="grid size-10 place-items-center rounded-full bg-gradient-to-br from-primary to-violet font-display font-bold text-white">{r.name?.[0]}</span>
                <span className="min-w-0">
                  <span className="block font-semibold text-navy">{r.name}</span>
                  <span className="flex items-center gap-2"><Stars value={r.rating} className="size-3.5" /><span className="text-xs text-muted">{formatDate(r.date)}</span></span>
                </span>
              </div>
              {r.review && <p className="mt-3 text-sm leading-relaxed text-ink/85">{r.review}</p>}
            </li>
          ))}
        </ul>
      )}
    </Block>
  );
}

const REVIEW_STATUS = {
  pending: ['bg-amber-50 text-amber-800 ring-amber-200', 'Pending approval'],
  approved: ['bg-emerald-50 text-emerald-700 ring-emerald-200', 'Published'],
  rejected: ['bg-red-50 text-red-700 ring-red-200', 'Not approved'],
};

function ReviewComposer({ course, viewer, onReview, enrol }) {
  const mine = viewer.review;
  const [editing, setEditing] = useState(false);
  const [rating, setRating] = useState(mine?.rating || 0);
  const [hover, setHover] = useState(0);
  const [text, setText] = useState(mine?.review || '');
  const [state, setState] = useState({ busy: false, error: null, fields: {}, message: null });

  if (!viewer.enrolled) return null;
  if (!viewer.can_review && !mine) {
    return (
      <div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-dashed border-line p-4 text-sm text-muted">
        <span>Start the course to share your review with other students.</span>
        {enrol.cta.to && <Link to={enrol.cta.to} className="btn-light btn-sm">{enrol.cta.label}</Link>}
      </div>
    );
  }

  if (mine && !editing) {
    const [cls, label] = REVIEW_STATUS[mine.status] || REVIEW_STATUS.pending;
    return (
      <div className="mt-5 rounded-2xl border border-primary/20 bg-primary-soft/40 p-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="font-display font-bold text-navy">Your review</p>
          <span className={'rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 ' + cls}>{label}</span>
        </div>
        <div className="mt-2"><Stars value={mine.rating} /></div>
        {mine.review && <p className="mt-2 text-sm text-ink/85">{mine.review}</p>}
        {state.message && <p role="status" className="mt-2 text-sm font-semibold text-emerald-700">{state.message}</p>}
        {mine.status === 'pending' && !state.message && <p className="mt-2 text-xs text-muted">It will appear on this page once our team approves it.</p>}
        <button type="button" onClick={() => { setEditing(true); setRating(mine.rating); setText(mine.review || ''); }} className="btn-light btn-sm mt-3"><Icon name="edit" className="size-3.5" />Edit review</button>
      </div>
    );
  }

  const submit = async (e) => {
    e.preventDefault();
    if (!rating) {
      setState({ busy: false, error: null, fields: { rating: 'Please choose a star rating.' }, message: null });
      return;
    }
    setState({ busy: true, error: null, fields: {}, message: null });
    try {
      const res = await api.post('students.php', { course: course.slug, rating, review: text.trim() }, { action: 'review' });
      onReview(res.review || { rating, review: text.trim(), status: 'pending' });
      setEditing(false);
      setState({ busy: false, error: null, fields: {}, message: res.message || 'Thank you! Your review will appear once it is approved.' });
    } catch (err) {
      // ApiError.fields is always an object: show the message unless a field below displays the error
      // (403 not_enrolled, 409 not_started, 429 rate_limited and network errors carry no fields).
      const fields = err.fields || {};
      const inline = !!(fields.rating || fields.review);
      setState({ busy: false, error: inline ? null : fields.course || err.message, fields, message: null });
    }
  };
  const shown = hover || rating;
  return (
    <form onSubmit={submit} className="mt-5 rounded-2xl border border-line p-4 sm:p-5" noValidate>
      <p className="font-display font-bold text-navy">{mine ? 'Edit your review' : 'Rate this course'}</p>
      <fieldset className="mt-2" aria-describedby={state.fields.rating ? 'rv-rating-err' : undefined}>
        <legend className="sr-only">Your rating</legend>
        <div className="flex items-center gap-1" onMouseLeave={() => setHover(0)}>
          {[1, 2, 3, 4, 5].map((n) => (
            <label key={n} className="cursor-pointer" onMouseEnter={() => setHover(n)}>
              <input type="radio" name="rv-rating" value={n} checked={rating === n} onChange={() => setRating(n)} className="peer sr-only" />
              <span className="sr-only">{plural(n, 'star')}</span>
              <Icon name="star" className={'size-8 rounded transition-transform peer-focus-visible:outline-2 peer-focus-visible:outline-primary hover:scale-110 ' + (n <= shown ? 'text-gold' : 'text-line')} />
            </label>
          ))}
          {shown > 0 && <span className="ml-2 text-sm font-semibold text-muted">{['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'][shown]}</span>}
        </div>
        {state.fields.rating && <p id="rv-rating-err" className="mt-1 text-xs text-danger">{state.fields.rating}</p>}
      </fieldset>
      <label htmlFor="rv-text" className="label mt-4">Your review <span className="font-normal text-muted">(optional)</span></label>
      <textarea id="rv-text" rows={4} maxLength={2000} value={text} onChange={(e) => setText(e.target.value)} className="input resize-y"
        aria-invalid={!!state.fields.review} aria-describedby={state.fields.review ? 'rv-text-err' : undefined}
        placeholder="What did you like? How did it help your preparation?" />
      {state.fields.review && <p id="rv-text-err" className="mt-1 text-xs text-danger">{state.fields.review}</p>}
      {state.error && <Alert className="mt-3">{state.error}</Alert>}
      <div className="mt-4 flex flex-wrap gap-2">
        <button type="submit" disabled={state.busy} className="btn-primary">{state.busy ? 'Submitting…' : 'Submit review'}</button>
        {mine && <button type="button" onClick={() => setEditing(false)} className="btn-light">Cancel</button>}
      </div>
      <p className="mt-2 text-xs text-muted">Reviews are checked by our team before they are published.</p>
    </form>
  );
}

function Faqs({ course }) {
  const faqs = course.faqs || [];
  // Enquiry-only and Enroll Now + Apply Now courses use the admission form; online-only courses a callback form.
  const admission = course.admission === 'enquiry' || course.admission === 'both';
  const both = course.admission === 'both';
  return (
    <Reveal as="section" id="faqs" className="min-w-0 scroll-mt-36 space-y-6">
      {faqs.length > 0 && (
        <div className="card p-5 sm:p-7">
          <h2 className="mb-4 flex items-center gap-2.5 font-display text-xl font-extrabold text-navy sm:text-2xl">
            <span className="grid size-9 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name="help" className="size-5" /></span>Frequently asked questions
          </h2>
          <div className="space-y-2.5">
            {faqs.map((f, i) => (
              <details key={i} className="group rounded-xl border border-line bg-white open:border-primary/30 open:shadow-card">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3.5 font-semibold text-navy">
                  <span className="min-w-0">{f.q}</span>
                  <span className="grid size-7 shrink-0 place-items-center rounded-full bg-surface text-primary transition-transform group-open:rotate-45"><Icon name="x" className="size-3.5 rotate-45" /></span>
                </summary>
                <p className="px-4 pb-4 text-sm leading-relaxed text-muted">{f.a}</p>
              </details>
            ))}
          </div>
        </div>
      )}
      <div id="enquire" className="relative scroll-mt-36 overflow-hidden rounded-2xl bg-navy-mesh p-5 text-white shadow-card-hover sm:p-7">
        <div aria-hidden="true" className="dot-grid absolute inset-0 opacity-30" />
        <div className="relative grid gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] md:items-center">
          <div className="min-w-0">
            <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-bold tracking-wide text-gold uppercase ring-1 ring-white/15"><Icon name="headset" className="size-3.5" />Admissions desk</span>
            {admission ? (
              <>
                <h2 className="mt-3 font-display text-2xl font-extrabold text-white">{both ? <>Prefer to talk first? <span className="text-gold">Apply Now</span></> : <>Apply for <span className="text-gold">admission</span></>}</h2>
                <p className="mt-2 text-sm leading-relaxed text-white/75">Fill in your details for {course.title}. Our admission counsellor will call you with the batch timings, fee options and the admission process.</p>
                <ul className="mt-4 space-y-2 text-sm text-white/85">
                  {['No payment needed to apply', 'Free counselling on the right course for you', both ? 'Ready to join? Use Enroll Now to pay online and start right away' : 'We usually call back within a few working hours'].map((t) => (
                    <li key={t} className="flex items-start gap-2"><Icon name="checkCircle" className="mt-0.5 size-4 shrink-0 text-gold" />{t}</li>
                  ))}
                </ul>
              </>
            ) : (
              <>
                <h2 className="mt-3 font-display text-2xl font-extrabold text-white">Have questions? <span className="text-gold">Get a callback</span></h2>
                <p className="mt-2 text-sm leading-relaxed text-white/75">Talk to a counsellor about {course.title} - batches, fees, study plan and anything else. We usually call back within a few working hours.</p>
              </>
            )}
          </div>
          <div className="min-w-0 rounded-2xl bg-white p-4 text-ink sm:p-5">
            {admission ? (
              <EnquiryForm source="course_page" course={{ slug: course.slug, title: course.title }} compact admission idPrefix="course-enq" submitLabel="Apply Now"
                successMessage={`Thank you! Your application for ${course.title} is received. Our admission counsellor will call you shortly.`} />
            ) : <EnquiryForm source="course_page" course={course.slug} compact idPrefix="course-enq" />}
          </div>
        </div>
      </div>
    </Reveal>
  );
}

function DetailSkeleton() {
  return (
    <div aria-busy="true" aria-label="Loading course">
      <div className="bg-navy-mesh">
        <div className="container-x grid gap-8 py-10 lg:grid-cols-[minmax(0,1fr)_460px]">
          <div className="space-y-4">
            <div className="h-4 w-40 rounded bg-white/15" />
            <div className="h-10 w-4/5 rounded-lg bg-white/15" />
            <div className="h-5 w-1/2 rounded bg-white/10" />
            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">{Array.from({ length: 4 }, (_, i) => <div key={i} className="h-14 rounded-xl bg-white/10" />)}</div>
          </div>
          <div className="aspect-video rounded-2xl bg-white/10" />
        </div>
      </div>
      <div className="container-x grid gap-8 py-8 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="space-y-4"><Skeleton className="h-60" rounded="rounded-2xl" /><Skeleton className="h-80" rounded="rounded-2xl" /></div>
        <Skeleton className="hidden h-96 lg:block" rounded="rounded-2xl" />
      </div>
    </div>
  );
}
