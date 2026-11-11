// Website chat assistant: floating launcher (bottom-left), chat panel with quick replies,
// follow-up suggestions and an inline "call me back" form. Answers come from api/chat.php
// (Claude when configured in admin → Chatbot, built-in answers otherwise).
import { useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { api } from './api.js';
import { Icon, MicButton, useSite } from './components.jsx';

const STORE = 'lx-chat'; // { id } of this browser's conversation (localStorage)
const TEASER_KEY = 'lx-chat-teaser'; // teaser bubble dismissed this visit (sessionStorage)
const MAX = 500;

const QUICK = [
  ['Courses & fees', 'Which courses do you offer and what are the fees?'],
  ['Mock tests', 'Tell me about your mock tests'],
  ['Live classes', 'When are the live classes?'],
  ['How to enrol', 'How do I take admission?'],
  ['Free study material', 'Do you have free study material?'],
  ['Address & timings', 'Where is your institute and what are the timings?'],
];

const readStore = () => {
  try { return JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch { return {}; }
};
const writeStore = (v) => {
  try { v ? localStorage.setItem(STORE, JSON.stringify(v)) : localStorage.removeItem(STORE); } catch { /* storage unavailable */ }
};
let seq = 0;
const uid = () => `m${Date.now()}-${++seq}`;
const timeOf = (iso) => new Intl.DateTimeFormat('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Asia/Kolkata' }).format(iso ? new Date(iso) : new Date()).toUpperCase();
const waLink = (number) => `https://wa.me/${number}?text=${encodeURIComponent('Hello Laxyam Academy, I would like to know more about your courses.')}`;

/** Illustrated counsellor with a headset, in Laxyam colours. */
export function AssistantAvatar({ className = 'size-12' }) {
  return (
    <svg viewBox="0 0 64 64" className={className} aria-hidden="true">
      <defs>
        <linearGradient id="lxa-bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stopColor="#EEF4FF" /><stop offset="1" stopColor="#C7DAFF" /></linearGradient>
        <clipPath id="lxa-clip"><circle cx="32" cy="32" r="32" /></clipPath>
      </defs>
      <g clipPath="url(#lxa-clip)">
        <rect width="64" height="64" fill="url(#lxa-bg)" />
        <circle cx="52" cy="12" r="7" fill="#FFC928" opacity=".35" />
        <circle cx="32" cy="12.5" r="6" fill="#241712" />
        <path d="M17 38c-2-13 4-22.5 15-22.5S49 25 47 38c-.6 4-2.5 8-2.5 8h-25S17.6 42 17 38z" fill="#241712" />
        <path d="M5 64c2.5-10.5 12.5-16 27-16s24.5 5.5 27 16z" fill="#08264D" />
        <path d="M24.5 48.5 32 57l7.5-8.5c-2.4-.9-4.9-1.3-7.5-1.3s-5.1.4-7.5 1.3z" fill="#fff" />
        <path d="M30.6 51.5h2.8l.9 5.5-2.3 2.2-2.3-2.2z" fill="#0B63F6" />
        <path d="M27.6 40h8.8v6.8a4.4 4.4 0 0 1-8.8 0z" fill="#D49470" />
        <ellipse cx="32" cy="31.5" rx="10.4" ry="11.8" fill="#EDB993" />
        <path d="M21.4 30.2c.4-8 5-12.6 10.8-12.6 6.3 0 10.6 4.4 10.5 11.6-5-1.2-9.4-3.9-11.3-7.4-1.6 4.3-5.4 7.4-10 8.4z" fill="#241712" />
        <circle cx="28.1" cy="32.6" r="1.35" fill="#1E293B" />
        <circle cx="35.9" cy="32.6" r="1.35" fill="#1E293B" />
        <path d="M28.7 37c1.9 1.7 4.7 1.7 6.6 0" stroke="#9B3B1E" strokeWidth="1.5" fill="none" strokeLinecap="round" />
        <circle cx="25.6" cy="35.6" r="1.6" fill="#F4845F" opacity=".25" />
        <circle cx="38.4" cy="35.6" r="1.6" fill="#F4845F" opacity=".25" />
        <path d="M19.8 31.5a12.2 12.2 0 0 1 24.4 0" stroke="#0B63F6" strokeWidth="2.3" fill="none" strokeLinecap="round" />
        <rect x="17.8" y="29.4" width="4.6" height="7.6" rx="2.2" fill="#0B63F6" />
        <rect x="41.6" y="29.4" width="4.6" height="7.6" rx="2.2" fill="#0B63F6" />
        <path d="M20.6 36.6c.4 3.4 2.9 5.4 6.7 5.8" stroke="#0B63F6" strokeWidth="1.6" fill="none" strokeLinecap="round" />
        <circle cx="28" cy="42.4" r="1.6" fill="#FFC928" />
      </g>
    </svg>
  );
}

// ------------------------------------------------------------------
// Message text: **bold**, ~~strike~~, [label](/path or https://…), "- " / "1. " lists
// ------------------------------------------------------------------
function inline(text, onNavigate, key = 'i') {
  const out = [];
  const re = /\*\*(.+?)\*\*|~~(.+?)~~|\[([^\]]+)\]\(([^)\s]+)\)|(https?:\/\/[^\s)]+)/g;
  let last = 0;
  let m;
  let n = 0;
  while ((m = re.exec(text))) {
    if (m.index > last) out.push(text.slice(last, m.index));
    const k = `${key}-${n++}`;
    if (m[1] !== undefined) out.push(<strong key={k} className="font-semibold text-navy">{inline(m[1], onNavigate, k)}</strong>);
    else if (m[2] !== undefined) out.push(<s key={k} className="text-muted">{m[2]}</s>);
    else {
      const label = m[3] ?? m[5];
      const href = m[4] ?? m[5];
      if (/^\/(?!\/)/.test(href)) out.push(<Link key={k} to={href} onClick={onNavigate} className="font-medium text-primary underline underline-offset-2 hover:text-primary-dark">{label}</Link>);
      else if (/^https?:\/\//i.test(href)) out.push(<a key={k} href={href} target="_blank" rel="noopener noreferrer" className="font-medium break-all text-primary underline underline-offset-2">{label}</a>);
      else out.push(label);
    }
    last = re.lastIndex;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}

function RichText({ text, onNavigate }) {
  const blocks = [];
  for (const line of String(text).split('\n')) {
    const bullet = line.match(/^\s*[-•*]\s+(.*)$/);
    const numbered = line.match(/^\s*\d+[.)]\s+(.*)$/);
    const type = bullet ? 'ul' : numbered ? 'ol' : null;
    if (type) {
      const prev = blocks[blocks.length - 1];
      if (prev?.type === type) prev.items.push((bullet || numbered)[1]);
      else blocks.push({ type, items: [(bullet || numbered)[1]] });
    } else if (line.trim()) blocks.push({ type: 'p', text: line });
  }
  return (
    <div className="space-y-1.5">
      {blocks.map((b, i) => {
        if (b.type === 'p') return <p key={i}>{inline(b.text, onNavigate, `p${i}`)}</p>;
        const List = b.type;
        return (
          <List key={i} className={(b.type === 'ul' ? 'list-disc' : 'list-decimal') + ' space-y-1 pl-4 marker:text-primary'}>
            {b.items.map((it, j) => <li key={j}>{inline(it, onNavigate, `l${i}-${j}`)}</li>)}
          </List>
        );
      })}
    </div>
  );
}

// ------------------------------------------------------------------
// "Call me back" form shown inside the chat
// ------------------------------------------------------------------
function LeadForm({ courses, defaultCourse, conversationId, hours, onDone }) {
  const [f, setF] = useState({ name: '', phone: '', email: '', course_slug: defaultCourse || '', consent: false, website: '' });
  const [errors, setErrors] = useState({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }));

  const submit = async (e) => {
    e.preventDefault();
    const errs = {};
    if (f.name.trim().length < 2) errs.name = 'Please enter your name.';
    if (!/^[6-9]\d{9}$/.test(f.phone)) errs.phone = 'Enter a valid 10-digit mobile number.';
    if (f.email && !/^\S+@\S+\.\S+$/.test(f.email)) errs.email = 'Enter a valid email or leave it empty.';
    if (!f.consent) errs.consent = 'Please allow us to contact you.';
    setErrors(errs);
    if (Object.keys(errs).length) return;
    setBusy(true);
    setError('');
    try {
      const res = await api.post('chat.php', { ...f, conversation_id: conversationId }, { action: 'lead' });
      onDone(res.reply);
    } catch (err) {
      setErrors(err.fields || {});
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };

  const field = 'w-full rounded-lg border bg-white px-3 py-2 text-sm text-ink placeholder:text-muted/70 focus:border-primary focus:ring-2 focus:ring-primary/20 focus:outline-none';
  const border = (k) => (errors[k] ? ' border-red-400' : ' border-line');
  const err = (k) => errors[k] && <span className="mt-1 block text-xs text-red-600">{errors[k]}</span>;
  return (
    <form onSubmit={submit} noValidate className="mt-2 rounded-2xl border border-primary/15 bg-white p-3.5 shadow-sm">
      <p className="flex items-center gap-2 text-sm font-semibold text-navy"><Icon name="phone" className="size-4 text-primary" /> Request a callback</p>
      <p className="mt-1 text-xs text-muted">A counsellor will call you{hours ? ` (${hours})` : ''}. We use these details only to help you with admission.</p>
      <div className="mt-3 space-y-2.5">
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-ink">Full name <span className="text-red-600">*</span></span>
          <input value={f.name} onChange={set('name')} autoComplete="name" maxLength={120} className={field + border('name')} aria-invalid={!!errors.name} />
          {err('name')}
        </label>
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-ink">Mobile number <span className="text-red-600">*</span></span>
          <span className={'flex overflow-hidden rounded-lg border bg-white focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/20' + border('phone')}>
            <span className="flex items-center gap-1 border-r border-line bg-surface px-2.5 text-xs font-semibold text-muted">IN +91</span>
            <input value={f.phone} onChange={(e) => setF((s) => ({ ...s, phone: e.target.value.replace(/\D/g, '').slice(-10) }))} inputMode="numeric" autoComplete="tel-national" placeholder="98765 43210"
              className="w-full px-3 py-2 text-sm text-ink placeholder:text-muted/60 focus:outline-none" aria-invalid={!!errors.phone} />
          </span>
          {err('phone')}
        </label>
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-ink">Email <span className="font-normal text-muted">(optional)</span></span>
          <input type="email" value={f.email} onChange={set('email')} autoComplete="email" maxLength={190} className={field + border('email')} />
          {err('email')}
        </label>
        {courses?.length > 0 && (
          <label className="block">
            <span className="mb-1 block text-xs font-medium text-ink">Course of interest <span className="font-normal text-muted">(optional)</span></span>
            <select value={f.course_slug} onChange={set('course_slug')} className={field + border('course_slug')}>
              <option value="">Not sure yet</option>
              {courses.map((c) => <option key={c.slug} value={c.slug}>{c.title}</option>)}
            </select>
          </label>
        )}
        <input type="text" name="website" value={f.website} onChange={set('website')} tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />
        <label className="flex items-start gap-2 text-xs text-muted">
          <input type="checkbox" checked={f.consent} onChange={set('consent')} className="mt-0.5 size-4 shrink-0 accent-primary" />
          <span>I agree to be contacted by Laxyam Academy about courses by phone, SMS, email or WhatsApp. <span className="text-red-600">*</span></span>
        </label>
        {err('consent')}
        {error && <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{error}</p>}
        <button type="submit" disabled={busy} className="btn-primary w-full rounded-xl py-2.5">
          {busy ? 'Sending…' : <>Call me back <Icon name="arrowRight" className="size-4" /></>}
        </button>
      </div>
    </form>
  );
}

// ------------------------------------------------------------------
// The widget
// ------------------------------------------------------------------
export default function ChatAssistant() {
  const { data } = useSite();
  const cfg = data?.chatbot;
  const site = data?.site;
  const { pathname } = useLocation();
  const [open, setOpen] = useState(false);
  const [mounted, setMounted] = useState(false); // panel stays mounted after the first open
  const [teaser, setTeaser] = useState(false);
  const [convId, setConvId] = useState(() => readStore().id || null);
  const [messages, setMessages] = useState([]);
  const [restored, setRestored] = useState(false);
  const [typing, setTyping] = useState(false);
  const [input, setInput] = useState('');
  const [leadDone, setLeadDone] = useState(false);
  const scroller = useRef(null);
  const inputRef = useRef(null);
  const launcher = useRef(null);
  // Keep the exam room, lesson player and checkout / payment / enrolled pages distraction-free
  // (on phones the launcher would cover the billing form and the order total).
  const hidden = !cfg || /\/attempt\/|^\/learn\/|^\/(?:checkout|payment|enrolled)(?:\/|$)/.test(pathname);

  // Links ending in #chat (emails, ads, "Chat with us" buttons) open the assistant
  useEffect(() => {
    const onHash = () => {
      if (window.location.hash === '#chat') {
        setMounted(true);
        setOpen(true);
        setTeaser(false);
      }
    };
    onHash();
    window.addEventListener('hashchange', onHash);
    return () => window.removeEventListener('hashchange', onHash);
  }, []);

  // Teaser bubble a few seconds after landing, once per visit
  useEffect(() => {
    if (hidden || !cfg?.teaser || open) return undefined;
    try { if (sessionStorage.getItem(TEASER_KEY)) return undefined; } catch { /* ignore */ }
    const t = setTimeout(() => setTeaser(true), 7000);
    return () => clearTimeout(t);
  }, [hidden, cfg?.teaser, open]);

  // Restore this browser's earlier chat the first time the panel opens
  useEffect(() => {
    if (!open || restored) return;
    setRestored(true);
    if (!convId) return;
    api.get('chat.php', { action: 'history', conversation_id: convId })
      .then((res) => {
        if (!res.messages.length) {
          setConvId(null);
          writeStore(null);
          return;
        }
        setMessages((m) => [...res.messages.map((x) => ({ id: uid(), role: x.role, text: x.content, at: x.created_at })), ...m]);
        setLeadDone(res.lead_submitted);
      })
      .catch(() => {});
  }, [open, restored, convId]);

  useEffect(() => {
    const el = scroller.current;
    if (el) el.scrollTo({ top: el.scrollHeight, behavior: messages.length > 1 ? 'smooth' : 'auto' });
  }, [messages, typing, open]);

  useEffect(() => {
    if (!open) return;
    if (window.matchMedia?.('(pointer: fine)').matches) setTimeout(() => inputRef.current?.focus(), 250);
  }, [open]);

  const dismissTeaser = () => {
    setTeaser(false);
    try { sessionStorage.setItem(TEASER_KEY, '1'); } catch { /* ignore */ }
  };
  const show = () => {
    dismissTeaser();
    setMounted(true);
    setOpen(true);
  };
  const hide = () => {
    setOpen(false);
    launcher.current?.focus();
  };
  // Close the sheet on phones when a link inside the chat is followed
  const onNavigate = () => {
    if (window.matchMedia?.('(max-width: 639px)').matches) setOpen(false);
  };

  const send = async (raw) => {
    const text = String(raw || '').trim().slice(0, MAX);
    if (!text || typing) return;
    setMessages((m) => [...m, { id: uid(), role: 'user', text, at: new Date().toISOString() }]);
    setInput('');
    setTyping(true);
    try {
      const res = await api.post('chat.php', { conversation_id: convId, message: text, page: pathname }, { action: 'message' });
      if (res.conversation_id !== convId) {
        setConvId(res.conversation_id);
        writeStore({ id: res.conversation_id });
      }
      setMessages((m) => [...m, {
        id: uid(), role: 'assistant', text: res.reply, at: res.created_at, suggestions: res.suggestions,
        lead: res.lead_prompt ? { course: res.lead_course || '' } : null,
      }]);
    } catch (err) {
      setMessages((m) => [...m, { id: uid(), role: 'assistant', error: true, retry: text, text: err.message || 'Something went wrong. Please try again.', at: new Date().toISOString() }]);
    } finally {
      setTyping(false);
    }
  };

  const askForLead = () => {
    if (leadDone) {
      setMessages((m) => [...m, { id: uid(), role: 'assistant', text: 'We already have your callback request - our counsellor will call you soon.' + (site?.phone ? ` You can also call us on **${site.phone}**.` : ''), at: new Date().toISOString() }]);
      return;
    }
    setMessages((m) => [...m, { id: uid(), role: 'assistant', text: 'Sure! Share your details below and our counsellor will call you back.', lead: { course: '' }, at: new Date().toISOString() }]);
  };

  const runSuggestion = (s) => {
    if (s.action === 'lead') askForLead();
    else if (s.action === 'whatsapp' && site?.whatsapp) window.open(waLink(site.whatsapp), '_blank', 'noopener');
    else if (s.message) send(s.message);
  };

  const restart = () => {
    setMessages([]);
    setConvId(null);
    writeStore(null);
    setLeadDone(false);
    setInput('');
    inputRef.current?.focus();
  };

  const onLeadDone = (reply) => {
    setLeadDone(true);
    setMessages((m) => [...m, { id: uid(), role: 'assistant', success: true, text: reply, at: new Date().toISOString() }]);
  };

  if (hidden) return null;

  const lastId = messages[messages.length - 1]?.id;
  const chip = 'inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-white px-3 py-1.5 text-xs font-medium text-navy shadow-sm transition-colors hover:border-primary hover:bg-primary-soft disabled:opacity-50';
  const suggestionChip = (s, i) => (
    s.action === 'whatsapp' ? (site?.whatsapp ? (
      <a key={i} href={waLink(site.whatsapp)} target="_blank" rel="noopener noreferrer" className={chip + ' border-[#25D366]/40 hover:border-[#25D366] hover:bg-[#25D366]/10'}>
        <Icon name="whatsapp" className="size-4 text-[#25D366]" />{s.label}
      </a>
    ) : null) : (
      <button key={i} type="button" disabled={typing} onClick={() => runSuggestion(s)} className={chip}>
        {s.action === 'lead' && <Icon name="phone" className="size-3.5 text-primary" />}{s.label}
      </button>
    )
  );

  return (
    <div className="print:hidden">
      {/* Launcher + teaser */}
      {/* Sits --fab-bottom from the bottom edge and steps aside over the footer's copyright bar (.fab-tuck) */}
      <div className={'fab-tuck fixed bottom-[var(--fab-bottom)] left-4 z-[45] flex items-end gap-3 transition-[opacity,transform] duration-200 sm:left-6 ' + (open ? 'pointer-events-none translate-y-2 opacity-0' : 'opacity-100')}>
        <button ref={launcher} type="button" onClick={show} aria-haspopup="dialog" aria-expanded={open} aria-label={`Chat with ${cfg.name} (online)`}
          className="group relative grid size-[62px] shrink-0 place-items-center rounded-full bg-white shadow-[0_14px_30px_-10px_rgba(8,38,77,.55)] ring-[3px] ring-primary transition-transform hover:scale-105 active:scale-95">
          <span className="block size-[54px] overflow-hidden rounded-full"><AssistantAvatar className="size-full" /></span>
          <span className="absolute right-0 bottom-0.5 size-3.5 rounded-full border-2 border-white bg-emerald-500" aria-hidden="true" />
          <span className="absolute -top-1 -right-1 grid size-5 place-items-center rounded-full bg-gold text-navy shadow" aria-hidden="true"><Icon name="messages" className="size-3" strokeWidth={2.5} /></span>
        </button>
        {teaser && (
          <div className="relative mb-2 max-w-[min(220px,calc(100vw-190px))] rounded-2xl rounded-bl-sm bg-white px-4 py-3 text-sm text-ink shadow-[0_12px_30px_-12px_rgba(8,38,77,.45)] ring-1 ring-navy/5">
            <button type="button" onClick={show} className="text-left font-medium">{cfg.teaser}</button>
            <button type="button" onClick={dismissTeaser} aria-label="Dismiss" className="absolute -top-2 -right-2 grid size-6 place-items-center rounded-full bg-white text-muted shadow ring-1 ring-line hover:text-navy">
              <Icon name="x" className="size-3.5" />
            </button>
          </div>
        )}
      </div>

      {/* Panel */}
      {mounted && (
        <section role="dialog" aria-label={cfg.name} aria-modal="false" onKeyDown={(e) => e.key === 'Escape' && hide()}
          className={'fixed inset-x-0 bottom-0 z-[55] flex h-[min(86dvh,640px)] origin-bottom flex-col overflow-hidden rounded-t-3xl bg-surface shadow-[0_-18px_60px_-20px_rgba(8,38,77,.55)] transition-[opacity,transform,visibility] duration-300 ease-out sm:inset-x-auto sm:bottom-5 sm:left-5 sm:h-[min(600px,calc(100dvh-40px))] sm:w-[384px] sm:origin-bottom-left sm:rounded-3xl sm:shadow-[0_28px_70px_-24px_rgba(8,38,77,.6)] sm:ring-1 sm:ring-navy/10 '
            + (open ? 'visible translate-y-0 scale-100 opacity-100' : 'invisible translate-y-6 scale-95 opacity-0')}>
          {/* Header */}
          <header className="relative flex items-center gap-3 overflow-hidden bg-gradient-to-br from-navy via-[#0B3A8C] to-primary px-4 py-3.5 text-white">
            <span className="dot-grid pointer-events-none absolute inset-0 opacity-40" aria-hidden="true" />
            <span className="relative shrink-0">
              <span className="block size-11 overflow-hidden rounded-full ring-2 ring-white/70"><AssistantAvatar className="size-full" /></span>
              <span className="absolute right-0 bottom-0 size-3 rounded-full border-2 border-navy bg-emerald-400" aria-hidden="true" />
            </span>
            <span className="relative min-w-0 flex-1">
              <strong className="line-clamp-2 block text-[15px] leading-tight font-semibold">{cfg.name}</strong>
              <span className="mt-0.5 flex items-center gap-1.5 text-xs text-white/80"><span className="size-1.5 rounded-full bg-emerald-400" aria-hidden="true" />Online · replies instantly</span>
            </span>
            <span className="relative flex items-center">
              {messages.length > 0 && (
                <button type="button" onClick={restart} title="Start a new chat" aria-label="Start a new chat" className="grid size-8 place-items-center rounded-full text-white/85 hover:bg-white/15 hover:text-white">
                  <Icon name="refresh" className="size-[18px]" />
                </button>
              )}
              {site?.phone && (
                <a href={`tel:${site.phone.replace(/\s/g, '')}`} title={`Call ${site.phone}`} aria-label={`Call ${site.phone}`} className="grid size-8 place-items-center rounded-full text-white/85 hover:bg-white/15 hover:text-white">
                  <Icon name="phone" className="size-[18px]" />
                </a>
              )}
              <button type="button" onClick={hide} title="Minimise" aria-label="Minimise chat" className="hidden size-8 place-items-center rounded-full text-white/85 hover:bg-white/15 hover:text-white sm:grid">
                <Icon name="chevronDown" className="size-5" />
              </button>
              <button type="button" onClick={hide} title="Close" aria-label="Close chat" className="grid size-8 place-items-center rounded-full text-white/85 hover:bg-white/15 hover:text-white">
                <Icon name="x" className="size-5" />
              </button>
            </span>
          </header>

          {/* Conversation */}
          <div ref={scroller} className="flex-1 space-y-4 overflow-y-auto overscroll-contain px-3.5 py-4" aria-live="polite">
            <div className="flex items-end gap-2">
              <span className="size-7 shrink-0 overflow-hidden rounded-full ring-1 ring-line"><AssistantAvatar className="size-full" /></span>
              <div className="rounded-2xl rounded-bl-md bg-white px-4 py-3 shadow-sm ring-1 ring-line">
                <p className="font-semibold text-navy">How can I help you? 👋</p>
                <p className="mt-1 text-[13px] leading-relaxed text-muted">{cfg.greeting}</p>
              </div>
            </div>
            {messages.length === 0 && (
              <div className="flex flex-wrap gap-2 pl-9">
                {QUICK.map(([label, msg]) => <button key={label} type="button" onClick={() => send(msg)} className={chip}>{label}</button>)}
                <button type="button" onClick={askForLead} className={chip}><Icon name="phone" className="size-3.5 text-primary" />Talk to a counsellor</button>
                {suggestionChip({ label: 'WhatsApp us', action: 'whatsapp' }, 'wa')}
              </div>
            )}

            {messages.map((m) => (
              m.role === 'user' ? (
                <div key={m.id} className="flex flex-col items-end">
                  <div className="max-w-[85%] rounded-2xl rounded-br-md bg-primary px-3.5 py-2.5 text-sm leading-relaxed whitespace-pre-wrap text-white shadow-sm">{m.text}</div>
                  <span className="mt-1 text-[11px] text-muted">{timeOf(m.at)}</span>
                </div>
              ) : (
                <div key={m.id}>
                  <div className="flex items-end gap-2">
                    <span className="size-7 shrink-0 overflow-hidden rounded-full ring-1 ring-line"><AssistantAvatar className="size-full" /></span>
                    <div className={'max-w-[85%] rounded-2xl rounded-bl-md px-3.5 py-2.5 text-sm leading-relaxed shadow-sm ring-1 '
                      + (m.error ? 'bg-red-50 text-red-800 ring-red-200' : m.success ? 'bg-emerald-50 text-emerald-900 ring-emerald-200' : 'bg-white text-ink ring-line')}>
                      {m.success && <Icon name="checkCircle" className="mr-1 mb-0.5 inline size-4 text-emerald-600" />}
                      <RichText text={m.text} onNavigate={onNavigate} />
                      {m.error && m.retry && m.id === lastId && (
                        <button type="button" onClick={() => { setMessages((all) => all.filter((x) => x.id !== m.id)); send(m.retry); }} className="mt-2 text-xs font-semibold text-red-700 underline">Try again</button>
                      )}
                    </div>
                  </div>
                  <span className="mt-1 block pl-9 text-[11px] text-muted">{timeOf(m.at)}</span>
                  {m.id === lastId && m.lead && !leadDone && (
                    <div className="pl-9"><LeadForm courses={cfg.courses} defaultCourse={m.lead.course} conversationId={convId} hours={site?.office_hours} onDone={onLeadDone} /></div>
                  )}
                  {m.id === lastId && m.suggestions?.length > 0 && !typing && (
                    <div className="mt-2.5 flex flex-wrap gap-2 pl-9">
                      {m.suggestions.filter((s) => !(s.action === 'lead' && m.lead && !leadDone)).map(suggestionChip)}
                    </div>
                  )}
                </div>
              )
            ))}

            {typing && (
              <div className="flex items-end gap-2" aria-label="Assistant is typing">
                <span className="size-7 shrink-0 overflow-hidden rounded-full ring-1 ring-line"><AssistantAvatar className="size-full" /></span>
                <div className="flex gap-1 rounded-2xl rounded-bl-md bg-white px-4 py-3.5 shadow-sm ring-1 ring-line">
                  {[0, 150, 300].map((d) => <span key={d} className="size-2 animate-bounce rounded-full bg-primary/60" style={{ animationDelay: `${d}ms` }} />)}
                </div>
              </div>
            )}
          </div>

          {/* Composer */}
          <form onSubmit={(e) => { e.preventDefault(); send(input); }} className="border-t border-line bg-white px-3 pt-2.5 pb-[max(10px,env(safe-area-inset-bottom))]">
            <div className="flex items-end gap-1 rounded-2xl border border-line bg-surface py-1 pr-1 pl-3 focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/15">
              <label htmlFor="lx-chat-input" className="sr-only">Type your question</label>
              <textarea id="lx-chat-input" ref={inputRef} rows={1} value={input} maxLength={MAX} placeholder="Type your question…"
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(input); } }}
                className="max-h-28 min-h-[38px] flex-1 resize-none bg-transparent py-2 text-sm text-ink placeholder:text-muted/70 focus:outline-none" />
              <MicButton onText={(t) => setInput((v) => (v ? v + ' ' : '') + t)} />
              <button type="submit" disabled={!input.trim() || typing} aria-label="Send"
                className="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-white transition-colors hover:bg-primary-dark disabled:bg-line disabled:text-muted">
                <Icon name="send" className="size-4" />
              </button>
            </div>
            <div className="mt-1.5 flex items-center justify-between px-1 text-[11px] text-muted">
              <span>{cfg.ai ? 'AI assistant · a counsellor can confirm details' : 'Laxyam Academy assistant'}</span>
              <span className={input.length >= MAX ? 'text-red-600' : ''}>{input.length}/{MAX}</span>
            </div>
          </form>
        </section>
      )}
    </div>
  );
}
