// Scorecard + answer review (/mock-tests/:slug/result/:attemptId).
// The answer key, correctness and explanations come from the server only when
// exams.answer_key_visibility and the result release rules allow it.
import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { formatDateTime } from '../api.js';
import { Alert, ErrorState, Icon, Reveal, ShareButtons, Skeleton, useApi, useSeo } from '../components.jsx';
import { ATTEMPT_STATUS, LANG_KEY, LangToggle, ModeBadge, ScoreRing, StatTile, TONE, fmtDuration, fmtNum, pick, store, toneFor } from './shared.jsx';
import { AskDoubt } from '../account/AskDoubt.jsx';

/**
 * Review colour code (owner request): green = correct, red = incorrect, yellow = unanswered.
 * Every state also carries a text label and an icon, so colour is never the only signal.
 * "answered" is used only while the answer key is hidden (correctness unknown to the student).
 */
const REVIEW = {
  correct: { label: 'Correct', icon: 'checkCircle', pill: 'bg-emerald-600 text-white', border: 'border-l-emerald-500', tint: 'from-emerald-50', chip: 'border-emerald-600 bg-emerald-500 text-white', dot: 'bg-emerald-500' },
  wrong: { label: 'Incorrect', icon: 'x', pill: 'bg-red-600 text-white', border: 'border-l-red-500', tint: 'from-red-50', chip: 'border-red-600 bg-red-500 text-white', dot: 'bg-red-500' },
  unanswered: { label: 'Unanswered', icon: 'help', pill: 'bg-yellow-300 text-yellow-950', border: 'border-l-yellow-400', tint: 'from-yellow-50', chip: 'border-yellow-500 bg-yellow-300 text-yellow-950', dot: 'bg-yellow-400' },
  answered: { label: 'Answered', icon: 'check', pill: 'bg-primary text-white', border: 'border-l-primary', tint: 'from-primary-soft', chip: 'border-primary bg-primary text-white', dot: 'bg-primary' },
};
const LETTERS = 'ABCDEFGH';

/** Hides the site chrome when printing the scorecard. */
const PRINT_CSS = `@media print {
  body { background: #fff !important; }
  header, footer, [data-print-hide] { display: none !important; }
  .lx-scorecard .card { box-shadow: none !important; break-inside: avoid; }
  .lx-scorecard * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}`;

export function ExamResult() {
  const { slug, attemptId } = useParams();
  const location = useLocation();
  const { data: r, loading, error, reload } = useApi('mock-tests.php', { action: 'result', id: attemptId });
  useSeo({ title: r?.exam ? `Result: ${r.exam.title} | Laxyam Academy` : 'Test result | Laxyam Academy', robots: 'noindex,nofollow' });

  // An attempt that has just timed out is finalised by the server a few seconds later.
  const finalising = error?.code === 'in_progress';
  useEffect(() => {
    if (!finalising) return undefined;
    const t = setTimeout(reload, 4000);
    return () => clearTimeout(t);
  }, [finalising, reload]);

  if (finalising) {
    return (
      <div className="container-x py-16">
        <div className="card mx-auto max-w-md rounded-2xl p-8 text-center">
          <span className="mx-auto block size-10 animate-spin rounded-full border-[3px] border-primary/20 border-t-primary" aria-hidden="true" />
          <h1 className="mt-4 font-display text-xl font-bold text-navy">Finalising your result…</h1>
          <p className="mt-1 text-sm text-muted">This takes a few seconds after the timer ends.</p>
          <Link to={`/mock-tests/${slug}/attempt/${attemptId}`} className="btn-outline mt-5">Back to the test</Link>
        </div>
      </div>
    );
  }
  if (error) return <div className="container-x"><ErrorState error={error} onRetry={reload} title="We couldn’t load this result." /></div>;
  if (loading || !r) return <ResultSkeleton />;

  const exam = r.exam || {};
  const auto = new URLSearchParams(location.search).get('auto') === '1' || r.status === 'auto_submitted' || r.auto_submitted;
  const crumbs = (
    <nav aria-label="Breadcrumb" className="text-sm text-muted" data-print-hide="">
      <ol className="flex flex-wrap items-center gap-1.5">
        <li><Link to="/mock-tests" className="font-medium hover:text-primary">Mock Tests</Link></li>
        <li aria-hidden="true"><Icon name="chevronRight" className="size-3.5" /></li>
        <li className="min-w-0"><Link to={`/mock-tests/${slug}`} className="font-medium hover:text-primary">{exam.title}</Link></li>
        <li aria-hidden="true"><Icon name="chevronRight" className="size-3.5" /></li>
        <li aria-current="page" className="font-semibold text-navy">Result</li>
      </ol>
    </nav>
  );

  if (!r.released) {
    return (
      <div className="bg-surface pb-16">
        <div className="container-x py-6">
          {crumbs}
          {auto && <Alert type="info" className="mt-4">Time ended - your test was submitted automatically.</Alert>}
          <div className="card relative mt-6 overflow-hidden rounded-2xl p-8 text-center sm:p-12">
            <div aria-hidden="true" className="dot-grid-dark absolute inset-0 [mask-image:radial-gradient(circle_at_center,black,transparent_70%)]" />
            <span className="relative mx-auto grid size-16 place-items-center rounded-2xl bg-primary-soft text-primary"><Icon name="checkCircle" className="size-8" /></span>
            <h1 className="relative mt-4 font-display text-2xl font-extrabold text-navy">Test submitted</h1>
            <p className="relative mx-auto mt-2 max-w-md text-muted">{r.message || 'Your result will be published soon.'}</p>
            {r.answer_key_message && <p className="relative mx-auto mt-2 max-w-md text-sm text-muted">{r.answer_key_message}</p>}
            {r.submitted_at && <p className="relative mt-3 text-xs text-muted">Submitted {formatDateTime(r.submitted_at)}</p>}
            <div className="relative mt-6 flex flex-wrap justify-center gap-3">
              <Link to="/dashboard/tests" className="btn-primary">My Tests</Link>
              <Link to="/mock-tests" className="btn-outline">More tests</Link>
            </div>
          </div>
        </div>
      </div>
    );
  }

  // r.total_marks is this attempt's own total (random pools); the exam fallbacks are for older payloads.
  const total = Number(r.total_marks ?? exam.marks_per_attempt ?? exam.total_marks ?? 0);
  const pct = r.percentage ?? (total > 0 ? Math.round((r.score / total) * 1000) / 10 : null);
  const tone = toneFor(pct, r.passed ?? null);
  const rank = r.rank && typeof r.rank === 'object' ? r.rank : null;
  const percentile = r.percentile ?? rank?.percentile ?? null;
  const participants = r.total_attempts ?? rank?.participants ?? null;
  const accuracy = r.accuracy ?? (r.correct + r.wrong > 0 ? Math.round((1000 * r.correct) / (r.correct + r.wrong)) / 10 : null);
  const practice = (r.mode || exam.mode) === 'practice';
  const canRetake = exam.window !== 'closed' && exam.window !== 'upcoming' && r.attempts_left !== 0;
  const [statusLabel] = ATTEMPT_STATUS[r.status] || [r.status];
  // The rank is computed from each student's best attempt, which may not be this one.
  const bestElsewhere = rank?.best_score !== null && rank?.best_score !== undefined && Number(rank.best_score) !== Number(r.score);
  const attempted = r.attempted ?? (Number(r.correct) || 0) + (Number(r.wrong) || 0);

  return (
    <div className="lx-scorecard bg-surface pb-16">
      <style>{PRINT_CSS}</style>
      <div className="container-x py-6">
        {crumbs}
        {auto && <Alert type="info" className="mt-4">Time ended - your test was submitted automatically.</Alert>}

        {/* Scorecard hero */}
        <section className="relative isolate mt-5 overflow-hidden rounded-3xl bg-navy-mesh p-6 text-white shadow-card sm:p-8" aria-labelledby="res-h">
          <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-30" />
          <div aria-hidden="true" className="absolute -top-20 -right-16 -z-10 size-72 rounded-full bg-primary/40 blur-3xl" />
          <div className="flex flex-col items-center gap-6 md:flex-row md:items-center md:gap-10">
            <ScoreRing value={Math.max(0, r.score)} max={total} color={TONE[tone].stroke} label={`Score ${fmtNum(r.score)} out of ${fmtNum(total)}`}>
              <div>
                <span className="block font-display text-4xl leading-none font-extrabold">{fmtNum(r.score)}</span>
                <span className="mt-1 block text-sm text-white/70">of {fmtNum(total)}</span>
                {pct !== null && <span className="mt-1 block text-xs font-bold text-gold">{fmtNum(pct)}%</span>}
              </div>
            </ScoreRing>
            <div className="min-w-0 flex-1 text-center md:text-left">
              <div className="flex flex-wrap items-center justify-center gap-2 md:justify-start">
                <ModeBadge mode={practice ? 'practice' : 'exam'} className="bg-white/95" />
                {r.passed === true && <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500 px-3 py-0.5 text-xs font-extrabold tracking-wide text-white"><Icon name="checkCircle" className="size-3.5" />PASS</span>}
                {r.passed === false && <span className="inline-flex items-center gap-1 rounded-full bg-red-500 px-3 py-0.5 text-xs font-extrabold tracking-wide text-white"><Icon name="x" className="size-3.5" />FAIL</span>}
                <span className="rounded-full bg-white/10 px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-white/20">Attempt {r.attempt_no} · {statusLabel}</span>
              </div>
              <h1 id="res-h" className="mt-3 font-display text-2xl leading-tight font-extrabold sm:text-3xl">{exam.title}</h1>
              <p className="mt-1 text-sm text-white/70">
                {r.submitted_at && <>Submitted {formatDateTime(r.submitted_at)}</>}
                {r.pass_marks !== null && r.pass_marks !== undefined && <> · Pass mark {fmtNum(r.pass_marks)}</>}
              </p>
              <dl className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <HeroStat label="Percentage" value={pct !== null ? `${fmtNum(pct)}%` : '-'} />
                <HeroStat label="Rank" value={rank?.position ? <>{rank.position}<span className="text-sm font-semibold text-white/60"> / {participants}</span></> : '-'} />
                <HeroStat label="Percentile" value={percentile !== null ? fmtNum(percentile) : '-'} />
                <HeroStat label="Time taken" value={fmtDuration(r.time_taken_seconds)} />
              </dl>
              {bestElsewhere && (
                <p className="mt-3 text-xs text-white/70">Rank and percentile use your best attempt ({fmtNum(rank.best_score)} marks) - each student is ranked once.</p>
              )}
            </div>
          </div>
        </section>

        {/* Stat tiles */}
        <ul className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
          <StatTile icon="trophy" label="Total marks" value={fmtNum(total)} />
          <StatTile icon="target" label="Marks obtained" value={fmtNum(r.score)} tone={TONE[tone].text} iconCls="bg-violet/10 text-violet" />
          <StatTile icon="checkCircle" label="Correct" value={r.correct} tone="text-emerald-700" iconCls="bg-emerald-50 text-emerald-600" />
          <StatTile icon="x" label="Incorrect" value={r.wrong} tone="text-red-700" iconCls="bg-red-50 text-red-600" />
          <StatTile icon="help" label="Unanswered" value={r.unanswered} tone="text-yellow-700" iconCls="bg-yellow-50 text-yellow-600" />
          <StatTile icon="sparkles" label="Accuracy" value={accuracy !== null ? `${fmtNum(accuracy)}%` : '-'} iconCls="bg-primary-soft text-primary" sub={`${attempted} attempted`} />
          <StatTile icon="trendingUp" label="Negative marks lost" value={r.negative_marks_lost ? `−${fmtNum(r.negative_marks_lost)}` : '0'} tone={r.negative_marks_lost ? 'text-coral' : 'text-navy'} iconCls="bg-coral/10 text-coral" />
        </ul>

        <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          {(r.average_score !== null && r.average_score !== undefined) || (r.topper_score !== null && r.topper_score !== undefined) ? (
            <Reveal as="section" className="card rounded-2xl p-6" aria-labelledby="cmp-h">
              <h2 id="cmp-h" className="font-display text-lg font-bold text-navy">How you compare</h2>
              <p className="text-xs text-muted">Best score of each student{participants ? ` · ${participants} ${participants === 1 ? 'student' : 'students'}` : ''}</p>
              <div className="mt-5 space-y-4">
                <CompareBar label="You (this attempt)" value={r.score} max={total} cls="bg-gradient-to-r from-primary to-violet" />
                {r.average_score !== null && r.average_score !== undefined && <CompareBar label="Average" value={r.average_score} max={total} cls="bg-slate-400" />}
                {r.topper_score !== null && r.topper_score !== undefined && <CompareBar label="Topper" value={r.topper_score} max={total} cls="bg-gradient-to-r from-gold to-amber-500" />}
              </div>
            </Reveal>
          ) : null}

          {r.sections?.length > 0 && <SectionTable sections={r.sections} timed={!!r.sectional_timing} />}
          {r.topics?.length > 0 && <TopicTable topics={r.topics} />}
        </div>

        {/* Actions */}
        <div className="card mt-6 flex flex-col gap-4 rounded-2xl p-5 lg:flex-row lg:items-center lg:justify-between" data-print-hide="">
          <div className="flex flex-wrap gap-2">
            <button type="button" onClick={() => window.print()} className="btn-primary"><Icon name="file" className="size-4" />Print / Save scorecard</button>
            {canRetake && <Link to={`/mock-tests/${slug}`} className="btn-outline"><Icon name="refresh" className="size-4" />Retake{r.attempts_left ? ` (${r.attempts_left} left)` : ''}</Link>}
            <Link to="/mock-tests" className="btn-light"><Icon name="chevronLeft" className="size-4" />Back to tests</Link>
          </div>
          <ShareButtons url={`/mock-tests/${slug}`} title={exam.title} text={`I scored ${pct !== null ? `${fmtNum(pct)}%` : fmtNum(r.score)} in "${exam.title}" on Laxyam Academy. Try it:`} label="Share result:" size="sm" />
        </div>

        <AnswerReview r={r} />
      </div>
    </div>
  );
}

function HeroStat({ label, value }) {
  return (
    <div className="rounded-2xl bg-white/10 p-3 ring-1 ring-white/15">
      <dt className="text-[11px] font-semibold tracking-wide text-white/60 uppercase">{label}</dt>
      <dd className="mt-0.5 font-display text-xl font-extrabold">{value}</dd>
    </div>
  );
}

function CompareBar({ label, value, max, cls }) {
  const w = max > 0 ? Math.max(0, Math.min(100, (Number(value) / max) * 100)) : 0;
  return (
    <div>
      <div className="flex justify-between text-sm"><span className="font-semibold text-navy">{label}</span><span className="font-bold text-navy tabular-nums">{fmtNum(value)} <span className="font-normal text-muted">/ {fmtNum(max)}</span></span></div>
      <div className="mt-1.5 h-3 overflow-hidden rounded-full bg-surface ring-1 ring-line" role="img" aria-label={`${label}: ${fmtNum(value)} of ${fmtNum(max)}`}>
        <div className={'h-full rounded-full motion-safe:transition-[width] motion-safe:duration-1000 ' + cls} style={{ width: `${w}%` }} />
      </div>
    </div>
  );
}

function TopicTable({ topics }) {
  const keyed = topics.some((t) => t.correct !== null && t.correct !== undefined);
  return (
    <Reveal as="section" className="card min-w-0 overflow-hidden rounded-2xl" aria-labelledby="topic-h">
      <h2 id="topic-h" className="px-6 pt-6 font-display text-lg font-bold text-navy">Topic-wise performance</h2>
      {!keyed && <p className="px-6 text-xs text-muted">Correct / incorrect per topic appears when the answer key is published.</p>}
      <div className="mt-4 overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-surface text-left text-[11px] tracking-wide text-muted uppercase">
            <tr>
              <th scope="col" className="px-4 py-2.5 sm:px-6">Topic</th>
              <th scope="col" className="px-2 py-2.5 text-center">Qs</th>
              {keyed && <><th scope="col" className="px-2 py-2.5 text-center text-emerald-700"><span aria-hidden="true">✓</span><span className="sr-only">Correct</span></th><th scope="col" className="px-2 py-2.5 text-center text-red-700"><span aria-hidden="true">✗</span><span className="sr-only">Incorrect</span></th></>}
              <th scope="col" className="px-2 py-2.5 text-center text-yellow-700">Skip<span className="sr-only">ped (unanswered)</span></th>
              {keyed && <th scope="col" className="px-4 py-2.5 text-right sm:px-6">Score</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {topics.map((t) => {
              const share = t.max_marks > 0 && t.score !== null ? Math.max(0, Math.min(100, (t.score / t.max_marks) * 100)) : t.total > 0 && t.correct !== null ? (t.correct / t.total) * 100 : 0;
              const tone = TONE[toneFor(share)];
              return (
                <tr key={t.topic}>
                  <th scope="row" className="px-4 py-3 text-left font-medium text-navy sm:px-6">
                    {t.topic}
                    {keyed && <div className="mt-1.5 h-1.5 w-full max-w-[180px] overflow-hidden rounded-full bg-surface"><div className={'h-full rounded-full ' + tone.bar} style={{ width: `${share}%` }} /></div>}
                  </th>
                  <td className="px-2 py-3 text-center tabular-nums">{t.total}</td>
                  {keyed && <><td className="px-2 py-3 text-center font-semibold text-emerald-700 tabular-nums">{t.correct}</td><td className="px-2 py-3 text-center font-semibold text-red-700 tabular-nums">{t.wrong}</td></>}
                  <td className="px-2 py-3 text-center font-semibold text-yellow-700 tabular-nums">{t.skipped}</td>
                  {keyed && <td className="px-4 py-3 text-right font-bold text-navy tabular-nums sm:px-6">{fmtNum(t.score)}{t.max_marks ? <span className="font-normal text-muted"> / {fmtNum(t.max_marks)}</span> : null}</td>}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </Reveal>
  );
}

/** Section-wise score (and time used when each section had its own timer). */
function SectionTable({ sections, timed }) {
  const keyed = sections.some((s) => s.correct !== null && s.correct !== undefined);
  return (
    <Reveal as="section" className="card min-w-0 overflow-hidden rounded-2xl" aria-labelledby="section-h">
      <h2 id="section-h" className="px-6 pt-6 font-display text-lg font-bold text-navy">Section-wise performance</h2>
      {!keyed && <p className="px-6 text-xs text-muted">Correct / incorrect per section appears when the answer key is published.</p>}
      <div className="mt-4 overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-surface text-left text-[11px] tracking-wide text-muted uppercase">
            <tr>
              <th scope="col" className="px-4 py-2.5 sm:px-6">Section</th>
              <th scope="col" className="px-2 py-2.5 text-center">Qs</th>
              {keyed && <><th scope="col" className="px-2 py-2.5 text-center text-emerald-700"><span aria-hidden="true">✓</span><span className="sr-only">Correct</span></th><th scope="col" className="px-2 py-2.5 text-center text-red-700"><span aria-hidden="true">✗</span><span className="sr-only">Incorrect</span></th></>}
              <th scope="col" className="px-2 py-2.5 text-center text-yellow-700">Skip<span className="sr-only">ped (unanswered)</span></th>
              {timed && <th scope="col" className="px-2 py-2.5 text-center">Time</th>}
              {keyed && <th scope="col" className="px-4 py-2.5 text-right sm:px-6">Score</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-line">
            {sections.map((s, i) => (
              <tr key={i}>
                <th scope="row" className="px-4 py-3 text-left font-medium text-navy sm:px-6">{s.title}{s.title_hi && <span className="block text-xs font-normal text-muted" lang="hi">{s.title_hi}</span>}</th>
                <td className="px-2 py-3 text-center tabular-nums">{s.total}</td>
                {keyed && <><td className="px-2 py-3 text-center font-semibold text-emerald-700 tabular-nums">{s.correct}</td><td className="px-2 py-3 text-center font-semibold text-red-700 tabular-nums">{s.wrong}</td></>}
                <td className="px-2 py-3 text-center font-semibold text-yellow-700 tabular-nums">{s.skipped}</td>
                {timed && <td className="px-2 py-3 text-center whitespace-nowrap tabular-nums">{fmtDuration(s.seconds_used)}{s.seconds_allowed ? <span className="text-muted"> / {fmtDuration(s.seconds_allowed)}</span> : null}</td>}
                {keyed && <td className="px-4 py-3 text-right font-bold text-navy tabular-nums sm:px-6">{fmtNum(s.score)}{s.max_marks ? <span className="font-normal text-muted"> / {fmtNum(s.max_marks)}</span> : null}</td>}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Reveal>
  );
}

function AnswerReview({ r }) {
  const [filter, setFilter] = useState('all');
  const review = r.review || [];
  const hasHindi = review.some((q) => q.text_hi);
  const [lang, setLangState] = useState(() => (store.get(LANG_KEY, 'en') === 'hi' ? 'hi' : 'en'));
  const setLang = (l) => { setLangState(l); store.set(LANG_KEY, l); };
  if (!review.length) return null;
  const keyVisible = r.answer_key_visible !== false && review.some((q) => Array.isArray(q.correct_option_ids));
  const statusOf = (q) => q.status || q.result || (q.selected?.length ? 'answered' : 'unanswered');
  const tabs = keyVisible ? [['all', 'All'], ['correct', 'Correct'], ['wrong', 'Incorrect'], ['unanswered', 'Unanswered']] : [['all', 'All'], ['answered', 'Answered'], ['unanswered', 'Unanswered']];
  const count = (k) => (k === 'all' ? review.length : review.filter((q) => statusOf(q) === k).length);
  const shown = filter === 'all' ? review : review.filter((q) => statusOf(q) === filter);
  const legend = keyVisible ? ['correct', 'wrong', 'unanswered'] : ['answered', 'unanswered'];

  // Question map: jump to a card (switching back to "All" if a filter hides it).
  const jump = (q) => {
    setFilter('all');
    requestAnimationFrame(() => {
      const el = document.getElementById(`review-q-${q.id}`);
      if (!el) return;
      const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
      el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
      el.focus({ preventScroll: true });
    });
  };

  return (
    <section className="mt-10" aria-labelledby="rev-h">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          <h2 id="rev-h" className="font-display text-2xl font-extrabold text-navy">Answer review</h2>
          <p className="mt-1 text-sm text-muted">{keyVisible ? 'Compare your answers with the official answer key.' : 'Your responses as submitted.'}</p>
          {hasHindi && <LangToggle lang={lang} onChange={setLang} tone="light" className="mt-3" />}
        </div>
        <div className="scrollbar-none -mx-4 flex min-w-0 gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0" role="group" aria-label="Filter questions" data-print-hide="">
          {tabs.map(([k, l]) => (
            <button key={k} type="button" onClick={() => setFilter(k)} aria-pressed={filter === k}
              className={'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-4 py-2 text-sm font-semibold transition-colors ' + (filter === k ? 'border-navy bg-navy text-white' : 'border-line bg-white text-ink hover:border-primary')}>
              {k !== 'all' && <span className={'size-2 rounded-full ' + REVIEW[k].dot} aria-hidden="true" />}
              {l}<span className={'rounded-full px-1.5 text-[11px] ' + (filter === k ? 'bg-white/20' : 'bg-surface text-muted')}>{count(k)}</span>
            </button>
          ))}
        </div>
      </div>

      {!keyVisible && r.answer_key_message && (
        <div className="mt-5 flex gap-3 rounded-2xl border border-primary/20 bg-primary-soft/60 p-4 text-sm text-navy" role="note">
          <Icon name="lock" className="mt-0.5 size-5 shrink-0 text-primary" />
          <p>{r.answer_key_message}</p>
        </div>
      )}

      {/* Colour legend + question map */}
      <div className="card mt-5 rounded-2xl p-4 sm:p-5">
        <ul className="flex flex-wrap gap-x-5 gap-y-2 text-xs font-semibold text-ink" aria-label="Colour key">
          {legend.map((k) => (
            <li key={k} className="inline-flex items-center gap-1.5">
              <span className={'grid size-5 place-items-center rounded-md border ' + REVIEW[k].chip} aria-hidden="true"><Icon name={REVIEW[k].icon} className="size-3" strokeWidth={3} /></span>
              {REVIEW[k].label}<span className="font-normal text-muted">({count(k)})</span>
            </li>
          ))}
        </ul>
        <ol className="mt-4 grid grid-cols-[repeat(auto-fill,minmax(2.75rem,1fr))] gap-2" aria-label="Question map" data-print-hide="">
          {review.map((q) => {
            const meta = REVIEW[statusOf(q)] || REVIEW.answered;
            return (
              <li key={q.id}>
                <button type="button" onClick={() => jump(q)} aria-label={`Question ${q.number}: ${meta.label}`} title={`Q${q.number} · ${meta.label}`}
                  className={'relative grid h-11 w-full place-items-center rounded-xl border-2 text-sm font-bold transition-transform hover:-translate-y-0.5 focus-visible:ring-4 focus-visible:ring-primary/30 focus-visible:outline-none ' + meta.chip}>
                  {q.number}
                  <span className="absolute -top-1.5 -right-1.5 grid size-4 place-items-center rounded-full bg-white text-navy shadow ring-1 ring-line" aria-hidden="true"><Icon name={meta.icon} className="size-2.5" strokeWidth={3} /></span>
                </button>
              </li>
            );
          })}
        </ol>
      </div>

      {shown.length === 0 ? (
        <p className="card mt-5 rounded-2xl p-8 text-center text-sm text-muted">No questions in this group.</p>
      ) : (
        <ol className="mt-5 space-y-4">
          {shown.map((q) => <ReviewCard key={q.id} q={q} status={statusOf(q)} keyVisible={keyVisible} lang={lang} attemptId={r.attempt_id} />)}
        </ol>
      )}
    </section>
  );
}

function ReviewCard({ q, status, keyVisible, lang = 'en', attemptId }) {
  const meta = REVIEW[status] || REVIEW.answered;
  const hi = lang === 'hi' && !!q.text_hi; // options follow the question's language
  const mine = q.your_option_ids || q.selected || [];
  const key = Array.isArray(q.correct_option_ids) ? q.correct_option_ids : (q.options || []).filter((o) => o.correct).map((o) => o.id);
  const awarded = q.marks_awarded;
  return (
    <li id={`review-q-${q.id}`} tabIndex={-1}
      className={'card scroll-mt-24 rounded-2xl border-l-[6px] bg-gradient-to-r via-white to-white p-5 focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/25 sm:p-6 ' + meta.border + ' ' + meta.tint}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 flex-wrap items-center gap-2">
          <span className="rounded-lg bg-navy px-2.5 py-1 font-display text-sm font-extrabold text-white">Q{q.number}</span>
          {q.topic && <span className="max-w-[12rem] truncate rounded-full bg-violet/10 px-2 py-0.5 text-[11px] font-semibold text-violet">{q.topic}</span>}
          {q.marked && <span className="rounded-full bg-violet/10 px-2 py-0.5 text-[11px] font-semibold text-violet">Marked for review</span>}
        </div>
        <div className="flex items-center gap-2">
          {awarded !== null && awarded !== undefined && (
            <span className={'text-sm font-bold tabular-nums ' + (awarded > 0 ? 'text-emerald-700' : awarded < 0 ? 'text-red-700' : 'text-muted')}>
              {awarded > 0 ? '+' : ''}{fmtNum(awarded)}<span className="sr-only"> marks</span>
            </span>
          )}
          <span className={'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold ' + meta.pill}><Icon name={meta.icon} className="size-3.5" strokeWidth={2.5} />{meta.label}</span>
        </div>
      </div>
      <h3 className="mt-3 leading-relaxed font-semibold break-words whitespace-pre-line text-navy" lang={hi ? 'hi' : undefined}>{hi ? q.text_hi : q.text}</h3>
      {q.image_url && <img src={q.image_url} alt={`Figure for question ${q.number}`} loading="lazy" className="mt-3 max-h-80 w-auto max-w-full rounded-xl border border-line bg-white object-contain" />}
      <ul className="mt-4 space-y-2">
        {(q.options || []).map((o, i) => {
          const picked = mine.includes(o.id);
          const right = keyVisible && key.includes(o.id);
          const wrongPick = picked && keyVisible && !right;
          const cls = right ? 'border-emerald-400 bg-emerald-50' : wrongPick ? 'border-red-400 bg-red-50' : picked ? 'border-primary/40 bg-primary-soft' : 'border-line bg-white';
          return (
            <li key={o.id} className={'flex items-start gap-3 rounded-xl border-2 px-3 py-2.5 text-sm ' + cls}>
              <span className={'grid size-7 shrink-0 place-items-center rounded-lg text-xs font-extrabold ' + (right ? 'bg-emerald-600 text-white' : wrongPick ? 'bg-red-600 text-white' : picked ? 'bg-primary text-white' : 'bg-surface text-navy ring-1 ring-line')} aria-hidden="true">{LETTERS[i] || i + 1}</span>
              <span className="min-w-0 flex-1 pt-0.5 break-words text-ink"><span className="sr-only">Option {LETTERS[i] || i + 1}: </span>{hi ? pick('hi', o.text, o.text_hi) : o.text}</span>
              <span className="flex shrink-0 flex-col items-end gap-1 sm:flex-row" aria-hidden="true">
                {picked && (
                  <span className={'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ' + (right ? 'bg-emerald-600 text-white' : wrongPick ? 'bg-red-600 text-white' : 'bg-primary text-white')}>
                    {wrongPick && <Icon name="x" className="size-3" strokeWidth={3} />}Your answer
                  </span>
                )}
                {right && <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800"><Icon name="check" className="size-3" strokeWidth={3} />Correct answer</span>}
              </span>
              <span className="sr-only">{right && picked ? '(your answer, correct)' : right ? '(correct answer)' : wrongPick ? '(your answer, incorrect)' : picked ? '(your answer)' : ''}</span>
            </li>
          );
        })}
      </ul>
      {status === 'unanswered' && (
        <p className="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-yellow-100 px-2.5 py-1 text-xs font-semibold text-yellow-900">
          <Icon name="help" className="size-3.5" />You did not answer this question{keyVisible && key.length ? ' - the correct answer is marked in green.' : '.'}
        </p>
      )}
      {status === 'wrong' && keyVisible && key.length > 0 && (
        <p className="mt-3 text-xs font-semibold text-red-800">
          Correct answer: {(q.options || []).map((o, i) => (key.includes(o.id) ? LETTERS[i] || i + 1 : null)).filter(Boolean).join(', ')}
          {q.negative_marks > 0 && awarded < 0 ? ` · ${fmtNum(Math.abs(awarded))} negative mark${Math.abs(awarded) === 1 ? '' : 's'} applied` : ''}
        </p>
      )}
      {q.explanation && (
        <div className="mt-4 rounded-xl bg-primary-soft/70 p-4 text-sm text-navy">
          <p className="flex items-center gap-1.5 font-bold"><Icon name="info" className="size-4 text-primary" />{lang === 'hi' && q.explanation_hi ? 'व्याख्या' : 'Explanation'}</p>
          <p className="mt-1 leading-relaxed break-words whitespace-pre-line text-ink">{pick(lang, q.explanation, q.explanation_hi)}</p>
        </div>
      )}
      {attemptId && (
        <div className="mt-4 flex justify-end" data-print-hide="">
          <AskDoubt attemptId={attemptId} questionId={q.id} title={`Doubt on Q${q.number}`} />
        </div>
      )}
    </li>
  );
}

function ResultSkeleton() {
  return (
    <div className="container-x space-y-6 py-6" aria-busy="true">
      <Skeleton className="h-4 w-56" />
      <Skeleton className="h-64" rounded="rounded-3xl" />
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">{Array.from({ length: 7 }, (_, i) => <Skeleton key={i} className="h-20" rounded="rounded-2xl" />)}</div>
      <div className="grid gap-6 lg:grid-cols-2"><Skeleton className="h-48" rounded="rounded-2xl" /><Skeleton className="h-48" rounded="rounded-2xl" /></div>
    </div>
  );
}
