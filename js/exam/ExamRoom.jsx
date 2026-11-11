// Distraction-free exam room (/mock-tests/:slug/attempt/:attemptId), NTA / IBPS style.
// The server owns the clock and the answer key: the countdown is computed from a deadline
// (now + seconds_left) and re-synced on every save and whenever the tab becomes visible.
// Practice quizzes (mode = practice) are untimed and check each answer instantly.
// Sections: shown as tabs. With sectional timing only the running section is open - the server
// sends its questions alone, moves on when its timer ends and accepts answers only there.
// Bilingual tests: English / हिंदी switch; a question without a Hindi version stays in English.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '../api.js';
import { useAuth } from '../auth.jsx';
import { Alert, ErrorState, Icon, Spinner, useApi, useSeo } from '../components.jsx';
import { LANG_KEY, LangToggle, ModeBadge, fmtClock, fmtNum, pick, store } from './shared.jsx';

/** The five standard palette states. */
export const Q_STATES = {
  not_visited: { label: 'Not visited', cls: 'border-slate-300 bg-slate-100 text-slate-600' },
  not_answered: { label: 'Not answered', cls: 'border-red-500 bg-red-500 text-white' },
  answered: { label: 'Answered', cls: 'border-emerald-600 bg-emerald-600 text-white' },
  marked: { label: 'Marked for review', cls: 'border-violet bg-violet text-white' },
  answered_marked: { label: 'Answered & marked for review', cls: 'border-violet bg-violet text-white', dot: true },
};
const STATE_ORDER = ['answered', 'not_answered', 'not_visited', 'marked', 'answered_marked'];
const LEAVE_MSG = 'Leave the exam room? Your saved answers are kept, but the timer keeps running.';
const BLANK = { option_ids: [], marked: false, checked: false, feedback: null };
const LETTERS = 'ABCDEFGH';
const KBD = 'rounded border border-line bg-white px-1 font-mono text-[10px] font-semibold text-navy';

export function ExamRoom() {
  const { slug, attemptId } = useParams();
  const navigate = useNavigate();
  const { data, loading, error, reload } = useApi('mock-tests.php', { action: 'attempt', id: attemptId });
  useSeo({ title: data?.exam ? `${data.exam.title} | Exam room` : 'Exam room | Laxyam Academy', robots: 'noindex,nofollow' });
  const finished = !!data && data.status !== 'in_progress';

  useEffect(() => {
    if (finished) navigate(`/mock-tests/${slug}/result/${attemptId}`, { replace: true });
  }, [finished, navigate, slug, attemptId]);

  if (error) return <div className="container-x py-10"><ErrorState error={error} onRetry={reload} title="We couldn’t open this test." /></div>;
  if (loading || !data || finished) return <Spinner label="Opening your test…" className="min-h-[60vh]" />;
  if (!data.questions?.length) return <div className="container-x py-10"><ErrorState error={{ message: 'This attempt has no questions.' }} /></div>;
  return <Room key={data.attempt_id} data={data} slug={slug} id={Number(attemptId)} />;
}

/** Index to show for an attempt payload: the remembered question, unless its section is not open. */
function startIndex(d, wanted) {
  const qs = d.questions;
  const i = Math.min(Math.max(0, Number(wanted) || 0), qs.length - 1);
  if (!d.section_timed || d.current_section === null || d.current_section === undefined || qs[i]?.section === d.current_section) return i;
  const first = qs.findIndex((x) => x.section === d.current_section);
  return first >= 0 ? first : i;
}

function Room({ data, slug, id }) {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [qs, setQs] = useState(data.questions);
  const [sections, setSections] = useState(data.sections || null);
  const secTimed = !!data.section_timed;
  const [runSec, setRunSec] = useState(data.current_section ?? null);
  const practice = data.mode === 'practice' || data.exam?.mode === 'practice' || data.timed === false || data.seconds_left === null;
  const storeKey = `lx-exam-${id}`;
  const saved = useMemo(() => store.get(storeKey, {}), [storeKey]);
  const hasHindi = (data.languages || []).includes('hi');
  const [lang, setLangState] = useState(() => (hasHindi && store.get(LANG_KEY, 'en') === 'hi' ? 'hi' : 'en'));
  const setLang = (l) => { setLangState(l); store.set(LANG_KEY, l); };

  const [answers, setAnswers] = useState(() => {
    const out = {};
    Object.entries(data.answers || {}).forEach(([qid, a]) => { out[qid] = { ...BLANK, ...a, option_ids: (a.option_ids || []).map(Number) }; });
    return out;
  });
  const [current, setCurrent] = useState(() => startIndex(data, saved.current));
  const [visited, setVisited] = useState(() => {
    const v = {};
    (saved.visited || []).forEach((qid) => { v[qid] = true; });
    Object.keys(data.answers || {}).forEach((qid) => { v[qid] = true; });
    v[data.questions[startIndex(data, saved.current)].id] = true;
    return v;
  });
  // Start time comes from the server (started_at) so "time spent" survives a device switch.
  const [started] = useState(() => {
    const server = Date.parse(data.started_at || '');
    return Number.isFinite(server) ? Math.min(server, Date.now()) : Number(saved.started) || Date.now();
  });
  const [deadline, setDeadline] = useState(() => (practice ? null : Date.now() + Number(data.seconds_left || 0) * 1000));
  const [secDeadline, setSecDeadline] = useState(() => (typeof data.section_seconds_left === 'number' ? Date.now() + data.section_seconds_left * 1000 : null));
  const [now, setNow] = useState(() => Date.now());
  const [saveState, setSaveState] = useState('idle'); // idle | saving | saved | error | offline
  const [notice, setNotice] = useState(null);
  const [checking, setChecking] = useState(null);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [finishOpen, setFinishOpen] = useState(false); // timed sections: "finish this section?"
  const [moving, setMoving] = useState(false);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [filter, setFilter] = useState('all');
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState(null);
  const [timeUp, setTimeUp] = useState(false);
  const doneRef = useRef(false);
  const queueRef = useRef(new Map());
  const flushingRef = useRef(null);
  const panelRef = useRef(null);
  const refreshingRef = useRef(false);
  const runSecRef = useRef(data.current_section ?? null);

  const q = qs[current];
  const ans = answers[q.id] || BLANK;
  const secondsLeft = practice ? null : Math.max(0, Math.ceil((deadline - now) / 1000));
  const sectionLeft = secTimed && secDeadline !== null ? Math.max(0, Math.ceil((secDeadline - now) / 1000)) : null;
  const elapsed = Math.max(0, Math.floor((now - started) / 1000));
  // The section on screen: the running one (timed) or the current question's (sections as tabs).
  const activeSec = sections ? (secTimed ? runSec : q.section ?? null) : null;
  const inActive = (x) => activeSec === null || activeSec === undefined || x.section === activeSec;
  const secRange = useMemo(() => {
    if (!secTimed) return [0, qs.length - 1];
    const idx = qs.map((x, i) => (x.section === runSec ? i : -1)).filter((i) => i >= 0);
    return idx.length ? [idx[0], idx[idx.length - 1]] : [0, qs.length - 1];
  }, [qs, secTimed, runSec]);
  const lastSection = !sections || !secTimed || runSec === null || runSec >= sections.length - 1;

  // ---- local UI memory (last question, visited set) so a resume lands where you were
  useEffect(() => {
    store.set(storeKey, { current, visited: Object.keys(visited), started });
  }, [storeKey, current, visited, started]);

  // ---- clock tick (display only; the server enforces the deadline)
  useEffect(() => {
    const t = setInterval(() => setNow(Date.now()), 500);
    return () => clearInterval(t);
  }, []);

  const sync = useCallback((secs, sectionSecs) => {
    if (typeof secs === 'number') setDeadline(Date.now() + secs * 1000);
    if (typeof sectionSecs === 'number') setSecDeadline(Date.now() + sectionSecs * 1000);
  }, []);

  /** Attempt is over (submitted, timed out or closed elsewhere): go to the scorecard. */
  const ended = useCallback((auto) => {
    doneRef.current = true;
    store.remove(storeKey);
    navigate(`/mock-tests/${slug}/result/${id}${auto ? '?auto=1' : ''}`, { replace: true });
  }, [navigate, slug, id, storeKey]);

  /**
   * Reload the attempt from the server (clock, sections, newly opened questions). With timed
   * sections a new running section brings its questions and the view jumps to its first one.
   */
  const refresh = useCallback(async (message) => {
    if (refreshingRef.current || doneRef.current) return;
    refreshingRef.current = true;
    try {
      const r = await api.get('mock-tests.php', { action: 'attempt', id });
      if (r.status !== 'in_progress') {
        ended(r.status === 'auto_submitted');
        return;
      }
      sync(r.seconds_left, r.section_seconds_left);
      if (!r.section_timed) return;
      setQs(r.questions);
      setSections(r.sections || null);
      if (runSecRef.current !== r.current_section) {
        runSecRef.current = r.current_section;
        setRunSec(r.current_section);
        const first = r.questions.findIndex((x) => x.section === r.current_section);
        if (first >= 0) {
          setCurrent(first);
          setVisited((v) => ({ ...v, [r.questions[first].id]: true }));
          panelRef.current?.scrollTo({ top: 0 });
        }
        const s = r.sections?.[r.current_section];
        if (message !== false && s) setNotice(message || `Section “${s.title}” has started.`);
      }
    } catch { /* keep the local state; the next save or focus re-syncs */ } finally {
      refreshingRef.current = false;
    }
  }, [id, sync, ended]);

  // ---- autosave queue: latest payload per question, sent one at a time, retried on failure
  const flush = useCallback(() => {
    if (flushingRef.current) return flushingRef.current;
    const run = async () => {
      while (queueRef.current.size && !doneRef.current) {
        const [qid, payload] = queueRef.current.entries().next().value;
        setSaveState('saving');
        try {
          const r = await api.post('mock-tests.php', { attempt_id: id, question_id: qid, option_ids: payload.option_ids, marked: payload.marked }, { action: 'answer' });
          if (queueRef.current.get(qid) === payload) queueRef.current.delete(qid);
          sync(r?.seconds_left, r?.section_seconds_left);
        } catch (e) {
          if (e.code === 'time_up' || e.code === 'already_submitted') {
            queueRef.current.clear();
            ended(e.code === 'time_up');
            return false;
          }
          if (e.code === 'section_closed' || e.code === 'section_locked') {
            queueRef.current.delete(qid); // that section's time is over: the change cannot count
            setNotice(e.message);
            refresh(false);
            continue;
          }
          if (e.code === 'locked' || e.status === 422) {
            queueRef.current.delete(qid); // nothing to retry - the server rejected this change
            setNotice(e.message);
            continue;
          }
          setSaveState(typeof navigator !== 'undefined' && navigator.onLine === false ? 'offline' : 'error');
          return false;
        }
      }
      setSaveState('saved');
      return true;
    };
    const p = run().finally(() => { flushingRef.current = null; });
    flushingRef.current = p;
    return p;
  }, [id, sync, ended, refresh]);

  // Automatic retry while unsaved changes remain
  useEffect(() => {
    if (saveState !== 'error' && saveState !== 'offline') return undefined;
    const t = setTimeout(() => { if (queueRef.current.size) flush(); }, 5000);
    return () => clearTimeout(t);
  }, [saveState, flush]);
  useEffect(() => {
    const online = () => { if (queueRef.current.size) flush(); };
    window.addEventListener('online', online);
    return () => window.removeEventListener('online', online);
  }, [flush]);

  const persist = (questionId, next) => {
    setAnswers((a) => ({ ...a, [questionId]: next }));
    queueRef.current.set(questionId, { option_ids: next.option_ids, marked: next.marked });
    flush();
  };

  // ---- submit (manual or at 00:00)
  const submit = useCallback(async (reason = 'manual', force = false) => {
    if (doneRef.current) return;
    setSubmitting(true);
    setSubmitError(null);
    if (reason === 'timeout') setTimeUp(true);
    const allSaved = await flush();
    if (!allSaved && reason === 'manual' && !force && !doneRef.current) {
      setSubmitError('unsaved');
      setSubmitting(false);
      return;
    }
    if (doneRef.current) return;
    try {
      const r = await api.post('mock-tests.php', { attempt_id: id, reason }, { action: 'submit' });
      ended(r?.auto_submitted || reason === 'timeout');
    } catch (e) {
      if (reason === 'timeout' || e.code === 'already_submitted' || e.code === 'time_up') {
        ended(true); // the server finalises expired attempts on its own
        return;
      }
      setSubmitError(e.message || 'Could not submit. Check your connection and try again.');
      setSubmitting(false);
    }
  }, [flush, id, ended]);

  useEffect(() => {
    if (!practice && secondsLeft === 0 && !doneRef.current && !submitting) submit('timeout');
  }, [practice, secondsLeft, submitting, submit]);

  // ---- timed sections: when the section clock ends, save what is pending and open the next section
  useEffect(() => {
    if (!secTimed || sectionLeft !== 0 || secondsLeft === 0 || doneRef.current) return;
    setFinishOpen(false);
    const done = sections?.[runSec];
    (queueRef.current.size ? flush() : Promise.resolve()).then(() => refresh(done ? `Time for “${done.title}” is over. The next section has started.` : undefined));
  }, [secTimed, sectionLeft, secondsLeft, sections, runSec, flush, refresh]);

  // ---- re-sync the clock when the tab comes back (background tabs throttle timers)
  useEffect(() => {
    let last = Date.now();
    const onBack = async () => {
      if (document.visibilityState !== 'visible' || doneRef.current) return;
      setNow(Date.now());
      if (Date.now() - last < 10000) return;
      last = Date.now();
      await refresh();
      if (queueRef.current.size) flush();
    };
    document.addEventListener('visibilitychange', onBack);
    window.addEventListener('focus', onBack);
    return () => {
      document.removeEventListener('visibilitychange', onBack);
      window.removeEventListener('focus', onBack);
    };
  }, [refresh, flush]);

  /** Timed sections: finish the running section early (it cannot be opened again). */
  const finishSection = async () => {
    setMoving(true);
    await flush();
    try {
      await api.post('mock-tests.php', { attempt_id: id, from: runSec }, { action: 'next_section' });
      setFinishOpen(false);
      await refresh();
    } catch (e) {
      if (e.code === 'time_up' || e.code === 'already_submitted') ended(true);
      else {
        setFinishOpen(false);
        setNotice(e.message);
        refresh(false);
      }
    } finally {
      setMoving(false);
    }
  };

  // ---- navigation guards while the attempt is open
  useEffect(() => {
    const unload = (e) => {
      if (doneRef.current) return;
      e.preventDefault();
      e.returnValue = '';
    };
    // In-app links (capture phase runs before React Router's click handler)
    const click = (e) => {
      if (doneRef.current || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      const a = e.target.closest?.('a[href]');
      if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
      if (!window.confirm(LEAVE_MSG)) {
        e.preventDefault();
        e.stopPropagation();
      } else doneRef.current = true;
    };
    // Browser back button: keep a duplicate history entry and confirm before leaving
    window.history.pushState(window.history.state, '', window.location.href);
    const pop = () => {
      if (doneRef.current) return;
      if (window.confirm(LEAVE_MSG)) {
        doneRef.current = true;
        window.history.back();
      } else {
        window.history.pushState(window.history.state, '', window.location.href);
      }
    };
    window.addEventListener('beforeunload', unload);
    document.addEventListener('click', click, true);
    window.addEventListener('popstate', pop);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      window.removeEventListener('beforeunload', unload);
      document.removeEventListener('click', click, true);
      window.removeEventListener('popstate', pop);
      document.body.style.overflow = prevOverflow;
    };
  }, []);

  // ---- question navigation and answering (timed sections: only inside the running section)
  const goTo = (i) => {
    const n = Math.min(Math.max(secRange[0], i), secRange[1]);
    if (qs[n].locked) return;
    setCurrent(n);
    setVisited((v) => (v[qs[n].id] ? v : { ...v, [qs[n].id]: true }));
    setNotice(null);
    setPaletteOpen(false);
    panelRef.current?.scrollTo({ top: 0 });
  };

  const check = async (question, optionIds) => {
    const prev = answers[question.id] || BLANK;
    setChecking(question.id);
    setAnswers((a) => ({ ...a, [question.id]: { ...prev, option_ids: optionIds } }));
    try {
      const r = await api.post('mock-tests.php', { attempt: id, question_id: question.id, option_ids: optionIds, marked: prev.marked }, { action: 'check' });
      setAnswers((a) => ({
        ...a,
        [question.id]: {
          ...(a[question.id] || BLANK),
          option_ids: (r.your_option_ids || optionIds).map(Number),
          checked: true,
          feedback: { correct: !!r.correct, correct_option_ids: r.correct_option_ids ?? null, explanation: r.explanation ?? null, explanation_hi: r.explanation_hi ?? null, marks_awarded: r.marks_awarded ?? null },
        },
      }));
    } catch (e) {
      if (e.code === 'time_up' || e.code === 'already_submitted') ended(e.code === 'time_up');
      else {
        setAnswers((a) => ({ ...a, [question.id]: prev }));
        setNotice(e.message);
      }
    } finally {
      setChecking(null);
    }
  };

  const locked = practice && (ans.checked || checking === q.id);
  const choose = (optionId) => {
    if (locked) return;
    if (q.type === 'multiple') {
      const ids = ans.option_ids.includes(optionId) ? ans.option_ids.filter((x) => x !== optionId) : [...ans.option_ids, optionId];
      persist(q.id, { ...ans, option_ids: ids });
    } else if (practice) {
      check(q, [optionId]);
    } else {
      persist(q.id, { ...ans, option_ids: [optionId] });
    }
  };
  const clear = () => { if (!locked && ans.option_ids.length) persist(q.id, { ...ans, option_ids: [] }); };
  const atSectionEnd = current >= secRange[1];
  const next = () => {
    if (queueRef.current.size) flush();
    if (!atSectionEnd) goTo(current + 1);
    else if (secTimed && !lastSection) setFinishOpen(true);
    else setConfirmOpen(true);
  };
  const markNext = () => {
    if (ans.marked) {
      persist(q.id, { ...ans, marked: false });
      return;
    }
    persist(q.id, { ...ans, marked: true });
    if (!atSectionEnd) goTo(current + 1);
  };

  // ---- keyboard shortcuts: 1-8 / A-H choose, N next, P previous, M mark
  const keys = useRef({});
  keys.current = { choose, next, prev: () => goTo(current - 1), mark: markNext, options: q.options };
  useEffect(() => {
    const onKey = (e) => {
      if (e.ctrlKey || e.metaKey || e.altKey || doneRef.current) return;
      if (confirmOpen || paletteOpen || finishOpen) {
        if (e.key === 'Escape') { setConfirmOpen(false); setPaletteOpen(false); setFinishOpen(false); }
        return;
      }
      const el = e.target;
      if (el && (el.isContentEditable || (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) && !/^(radio|checkbox)$/.test(el.type)))) return;
      const k = e.key.toLowerCase();
      const h = keys.current;
      let idx = -1;
      if (/^[1-8]$/.test(k)) idx = Number(k) - 1;
      else if (k.length === 1 && LETTERS.toLowerCase().includes(k)) idx = LETTERS.toLowerCase().indexOf(k);
      if (idx >= 0 && idx < h.options.length) { e.preventDefault(); h.choose(h.options[idx].id); }
      else if (k === 'n' || e.key === 'ArrowRight') { e.preventDefault(); h.next(); }
      else if (k === 'p' || e.key === 'ArrowLeft') { e.preventDefault(); h.prev(); }
      else if (k === 'm') { e.preventDefault(); h.mark(); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [confirmOpen, paletteOpen, finishOpen]);

  // ---- palette state (the palette and its counts follow the section on screen)
  const stateOf = (question) => {
    const a = answers[question.id];
    const has = !!a?.option_ids?.length;
    if (has && a.marked) return 'answered_marked';
    if (a?.marked) return 'marked';
    if (has) return 'answered';
    return visited[question.id] ? 'not_answered' : 'not_visited';
  };
  const tally = (list) => list.reduce((acc, x) => { const s = stateOf(x); acc[s] = (acc[s] || 0) + 1; return acc; }, {});
  const counts = tally(qs.filter(inActive));
  const allCounts = tally(qs);
  const answeredTotal = (allCounts.answered || 0) + (allCounts.answered_marked || 0);
  const secAnswered = (counts.answered || 0) + (counts.answered_marked || 0);
  const secTotal = qs.filter(inActive).length;
  const upcoming = sections && secTimed ? sections.filter((s) => s.state === 'upcoming') : [];
  const secTitle = (s) => (s ? pick(lang, s.title, s.title_hi) : '');
  const fb = ans.feedback;
  const name = user?.name || user?.first_name || user?.email || 'Candidate';
  const qText = pick(lang, q.text, q.text_hi);
  const noHindi = lang === 'hi' && !q.text_hi;

  const optionClass = (o) => {
    const on = ans.option_ids.includes(o.id);
    if (practice && ans.checked && fb) {
      const key = fb.correct_option_ids;
      const isRight = key ? key.includes(o.id) : on && fb.correct;
      if (isRight) return ['border-emerald-500 bg-emerald-50', 'bg-emerald-600 text-white', 'right', on];
      if (on) return ['border-red-500 bg-red-50', 'bg-red-600 text-white', 'wrong', on];
      return ['border-line bg-white opacity-70', 'bg-surface text-navy ring-1 ring-line', null, on];
    }
    if (on) return ['border-primary bg-primary-soft shadow-[0_8px_24px_-12px_rgba(11,99,246,.5)]', 'bg-primary text-white', null, on];
    return ['border-line bg-white hover:border-primary/50 hover:bg-primary-soft/40', 'bg-surface text-navy ring-1 ring-line group-hover:ring-primary/40', null, on];
  };

  return (
    // Full-screen exam shell pinned at top 0 (the site header is hidden in exam mode);
    // only <main> scrolls, so nothing can push the page sideways at 360px.
    <div className="fixed inset-0 z-[70] flex flex-col overflow-hidden bg-surface text-ink">
      {/* Top bar */}
      <div className="shrink-0 bg-gradient-to-r from-navy via-[#0B3A8C] to-navy text-white shadow-lg">
        <div className="flex items-center gap-2 px-3 py-2.5 sm:gap-4 sm:px-5">
          <div className="min-w-0 flex-1">
            <p className="flex items-center gap-2">
              <span className="hidden sm:inline-flex"><ModeBadge mode={practice ? 'practice' : 'exam'} className="bg-white/95" /></span>
              <span className="truncate font-display text-sm font-bold sm:text-base">{data.exam?.title}</span>
            </p>
            <p className="mt-0.5 flex min-w-0 items-center gap-1.5 text-[11px] text-white/70">
              <Icon name="user" className="size-3.5 shrink-0" />
              <span className="truncate">{name}</span>
              {data.attempt_no && <span className="hidden shrink-0 sm:inline">· Attempt {data.attempt_no}</span>}
              {/* marks_per_attempt = marks of the questions drawn for THIS attempt (random pools) */}
              {(data.exam?.marks_per_attempt ?? data.exam?.total_marks) > 0 && <span className="hidden shrink-0 sm:inline">· {fmtNum(data.exam.marks_per_attempt ?? data.exam.total_marks)} marks</span>}
            </p>
          </div>
          <SaveIndicator state={saveState} pending={queueRef.current.size} onRetry={flush} />
          {hasHindi && <LangToggle lang={lang} onChange={setLang} />}
          <Clock practice={practice} secondsLeft={secTimed && sectionLeft !== null ? sectionLeft : secondsLeft} elapsed={elapsed}
            label={secTimed ? 'Section time' : 'Time left'} total={secTimed ? secondsLeft : null} />
          <button type="button" onClick={() => setConfirmOpen(true)} className="btn-gold btn-sm shrink-0">{practice ? 'Finish' : 'Submit'}</button>
        </div>
        {sections && sections.length > 0 && (
          <SectionTabs sections={sections} timed={secTimed} active={activeSec} title={secTitle}
            onPick={(s) => { const i = qs.findIndex((x) => x.section === s.index && !x.locked); if (i >= 0) goTo(i); }} />
        )}
      </div>

      <div className="flex min-h-0 flex-1">
        <main ref={panelRef} className="min-w-0 flex-1 overflow-x-hidden overflow-y-auto" aria-labelledby="q-text">
          <div className="mx-auto max-w-4xl p-3 sm:p-6">
            {notice && <Alert type="warning" className="mb-3">{notice}</Alert>}
            <article className="card animate-fade-in rounded-2xl p-5 sm:p-7" key={q.id} lang={lang === 'hi' && q.text_hi ? 'hi' : undefined}>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex min-w-0 items-center gap-2">
                  <span className="rounded-lg bg-navy px-2.5 py-1 font-display text-sm font-extrabold text-white">Q{current + 1}</span>
                  <span className="text-sm text-muted">of {qs.length}</span>
                  {q.topic && <span className="truncate rounded-full bg-violet/10 px-2 py-0.5 text-[11px] font-semibold text-violet">{q.topic}</span>}
                </div>
                <div className="flex gap-1.5 text-xs font-bold">
                  <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700 ring-1 ring-emerald-200" title="Marks for a correct answer">+{fmtNum(q.marks)}</span>
                  <span className={'rounded-full px-2.5 py-1 ring-1 ' + (q.negative_marks > 0 ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-slate-50 text-slate-500 ring-slate-200')} title="Marks deducted for a wrong answer">−{fmtNum(q.negative_marks || 0)}</span>
                </div>
              </div>
              <p className="mt-3 text-xs font-semibold tracking-wide text-muted uppercase">
                {q.type === 'multiple' ? 'Multiple correct · select all that apply' : 'Single correct answer'}
                {noHindi && <span className="ml-2 normal-case tracking-normal text-amber-700" lang="hi">· हिंदी में उपलब्ध नहीं - English में दिखाया गया</span>}
              </p>
              <h2 id="q-text" className="mt-2 text-[17px] leading-relaxed font-semibold break-words whitespace-pre-line text-navy sm:text-lg">{qText}</h2>
              {q.image_url && (
                <figure className="mt-4">
                  <img src={q.image_url} alt={`Figure for question ${current + 1}`} loading="lazy" className="max-h-96 w-auto max-w-full rounded-xl border border-line bg-white object-contain" />
                </figure>
              )}

              <fieldset className="mt-6" disabled={locked}>
                <legend className="sr-only">Options for question {current + 1}</legend>
                <div className="space-y-3">
                  {q.options.map((o, i) => {
                    const [box, letter, mark, on] = optionClass(o);
                    return (
                      <label key={o.id} className={'group flex cursor-pointer items-start gap-3.5 rounded-2xl border-2 p-3.5 transition-all duration-200 has-[:focus-visible]:ring-4 has-[:focus-visible]:ring-primary/25 sm:p-4 ' + box + (locked ? ' cursor-default' : '')}>
                        <input type={q.type === 'multiple' ? 'checkbox' : 'radio'} name={`q-${q.id}`} checked={on} onChange={() => choose(o.id)} className="sr-only" />
                        <span className={'grid size-9 shrink-0 place-items-center rounded-xl font-display text-sm font-extrabold transition-colors ' + letter} aria-hidden="true">{LETTERS[i] || i + 1}</span>
                        <span className="min-w-0 flex-1 pt-1.5 text-[15px] leading-relaxed break-words text-ink"><span className="sr-only">Option {LETTERS[i]}: </span>{pick(lang, o.text, q.text_hi ? o.text_hi : null)}</span>
                        {mark === 'right' && <span className="flex shrink-0 items-center gap-1 pt-1.5 text-xs font-bold text-emerald-700"><Icon name="checkCircle" className="size-5" /><span className="hidden sm:inline">Correct answer</span><span className="sr-only sm:hidden">Correct answer</span></span>}
                        {mark === 'wrong' && <span className="flex shrink-0 items-center gap-1 pt-1.5 text-xs font-bold text-red-700"><Icon name="x" className="size-5" /><span className="hidden sm:inline">Your answer</span><span className="sr-only sm:hidden">Your answer, incorrect</span></span>}
                        {!mark && on && <Icon name="check" className="mt-2 size-5 shrink-0 text-primary" />}
                      </label>
                    );
                  })}
                </div>
              </fieldset>

              {practice && q.type === 'multiple' && !ans.checked && (
                <button type="button" onClick={() => check(q, ans.option_ids)} disabled={!ans.option_ids.length || checking === q.id} className="btn-primary mt-5">
                  <Icon name="checkCircle" className="size-4" />{checking === q.id ? 'Checking…' : 'Check answer'}
                </button>
              )}
              {checking === q.id && q.type !== 'multiple' && <p className="mt-4 text-sm text-muted" role="status">Checking your answer…</p>}
              {practice && ans.checked && fb && (
                <div role="status" className={'mt-5 animate-fade-up rounded-2xl border p-4 sm:p-5 ' + (fb.correct ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50')}>
                  <p className={'flex flex-wrap items-center gap-2 font-display text-lg font-bold ' + (fb.correct ? 'text-emerald-800' : 'text-red-800')}>
                    <Icon name={fb.correct ? 'checkCircle' : 'alert'} className="size-6" />
                    {fb.correct ? 'Correct!' : 'Incorrect'}
                    {/* No suffix for 0 (no negative marking): "(0 marks)" / "(-0 marks)" says nothing useful */}
                    {fb.marks_awarded !== null && fb.marks_awarded !== undefined && Number(fb.marks_awarded) !== 0 && <span className="text-sm font-semibold">({fb.marks_awarded > 0 ? '+' : ''}{fmtNum(fb.marks_awarded)} marks)</span>}
                  </p>
                  {!fb.correct && fb.correct_option_ids?.length > 0 && (
                    <p className="mt-1 text-sm text-ink">Correct answer: <strong>{q.options.map((o, i) => (fb.correct_option_ids.includes(o.id) ? LETTERS[i] : null)).filter(Boolean).join(', ')}</strong></p>
                  )}
                  {fb.explanation && (
                    <div className="mt-3 rounded-xl bg-white/70 p-3 text-sm text-ink">
                      <p className="font-bold text-navy">{lang === 'hi' && fb.explanation_hi ? 'व्याख्या' : 'Explanation'}</p>
                      <p className="mt-1 leading-relaxed break-words whitespace-pre-line">{pick(lang, fb.explanation, fb.explanation_hi)}</p>
                    </div>
                  )}
                </div>
              )}
            </article>
            <p className="mt-3 hidden text-center text-xs text-muted sm:block">Shortcuts: <kbd className={KBD}>1-4</kbd>/<kbd className={KBD}>A-D</kbd> choose · <kbd className={KBD}>N</kbd> next · <kbd className={KBD}>P</kbd> previous · <kbd className={KBD}>M</kbd> mark for review</p>
          </div>
        </main>

        <aside className="hidden w-[320px] shrink-0 overflow-y-auto border-l border-line bg-white xl:w-[340px] lg:block" aria-label="Question palette">
          <div className="flex items-center gap-3 border-b border-line p-4">
            <span className="grid size-11 shrink-0 place-items-center rounded-full bg-gradient-to-br from-primary to-violet font-display text-lg font-bold text-white">{name.charAt(0).toUpperCase()}</span>
            <div className="min-w-0"><p className="truncate font-semibold text-navy">{name}</p>
              <p className="text-xs text-muted">{activeSec !== null && sections ? <>{secTitle(sections[activeSec])}: {secAnswered} of {secTotal} answered</> : <>{answeredTotal} of {qs.length} answered</>}</p></div>
          </div>
          <Palette qs={qs} inView={inActive} stateOf={stateOf} current={current} goTo={goTo} counts={counts} filter={filter} setFilter={setFilter} />
        </aside>
      </div>

      {/* Action bar */}
      <div className="safe-bottom shrink-0 border-t border-line bg-white px-3 py-2.5 shadow-[0_-8px_24px_-16px_rgba(8,38,77,.35)] sm:px-5">
        <div className="mx-auto flex max-w-6xl flex-col gap-2 sm:flex-row sm:items-center">
          <div className="flex gap-2">
            <button type="button" onClick={markNext} className={'btn flex-1 border-2 px-3 text-sm sm:flex-none ' + (ans.marked ? 'border-violet bg-violet text-white' : 'border-violet/40 bg-white text-violet hover:bg-violet/5')}>
              <Icon name="tag" className="size-4" />
              {ans.marked ? 'Unmark review' : <><span className="sm:hidden">Mark &amp; Next</span><span className="hidden sm:inline">Mark for Review &amp; Next</span></>}
            </button>
            <button type="button" onClick={clear} disabled={locked || !ans.option_ids.length} className="btn flex-1 border-2 border-line bg-white px-3 text-sm text-ink hover:border-red-300 hover:text-red-700 disabled:opacity-40 sm:flex-none">
              <Icon name="x" className="size-4" />Clear<span className="hidden sm:inline"> Response</span>
            </button>
          </div>
          <div className="flex gap-2 sm:ml-auto">
            <button type="button" onClick={() => goTo(current - 1)} disabled={current <= secRange[0]} className="btn border-2 border-line bg-white px-3 text-sm text-ink disabled:opacity-40" aria-label="Previous question">
              <Icon name="chevronLeft" className="size-4" /><span className="hidden sm:inline">Previous</span>
            </button>
            <button type="button" onClick={() => setPaletteOpen(true)} className="btn border-2 border-line bg-white px-3 text-sm text-navy lg:hidden" aria-haspopup="dialog" aria-expanded={paletteOpen}>
              <Icon name="grid" className="size-4" />{activeSec !== null && sections ? `${secAnswered}/${secTotal}` : `${answeredTotal}/${qs.length}`}<span className="sr-only"> answered - open question palette</span>
            </button>
            <button type="button" onClick={next} className="btn-primary flex-1 px-4 text-sm sm:flex-none">
              {!atSectionEnd ? <>Save &amp; Next<Icon name="chevronRight" className="size-4" /></>
                : secTimed && !lastSection ? <>Save &amp; Finish section<Icon name="chevronRight" className="size-4" /></>
                  : <>Save &amp; Finish<Icon name="checkCircle" className="size-4" /></>}
            </button>
          </div>
        </div>
      </div>

      {paletteOpen && (
        <Sheet onClose={() => setPaletteOpen(false)} title={activeSec !== null && sections ? `Question palette · ${secTitle(sections[activeSec])}` : 'Question palette'}>
          <Palette qs={qs} inView={inActive} stateOf={stateOf} current={current} goTo={goTo} counts={counts} filter={filter} setFilter={setFilter} />
        </Sheet>
      )}

      {finishOpen && sections && runSec !== null && (
        <FinishSectionDialog section={secTitle(sections[runSec])} nextSection={secTitle(sections[runSec + 1])} answered={secAnswered} total={secTotal}
          sectionLeft={sectionLeft} busy={moving} onCancel={() => setFinishOpen(false)} onConfirm={finishSection} />
      )}

      {confirmOpen && (
        <SubmitDialog
          counts={allCounts} total={qs.length} practice={practice} submitting={submitting} error={submitError} secondsLeft={secondsLeft}
          upcoming={upcoming.map(secTitle)}
          onCancel={() => { setConfirmOpen(false); setSubmitError(null); }}
          onSubmit={() => submit('manual', submitError === 'unsaved')}
          onRetrySave={flush}
        />
      )}

      {timeUp && (
        <div className="fixed inset-0 z-[90] grid place-items-center bg-navy/70 p-4 backdrop-blur-sm" role="alertdialog" aria-modal="true" aria-labelledby="timeup-h">
          <div className="card animate-scale-in max-w-sm rounded-2xl p-7 text-center">
            <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-red-50 text-red-600"><Icon name="clock" className="size-7" /></span>
            <h2 id="timeup-h" className="mt-4 font-display text-xl font-bold text-navy">Time is up</h2>
            <p className="mt-1 text-sm text-muted">Saving your answers and submitting the test…</p>
            <Spinner label="Submitting" className="py-6" />
          </div>
        </div>
      )}
    </div>
  );
}

function Clock({ practice, secondsLeft, elapsed, label = 'Time left', total = null }) {
  if (practice) {
    return (
      <div className="shrink-0 rounded-xl bg-white/10 px-2.5 py-1.5 text-center ring-1 ring-white/15 sm:px-3">
        <span className="block text-[10px] font-semibold tracking-wide text-white/70 uppercase">Time spent</span>
        <span className="font-mono text-base font-bold tabular-nums sm:text-lg">{fmtClock(elapsed)}</span>
      </div>
    );
  }
  const tone = secondsLeft < 60 ? 'bg-red-600 ring-red-300 motion-safe:animate-pulse' : secondsLeft < 300 ? 'bg-amber-400 text-navy ring-amber-200' : 'bg-white/10 ring-white/15';
  const what = total !== null ? 'in this section' : '';
  const warning = secondsLeft <= 60 ? `Less than one minute left ${what}.` : secondsLeft <= 300 ? `Less than five minutes left ${what}.` : '';
  const sub = secondsLeft < 300 && secondsLeft >= 60 ? 'text-navy/70' : 'text-white/70';
  return (
    <div className={'shrink-0 rounded-xl px-2.5 py-1.5 text-center ring-1 transition-colors sm:px-3 ' + tone}>
      <span className={'block text-[10px] font-semibold tracking-wide uppercase ' + sub}>{label}</span>
      <span role="timer" aria-label={label} className="font-mono text-base font-bold tabular-nums sm:text-lg">{fmtClock(secondsLeft)}</span>
      {total !== null && <span className={'block font-mono text-[10px] tabular-nums ' + sub}>Total {fmtClock(total)}</span>}
      <span className="sr-only" aria-live="assertive">{warning}</span>
    </div>
  );
}

/** Section tabs. Timed: finished sections are closed, later ones locked, only the running one is open. */
function SectionTabs({ sections, timed, active, title, onPick }) {
  return (
    <nav className="scrollbar-none flex gap-1.5 overflow-x-auto border-t border-white/10 px-3 py-2 sm:px-5" aria-label="Test sections">
      {sections.map((s) => {
        const isActive = s.index === active;
        const closed = timed && s.state === 'done';
        const locked = timed && s.state === 'upcoming';
        return (
          <button key={s.index} type="button" onClick={() => onPick(s)} disabled={closed || locked || (timed && isActive)} aria-current={isActive ? 'step' : undefined}
            className={'inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold transition-colors '
              + (isActive ? 'bg-white text-navy shadow' : closed ? 'bg-white/5 text-white/45' : locked ? 'bg-white/5 text-white/60' : 'bg-white/10 text-white hover:bg-white/20')}>
            {closed && <Icon name="checkCircle" className="size-3.5" />}
            {locked && <Icon name="lock" className="size-3.5" />}
            <span>{title(s)}</span>
            <span className={'rounded-full px-1.5 text-[10px] ' + (isActive ? 'bg-navy/10' : 'bg-white/10')}>{s.count}</span>
            {timed && s.seconds && !closed && <span className="font-normal opacity-75">{Math.round(s.seconds / 60)}m</span>}
            <span className="sr-only">{closed ? '(finished)' : locked ? '(opens later)' : isActive ? '(current section)' : ''}</span>
          </button>
        );
      })}
    </nav>
  );
}

function FinishSectionDialog({ section, nextSection, answered, total, sectionLeft, busy, onCancel, onConfirm }) {
  const cancelRef = useRef(null);
  useEffect(() => { cancelRef.current?.focus(); }, []);
  return (
    <div className="fixed inset-0 z-[85] grid place-items-end bg-navy/60 p-0 backdrop-blur-sm sm:place-items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="finish-h">
      <div className="card safe-bottom w-full max-w-md animate-slide-up rounded-t-3xl rounded-b-none p-6 sm:animate-scale-in sm:rounded-2xl">
        <h2 id="finish-h" className="font-display text-xl font-bold text-navy">Finish “{section}”?</h2>
        <p className="mt-1 text-sm text-muted">
          You answered <strong className="text-navy">{answered} of {total}</strong> questions in this section{sectionLeft !== null && <> and have <strong className="font-mono text-navy">{fmtClock(sectionLeft)}</strong> left</>}.
          Once you move on, this section closes and cannot be opened again.
        </p>
        {nextSection && <p className="mt-3 flex items-center gap-2 rounded-xl bg-primary-soft p-3 text-sm text-navy"><Icon name="chevronRight" className="size-4 shrink-0" />Next: <strong>{nextSection}</strong> starts with its own timer.</p>}
        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <button ref={cancelRef} type="button" onClick={onCancel} disabled={busy} className="btn-light">Stay in this section</button>
          <button type="button" onClick={onConfirm} disabled={busy} className="btn-primary">{busy ? 'Moving on…' : 'Finish section & continue'}</button>
        </div>
      </div>
    </div>
  );
}

function SaveIndicator({ state, pending, onRetry }) {
  if (state === 'error' || state === 'offline') {
    return (
      <button type="button" onClick={onRetry} className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-red-500/90 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-red-500" title="Some answers are not saved yet">
        <Icon name="refresh" className="size-3.5" /><span className="hidden sm:inline">{state === 'offline' ? 'Offline · ' : 'Unsaved · '}</span>Retry
        <span className="sr-only">{pending} unsaved answer(s)</span>
      </button>
    );
  }
  return (
    <span className="hidden shrink-0 items-center gap-1.5 text-[11px] font-semibold text-white/75 sm:inline-flex" aria-live="polite">
      {state === 'saving' ? <><span className="size-3 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true" />Saving…</>
        : state === 'saved' ? <><Icon name="checkCircle" className="size-3.5 text-emerald-300" />All answers saved</>
          : <><Icon name="shield" className="size-3.5" />Autosave on</>}
    </span>
  );
}

const FILTERS = [['all', 'All'], ['unanswered', 'Not answered'], ['marked', 'Marked']];

function Palette({ qs, inView = () => true, stateOf, current, goTo, counts, filter, setFilter }) {
  const show = (s) => filter === 'all' || (filter === 'unanswered' ? s === 'not_answered' || s === 'not_visited' || s === 'marked' : s === 'marked' || s === 'answered_marked');
  return (
    <div className="p-4">
      <ul className="grid grid-cols-2 gap-x-3 gap-y-2 text-xs text-ink">
        {STATE_ORDER.map((k) => (
          <li key={k} className={'flex items-center gap-2 ' + (k === 'answered_marked' ? 'col-span-2' : '')}>
            <PaletteChip state={k} small>{counts[k] || 0}</PaletteChip>
            <span>{Q_STATES[k].label}</span>
          </li>
        ))}
      </ul>
      <p className="mt-2 text-[11px] text-muted">Answered &amp; marked questions are evaluated.</p>
      <div className="mt-4 flex gap-1 rounded-xl bg-surface p-1" role="group" aria-label="Filter questions">
        {FILTERS.map(([k, l]) => (
          <button key={k} type="button" onClick={() => setFilter(k)} aria-pressed={filter === k} className={'flex-1 rounded-lg px-2 py-1.5 text-xs font-semibold transition-colors ' + (filter === k ? 'bg-white text-navy shadow-sm' : 'text-muted hover:text-navy')}>{l}</button>
        ))}
      </div>
      <ol className="mt-4 grid grid-cols-5 gap-2.5 sm:grid-cols-8 lg:grid-cols-5">
        {qs.map((x, i) => {
          const s = stateOf(x);
          if (!inView(x) || x.locked || !show(s)) return null;
          return (
            <li key={x.id}>
              <button type="button" onClick={() => goTo(i)} aria-current={i === current ? 'step' : undefined} aria-label={`Question ${i + 1}, ${Q_STATES[s].label}`}
                className={'relative grid h-11 w-full place-items-center rounded-xl border-2 text-sm font-bold transition-transform hover:-translate-y-0.5 ' + Q_STATES[s].cls + (i === current ? ' ring-2 ring-primary ring-offset-2' : '')}>
                {i + 1}
                {Q_STATES[s].dot && <span className="absolute -right-1 -bottom-1 size-3.5 rounded-full border-2 border-white bg-emerald-500" aria-hidden="true" />}
              </button>
            </li>
          );
        })}
      </ol>
    </div>
  );
}

function PaletteChip({ state, children }) {
  const s = Q_STATES[state];
  return (
    <span className={'relative grid h-7 min-w-7 shrink-0 place-items-center rounded-lg border-2 px-1 text-[11px] font-bold ' + s.cls}>
      {children}
      {s.dot && <span className="absolute -right-1 -bottom-1 size-2.5 rounded-full border-2 border-white bg-emerald-500" aria-hidden="true" />}
    </span>
  );
}

/** Mobile bottom sheet. */
function Sheet({ title, onClose, children }) {
  const closeRef = useRef(null);
  useEffect(() => { closeRef.current?.focus(); }, []);
  return (
    <div className="fixed inset-0 z-[80] lg:hidden" role="dialog" aria-modal="true" aria-label={title}>
      <button type="button" className="absolute inset-0 bg-navy/50 backdrop-blur-[2px]" onClick={onClose} aria-label="Close palette" tabIndex={-1} />
      <div className="safe-bottom absolute inset-x-0 bottom-0 max-h-[82dvh] animate-slide-up overflow-y-auto rounded-t-3xl bg-white shadow-2xl">
        <div className="sticky top-0 z-10 flex items-center justify-between border-b border-line bg-white px-4 py-3">
          <span aria-hidden="true" className="absolute top-1.5 left-1/2 h-1 w-10 -translate-x-1/2 rounded-full bg-line" />
          <h2 className="font-display font-bold text-navy">{title}</h2>
          <button ref={closeRef} type="button" onClick={onClose} className="grid size-9 place-items-center rounded-full hover:bg-surface" aria-label="Close"><Icon name="x" className="size-5" /></button>
        </div>
        {children}
      </div>
    </div>
  );
}

function SubmitDialog({ counts, total, practice, submitting, error, secondsLeft, upcoming = [], onCancel, onSubmit, onRetrySave }) {
  const cancelRef = useRef(null);
  useEffect(() => { cancelRef.current?.focus(); }, []);
  const rows = [
    ['answered', counts.answered || 0],
    ['answered_marked', counts.answered_marked || 0],
    ['marked', counts.marked || 0],
    ['not_answered', counts.not_answered || 0],
    ['not_visited', counts.not_visited || 0],
  ];
  const unattempted = (counts.marked || 0) + (counts.not_answered || 0) + (counts.not_visited || 0);
  return (
    <div className="fixed inset-0 z-[85] grid place-items-end bg-navy/60 p-0 backdrop-blur-sm sm:place-items-center sm:p-4" role="dialog" aria-modal="true" aria-labelledby="submit-h">
      <div className="card safe-bottom w-full max-w-lg animate-slide-up rounded-t-3xl rounded-b-none p-6 sm:animate-scale-in sm:rounded-2xl">
        <h2 id="submit-h" className="font-display text-xl font-bold text-navy">{practice ? 'Finish this practice quiz?' : 'Submit your test?'}</h2>
        <p className="mt-1 text-sm text-muted">
          {practice ? 'Questions with no answer score 0. Answers you selected but did not check are still scored.' : <>You cannot change answers after submitting.{secondsLeft !== null && <> Time left: <strong className="font-mono text-navy">{fmtClock(secondsLeft)}</strong>.</>}</>}
        </p>
        <table className="mt-5 w-full overflow-hidden rounded-xl text-sm ring-1 ring-line">
          <caption className="sr-only">Summary of your responses</caption>
          <thead className="bg-surface text-left text-xs text-muted uppercase"><tr><th scope="col" className="px-4 py-2">Status</th><th scope="col" className="px-4 py-2 text-right">Questions</th></tr></thead>
          <tbody className="divide-y divide-line">
            {rows.map(([k, n]) => (
              <tr key={k}>
                <th scope="row" className="px-4 py-2.5 text-left font-medium"><span className="flex items-center gap-2"><PaletteChip state={k}>{''}</PaletteChip>{Q_STATES[k].label}{k === 'answered_marked' && <span className="text-[11px] text-muted">(evaluated)</span>}</span></th>
                <td className="px-4 py-2.5 text-right font-bold text-navy tabular-nums">{n}</td>
              </tr>
            ))}
            <tr className="bg-surface"><th scope="row" className="px-4 py-2.5 text-left font-bold">Total</th><td className="px-4 py-2.5 text-right font-extrabold text-navy">{total}</td></tr>
          </tbody>
        </table>
        {unattempted > 0 && !practice && <p className="mt-3 flex items-start gap-2 text-xs text-amber-800"><Icon name="alert" className="size-4 shrink-0" />{unattempted} question{unattempted === 1 ? ' is' : 's are'} not answered yet.</p>}
        {upcoming.length > 0 && <p className="mt-2 flex items-start gap-2 text-xs text-red-700"><Icon name="lock" className="size-4 shrink-0" />Still to come: {upcoming.join(', ')}. Submitting now ends the whole test, including {upcoming.length === 1 ? 'this section' : 'these sections'}.</p>}
        {error === 'unsaved' ? (
          <Alert type="warning" className="mt-4">
            Some answers could not be saved - check your internet connection.
            <button type="button" onClick={onRetrySave} className="ml-1 font-bold underline">Retry saving</button> or submit anyway (unsaved changes will be lost).
          </Alert>
        ) : error && <Alert className="mt-4">{error}</Alert>}
        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
          <button ref={cancelRef} type="button" onClick={onCancel} disabled={submitting} className="btn-light">Back to {practice ? 'quiz' : 'test'}</button>
          <button type="button" onClick={onSubmit} disabled={submitting} className="btn-primary">
            {submitting ? 'Submitting…' : error === 'unsaved' ? 'Submit anyway' : practice ? 'Finish & see score' : 'Submit test'}
          </button>
        </div>
      </div>
    </div>
  );
}
