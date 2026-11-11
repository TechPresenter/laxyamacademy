// My Doubts (/dashboard/doubts and /dashboard/doubts/:id): doubts the student asked on lessons and test
// questions, with the teachers' answers. Opening an answered doubt marks the answer as read.
import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, apiUrl, formatDateTime } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { Alert, EmptyState, ErrorState, Icon, Skeleton, useApi } from '../js/components.jsx';
import { Busy } from '../js/account/AuthLayout.jsx';
import { DashTitle, Panel, Pill } from '../js/account/ui.jsx';

const TONE = { open: 'amber', answered: 'green', closed: 'slate' };

export default function MyDoubts() {
  const { id } = useParams();
  return id ? <Thread id={id} key={id} /> : <DoubtList />;
}

function DoubtList() {
  const { data, loading, error, reload } = useApi('doubts.php', { action: 'mine' });
  const items = data?.items || [];
  return (
    <>
      <DashTitle title="My Doubts" icon="messages" subtitle="Questions you asked on lessons and mock test questions, with your teachers’ answers." />
      {error ? <ErrorState error={error} onRetry={reload} />
        : loading && !data ? <div className="space-y-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-24" />)}</div>
          : !items.length ? (
            <EmptyState icon="messages" title="No doubts yet"
              text="Stuck somewhere? Use “Ask a doubt” under any lesson of your course, or on a question in the answer review of a test you finished."
              action={<Link to="/dashboard/courses" className="btn-primary">Go to my courses <Icon name="arrowRight" className="size-4" /></Link>} />
          ) : (
            <ul className="space-y-3">
              {items.map((d) => (
                <li key={d.id}>
                  <Link to={`/dashboard/doubts/${d.id}`} className={'card card-hover flex items-start gap-4 p-4 sm:p-5 ' + (d.unread ? 'ring-2 ring-emerald-400/60' : '')}>
                    <span className={'grid size-11 shrink-0 place-items-center rounded-xl ' + (d.kind === 'test' ? 'bg-violet/10 text-violet' : 'bg-primary-soft text-primary')}>
                      <Icon name={d.kind === 'test' ? 'clipboard' : 'book'} className="size-5" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="flex flex-wrap items-center gap-2">
                        <span className="font-semibold text-navy">{d.subject}</span>
                        <Pill tone={TONE[d.status]}>{d.status_label}</Pill>
                        {d.unread && <Pill tone="green">New answer</Pill>}
                      </span>
                      {d.preview && <span className="mt-1 line-clamp-2 block text-sm text-muted">{d.preview}</span>}
                      <span className="mt-1 block text-xs text-muted">{d.messages} message{d.messages === 1 ? '' : 's'} · {formatDateTime(d.last_reply_at || d.created_at)}</span>
                    </span>
                    <Icon name="chevronRight" className="mt-3 size-4 shrink-0 text-muted" />
                  </Link>
                </li>
              ))}
            </ul>
          )}
    </>
  );
}

function Thread({ id }) {
  const { refreshSummary } = useAuth();
  const { data: d, loading, error, reload } = useApi('doubts.php', { action: 'thread', id });
  const [body, setBody] = useState('');
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(null);

  // Opening the thread marked the answer read on the server: refresh the menu badge.
  useEffect(() => { if (d) refreshSummary?.(); }, [d, refreshSummary]);

  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading && !d) return <div className="space-y-3"><Skeleton className="h-10 w-2/3" /><Skeleton className="h-40" /><Skeleton className="h-40" /></div>;

  const send = async (e) => {
    e.preventDefault();
    setBusy(true);
    setMsg(null);
    try {
      if (file) await api.upload('doubts.php', file, { action: 'reply' }, { id: d.id, body });
      else await api.post('doubts.php', { id: d.id, body }, { action: 'reply' });
      setBody('');
      setFile(null);
      e.target.reset();
      reload();
    } catch (err) {
      setMsg({ type: 'error', text: err.fields?.body || err.fields?.file || err.message });
    } finally {
      setBusy(false);
    }
  };
  const resolve = async () => {
    setBusy(true);
    try {
      await api.post('doubts.php', { id: d.id }, { action: 'close' });
      reload();
    } catch (err) {
      setMsg({ type: 'error', text: err.message });
    } finally {
      setBusy(false);
    }
  };
  const ctx = d.context;

  return (
    <>
      <DashTitle title={d.subject} icon="messages" subtitle={`Asked ${formatDateTime(d.created_at)}`}
        action={<><Pill tone={TONE[d.status]}>{d.status_label}</Pill><Link to="/dashboard/doubts" className="btn-light btn-sm"><Icon name="chevronLeft" className="size-4" />All doubts</Link></>} />
      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="min-w-0 space-y-4">
          {d.thread.map((m) => (
            <article key={m.id} className={'card p-4 sm:p-5 ' + (m.staff ? 'border-l-4 border-l-emerald-500' : '')}>
              <header className="flex flex-wrap items-center justify-between gap-2">
                <span className="flex items-center gap-2 font-semibold text-navy">
                  <span className={'grid size-8 place-items-center rounded-full text-xs font-bold ' + (m.staff ? 'bg-emerald-100 text-emerald-700' : 'bg-primary-soft text-primary')}>{m.author.charAt(0).toUpperCase()}</span>
                  {m.staff ? <>{m.author} <Pill tone="green">Teacher</Pill></> : 'You'}
                </span>
                <time className="text-xs text-muted" dateTime={m.created_at}>{formatDateTime(m.created_at)}</time>
              </header>
              <p className="mt-3 text-[15px] leading-relaxed break-words whitespace-pre-line text-ink">{m.body}</p>
              {m.image_url && (
                <a href={apiUrl('doubts.php', { action: 'image', m: m.id })} target="_blank" rel="noopener noreferrer" className="mt-3 inline-block">
                  <img src={apiUrl('doubts.php', { action: 'image', m: m.id })} alt={m.staff ? 'Photo from your teacher' : 'Your photo'} loading="lazy" className="max-h-80 max-w-full rounded-xl border border-line" />
                </a>
              )}
            </article>
          ))}
          {d.status === 'open' && <p className="flex items-center gap-2 text-sm text-muted"><Icon name="clock" className="size-4" />Waiting for a teacher’s answer - you will get a notification.</p>}

          <form onSubmit={send} className="card space-y-3 p-4 sm:p-5" aria-busy={busy}>
            <label htmlFor="reply" className="label">{d.status === 'closed' ? 'Still stuck? Write again to re-open this doubt' : 'Add a follow-up'}</label>
            {msg && <Alert type={msg.type}>{msg.text}</Alert>}
            <textarea id="reply" className="input min-h-28" maxLength={4000} value={body} onChange={(e) => setBody(e.target.value)} placeholder="Type your message…" />
            <div className="flex flex-wrap items-center justify-between gap-3">
              <input type="file" accept="image/jpeg,image/png,image/webp" aria-label="Attach a photo (optional)" onChange={(e) => setFile(e.target.files?.[0] || null)}
                className="text-sm text-ink file:mr-3 file:rounded-lg file:border-0 file:bg-primary-soft file:px-3 file:py-2 file:font-semibold file:text-primary" />
              <button type="submit" className="btn-primary" disabled={busy || body.trim().length < 5}>{busy ? <><Busy /> Sending…</> : <>Send <Icon name="send" className="size-4" /></>}</button>
            </div>
          </form>
        </div>

        <aside className="space-y-4">
          {ctx && (
            <Panel title={ctx.type === 'test' ? 'Test question' : 'Lesson'} icon={ctx.type === 'test' ? 'clipboard' : 'book'}>
              {ctx.course && <p className="text-sm font-semibold text-navy">{ctx.course}</p>}
              {ctx.type === 'lesson' && <p className="mt-1 text-sm text-ink">{ctx.title}</p>}
              {ctx.question && (
                <div className="mt-2 text-sm">
                  <p className="break-words whitespace-pre-line text-ink">{ctx.question.text}</p>
                  <ol className="mt-2 space-y-1">{ctx.question.options.map((o, i) => <li key={i} className="rounded-lg bg-surface px-2.5 py-1.5"><b className="mr-1.5">{'ABCDEF'[i]}.</b>{o.text}</li>)}</ol>
                </div>
              )}
              {ctx.url && <Link to={ctx.url} className="btn-light btn-sm mt-4 w-full">{ctx.type === 'test' ? 'Open my result' : 'Open the lesson'} <Icon name="arrowRight" className="size-4" /></Link>}
            </Panel>
          )}
          {d.status !== 'closed' && (
            <Panel title="Doubt cleared?" icon="checkCircle">
              <p className="text-sm text-muted">Mark it resolved once the answer helped. You can always write again to re-open it.</p>
              <button type="button" onClick={resolve} disabled={busy} className="btn-outline btn-sm mt-3 w-full"><Icon name="check" className="size-4" />Mark as resolved</button>
            </Panel>
          )}
        </aside>
      </div>
    </>
  );
}
