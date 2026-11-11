// Shared helpers for the content pages (jobs, careers, resources, live classes, blog, policies).
import { useEffect, useState } from 'react';
import { formatDate } from '../api.js'; // IST date for ISO datetimes
import { Icon } from '../components.jsx';

const IST = 'Asia/Kolkata';
const YMD = /^(\d{4})-(\d{2})-(\d{2})$/;

/** Today's calendar date in India as 'YYYY-MM-DD'. */
export function todayIst() {
  return new Intl.DateTimeFormat('en-CA', { timeZone: IST, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/**
 * Formats a calendar date ('YYYY-MM-DD') without any timezone shift, so "2026-11-05" is always
 * "5 Nov 2026" whatever the viewer's timezone. Full ISO datetimes are shown in IST; any other
 * text (e.g. "To be announced") is returned unchanged.
 */
export function formatDay(value, opts = { day: 'numeric', month: 'short', year: 'numeric' }) {
  if (!value) return '';
  const m = YMD.exec(String(value).trim());
  if (m) return new Intl.DateTimeFormat('en-IN', { ...opts, timeZone: 'UTC' }).format(new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])));
  if (!/^\d{4}-\d{2}-\d{2}[T ]\d/.test(String(value))) return String(value);
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? String(value) : new Intl.DateTimeFormat('en-IN', { ...opts, timeZone: IST }).format(d);
}

/** Whole days from today (IST) until a 'YYYY-MM-DD' date; negative when past, null when unknown. */
export function daysUntil(ymd) {
  const m = YMD.exec(String(ymd || ''));
  const t = YMD.exec(todayIst());
  if (!m || !t) return null;
  return Math.round((Date.UTC(+m[1], +m[2] - 1, +m[3]) - Date.UTC(+t[1], +t[2] - 1, +t[3])) / 86400000);
}

/** "Today", "Yesterday", "3 days ago" or a date for older ISO datetimes. */
export function postedLabel(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  const day = new Intl.DateTimeFormat('en-CA', { timeZone: IST, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
  const diff = -daysUntil(day);
  if (diff <= 0) return 'Today';
  if (diff === 1) return 'Yesterday';
  if (diff < 7) return `${diff} days ago`;
  return formatDate(iso);
}

/** Re-renders every `ms` (default 30s) and returns the current timestamp. */
export function useNow(ms = 30000) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), ms);
    return () => clearInterval(id);
  }, [ms]);
  return now;
}

/** "2d 4h", "3h 20m", "12m" - a compact countdown for a duration in ms. */
export function countdownText(ms) {
  if (ms <= 0) return 'now';
  const mins = Math.ceil(ms / 60000);
  const d = Math.floor(mins / 1440);
  const h = Math.floor((mins % 1440) / 60);
  const m = mins % 60;
  if (d > 0) return `${d}d ${h}h`;
  if (h > 0) return `${h}h ${m}m`;
  return `${m}m`;
}

// ------------------------------------------------------------------
// Govt job alert types (colour-coded everywhere)
// ------------------------------------------------------------------
export const JOB_TYPES = [
  { key: 'notification', label: 'Recruitment', plural: 'Recruitment', icon: 'megaphone', chip: 'bg-blue-50 text-blue-700 ring-blue-200', on: 'bg-primary text-white ring-primary', dot: 'bg-primary' },
  { key: 'admit_card', label: 'Admit Card', plural: 'Admit Cards', icon: 'ticket', chip: 'bg-violet-50 text-violet-700 ring-violet-200', on: 'bg-violet text-white ring-violet', dot: 'bg-violet' },
  { key: 'result', label: 'Result', plural: 'Results', icon: 'trophy', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200', on: 'bg-emerald-600 text-white ring-emerald-600', dot: 'bg-emerald-500' },
  { key: 'answer_key', label: 'Answer Key', plural: 'Answer Keys', icon: 'checkCircle', chip: 'bg-amber-50 text-amber-800 ring-amber-200', on: 'bg-amber-500 text-white ring-amber-500', dot: 'bg-amber-500' },
  { key: 'syllabus', label: 'Syllabus', plural: 'Syllabus', icon: 'book', chip: 'bg-teal-50 text-teal-700 ring-teal-200', on: 'bg-teal text-white ring-teal', dot: 'bg-teal' },
];
export const JOB_TYPE = Object.fromEntries(JOB_TYPES.map((t) => [t.key, t]));

export function JobTypeBadge({ type, label, className = '' }) {
  const t = JOB_TYPE[type] || JOB_TYPES[0];
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-bold ring-1 ring-inset ${t.chip} ${className}`}>
      <Icon name={t.icon} className="size-3.5" />{label || t.label}
    </span>
  );
}

export function NewBadge() {
  return <span className="inline-flex items-center rounded-full bg-gradient-to-r from-coral to-[#ff7a45] px-2 py-0.5 text-[10px] font-extrabold tracking-wider text-white uppercase shadow-sm">New</span>;
}

/** Days before the last date when an alert counts as "closing soon" (JOB_ALERT_CLOSING_DAYS in includes/recruitment.php). */
export const JOB_CLOSING_DAYS = 7;

/**
 * Red "3 days left" pill while an alert is closing soon, grey "Closed" once the last date has passed.
 * Uses the API's closed / closing_soon / days_left when given (jobs.php), else works it out from lastDate.
 */
export function DeadlineChip({ lastDate, closed, closingSoon, daysLeft }) {
  const days = Number.isFinite(daysLeft) ? daysLeft : daysUntil(lastDate);
  if (closed || (days !== null && days < 0)) return <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-600">Closed</span>;
  if (days === null) return null;
  if (!(closingSoon ?? days <= JOB_CLOSING_DAYS)) return null;
  const text = days === 0 ? 'Closes today' : days === 1 ? '1 day left' : `${days} days left`;
  return (
    <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-0.5 text-[11px] font-bold text-red-700 ring-1 ring-red-200 ring-inset">
      <Icon name="clock" className="size-3.5" />{text}
    </span>
  );
}

// ------------------------------------------------------------------
// Resource file types
// ------------------------------------------------------------------
const FILE_TYPES = {
  pdf: ['file', '#DC2626', 'PDF'],
  doc: ['file', '#2563EB', 'DOC'],
  docx: ['file', '#2563EB', 'DOCX'],
  ppt: ['presentation', '#EA580C', 'PPT'],
  pptx: ['presentation', '#EA580C', 'PPTX'],
  xls: ['grid', '#16A34A', 'XLS'],
  xlsx: ['grid', '#16A34A', 'XLSX'],
  zip: ['layers', '#7C3AED', 'ZIP'],
  jpg: ['camera', '#0EA5A4', 'Image'],
  jpeg: ['camera', '#0EA5A4', 'Image'],
  png: ['camera', '#0EA5A4', 'Image'],
  webp: ['camera', '#0EA5A4', 'Image'],
  mp4: ['video', '#DB2777', 'Video'],
  video: ['video', '#DB2777', 'Video'],
  link: ['globe', '#0B63F6', 'Link'],
};

export function fileTypeMeta(type) {
  const [icon, color, label] = FILE_TYPES[String(type || '').toLowerCase()] || ['file', '#64748B', (type || 'File').toUpperCase()];
  return { icon, color, label };
}

export function FileTypeIcon({ type, className = 'size-11' }) {
  const { icon, color, label } = fileTypeMeta(type);
  return (
    <span className={`relative grid shrink-0 place-items-center rounded-xl ${className}`} style={{ backgroundColor: color + '14', color }} title={label}>
      <Icon name={icon} className="size-5" />
      <span className="absolute -right-1 -bottom-1 rounded px-1 text-[8px] leading-tight font-extrabold text-white" style={{ backgroundColor: color }}>{label.slice(0, 4)}</span>
    </span>
  );
}
