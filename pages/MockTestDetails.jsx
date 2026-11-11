// Mock test details (/mock-tests/:slug). The exam room (/attempt/:id) and the result
// page (/result/:id) live in js/exam/ and are re-exported here for the router.
import { useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { api, formatDateTime } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { Alert, CategoryPill, DemoBadge, ErrorState, Icon, Reveal, ShareButtons, Skeleton, useApi, useSeo } from '../js/components.jsx';
import { ACCESS, ATTEMPT_STATUS, ModeBadge, WindowBadge, attemptMarks, fmtNum } from '../js/exam/shared.jsx';

export { ExamRoom } from '../js/exam/ExamRoom.jsx';
export { ExamResult } from '../js/exam/ExamResult.jsx';

const RELEASE = {
  immediate: 'Immediately after you submit',
  after_end: 'After the test window closes',
  manual: 'When the academy publishes results',
};
const KEY_NOTE = {
  after_submit: 'The answer key and explanations are shown on your scorecard right after you submit.',
  after_result: 'The answer key unlocks once your result is final - after your last allowed attempt, or when the test closes.',
  never: 'The answer key for this test is not published. Your scorecard still shows your score, rank and topic-wise analysis.',
};

/** Unknown, unpublished or removed test (API 404): a real not-found state, no retry button. */
function TestNotFound() {
  return (
    <section className="relative isolate overflow-hidden bg-mesh">
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:radial-gradient(circle_at_center,black,transparent_75%)]" />
      <div className="container-x py-16 text-center sm:py-24">
        <span className="mx-auto grid size-16 animate-scale-in place-items-center rounded-2xl bg-gradient-to-br from-violet to-primary text-white shadow-card-hover">
          <Icon name="clipboard" className="size-8" />
        </span>
        <h1 className="heading-lg mt-6">Test not found</h1>
        <p className="mx-auto mt-3 max-w-md text-muted">This mock test or quiz does not exist or is no longer available. Browse the tests that are open right now.</p>
        <div className="mt-8 flex flex-wrap justify-center gap-3">
          <Link to="/mock-tests" className="btn-primary"><Icon name="chevronLeft" className="size-4" />All mock tests</Link>
          <Link to="/" className="btn-light"><Icon name="home" className="size-4" />Go to homepage</Link>
        </div>
      </div>
    </section>
  );
}

export default function MockTestDetails() {
  const { slug } = useParams();
  const { user } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const { data: t, loading, error, reload } = useApi('mock-tests.php', { action: 'detail', slug, u: user?.id || 0 });
  const [agree, setAgree] = useState(false);
  const [starting, setStarting] = useState(false);
  const [startError, setStartError] = useState(null);
  const missing = error?.status === 404;
  // One useSeo call for every state (a child's useSeo would run first and be overwritten by this one)
  useSeo({
    title: missing ? 'Test not found | Laxyam Academy' : t ? `${t.title} | ${t.mode === 'practice' ? 'Practice Quiz' : 'Mock Test'} - Laxyam Academy` : 'Mock Test - Laxyam Academy',
    description: t?.description,
    robots: missing ? 'noindex,follow' : undefined,
  });

  if (missing) return <TestNotFound />;
  if (error) return <div className="container-x"><ErrorState error={error} onRetry={reload} /></div>;
  if (loading || !t) return <DetailSkeleton />;

  const practice = t.mode === 'practice';
  const qCount = t.questions_count ?? t.question_count;
  // Marks per attempt from the question bank (null when a random pool with mixed marks makes it vary).
  const marks = attemptMarks(t);
  const allowed = t.attempts_allowed ?? t.max_attempts;
  const lastFinished = t.attempts?.[0];

  const start = async () => {
    setStarting(true);
    setStartError(null);
    try {
      const r = await api.post('mock-tests.php', { slug }, { action: 'start' });
      navigate(`/mock-tests/${slug}/attempt/${r.attempt_id}`);
    } catch (e) {
      setStartError(e.message);
      setStarting(false);
      if (e.status === 403) reload();
    }
  };

  const stats = [
    ['help', qCount, 'Questions'],
    ['clock', practice || t.timed === false ? 'Untimed' : `${t.duration_minutes} min`, 'Duration'],
    ['trophy', t.marks_vary ? 'Varies' : fmtNum(marks), t.marks_vary ? 'Total marks vary by attempt' : 'Total marks'],
    ['target', allowed > 0 ? allowed : 'Unlimited', 'Attempts'],
  ];
  const scheme = [
    // uniform_marks comes from the question bank: set only when every question carries the same marks.
    ['Correct answer', !(qCount > 0) ? '-' : t.uniform_marks !== null && t.uniform_marks !== undefined ? `+${fmtNum(t.uniform_marks)} per question` : 'Marks vary by question'],
    // A question may carry its own negative value; the exam room shows the exact figure per question.
    ['Wrong answer', t.negative_marking ? (t.default_negative_marks > 0 ? `−${fmtNum(t.default_negative_marks)} marks (unless the question shows otherwise)` : 'Negative marks as shown on each question') : 'No negative marking'],
    ['Unanswered', '0 marks'],
    ['Pass marks', t.pass_marks !== null && t.pass_marks !== undefined ? (t.marks_vary ? `${fmtNum(t.pass_marks)} marks (total varies by attempt)` : `${fmtNum(t.pass_marks)} of ${fmtNum(marks)}`) : 'No pass mark - score and rank only'],
    ['Duration', practice || t.timed === false ? 'Untimed practice - instant feedback per question'
      : t.sectional_timing ? `${t.duration_minutes} minutes - each section has its own timer` : `${t.duration_minutes} minutes (auto-submits at 00:00)`],
    ...(t.bilingual ? [['Language', 'English + हिंदी - switch any time during the test']] : []),
    ['Attempts allowed', allowed > 0 ? `${allowed}${t.attempts_left !== null && t.attempts_left !== undefined && user ? ` (${t.attempts_left} left)` : ''}` : 'Unlimited'],
    ['Question order', t.shuffle_questions ? 'Shuffled for every attempt' : 'Same for everyone'],
    ['Option order', t.shuffle_options ? 'Options shuffled for every attempt' : 'As set by the academy'],
    ['Result', RELEASE[t.result_release] || RELEASE.immediate],
  ];

  return (
    <div className="bg-surface pb-16">
      {/* Hero */}
      <section className="relative isolate overflow-hidden bg-navy-mesh text-white">
        <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40 [mask-image:linear-gradient(to_bottom,black,transparent)]" />
        <div aria-hidden="true" className="absolute -top-24 -right-20 -z-10 size-80 rounded-full bg-primary/30 blur-3xl" />
        <div className="container-x py-8 sm:py-12">
          <nav aria-label="Breadcrumb" className="text-sm text-white/70">
            <ol className="flex flex-wrap items-center gap-1.5">
              <li><Link to="/" className="hover:text-white">Home</Link></li>
              <li aria-hidden="true"><Icon name="chevronRight" className="size-3.5" /></li>
              <li><Link to="/mock-tests" className="hover:text-white">Mock Tests</Link></li>
              <li aria-hidden="true"><Icon name="chevronRight" className="size-3.5" /></li>
              <li aria-current="page" className="min-w-0 truncate font-semibold text-white">{t.title}</li>
            </ol>
          </nav>
          <div className="mt-5 flex flex-wrap items-center gap-2">
            <ModeBadge mode={t.mode} className="bg-white/95" />
            {t.category && <span className="rounded-full bg-white/95 px-1 py-0.5"><CategoryPill category={t.category} /></span>}
            <WindowBadge window={t.window} />
            {t.subject && <span className="rounded-full bg-white/10 px-2.5 py-0.5 text-[11px] font-bold ring-1 ring-white/20">{t.subject}</span>}
            {t.bilingual && <span className="inline-flex items-center gap-1 rounded-full bg-white/10 px-2.5 py-0.5 text-[11px] font-bold ring-1 ring-white/20"><Icon name="languages" className="size-3.5" />English + हिंदी</span>}
            {t.sectional_timing && <span className="inline-flex items-center gap-1 rounded-full bg-white/10 px-2.5 py-0.5 text-[11px] font-bold ring-1 ring-white/20"><Icon name="clock" className="size-3.5" />Sectional timing</span>}
            {t.is_demo && <DemoBadge />}
          </div>
          <h1 className="mt-3 max-w-3xl animate-fade-up font-display text-3xl leading-tight font-extrabold sm:text-4xl">{t.title}</h1>
          {t.description && <p className="mt-3 max-w-2xl animate-fade-up text-white/80 [animation-delay:80ms]">{t.description}</p>}
          <ul className="mt-7 grid max-w-3xl grid-cols-2 gap-3 sm:grid-cols-4">
            {stats.map(([i, v, l], n) => (
              <li key={l} className="glass animate-fade-up rounded-2xl p-4 ring-1 ring-white/15" style={{ animationDelay: `${120 + n * 60}ms` }}>
                <Icon name={i} className="size-5 text-gold" />
                <span className="mt-2 block font-display text-xl font-extrabold">{v}</span>
                <span className="text-xs text-white/70">{l}</span>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <div className="container-x mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <div className="min-w-0 space-y-6">
          <Reveal as="section" className="card rounded-2xl p-6" aria-labelledby="ins-h">
            <h2 id="ins-h" className="flex items-center gap-2 font-display text-xl font-bold text-navy"><Icon name="clipboard" className="size-5 text-primary" />Instructions</h2>
            {t.instructions
              ? <div className="prose-lite mt-3 text-sm text-ink/90" dangerouslySetInnerHTML={{ __html: t.instructions }} />
              : <p className="mt-3 text-sm text-ink/90">Read each question carefully and choose the best answer.</p>}
            <ul className="mt-4 space-y-2 text-sm text-muted">
              {(practice ? [
                'Pick an answer to see instantly whether it is right, with the correct option and explanation.',
                'Checked answers are locked; finish the quiz any time to get your scorecard.',
              ] : [
                'The timer starts when you begin and keeps running even if you close the page; the test is submitted automatically at 00:00.',
                'Answers are saved on our server as you go - you can resume from any device if your connection drops.',
                'Use “Mark for Review” to revisit a question. Answered + marked questions are still evaluated.',
                'Keyboard: 1-4 or A-D choose an option, N next, P previous, M mark for review.',
              ]).map((x) => <li key={x} className="flex gap-2"><Icon name="checkCircle" className="mt-0.5 size-4 shrink-0 text-emerald-600" />{x}</li>)}
              {t.course && <li className="flex gap-2"><Icon name="book" className="mt-0.5 size-4 shrink-0 text-primary" /><span>Linked course: <Link to={`/courses/${t.course.slug}`} className="font-semibold text-primary hover:underline">{t.course.title}</Link></span></li>}
            </ul>
          </Reveal>

          {t.sections?.length > 0 && (
            <Reveal as="section" className="card overflow-hidden rounded-2xl" aria-labelledby="sec-h">
              <h2 id="sec-h" className="flex items-center gap-2 px-6 pt-6 font-display text-xl font-bold text-navy"><Icon name="layers" className="size-5 text-primary" />Sections</h2>
              <p className="px-6 pt-1 text-sm text-muted">
                {t.sectional_timing
                  ? 'Each section has its own timer. When a section’s time is over the next one starts automatically, and a finished section cannot be opened again.'
                  : 'Questions are grouped into sections - move between them freely within the test time.'}
              </p>
              <table className="mt-4 w-full text-sm">
                <caption className="sr-only">Sections of {t.title}</caption>
                <thead className="bg-surface text-left text-[11px] tracking-wide text-muted uppercase">
                  <tr><th scope="col" className="px-6 py-2.5">Section</th><th scope="col" className="px-3 py-2.5 text-center">Questions</th>{t.sectional_timing && <th scope="col" className="px-6 py-2.5 text-right">Time</th>}</tr>
                </thead>
                <tbody className="divide-y divide-line">
                  {t.sections.map((s, i) => (
                    <tr key={i}>
                      <th scope="row" className="px-6 py-3 text-left font-semibold text-navy">{i + 1}. {s.title}{s.title_hi && <span className="ml-1.5 font-normal text-muted" lang="hi">({s.title_hi})</span>}</th>
                      <td className="px-3 py-3 text-center tabular-nums">{s.questions}</td>
                      {t.sectional_timing && <td className="px-6 py-3 text-right font-semibold text-navy tabular-nums">{s.minutes} min</td>}
                    </tr>
                  ))}
                </tbody>
              </table>
            </Reveal>
          )}

          <Reveal as="section" className="card overflow-hidden rounded-2xl" aria-labelledby="mark-h">
            <h2 id="mark-h" className="flex items-center gap-2 px-6 pt-6 font-display text-xl font-bold text-navy"><Icon name="calculator" className="size-5 text-primary" />Marking scheme</h2>
            <table className="mt-4 w-full text-sm">
              <caption className="sr-only">Marking scheme for {t.title}</caption>
              <tbody className="divide-y divide-line">
                {scheme.map(([k, v]) => (
                  <tr key={k} className="even:bg-surface/60">
                    <th scope="row" className="w-2/5 px-6 py-3 text-left font-medium text-muted">{k}</th>
                    <td className={'px-6 py-3 font-semibold ' + (k === 'Wrong answer' && t.negative_marking ? 'text-coral' : k === 'Correct answer' ? 'text-emerald-700' : 'text-navy')}>{v}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Reveal>

          <Reveal as="section" className="flex gap-3 rounded-2xl border border-primary/15 bg-primary-soft/60 p-5 text-sm text-navy">
            <Icon name="eye" className="mt-0.5 size-5 shrink-0 text-primary" />
            <div>
              <h2 className="font-bold">Answer key</h2>
              <p className="mt-1 text-navy/80">{practice ? (t.answer_key_visibility === 'never' ? 'You will see whether each answer is right; the correct option is not revealed for this quiz.' : 'Each answer is checked instantly with the correct option and explanation.') : (KEY_NOTE[t.answer_key_visibility] || KEY_NOTE.after_result)}</p>
            </div>
          </Reveal>

          {t.attempts?.length > 0 && (
            <Reveal as="section" className="card rounded-2xl p-6" aria-labelledby="att-h">
              <h2 id="att-h" className="font-display text-xl font-bold text-navy">Your attempts</h2>
              <ul className="mt-4 divide-y divide-line">
                {t.attempts.map((a) => {
                  const [label, cls] = ATTEMPT_STATUS[a.status] || [a.status, 'bg-surface text-muted ring-line'];
                  return (
                    <li key={a.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                      <div className="min-w-0">
                        <p className="font-semibold text-navy">Attempt {a.attempt_no}</p>
                        <p className="text-xs text-muted">{formatDateTime(a.submitted_at)}</p>
                      </div>
                      <span className={'rounded-full px-2.5 py-0.5 text-[11px] font-bold ring-1 ' + cls}>{label}</span>
                      <Link to={`/mock-tests/${slug}/result/${a.id}`} className="btn-light btn-sm">
                        {a.score !== null && a.score !== undefined ? `${fmtNum(a.score)} / ${fmtNum(a.total_marks ?? t.total_marks)}` : 'View result'}<Icon name="chevronRight" className="size-4" />
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </Reveal>
          )}
        </div>

        <aside className="min-w-0 space-y-5 lg:sticky lg:top-24 lg:self-start">
          <div className="card rounded-2xl p-6">
            <h2 className="font-display text-lg font-bold text-navy">{t.window === 'closed' && !t.in_progress_attempt_id ? 'Test closed' : practice ? 'Ready to practise?' : 'Ready to begin?'}</h2>
            <p className="mt-1 text-sm text-muted">{ACCESS[t.access_type] || ''}{t.ends_at && t.window === 'open' ? ` · Closes ${formatDateTime(t.ends_at)}` : ''}</p>
            {startError && <Alert className="mt-4">{startError}</Alert>}
            <div className="mt-5">
              {t.in_progress_attempt_id ? (
                <>
                  <Alert type="info" className="mb-4">{practice ? 'You have a practice quiz in progress. Resume where you left off - your checked answers are saved.' : 'You have an attempt in progress. Resume where you left off - the timer is still running.'}</Alert>
                  <Link to={`/mock-tests/${slug}/attempt/${t.in_progress_attempt_id}`} className="btn-gold btn-lg w-full"><Icon name="playCircle" className="size-5" />Resume {practice ? 'quiz' : 'test'}</Link>
                </>
              ) : t.window === 'upcoming' ? (
                <>
                  {/* Students who may take it get the server's not_started message (with the opening time);
                      guests / non-enrolled students get the API's other blockers first, so build it here. */}
                  <Alert type="warning" className="mb-4">
                    {t.blocker?.code === 'not_started' && t.blocker.message
                      ? <>{t.blocker.message}{t.ends_at ? ` It closes on ${formatDateTime(t.ends_at)}.` : ''}</>
                      : <>This test opens on <strong>{formatDateTime(t.starts_at)}</strong>{t.ends_at ? ` and closes on ${formatDateTime(t.ends_at)}` : ''}.</>}
                  </Alert>
                  {user && t.blocker && !['not_started', 'unauthenticated'].includes(t.blocker.code) && t.blocker.message && (
                    <p className="mb-4 rounded-xl bg-surface p-3 text-sm text-muted">{t.blocker.message}{t.blocker.code === 'not_enrolled' && t.course && <> <Link to={`/courses/${t.course.slug}`} className="font-semibold text-primary hover:underline">View course</Link></>}</p>
                  )}
                  <button type="button" disabled aria-disabled="true" className="btn btn-lg w-full cursor-not-allowed border border-amber-200 bg-amber-50 text-amber-800"><Icon name="lock" className="size-5" />Opens {formatDateTime(t.starts_at)}</button>
                  {!user && <Link to={`/login?next=${encodeURIComponent(location.pathname)}`} className="btn-outline mt-3 w-full">Log in now to be ready</Link>}
                </>
              ) : t.window === 'closed' ? (
                // Checked before the guest branch: the API reports "unauthenticated" first for guests.
                <>
                  <Alert type="warning">This test closed{t.ends_at ? ` on ${formatDateTime(t.ends_at)}` : ''}. New attempts are no longer accepted.</Alert>
                  {lastFinished && <Link to={`/mock-tests/${slug}/result/${lastFinished.id}`} className="btn-primary mt-4 w-full">View my result</Link>}
                  {!user && <Link to={`/login?next=${encodeURIComponent(location.pathname)}`} className="btn-outline mt-3 w-full">Log in to see your result</Link>}
                  <Link to="/mock-tests" className="btn-outline mt-3 w-full">Browse other tests</Link>
                </>
              ) : !user ? (
                <>
                  <p className="mb-4 rounded-xl bg-surface p-3 text-sm text-muted">Log in with your student account to attempt this test. Your answers, score and rank are saved to your dashboard.</p>
                  <Link to={`/login?next=${encodeURIComponent(location.pathname)}`} className="btn-primary btn-lg w-full"><Icon name="user" className="size-5" />Login to attempt</Link>
                  <Link to={`/register?next=${encodeURIComponent(location.pathname)}`} className="btn-outline mt-3 w-full">Create a free account</Link>
                </>
              ) : t.can_start ? (
                <>
                  <label className="mb-4 flex cursor-pointer items-start gap-2.5 rounded-xl bg-surface p-3 text-sm">
                    <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} className="mt-0.5 size-4 shrink-0 accent-primary" />
                    <span>I have read the instructions and marking scheme and I am ready to begin.</span>
                  </label>
                  <button type="button" disabled={!agree || starting} onClick={start} className="btn-primary btn-lg w-full">
                    <Icon name={t.attempts_used > 0 ? 'refresh' : 'playCircle'} className="size-5" />
                    {starting ? 'Starting…' : t.attempts_used > 0 ? `Retake (attempt ${t.attempts_used + 1})` : practice ? 'Start practice' : 'Start test'}
                  </button>
                  {!agree && <p className="mt-2 text-center text-xs text-muted">Tick the box above to enable the start button.</p>}
                </>
              ) : (
                <Blocker t={t} slug={slug} lastFinished={lastFinished} />
              )}
            </div>
          </div>

          <div className="card rounded-2xl p-5">
            <h2 className="mb-3 text-sm font-bold text-navy">Share this test</h2>
            <ShareButtons url={`/mock-tests/${slug}`} title={t.title} text={`Try the "${t.title}" ${practice ? 'practice quiz' : 'mock test'} on Laxyam Academy`} label={null} size="sm" />
          </div>
        </aside>
      </div>
    </div>
  );
}

/** Explains why the student cannot start right now, with the next useful step. */
function Blocker({ t, slug, lastFinished }) {
  const b = t.blocker || {};
  const tips = {
    not_enrolled: 'Enrol in the linked course to unlock this test.',
    forbidden: 'Staff accounts can preview tests from the admin panel. Log in with a student account to attempt it.',
    attempts_exhausted: 'You can still review your scorecard and answers.',
    closed: 'This test window has ended.',
    unavailable: 'Questions are being added - check back soon.',
    not_started: 'Come back when the test window opens.',
  };
  return (
    <>
      <Alert type="warning">{b.message || 'This test cannot be started right now.'}</Alert>
      {tips[b.code] && <p className="mt-3 text-sm text-muted">{tips[b.code]}</p>}
      {b.code === 'not_enrolled' && t.course && <Link to={`/courses/${t.course.slug}`} className="btn-primary btn-lg mt-4 w-full"><Icon name="book" className="size-5" />View course</Link>}
      {lastFinished && (b.code === 'attempts_exhausted' || b.code === 'closed') && <Link to={`/mock-tests/${slug}/result/${lastFinished.id}`} className="btn-primary mt-4 w-full">View my result</Link>}
      <Link to="/mock-tests" className="btn-outline mt-3 w-full">Browse other tests</Link>
    </>
  );
}

function DetailSkeleton() {
  return (
    <div className="bg-surface pb-16" aria-busy="true">
      <div className="bg-navy-mesh">
        <div className="container-x space-y-4 py-12">
          <Skeleton className="h-4 w-48 opacity-30" />
          <Skeleton className="h-9 w-3/4 max-w-xl opacity-30" />
          <Skeleton className="h-4 w-2/3 max-w-lg opacity-30" />
          <div className="grid max-w-3xl grid-cols-2 gap-3 sm:grid-cols-4">{[1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-24 opacity-20" rounded="rounded-2xl" />)}</div>
        </div>
      </div>
      <div className="container-x mt-8 grid gap-6 lg:grid-cols-[1fr_360px]">
        <div className="space-y-6"><Skeleton className="h-56" rounded="rounded-2xl" /><Skeleton className="h-72" rounded="rounded-2xl" /></div>
        <Skeleton className="h-64" rounded="rounded-2xl" />
      </div>
    </div>
  );
}
