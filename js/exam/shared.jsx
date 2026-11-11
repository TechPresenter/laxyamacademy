// Shared bits for the mock test / practice quiz pages: badges, number formatting,
// colour tones and the animated score ring.
import { useEffect, useState } from 'react';
import { Icon } from '../components.jsx';

/** "12.5" / "10" - trims trailing decimals, Indian grouping. */
export const fmtNum = (n) => (n === null || n === undefined || Number.isNaN(Number(n)) ? '-' : Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 }));

/** Bilingual tests: the chosen language is remembered on this device only (both versions come from the server). */
export const LANG_KEY = 'lx-exam-lang';

/** The Hindi version when Hindi is chosen and the question has one, else the English text. */
export const pick = (lang, en, hi) => (lang === 'hi' && hi ? hi : en);

/** English / हिंदी switch. tone 'dark' sits on the navy exam bar, 'light' on white cards. */
export function LangToggle({ lang, onChange, tone = 'dark', className = '' }) {
  const wrap = tone === 'dark' ? 'bg-white/10 ring-white/20' : 'bg-surface ring-line';
  const on = tone === 'dark' ? 'bg-white text-navy shadow-sm' : 'bg-white text-navy shadow-sm ring-1 ring-line';
  const off = tone === 'dark' ? 'text-white/80 hover:text-white' : 'text-muted hover:text-navy';
  return (
    <div className={'inline-flex shrink-0 rounded-lg p-0.5 ring-1 ' + wrap + ' ' + className} role="group" aria-label="Question language / प्रश्न की भाषा">
      {[['en', 'EN', 'English'], ['hi', 'हिंदी', 'हिंदी']].map(([k, short, full]) => (
        <button key={k} type="button" onClick={() => onChange(k)} aria-pressed={lang === k} lang={k} translate="no"
          className={'rounded-md px-2 py-1 text-xs font-bold transition-colors ' + (lang === k ? on : off)}>
          <span className="sm:hidden">{short}</span><span className="hidden sm:inline">{full}</span>
        </button>
      ))}
    </div>
  );
}

/**
 * Marks one attempt of a test carries: the API's marks_per_attempt (random-pool aware),
 * falling back to the configured total_marks. null when it differs between attempts
 * (marks_vary: random pool with mixed question marks).
 */
export const attemptMarks = (t) => (t?.marks_vary ? null : (t?.marks_per_attempt ?? t?.total_marks ?? null));

/** Seconds → "1h 05m", "12m 05s", "45s". */
export function fmtDuration(seconds) {
  const s = Math.max(0, Math.round(Number(seconds) || 0));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const r = s % 60;
  if (h) return `${h}h ${String(m).padStart(2, '0')}m`;
  if (m) return `${m}m ${String(r).padStart(2, '0')}s`;
  return `${r}s`;
}

/** Seconds → "05:09" or "1:05:09" for the exam clock. */
export function fmtClock(seconds) {
  const s = Math.max(0, Math.floor(Number(seconds) || 0));
  const h = Math.floor(s / 3600);
  const mm = String(Math.floor((s % 3600) / 60)).padStart(2, '0');
  const ss = String(s % 60).padStart(2, '0');
  return h ? `${h}:${mm}:${ss}` : `${mm}:${ss}`;
}

export const MODE = {
  exam: { label: 'Mock Test', icon: 'clipboard', cls: 'bg-primary-soft text-primary ring-primary/15' },
  practice: { label: 'Practice Quiz', icon: 'target', cls: 'bg-teal/10 text-teal ring-teal/20' },
};

export function ModeBadge({ mode, className = '' }) {
  const m = MODE[mode] || MODE.exam;
  return (
    <span className={'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-bold ring-1 ' + m.cls + ' ' + className}>
      <Icon name={m.icon} className="size-3.5" />{m.label}
    </span>
  );
}

export const WINDOW = {
  open: ['Open now', 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'bg-emerald-500'],
  upcoming: ['Upcoming', 'bg-amber-50 text-amber-800 ring-amber-200', 'bg-amber-500'],
  closed: ['Closed', 'bg-slate-100 text-slate-600 ring-slate-200', 'bg-slate-400'],
};

export function WindowBadge({ window: w, className = '' }) {
  const [label, cls, dot] = WINDOW[w] || WINDOW.open;
  return (
    <span className={'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-bold ring-1 ' + cls + ' ' + className}>
      <span className={'size-1.5 rounded-full ' + dot} aria-hidden="true" />{label}
    </span>
  );
}

// The API treats "free" like "registered" for attempts (a student login is always needed).
export const ACCESS = { free: 'Free · log in to attempt', registered: 'Free for registered students', enrolled: 'Enrolled students only' };

export const ATTEMPT_STATUS = {
  in_progress: ['In progress', 'bg-amber-50 text-amber-800 ring-amber-200'],
  submitted: ['Submitted', 'bg-emerald-50 text-emerald-700 ring-emerald-200'],
  auto_submitted: ['Auto-submitted (time up)', 'bg-blue-50 text-blue-700 ring-blue-200'],
  expired: ['Time over', 'bg-slate-100 text-slate-600 ring-slate-200'],
};

/** Colour tone for a percentage: pass/fail wins when the test has pass marks. */
export function toneFor(pct, passed = null) {
  if (passed === true) return 'green';
  if (passed === false) return 'red';
  if (pct === null || pct === undefined) return 'slate';
  if (pct >= 70) return 'green';
  if (pct >= 40) return 'amber';
  return 'red';
}

export const TONE = {
  green: { text: 'text-emerald-700', bg: 'bg-emerald-50', ring: 'ring-emerald-200', bar: 'bg-emerald-500', border: 'border-l-emerald-500', stroke: '#10b981' },
  amber: { text: 'text-amber-700', bg: 'bg-amber-50', ring: 'ring-amber-200', bar: 'bg-amber-500', border: 'border-l-amber-500', stroke: '#f59e0b' },
  red: { text: 'text-red-700', bg: 'bg-red-50', ring: 'ring-red-200', bar: 'bg-red-500', border: 'border-l-red-500', stroke: '#ef4444' },
  slate: { text: 'text-slate-600', bg: 'bg-slate-50', ring: 'ring-slate-200', bar: 'bg-slate-400', border: 'border-l-slate-300', stroke: '#94a3b8' },
};

const reducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/**
 * Animated SVG progress ring. value/max drive the arc; children render in the middle.
 * The arc animates from 0 once mounted (skipped with prefers-reduced-motion).
 */
export function ScoreRing({ value = 0, max = 100, size = 168, stroke = 14, color = '#0B63F6', track = 'rgba(255,255,255,.14)', label, children }) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const ratio = max > 0 ? Math.min(1, Math.max(0, value / max)) : 0;
  const [shown, setShown] = useState(reducedMotion() ? ratio : 0);
  useEffect(() => {
    const t = setTimeout(() => setShown(ratio), 60);
    return () => clearTimeout(t);
  }, [ratio]);
  return (
    <div className="relative shrink-0" style={{ width: size, height: size }} role="img" aria-label={label}>
      <svg viewBox={`0 0 ${size} ${size}`} className="size-full -rotate-90" aria-hidden="true">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={track} strokeWidth={stroke} />
        <circle
          cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color} strokeWidth={stroke} strokeLinecap="round"
          strokeDasharray={c} strokeDashoffset={c * (1 - shown)}
          className="motion-safe:transition-[stroke-dashoffset] motion-safe:duration-[1400ms] motion-safe:ease-out"
        />
      </svg>
      <div className="absolute inset-0 grid place-items-center text-center">{children}</div>
    </div>
  );
}

/** Small icon + value + label tile. */
export function StatTile({ icon, label, value, tone = 'text-navy', iconCls = 'bg-primary-soft text-primary', sub }) {
  return (
    <li className="card flex items-center gap-3 rounded-2xl p-4">
      <span className={'grid size-11 shrink-0 place-items-center rounded-xl ' + iconCls}><Icon name={icon} className="size-5" /></span>
      <span className="min-w-0">
        <span className={'block font-display text-xl leading-tight font-extrabold ' + tone}>{value}</span>
        <span className="block text-xs font-medium text-muted">{label}</span>
        {sub && <span className="block text-[11px] text-muted">{sub}</span>}
      </span>
    </li>
  );
}

/** localStorage helpers that never throw (private mode, blocked storage). */
export const store = {
  get(key, fallback = null) {
    try {
      const v = window.localStorage.getItem(key);
      return v === null ? fallback : JSON.parse(v);
    } catch {
      return fallback;
    }
  },
  set(key, value) {
    try { window.localStorage.setItem(key, JSON.stringify(value)); } catch { /* ignore */ }
  },
  remove(key) {
    try { window.localStorage.removeItem(key); } catch { /* ignore */ }
  },
};
