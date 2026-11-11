import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, formatDateTime } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { Alert, EmptyState, ErrorState, Field, Icon, Skeleton, useApi, useForm } from '../js/components.jsx';
import { Busy } from '../js/account/AuthLayout.jsx';
import { DashTitle, Panel, Pill } from '../js/account/ui.jsx';
import { HelpPanel } from './MyProfile.jsx';

const STATUS = { open: ['amber', 'Open'], in_progress: ['blue', 'In progress'], waiting: ['violet', 'Awaiting your reply'], resolved: ['green', 'Resolved'], closed: ['slate', 'Closed'] };
const ACTIVE = ['open', 'in_progress', 'waiting'];

function StatusPill({ status }) {
  const [tone, label] = STATUS[status] || ['slate', String(status || '').replace('_', ' ')];
  return <Pill tone={tone} className="shrink-0">{label}</Pill>;
}

export default function Support() {
  const [params, setParams] = useSearchParams();
  const openId = Number(params.get('ticket')) || null;
  const newSubject = params.get('new');
  const list = useApi('students.php', { action: 'tickets' });
  const { refreshSummary } = useAuth();
  const [filter, setFilter] = useState('all');
  const items = Array.isArray(list.data?.items) ? list.data.items : [];
  const cats = list.data?.categories || {};
  const shown = items.filter((t) => filter === 'all' || (filter === 'active' ? ACTIVE.includes(t.status) : !ACTIVE.includes(t.status)));
  const activeCount = items.filter((t) => ACTIVE.includes(t.status)).length;

  return (
    <>
      <DashTitle title="Help & Support" icon="headset" subtitle="Raise a ticket and track replies from our team."
        action={!newSubject && <button type="button" onClick={() => setParams({ new: '1' })} className="btn-primary"><Icon name="ticket" className="size-4" /> Raise a ticket</button>} />
      <div className="grid gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <div className="min-w-0 space-y-5">
          {newSubject && (
            <NewTicket categories={Object.keys(cats).length ? cats : { general: 'General' }} initialSubject={newSubject === '1' ? '' : newSubject}
              onDone={(id) => { list.reload(); refreshSummary(); setParams({ ticket: String(id) }); }} onCancel={() => setParams({})} />
          )}
          {openId && <TicketThread key={openId} id={openId} categories={cats} onClose={() => setParams({})} onChange={() => { list.reload(); refreshSummary(); }} />}
          <Panel title="My tickets" icon="messages" subtitle={items.length ? `${activeCount} active · ${items.length} total` : undefined}
            action={items.length > 0 && (
              <div role="group" aria-label="Filter tickets" className="hidden rounded-lg border border-line p-0.5 sm:flex">
                {[['all', 'All'], ['active', 'Active'], ['done', 'Resolved']].map(([k, l]) => (
                  <button key={k} type="button" aria-pressed={filter === k} onClick={() => setFilter(k)} className={'rounded-md px-2.5 py-1 text-xs font-semibold ' + (filter === k ? 'bg-primary text-white' : 'text-muted hover:text-primary')}>{l}</button>
                ))}
              </div>
            )}>
            {list.error && !list.data ? <ErrorState error={list.error} onRetry={list.reload} /> : list.loading && !list.data ? (
              <div className="space-y-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-16" rounded="rounded-xl" />)}</div>
            ) : items.length === 0 ? (
              <EmptyState icon="ticket" title="No tickets yet" text="Need help with a course, payment or test? Raise a ticket and we will reply here." className="shadow-none"
                action={!newSubject && <button type="button" onClick={() => setParams({ new: '1' })} className="btn-primary">Raise a ticket</button>} />
            ) : shown.length === 0 ? <p className="py-6 text-center text-sm text-muted">No tickets in this view.</p> : (
              <ul className="-mx-2 space-y-1">
                {shown.map((t) => (
                  <li key={t.id}>
                    <button type="button" onClick={() => setParams({ ticket: String(t.id) })} aria-current={openId === Number(t.id) ? 'true' : undefined}
                      className={'flex w-full items-center gap-3 rounded-xl px-2 py-3 text-left transition-colors ' + (openId === Number(t.id) ? 'bg-primary-soft' : 'hover:bg-surface')}>
                      <span className={'grid size-10 shrink-0 place-items-center rounded-xl ' + (ACTIVE.includes(t.status) ? 'bg-primary-soft text-primary' : 'bg-surface text-muted')}><Icon name="messages" className="size-5" /></span>
                      <span className="min-w-0 flex-1">
                        <span className={'block truncate font-semibold ' + (openId === Number(t.id) ? 'text-primary' : 'text-ink')}>#{t.id} · {t.subject}</span>
                        <span className="block truncate text-xs text-muted">{cats[t.category] || t.category} · updated {formatDateTime(t.updated_at)} · {t.messages} message{Number(t.messages) === 1 ? '' : 's'}</span>
                      </span>
                      <StatusPill status={t.status} />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </div>
        <div className="min-w-0 space-y-5">
          <HelpPanel />
        </div>
      </div>
    </>
  );
}

function NewTicket({ categories, initialSubject, onDone, onCancel }) {
  const form = useForm({ subject: initialSubject, category: Object.keys(categories)[0] || 'general', message: '' });
  const ref = useRef(null);
  useEffect(() => {
    ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, []);
  const submit = form.submit(async (v) => {
    const fields = {};
    if (v.subject.trim().length < 4) fields.subject = 'Enter a short subject.';
    if (v.message.trim().length < 10) fields.message = 'Describe the issue in a few words (10+ characters).';
    if (Object.keys(fields).length) throw Object.assign(new Error('Please correct the highlighted fields.'), { fields });
    const r = await api.post('students.php', { ...v, subject: v.subject.trim(), message: v.message.trim() }, { action: 'tickets' });
    onDone(r.id);
  });
  return (
    <div ref={ref} className="scroll-mt-28">
      <Panel title="Raise a ticket" icon="ticket" subtitle="We usually need your order number or course name to help quickly.">
        {form.message && <Alert className="mb-4">{form.message.text}</Alert>}
        <form onSubmit={submit} noValidate className="grid gap-4 sm:grid-cols-2" aria-busy={form.busy}>
          <Field label="Subject" maxLength={180} className="min-w-0" {...form.bind('subject')} />
          <Field id="category" label="Category" error={form.errors.category} className="min-w-0">
            <select id="category" className="input" value={form.values.category} onChange={form.bind('category').onChange}>
              {Object.entries(categories).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </Field>
          <Field label="Describe the issue" as="textarea" rows={5} className="min-w-0 sm:col-span-2" hint="Include the order number, course name or what you see on screen." {...form.bind('message')} />
          <div className="flex flex-wrap gap-3 sm:col-span-2">
            <button disabled={form.busy} className="btn-primary">{form.busy ? <><Busy /> Submitting…</> : <><Icon name="send" className="size-4" /> Submit ticket</>}</button>
            <button type="button" onClick={onCancel} className="btn-light">Cancel</button>
          </div>
        </form>
      </Panel>
    </div>
  );
}

function TicketThread({ id, categories, onClose, onChange }) {
  const { data, loading, error, reload } = useApi('students.php', { action: 'ticket', id });
  const [msg, setMsg] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState(null);
  const ref = useRef(null);
  const listRef = useRef(null);
  useEffect(() => {
    ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }, []);
  useEffect(() => {
    // Show the newest message: scroll only the thread box, not the page.
    if (listRef.current) listRef.current.scrollTop = listRef.current.scrollHeight;
  }, [data]);

  const send = async (e) => {
    e?.preventDefault();
    if (msg.trim().length < 2 || busy) return;
    setBusy(true);
    setErr(null);
    try {
      await api.post('students.php', { id, message: msg.trim() }, { action: 'ticket-reply' });
      setMsg('');
      reload();
      onChange();
    } catch (x) {
      setErr(x.fields?.message || x.message);
    } finally {
      setBusy(false);
    }
  };

  const close = <button type="button" onClick={onClose} aria-label="Close ticket" className="grid size-9 place-items-center rounded-lg text-muted hover:bg-surface hover:text-ink"><Icon name="x" className="size-4" /></button>;
  if (error && !data) return <div ref={ref} className="scroll-mt-28"><Panel title={`Ticket #${id}`} icon="messages" action={close}><ErrorState error={error} onRetry={reload} /></Panel></div>;
  if (!data) return <div ref={ref} className="scroll-mt-28"><Skeleton className="h-64" rounded="rounded-2xl" /></div>;

  const t = data.ticket;
  return (
    <div ref={ref} className="scroll-mt-28">
      <Panel title={`#${t.id} · ${t.subject}`} icon="messages" subtitle={`${categories[t.category] || t.category} · opened ${formatDateTime(t.created_at)}`} action={<><StatusPill status={t.status} />{close}</>}>
        <ul ref={listRef} className={'max-h-[28rem] space-y-3 overflow-y-auto pr-1 scrollbar-thin ' + (loading ? 'opacity-70' : '')}>
          {data.messages.map((m, i) => (
            <li key={i} className={'flex gap-2.5 ' + (m.from_staff ? '' : 'flex-row-reverse')}>
              <span className={'grid size-8 shrink-0 place-items-center rounded-full text-xs font-bold ' + (m.from_staff ? 'bg-navy text-white' : 'bg-primary-soft text-primary')} aria-hidden="true">
                {m.from_staff ? <Icon name="headset" className="size-4" /> : (m.name || '?')[0].toUpperCase()}
              </span>
              <div className={'max-w-[85%] min-w-0 rounded-2xl px-4 py-3 text-sm ' + (m.from_staff ? 'rounded-tl-sm border border-line bg-white text-ink' : 'rounded-tr-sm bg-gradient-to-br from-primary to-[#2f7bff] text-white')}>
                <p className={'mb-1 text-xs font-semibold ' + (m.from_staff ? 'text-navy' : 'text-white/85')}>{m.from_staff ? m.name || 'Laxyam Support' : 'You'} · {formatDateTime(m.created_at)}</p>
                <p className="break-words whitespace-pre-wrap">{m.message}</p>
              </div>
            </li>
          ))}
        </ul>
        {t.status === 'closed' ? (
          <p className="mt-4 rounded-xl bg-surface p-3 text-sm text-muted">This ticket is closed. Raise a new ticket if you still need help.</p>
        ) : (
          <form onSubmit={send} className="mt-4">
            <label htmlFor="reply" className="sr-only">Reply</label>
            <div className="flex items-end gap-2 rounded-2xl border border-line bg-white p-2 focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15">
              <textarea id="reply" rows={2} value={msg} onChange={(e) => setMsg(e.target.value)} maxLength={5000}
                onKeyDown={(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) send(e); }}
                className="min-w-0 flex-1 resize-none bg-transparent px-2 py-1.5 text-sm outline-none" placeholder="Write a reply…" aria-describedby="reply-hint" />
              <button disabled={busy || msg.trim().length < 2} className="btn-primary btn-sm shrink-0" aria-label="Send reply">{busy ? <Busy /> : <Icon name="send" className="size-4" />}<span className="hidden sm:inline">Send</span></button>
            </div>
            <p id="reply-hint" className="mt-1 text-xs text-muted">Press Ctrl + Enter to send.</p>
          </form>
        )}
        {err && <Alert className="mt-2">{err}</Alert>}
      </Panel>
    </div>
  );
}
