// Student dashboard → My Tests: every attempt with colour-coded score, review and retake links.
import { Link } from 'react-router-dom';
import { formatDateTime } from '../js/api.js';
import { EmptyState, ErrorState, Icon, Skeleton, useApi } from '../js/components.jsx';
import { ATTEMPT_STATUS, ModeBadge, TONE, fmtNum, toneFor } from '../js/exam/shared.jsx';
import { DashTitle } from './StudentDashboard.jsx';

export default function MyTests() {
  const { data, loading, error, reload } = useApi('mock-tests.php', { action: 'mine' });
  const action = <Link to="/mock-tests" className="btn-primary"><Icon name="search" className="size-4" />Find a test</Link>;

  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading || !data) {
    return (
      <div aria-busy="true">
        <DashTitle title="My Tests" subtitle="Your mock test and practice quiz attempts." />
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-24" rounded="rounded-2xl" />)}</div>
        <div className="mt-6 space-y-3">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-20" rounded="rounded-2xl" />)}</div>
      </div>
    );
  }

  const rows = Array.isArray(data) ? data : data.items || [];
  // The newest attempt per test carries the Retake link.
  const latest = new Set();
  const seen = new Set();
  rows.forEach((a) => { if (!seen.has(a.slug)) { seen.add(a.slug); latest.add(a.attempt_id); } });
  const scored = rows.filter((a) => a.percentage !== null && a.percentage !== undefined);
  const avg = scored.length ? scored.reduce((s, a) => s + Number(a.percentage), 0) / scored.length : null;
  const best = scored.length ? Math.max(...scored.map((a) => Number(a.percentage))) : null;
  const judged = rows.filter((a) => typeof a.passed === 'boolean');

  return (
    <div>
      <DashTitle title="My Tests" subtitle="Your mock test and practice quiz attempts, scores and answer reviews." action={rows.length > 0 && action} />
      {rows.length === 0 ? (
        <EmptyState icon="clipboard" title="No tests attempted yet" text="Practise with timed mock tests and quizzes - your scores, ranks and answer reviews appear here." action={<Link to="/mock-tests" className="btn-primary">Browse mock tests</Link>} />
      ) : (
        <>
          <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Summary icon="layers" label="Tests taken" value={seen.size} cls="bg-primary-soft text-primary" />
            <Summary icon="history" label="Total attempts" value={rows.length} cls="bg-violet/10 text-violet" />
            <Summary icon="barChart" label="Average score" value={avg !== null ? `${fmtNum(Math.round(avg * 10) / 10)}%` : '-'} cls="bg-teal/10 text-teal" />
            <Summary icon="trophy" label={judged.length ? 'Passed' : 'Best score'} value={judged.length ? `${judged.filter((a) => a.passed).length} / ${judged.length}` : best !== null ? `${fmtNum(best)}%` : '-'} cls="bg-gold/20 text-amber-700" />
          </ul>

          {/* Mobile: cards */}
          <ul className="mt-6 space-y-3 md:hidden">
            {rows.map((a) => <AttemptCard key={a.attempt_id} a={a} retake={latest.has(a.attempt_id)} />)}
          </ul>

          {/* Desktop: table */}
          <div className="card mt-6 hidden overflow-x-auto rounded-2xl md:block">
            <table className="w-full text-sm">
              <caption className="sr-only">Your test attempts</caption>
              <thead className="bg-surface text-left text-[11px] tracking-wide text-muted uppercase">
                <tr>
                  <th scope="col" className="px-5 py-3">Test</th>
                  <th scope="col" className="px-3 py-3">Attempt</th>
                  <th scope="col" className="px-3 py-3">Date</th>
                  <th scope="col" className="px-3 py-3">Score</th>
                  <th scope="col" className="px-3 py-3">Accuracy</th>
                  <th scope="col" className="px-3 py-3">Result</th>
                  <th scope="col" className="px-5 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {rows.map((a) => {
                  const live = a.status === 'in_progress';
                  return (
                    <tr key={a.attempt_id} className="transition-colors hover:bg-surface/60">
                      <td className="max-w-[280px] px-5 py-3.5">
                        <Link to={`/mock-tests/${a.slug}`} className="line-clamp-2 font-semibold text-navy hover:text-primary">{a.title}</Link>
                        <ModeBadge mode={a.mode} className="mt-1" />
                      </td>
                      <td className="px-3 py-3.5 font-medium tabular-nums">#{a.attempt_no}</td>
                      <td className="px-3 py-3.5 text-muted">{formatDateTime(a.submitted_at || a.started_at)}</td>
                      <td className="px-3 py-3.5"><ScoreCell a={a} /></td>
                      <td className="px-3 py-3.5 font-semibold text-navy tabular-nums">{a.accuracy !== null && a.accuracy !== undefined ? `${fmtNum(a.accuracy)}%` : <span className="font-normal text-muted">-</span>}</td>
                      <td className="px-3 py-3.5"><ResultBadge a={a} /></td>
                      <td className="px-5 py-3.5">
                        <div className="flex justify-end gap-2">
                          <Actions a={a} retake={latest.has(a.attempt_id) && !live} />
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}

function Summary({ icon, label, value, cls }) {
  return (
    <li className="card flex items-center gap-3 rounded-2xl p-4">
      <span className={'grid size-11 shrink-0 place-items-center rounded-xl ' + cls}><Icon name={icon} className="size-5" /></span>
      <span className="min-w-0">
        <span className="block font-display text-xl font-extrabold text-navy">{value}</span>
        <span className="block text-xs text-muted">{label}</span>
      </span>
    </li>
  );
}

function ScoreCell({ a }) {
  if (a.percentage === null || a.percentage === undefined) {
    return <span className="text-xs font-medium text-muted">{a.status === 'in_progress' ? 'In progress' : a.status === 'expired' ? 'Being scored' : 'Result pending'}</span>;
  }
  const tone = TONE[toneFor(Number(a.percentage), typeof a.passed === 'boolean' ? a.passed : null)];
  const w = Math.max(0, Math.min(100, Number(a.percentage)));
  return (
    <div className="min-w-[120px]">
      <div className="flex items-baseline gap-1.5">
        <span className={'font-display text-base font-extrabold tabular-nums ' + tone.text}>{fmtNum(a.percentage)}%</span>
        <span className="text-xs text-muted tabular-nums">{fmtNum(a.score)}/{fmtNum(a.total_marks)}</span>
      </div>
      <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-surface" aria-hidden="true"><div className={'h-full rounded-full ' + tone.bar} style={{ width: `${w}%` }} /></div>
    </div>
  );
}

function ResultBadge({ a }) {
  if (typeof a.passed === 'boolean') {
    return a.passed
      ? <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700 ring-1 ring-emerald-200"><Icon name="checkCircle" className="size-3.5" />Pass</span>
      : <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-[11px] font-bold text-red-700 ring-1 ring-red-200"><Icon name="x" className="size-3.5" />Fail</span>;
  }
  const [label, cls] = ATTEMPT_STATUS[a.status] || [a.status, 'bg-surface text-muted ring-line'];
  return <span className={'rounded-full px-2.5 py-0.5 text-[11px] font-bold whitespace-nowrap ring-1 ' + cls}>{label}</span>;
}

function Actions({ a, retake }) {
  if (a.status === 'in_progress') {
    return <Link to={`/mock-tests/${a.slug}/attempt/${a.attempt_id}`} className="btn-gold btn-sm"><Icon name="playCircle" className="size-4" />Resume</Link>;
  }
  return (
    <>
      {/* "expired" = time ran out while away; opening the result scores it (server-side). */}
      <Link to={`/mock-tests/${a.slug}/result/${a.attempt_id}`} className="btn-light btn-sm">{a.percentage !== null && a.percentage !== undefined ? 'Review answers' : a.status === 'expired' ? 'View result' : 'View status'}</Link>
      {retake && a.can_retake !== false && <Link to={`/mock-tests/${a.slug}`} className="btn-outline btn-sm"><Icon name="refresh" className="size-3.5" />Retake</Link>}
    </>
  );
}

function AttemptCard({ a, retake }) {
  const pct = a.percentage;
  const tone = TONE[toneFor(pct ?? null, typeof a.passed === 'boolean' ? a.passed : null)];
  return (
    <li className={'card overflow-hidden rounded-2xl border-l-4 p-4 ' + tone.border}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <Link to={`/mock-tests/${a.slug}`} className="line-clamp-2 font-semibold text-navy hover:text-primary">{a.title}</Link>
          <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
            <ModeBadge mode={a.mode} />
            <span>Attempt #{a.attempt_no}</span>
            <span>{formatDateTime(a.submitted_at || a.started_at)}</span>
            {a.accuracy !== null && a.accuracy !== undefined && <span className="font-semibold text-navy">Accuracy {fmtNum(a.accuracy)}%</span>}
          </p>
        </div>
        {pct !== null && pct !== undefined ? (
          <span className={'grid size-14 shrink-0 place-items-center rounded-2xl text-center ring-1 ' + tone.bg + ' ' + tone.ring}>
            <span className={'font-display text-sm leading-none font-extrabold ' + tone.text}>{fmtNum(Math.round(pct))}%<span className="mt-0.5 block text-[10px] font-semibold text-muted">{fmtNum(a.score)}/{fmtNum(a.total_marks)}</span></span>
          </span>
        ) : null}
      </div>
      <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
        <ResultBadge a={a} />
        <div className="flex gap-2"><Actions a={a} retake={retake && a.status !== 'in_progress'} /></div>
      </div>
    </li>
  );
}
