import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { API_BASE, api, formatDate } from '../js/api.js';
import { Alert, EmptyState, ErrorState, Icon, Price, ShareButtons, Skeleton, Spinner, useApi, useSeo } from '../js/components.jsx';
import { LESSON_ICON, ProgressBar, formatMinutes, lessonTypeLabel, plural, useBodyLock, useEscape } from '../js/course/ui.jsx';
import { AskDoubt } from '../js/account/AskDoubt.jsx';
import NotFound from './NotFound.jsx';

/** Resume position is saved at most this often while a video plays (the API throttles to 10s). */
const SAVE_EVERY_MS = 15000;

export default function LessonPlayer() {
  const { slug, lessonId } = useParams();
  const navigate = useNavigate();
  // ?as=student (admin "Preview as student"): staff get the outline a non-enrolled student sees. Kept on every link.
  const [search] = useSearchParams();
  const asStudent = search.get('as') === 'student';
  const qs = asStudent ? '?as=student' : '';
  const player = useApi('lessons.php', { action: 'player', course: slug, ...(asStudent ? { as: 'student' } : {}) });
  const [progress, setProgress] = useState(null);
  const [done, setDone] = useState({});
  const [drawer, setDrawer] = useState(false);
  const [upNext, setUpNext] = useState(null); // { lesson, auto }
  const [celebrate, setCelebrate] = useState(false);

  const data = player.data;
  const viewer = data?.viewer || {};
  const enrolment = data?.enrolment || null;
  const flat = useMemo(() => (data ? data.sections.flatMap((s) => s.lessons) : []), [data]);
  const isDone = useCallback((l) => done[l.id] ?? l.completed, [done]);

  useSeo({ title: data ? `${data.course.title} | Learn` : 'Learn', robots: 'noindex,nofollow' });
  useEffect(() => { if (data) setProgress(enrolment?.progress ?? null); }, [data]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { setUpNext(null); window.scrollTo({ top: 0 }); }, [lessonId]);

  if (player.error?.status === 404) return <NotFound />;
  if (player.error) return <div className="container-x"><ErrorState error={player.error} onRetry={player.reload} /></div>;
  if (player.loading || !data) return <PlayerSkeleton />;

  const { course, sections, counts = {} } = data;
  const requested = Number(lessonId) || null;
  const firstOpen = flat.find((l) => !l.locked && !isDone(l)) || flat.find((l) => !l.locked);
  const resume = data.next_lesson_id && flat.find((l) => l.id === data.next_lesson_id && !l.locked);
  const current = requested ? flat.find((l) => l.id === requested) : resume || firstOpen || null;
  const index = current ? flat.indexOf(current) : -1;
  const prev = index > 0 ? flat[index - 1] : null;
  const next = index >= 0 && index < flat.length - 1 ? flat[index + 1] : null;
  const nextOpen = index >= 0 ? flat.slice(index + 1).find((l) => !l.locked) : null;
  const completedCount = flat.filter(isDone).length;
  const pct = progress ?? enrolment?.progress ?? 0;
  const go = (l) => { navigate(`/learn/${slug}/${l.id}${qs}`); setDrawer(false); };
  // Staff previewing as a student see the student's locks (viewer.preview_only) but no progress is saved.
  const staffUnlocked = viewer.is_staff && !viewer.preview_only;

  const onComplete = (r, auto) => {
    if (r) {
      setProgress(r.progress);
      setDone((d) => ({ ...d, [current.id]: true }));
      if (r.course_completed || (r.progress >= 100 && (progress ?? enrolment?.progress ?? 0) < 100)) { setCelebrate(true); return; }
    }
    if (nextOpen) setUpNext({ lesson: nextOpen, auto });
  };

  const outline = (
    <Outline sections={sections} currentId={current?.id} isDone={isDone} enrolled={!!enrolment} staff={staffUnlocked} onPick={go} />
  );

  return (
    <div className="min-h-[70vh] bg-surface">
      {/* Top bar */}
      <div className="bg-navy-mesh text-white">
        <div className="container-x flex flex-wrap items-center justify-between gap-3 py-3.5">
          <div className="min-w-0">
            <Link to={enrolment ? '/dashboard/courses' : `/courses/${course.slug}${qs}`} className="inline-flex items-center gap-1 text-xs font-semibold text-white/70 hover:text-white">
              <Icon name="chevronLeft" className="size-3.5" />{enrolment ? 'My courses' : 'Course details'}
            </Link>
            <h1 className="truncate font-display text-lg font-extrabold text-white sm:text-xl">{course.title}</h1>
          </div>
          {viewer.is_staff && viewer.preview_only ? (
            <span className="rounded-full bg-gold/20 px-3 py-1 text-xs font-bold text-gold ring-1 ring-gold/40">Preview as student · locked lessons stay locked · progress not saved</span>
          ) : enrolment ? (
            <div className="flex min-w-0 items-center gap-3">
              <ProgressBar value={pct} className="w-32 bg-white/15 sm:w-44" tone="from-gold to-[#ffb020]" />
              <span className="text-sm font-bold">{Math.round(pct)}%</span>
              <span className="hidden text-xs text-white/70 md:inline">{completedCount}/{flat.length} done{enrolment.expires_at ? ` · access until ${formatDate(enrolment.expires_at)}` : ''}</span>
            </div>
          ) : viewer.is_staff ? (
            <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-bold ring-1 ring-white/20">Staff view · all lessons unlocked · progress not saved</span>
          ) : (
            <Link to={enrolHref(course, viewer)} className="btn-gold btn-sm">
              <Icon name="lock" className="size-3.5" />{viewer.enrolment_status === 'expired' ? 'Renew access' : `Enroll to unlock ${plural(counts.locked ?? flat.filter((l) => l.locked).length, 'lesson')}`}
            </Link>
          )}
        </div>
      </div>

      {enrolment?.completed_at && !celebrate && (
        <div className="border-b border-emerald-100 bg-emerald-50">
          <p className="container-x flex flex-wrap items-center gap-x-3 gap-y-1 py-2 text-sm text-emerald-800">
            <Icon name="trophy" className="size-4" />You completed this course on {formatDate(enrolment.completed_at)}.
            <Link to="/dashboard/certificates" className="font-semibold underline">My certificates</Link>
          </p>
        </div>
      )}

      <div className="container-x grid gap-6 py-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="min-w-0">
          <button type="button" onClick={() => setDrawer(true)} className="btn-light mb-4 w-full justify-between lg:hidden" aria-haspopup="dialog">
            <span className="flex items-center gap-2"><Icon name="layers" className="size-4" />Course content</span>
            <span className="text-xs text-muted">{enrolment ? `${completedCount}/${flat.length} done` : plural(flat.length, 'lesson')}</span>
          </button>

          {flat.length === 0 ? (
            <EmptyState icon="book" title="Lessons are being prepared" text="The first lessons of this course will appear here soon." action={<Link to={`/courses/${course.slug}`} className="btn-primary">Back to the course</Link>} />
          ) : requested && !current ? (
            <EmptyState icon="search" title="This lesson is not part of the course" text="It may have been moved or unpublished." action={firstOpen ? <button type="button" onClick={() => go(firstOpen)} className="btn-primary">Open the first lesson</button> : null} />
          ) : !current || current.locked ? (
            <LockPanel course={course} viewer={viewer} lesson={current} flat={flat} onPick={go} />
          ) : (
            <LessonView
              key={current.id}
              id={current.id}
              canTrack={!!enrolment && viewer.can_track !== false}
              staff={!!viewer.is_staff}
              guest={!viewer.logged_in}
              course={course}
              onComplete={onComplete}
              upNext={upNext}
              onDismissNext={() => setUpNext(null)}
              onGoNext={() => upNext && go(upNext.lesson)}
            />
          )}

          {flat.length > 0 && (
            <div className="mt-4 flex items-center justify-between gap-3">
              <button type="button" disabled={!prev} onClick={() => go(prev)} className="btn-light min-w-0"><Icon name="chevronLeft" className="size-4" /><span className="truncate">Previous</span></button>
              <button type="button" disabled={!next} onClick={() => go(next)} className="btn-primary min-w-0">
                <span className="truncate">{next?.locked ? 'Next (locked)' : 'Next lesson'}</span><Icon name={next?.locked ? 'lock' : 'chevronRight'} className="size-4" />
              </button>
            </div>
          )}
        </div>

        <aside className="hidden min-w-0 lg:block" aria-label="Course content">
          <div className="card sticky top-24 overflow-hidden">
            <div className="border-b border-line p-4">
              <p className="font-display font-bold text-navy">Course content</p>
              <p className="text-xs text-muted">{enrolment ? `${completedCount} of ${plural(flat.length, 'lesson')} completed` : `${plural(flat.length, 'lesson')} · ${plural(counts.preview ?? flat.filter((l) => l.is_preview).length, 'free preview')}`}</p>
              {enrolment && <ProgressBar value={pct} className="mt-2" tone="from-emerald-500 to-teal" />}
            </div>
            <div className="scrollbar-thin max-h-[calc(100vh-14rem)] overflow-y-auto">{outline}</div>
          </div>
        </aside>
      </div>

      {drawer && <OutlineDrawer onClose={() => setDrawer(false)} title={course.title} summary={enrolment ? `${completedCount}/${flat.length} completed` : plural(flat.length, 'lesson')}>{outline}</OutlineDrawer>}
      {celebrate && <Celebration course={course} onClose={() => setCelebrate(false)} />}
    </div>
  );
}

/** Where "Enrol" goes: paid → checkout (login handled there), free → the course page's enrol button. */
function enrolHref(course, viewer) {
  if (course.is_free) return `/courses/${course.slug}`;
  return viewer.logged_in ? `/checkout/${course.slug}` : `/login?next=${encodeURIComponent(`/checkout/${course.slug}`)}`;
}

// ------------------------------------------------------------------
// Outline (sidebar + mobile drawer)
// ------------------------------------------------------------------
function Outline({ sections, currentId, isDone, enrolled, staff, onPick }) {
  return (
    <nav aria-label="Lessons">
      {sections.map((s, si) => (
        <div key={s.id}>
          <p className="sticky top-0 z-[1] border-b border-line bg-surface/95 px-4 py-2.5 text-xs font-bold tracking-wide text-navy uppercase backdrop-blur">
            <span className="text-primary">{String(si + 1).padStart(2, '0')}</span> · {s.title}
          </p>
          <ul>
            {s.lessons.map((l) => {
              const completed = isDone(l);
              const active = l.id === currentId;
              const dur = formatMinutes(l.duration_minutes);
              return (
                <li key={l.id}>
                  <button type="button" onClick={() => onPick(l)} aria-current={active ? 'step' : undefined}
                    className={'flex w-full items-start gap-3 border-b border-line px-4 py-3 text-left text-sm transition-colors ' + (active ? 'bg-primary-soft shadow-[inset_3px_0_0_var(--color-primary)]' : 'hover:bg-surface')}>
                    <span className={'mt-0.5 grid size-7 shrink-0 place-items-center rounded-lg ' + (completed ? 'bg-emerald-50 text-emerald-600' : l.locked ? 'bg-surface text-muted' : 'bg-primary-soft text-primary')}>
                      <Icon name={completed ? 'checkCircle' : l.locked ? 'lock' : LESSON_ICON[l.type] || 'file'} className="size-4" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className={'block leading-snug ' + (active ? 'font-semibold text-navy' : l.locked ? 'text-ink/70' : 'text-ink')}>{l.title}</span>
                      <span className="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-muted">
                        {lessonTypeLabel(l.type)}{dur && <> · {dur}</>}
                        {l.is_preview && !enrolled && !staff && <span className="rounded-full bg-primary px-2 py-px text-[10px] font-bold text-white">Preview</span>}
                        {l.locked && <span className="sr-only">(locked - enrol to unlock)</span>}
                      </span>
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}

function OutlineDrawer({ title, summary, onClose, children }) {
  useBodyLock(true);
  useEscape(true, onClose);
  return (
    <div className="fixed inset-0 z-[60] lg:hidden" role="dialog" aria-modal="true" aria-labelledby="outline-title">
      <button type="button" aria-label="Close course content" onClick={onClose} className="absolute inset-0 animate-fade-in bg-navy-deep/55" />
      <div className="absolute inset-x-0 bottom-0 flex max-h-[85dvh] animate-slide-up flex-col rounded-t-3xl bg-white shadow-2xl">
        <div className="mx-auto mt-2.5 h-1.5 w-12 rounded-full bg-line" aria-hidden="true" />
        <div className="flex items-center justify-between gap-3 border-b border-line px-5 py-3">
          <div className="min-w-0">
            <h2 id="outline-title" className="truncate font-display font-bold text-navy">{title}</h2>
            <p className="text-xs text-muted">{summary}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Close" className="grid size-10 shrink-0 place-items-center rounded-xl hover:bg-surface"><Icon name="x" /></button>
        </div>
        <div className="safe-bottom min-h-0 flex-1 overflow-y-auto">{children}</div>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------
// Locked lesson / nothing unlocked
// ------------------------------------------------------------------
function LockPanel({ course, viewer, lesson, flat, onPick }) {
  const location = useLocation();
  const previews = flat.filter((l) => !l.locked).slice(0, 4);
  const lockedCount = flat.filter((l) => l.locked).length;
  const expired = viewer.enrolment_status === 'expired';
  return (
    <div className="card relative overflow-hidden">
      <div className="relative grid aspect-video max-h-[420px] w-full place-items-center bg-navy-mesh p-6 text-center text-white">
        <div aria-hidden="true" className="dot-grid absolute inset-0 opacity-40" />
        <div className="relative">
          <span className="mx-auto grid size-16 place-items-center rounded-2xl bg-white/10 ring-1 ring-white/20"><Icon name="lock" className="size-8 text-gold" /></span>
          <p className="mt-4 font-display text-xl font-extrabold sm:text-2xl">{lesson ? lesson.title : `${plural(lockedCount, 'lesson')} waiting for you`}</p>
          <p className="mx-auto mt-2 max-w-md text-sm text-white/75">
            {expired ? 'Your access to this course has expired. Renew it to continue where you left off.'
              : viewer.logged_in ? 'This lesson is part of the full course. Enrol to unlock every lesson, notes and practice tests.'
                : 'Log in if you are already enrolled, or enrol to unlock every lesson.'}
          </p>
        </div>
      </div>
      <div className="p-5 sm:p-6">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <Price course={course} size="lg" />
          <div className="flex flex-wrap gap-2">
            {!viewer.logged_in && <Link to={`/login?next=${encodeURIComponent(location.pathname)}`} className="btn-light">Log in</Link>}
            <Link to={enrolHref(course, viewer)} className="btn-primary">{expired ? 'Renew access' : course.is_free ? 'Enroll free' : 'Enroll now'}<Icon name="arrowRight" className="size-4" /></Link>
          </div>
        </div>
        <Link to={`/courses/${course.slug}`} className="mt-3 inline-block text-sm font-semibold text-primary hover:underline">See everything in this course</Link>
        {previews.length > 0 && (
          <div className="mt-5 border-t border-line pt-4">
            <p className="text-sm font-semibold text-navy">Watch a free preview</p>
            <ul className="mt-2 grid gap-2 sm:grid-cols-2">
              {previews.map((l) => (
                <li key={l.id}>
                  <button type="button" onClick={() => onPick(l)} className="flex w-full items-center gap-2.5 rounded-xl border border-line p-3 text-left text-sm transition-colors hover:border-primary hover:bg-primary-soft/40">
                    <Icon name={LESSON_ICON[l.type] || 'playCircle'} className="size-4 shrink-0 text-primary" /><span className="min-w-0 flex-1 truncate">{l.title}</span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </div>
  );
}

// ------------------------------------------------------------------
// One lesson: content, resume position, progress, completion
// ------------------------------------------------------------------
let beaconToken = null;
/**
 * CSRF token for navigator.sendBeacon: a beacon cannot set the X-CSRF-Token header, so lessons.php
 * progress() also accepts a multipart form with an `_csrf` field (plus lesson_id, completed=0, position).
 * Re-read for every tracked lesson so a re-login in another tab never leaves a stale token behind.
 */
async function refreshBeaconToken() {
  try { beaconToken = (await api.get('auth.php', { action: 'csrf' })).token; } catch { /* the beacon is skipped; unmount still saves */ }
  return beaconToken;
}

const fmtTime = (s) => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`;

function LessonView({ id, canTrack, staff, guest, course, onComplete, upNext, onDismissNext, onGoNext }) {
  const { data, loading, error, reload } = useApi('lessons.php', { action: 'lesson', id });
  const location = useLocation();
  const [completed, setCompleted] = useState(false);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState(null);
  const [resumedAt, setResumedAt] = useState(0);
  const videoRef = useRef(null);
  const lastSave = useRef({ at: 0, pos: -1 });
  const timeRef = useRef(0); // last known playback time (the video element is gone during unmount cleanup)

  useEffect(() => { if (data) setCompleted(!!data.completed); }, [data]);
  useEffect(() => { if (canTrack) refreshBeaconToken(); }, [canTrack]);

  const position = () => Math.floor(videoRef.current?.currentTime ?? timeRef.current ?? 0);

  /** Resume-position save (never marks the lesson complete). */
  const savePosition = useCallback((force = false) => {
    if (!canTrack) return;
    const pos = position();
    const now = Date.now();
    if (pos < 3 || pos === lastSave.current.pos) return;
    if (!force && now - lastSave.current.at < SAVE_EVERY_MS) return;
    lastSave.current = { at: now, pos };
    api.post('lessons.php', { lesson_id: id, completed: false, position: pos }, { action: 'progress' }).catch(() => {});
  }, [canTrack, id]);

  // On tab hide / page unload use a beacon; on route change (unmount) a normal request still completes.
  useEffect(() => {
    if (!canTrack) return undefined;
    const beacon = () => {
      const pos = position();
      if (pos < 3 || pos === lastSave.current.pos || !beaconToken || !navigator.sendBeacon) return;
      const form = new FormData();
      form.append('_csrf', beaconToken);
      form.append('lesson_id', String(id));
      form.append('completed', '0');
      form.append('position', String(pos));
      if (navigator.sendBeacon(`${API_BASE}lessons.php?action=progress`, form)) lastSave.current = { at: Date.now(), pos };
    };
    const onVis = () => { if (document.visibilityState === 'hidden') beacon(); };
    document.addEventListener('visibilitychange', onVis);
    window.addEventListener('pagehide', beacon);
    return () => {
      document.removeEventListener('visibilitychange', onVis);
      window.removeEventListener('pagehide', beacon);
      savePosition(true);
    };
  }, [canTrack, id, savePosition]);

  const markComplete = async (auto = false) => {
    if (!canTrack) { onComplete(null, auto); return; }
    if (completed) { onComplete(null, auto); return; }
    setSaving(true);
    setMsg(null);
    try {
      const r = await api.post('lessons.php', { lesson_id: id, completed: true, position: position() }, { action: 'progress' });
      setCompleted(true);
      onComplete(r, auto);
    } catch (e) {
      setMsg(e.message);
    } finally {
      setSaving(false);
    }
  };

  // lessons.php answers 401 (guest, code unauthenticated) or 403 (not_enrolled | enrolment_expired) for a
  // locked lesson - e.g. when access changed after the outline loaded. Offer the matching way in.
  if (error && (error.status === 401 || error.status === 403)) {
    const expired = error.code === 'enrolment_expired';
    return (
      <div className="card p-6 text-center">
        <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-amber-50 text-amber-600"><Icon name="lock" className="size-7" /></span>
        <p className="mt-3 font-display text-lg font-bold text-navy">{error.message}</p>
        <div className="mt-4 flex flex-wrap justify-center gap-2">
          {error.status === 401 && <Link to={`/login?next=${encodeURIComponent(location.pathname + location.search)}`} className="btn-light">Log in</Link>}
          <Link to={enrolHref(course, { logged_in: error.status !== 401 })} className="btn-primary">
            {expired ? 'Renew access' : course.is_free ? 'Enroll free' : 'Enroll now'}<Icon name="arrowRight" className="size-4" />
          </Link>
          <Link to={`/courses/${course.slug}`} className="btn-light">View course</Link>
        </div>
      </div>
    );
  }
  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading || !data) return <div className="card overflow-hidden"><Skeleton className="aspect-video" rounded="rounded-none" /><div className="space-y-3 p-6"><Skeleton className="h-4 w-32" /><Skeleton className="h-7 w-3/4" /><Skeleton className="h-4 w-full" /></div></div>;

  const c = data.content || {};
  const dur = formatMinutes(data.duration_minutes);
  const downloadUrl = c.download_url || (c.downloadable && c.file_url ? `${c.file_url}&download=1` : null);

  return (
    <article className="card overflow-hidden">
      <div className="relative">
        {c.kind === 'video' && (c.video_url ? (
          <video
            ref={videoRef} src={c.video_url} controls controlsList="nodownload" preload="metadata" playsInline
            className="aspect-video w-full bg-black"
            onLoadedMetadata={(e) => {
              const v = e.currentTarget;
              if (data.position > 5 && (!v.duration || data.position < v.duration - 5)) { v.currentTime = data.position; setResumedAt(data.position); }
            }}
            onTimeUpdate={(e) => { timeRef.current = e.currentTarget.currentTime; savePosition(false); }}
            onPause={(e) => { timeRef.current = e.currentTarget.currentTime; savePosition(true); }}
            onEnded={() => markComplete(true)}
            onContextMenu={(e) => e.preventDefault()}
          />
        ) : <MediaPlaceholder text="The video for this lesson will be available soon." />)}
        {c.kind === 'external_video' && (c.embed_url ? (
          <iframe src={c.embed_url} title={data.title} className="aspect-video w-full bg-black" allow="encrypted-media; picture-in-picture; fullscreen" allowFullScreen />
        ) : <MediaPlaceholder text="The video for this lesson will be available soon." />)}
        {c.kind === 'pdf' && (c.file_url ? (
          <iframe src={c.file_url} title={data.title} className="h-[70vh] w-full bg-surface" />
        ) : <MediaPlaceholder icon="file" text="The notes for this lesson will be uploaded soon." />)}
        {resumedAt > 0 && (
          <div role="status" className="absolute top-3 left-3 flex animate-fade-in items-center gap-2 rounded-full bg-navy-deep/80 py-1 pr-1 pl-3 text-xs font-semibold text-white backdrop-blur">
            Resumed at {fmtTime(resumedAt)}
            <button type="button" onClick={() => { if (videoRef.current) videoRef.current.currentTime = 0; setResumedAt(0); }} className="rounded-full px-2 py-0.5 underline hover:bg-white/10">Start over</button>
            <button type="button" onClick={() => setResumedAt(0)} aria-label="Dismiss" className="grid size-6 place-items-center rounded-full hover:bg-white/10"><Icon name="x" className="size-3.5" /></button>
          </div>
        )}
      </div>

      <div className="p-5 sm:p-7">
        <div className="flex flex-wrap items-center gap-2 text-xs font-bold tracking-wide text-primary uppercase">
          <Icon name={LESSON_ICON[data.type] || 'file'} className="size-4" />{lessonTypeLabel(data.type)}{dur && <span className="text-muted">· {dur}</span>}
          {data.section && <span className="truncate font-semibold text-muted normal-case">· {data.section}</span>}
          {data.access === 'preview' && <span className="rounded-full bg-gold px-2 py-0.5 text-[10px] text-navy">Free preview</span>}
        </div>
        <h2 className="mt-1.5 font-display text-xl font-extrabold text-navy sm:text-2xl">{data.title}</h2>

        {c.html && <div className="prose-lite mt-4 text-[15px] text-ink/90 [&_h2]:text-lg [&_h2]:font-bold [&_li]:mb-1" dangerouslySetInnerHTML={{ __html: c.html }} />}
        {c.kind === 'live' && <Link to={guest ? '/live-classes' : '/dashboard/live-classes'} className="btn-primary mt-4"><Icon name="video" className="size-4" />Go to live classes</Link>}
        {c.kind === 'quiz' && (c.exam_slug ? <Link to={`/mock-tests/${c.exam_slug}`} className="btn-primary mt-4"><Icon name="clipboard" className="size-4" />Open practice test</Link> : <p className="mt-4 text-sm text-muted">The practice test for this lesson will be published soon.</p>)}
        {c.kind === 'pdf' && downloadUrl && <a href={downloadUrl} className="btn-outline mt-4"><Icon name="file" className="size-4" />Download PDF</a>}
        {c.kind === 'text' && !c.html && <p className="mt-4 text-sm text-muted">The reading for this lesson will be added soon.</p>}

        {msg && <Alert className="mt-4">{msg}</Alert>}

        {upNext && (
          <UpNext lesson={upNext.lesson} auto={upNext.auto} onGo={onGoNext} onDismiss={onDismissNext} />
        )}

        <div className="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-5">
          {canTrack ? (
            <div className="flex flex-wrap items-center gap-3">
              {completed ? (
                <span className="flex items-center gap-2 font-semibold text-emerald-700"><Icon name="checkCircle" />Completed</span>
              ) : (
                <button type="button" disabled={saving} onClick={() => markComplete(false)} className="btn-primary"><Icon name="check" className="size-4" />{saving ? 'Saving…' : 'Mark as complete'}</button>
              )}
              <AskDoubt lessonId={id} title={`Doubt on: ${data.title}`} />
            </div>
          ) : staff ? (
            <p className="text-sm text-muted">Staff preview - progress is not tracked.</p>
          ) : (
            <p className="flex items-center gap-2 text-sm text-navy"><Icon name="info" className="size-4 text-primary" />You are watching a free preview. Enrol to track progress and unlock every lesson.</p>
          )}
          <ShareButtons url={course.share_url || `/courses/${course.slug}`} title={course.title} label="Share course:" size="sm" />
        </div>
      </div>
    </article>
  );
}

/** "Up next" prompt after a lesson; counts down when the video ended on its own. */
function UpNext({ lesson, auto, onGo, onDismiss }) {
  const reduce = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  const [left, setLeft] = useState(auto && !reduce ? 8 : null);
  useEffect(() => {
    if (left === null) return undefined;
    if (left <= 0) { onGo(); return undefined; }
    const t = setTimeout(() => setLeft((n) => n - 1), 1000);
    return () => clearTimeout(t);
  }, [left]); // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <div role="status" className="mt-6 flex animate-fade-up flex-wrap items-center justify-between gap-3 rounded-2xl border border-primary/25 bg-gradient-to-r from-primary-soft to-white p-4">
      <div className="flex min-w-0 items-center gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary text-white"><Icon name={LESSON_ICON[lesson.type] || 'playCircle'} className="size-5" /></span>
        <div className="min-w-0">
          <p className="text-xs font-bold tracking-wide text-primary uppercase">Up next{left !== null ? ` in ${left}s` : ''}</p>
          <p className="truncate font-semibold text-navy">{lesson.title}</p>
        </div>
      </div>
      <div className="flex gap-2">
        <button type="button" onClick={() => (left !== null ? setLeft(null) : onDismiss())} className="btn-light btn-sm">{left !== null ? 'Stay here' : 'Not now'}</button>
        <button type="button" onClick={onGo} className="btn-primary btn-sm">Next lesson<Icon name="arrowRight" className="size-3.5" /></button>
      </div>
    </div>
  );
}

function Celebration({ course, onClose }) {
  useBodyLock(true);
  useEscape(true, onClose);
  const confetti = ['bg-gold', 'bg-coral', 'bg-primary', 'bg-violet', 'bg-teal', 'bg-emerald-500'];
  return (
    <div className="fixed inset-0 z-[70] grid place-items-center p-4" role="dialog" aria-modal="true" aria-labelledby="done-title">
      <button type="button" aria-label="Close" onClick={onClose} className="absolute inset-0 animate-fade-in bg-navy-deep/70 backdrop-blur-sm" />
      <div className="relative w-full max-w-md animate-scale-in overflow-hidden rounded-3xl bg-white p-7 text-center shadow-2xl">
        <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 top-0 h-40 overflow-hidden">
          {Array.from({ length: 18 }, (_, i) => (
            <span key={i} className={'absolute block size-2 animate-float rounded-sm ' + confetti[i % confetti.length]}
              style={{ left: `${(i * 53) % 100}%`, top: `${(i * 29) % 70}%`, transform: `rotate(${i * 37}deg)`, animationDelay: `${(i % 6) * 120}ms` }} />
          ))}
        </div>
        <span className="relative mx-auto grid size-20 animate-pop place-items-center rounded-full bg-gradient-to-br from-gold to-[#ffb020] text-navy shadow-glow-gold">
          <Icon name="trophy" className="size-10" />
        </span>
        <h2 id="done-title" className="relative mt-5 font-display text-2xl font-extrabold text-navy">Course completed!</h2>
        <p className="relative mt-2 text-sm text-muted">Congratulations - you finished every lesson of <strong className="text-ink">{course.title}</strong>. Your certificate appears in My Certificates as soon as it is issued.</p>
        <div className="relative mt-6 grid gap-2">
          <Link to="/dashboard/certificates" className="btn-primary"><Icon name="badge" className="size-4" />My certificates</Link>
          <Link to={`/courses/${course.slug}#reviews`} className="btn-light"><Icon name="star" className="size-4" />Rate this course</Link>
          <Link to="/courses" className="btn-light">Explore more courses</Link>
        </div>
        <button type="button" onClick={onClose} className="relative mt-4 text-sm font-semibold text-muted hover:text-ink">Close</button>
      </div>
    </div>
  );
}

function MediaPlaceholder({ text, icon = 'playCircle' }) {
  return (
    <div className="grid aspect-video place-items-center bg-navy-mesh text-center text-white/80">
      <div><Icon name={icon} className="mx-auto size-12 text-white/40" /><p className="mt-3 px-6 text-sm">{text}</p></div>
    </div>
  );
}

function PlayerSkeleton() {
  return (
    <div aria-busy="true">
      <div className="h-[72px] bg-navy-mesh" />
      <div className="container-x grid gap-6 py-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <Skeleton className="aspect-video" rounded="rounded-2xl" />
        <Skeleton className="hidden h-[420px] lg:block" rounded="rounded-2xl" />
      </div>
      <Spinner className="sr-only" />
    </div>
  );
}
