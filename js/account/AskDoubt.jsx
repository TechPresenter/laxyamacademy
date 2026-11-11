// "Ask a doubt" button + dialog, for a lesson (lessonId) or a question of a finished test
// (attemptId + questionId). The server checks the student may ask there (api/doubts.php).
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api.js';
import { Alert, Icon } from '../components.jsx';
import { Modal } from '../home/media.jsx';
import { Busy } from './AuthLayout.jsx';

export function AskDoubt({ lessonId, attemptId, questionId, label = 'Ask a doubt', title = 'Ask a doubt', className = 'btn-light btn-sm' }) {
  const [open, setOpen] = useState(false);
  const [body, setBody] = useState('');
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);
  const [done, setDone] = useState(null);
  const ctx = lessonId ? { lesson_id: lessonId } : { attempt_id: attemptId, question_id: questionId };

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const d = file
        ? await api.upload('doubts.php', file, { action: 'ask' }, { ...ctx, body })
        : await api.post('doubts.php', { ...ctx, body }, { action: 'ask' });
      setDone(d);
      setBody('');
      setFile(null);
    } catch (err) {
      setError(err.fields?.body || err.fields?.file || err.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <button type="button" className={className} onClick={() => { setOpen(true); setDone(null); setError(null); }}>
        <Icon name="messages" className="size-4" />{label}
      </button>
      <Modal open={open} onClose={() => setOpen(false)} title={title} className="max-w-lg">
        <div className="card rounded-2xl p-6">
          {done ? (
            <div className="text-center">
              <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-emerald-50 text-emerald-600"><Icon name="checkCircle" className="size-7" /></span>
              <p className="mt-4 font-display text-lg font-bold text-navy">Your doubt has been sent</p>
              <p className="mt-1 text-sm text-muted">A teacher will answer it. You will get a notification and an email.</p>
              <div className="mt-5 flex flex-wrap justify-center gap-2">
                <Link to={`/dashboard/doubts/${done.id}`} className="btn-primary">Open my doubt <Icon name="arrowRight" className="size-4" /></Link>
                <button type="button" className="btn-light" onClick={() => setOpen(false)}>Close</button>
              </div>
            </div>
          ) : (
            <form onSubmit={submit} noValidate className="space-y-4" aria-busy={busy}>
              <p className="text-sm text-muted">Explain what you did not understand. A teacher answers here and you get a notification.</p>
              {error && <Alert>{error}</Alert>}
              <div>
                <label className="label" htmlFor="doubt-body">Your doubt</label>
                <textarea id="doubt-body" className="input min-h-32" maxLength={4000} required value={body} onChange={(e) => setBody(e.target.value)}
                  placeholder="e.g. Why is option C correct and not B? / I did not understand the second example." />
              </div>
              <div>
                <label className="label" htmlFor="doubt-photo">Photo <span className="font-normal text-muted">(optional)</span></label>
                <input id="doubt-photo" type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setFile(e.target.files?.[0] || null)}
                  className="block w-full text-sm text-ink file:mr-3 file:rounded-lg file:border-0 file:bg-primary-soft file:px-3 file:py-2 file:font-semibold file:text-primary" />
                <p className="mt-1 text-xs text-muted">Your rough work or the page you are stuck on. Only teachers can see it.</p>
              </div>
              <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" className="btn-light" onClick={() => setOpen(false)}>Cancel</button>
                <button type="submit" className="btn-primary" disabled={busy || body.trim().length < 5}>{busy ? <><Busy /> Sending…</> : <>Send doubt <Icon name="send" className="size-4" /></>}</button>
              </div>
            </form>
          )}
        </div>
      </Modal>
    </>
  );
}
