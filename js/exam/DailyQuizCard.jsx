// Today's daily quiz with the student's streak (mock-tests.php?action=daily, includes/daily-quiz.php).
// Shown on the dashboard and the Mock Tests page; hidden when there is no quiz today and no streak to show.
import { Link } from 'react-router-dom';
import { Icon, useApi } from '../components.jsx';

const dayLetter = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString('en-IN', { weekday: 'narrow' });
const dayLabel = (iso) => new Date(iso + 'T12:00:00').toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'short' });

export function DailyQuizCard({ className = '' }) {
  const { data } = useApi('mock-tests.php', { action: 'daily' });
  if (!data || (!data.quiz && !data.streak?.current)) return null;
  const q = data.quiz;
  const s = data.streak;
  const done = !!s?.done_today;
  return (
    <section className={'relative isolate overflow-hidden rounded-2xl bg-gradient-to-br from-orange-50 via-white to-amber-50 p-5 ring-1 ring-amber-200 sm:p-6 ' + className} aria-labelledby="daily-quiz-h">
      <div className="flex flex-wrap items-center gap-4">
        <span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-orange-500 to-amber-400 text-white shadow-[0_10px_24px_-10px_rgba(234,88,12,.7)]"><Icon name="flame" className="size-7" /></span>
        <div className="min-w-0 flex-1">
          <p className="text-xs font-bold tracking-wide text-orange-700 uppercase">Daily quiz · {dayLabel(data.date)}</p>
          <h2 id="daily-quiz-h" className="truncate font-display text-lg font-extrabold text-navy">{q ? q.title : 'No quiz today'}</h2>
          <p className="text-sm text-muted">
            {q ? `${q.questions} question${q.questions === 1 ? '' : 's'} · ${q.minutes ? `${q.minutes} min` : 'untimed, instant answers'}` : data.tomorrow ? 'A new quiz is coming tomorrow.' : 'Check back tomorrow.'}
          </p>
        </div>
        {s && (
          <div className="text-center" aria-label={`${s.current} day streak`}>
            <p className="font-display text-3xl leading-none font-extrabold text-orange-600 tabular-nums">{s.current}</p>
            <p className="mt-1 text-[11px] font-semibold text-muted">day streak</p>
          </div>
        )}
        {q && (done
          ? <span className="inline-flex items-center gap-2 rounded-xl bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 ring-1 ring-emerald-200"><Icon name="checkCircle" className="size-4" />Done for today</span>
          : <Link to={`/mock-tests/${q.slug}`} className="btn-primary w-full sm:w-auto">{s?.current ? 'Keep your streak' : 'Start today’s quiz'}<Icon name="arrowRight" className="size-4" /></Link>)}
      </div>
      {s ? (
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          <ol className="flex gap-1.5" aria-label="Last 7 days">
            {s.days.map((d) => (
              <li key={d.date} title={`${dayLabel(d.date)}: ${d.done ? 'done' : d.scheduled ? 'missed' : 'no quiz'}`}
                className={'grid size-8 place-items-center rounded-lg text-[11px] font-bold ' + (d.done ? 'bg-orange-500 text-white' : d.scheduled ? 'bg-white text-muted ring-1 ring-amber-200' : 'text-muted/50')}>
                {d.done ? <Icon name="check" className="size-3.5" strokeWidth={3} /> : dayLetter(d.date)}
                <span className="sr-only">{d.done ? ' done' : d.scheduled ? ' missed' : ' no quiz'}</span>
              </li>
            ))}
          </ol>
          <p className="text-xs text-muted">Best: <strong className="text-navy">{s.best} day{s.best === 1 ? '' : 's'}</strong> · finish it on the day to keep the streak</p>
        </div>
      ) : q && (
        <p className="mt-3 text-xs text-muted"><Link to="/login" className="font-semibold text-primary hover:underline">Log in</Link> to build a daily streak.</p>
      )}
    </section>
  );
}
