// Popups from admin → Popup Manager. kind 'promo': image, title, text and a CTA link;
// kind 'enquiry': a lead form (EnquiryForm, source 'popup'). Page targeting (show_on),
// delay and frequency come from the admin; once a visitor closes or submits a popup it is
// never shown again for that popup version (localStorage, with an in-memory fallback).
import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { API_BASE } from '../api.js';
import { EnquiryForm, Icon, SmartLink, useSite } from '../components.jsx';
import { overlayOpen, useFocusTrap, useScrollLock } from './overlay.js';

// Never interrupt learning, tests, payments, sign-in or the dashboard
const EXCLUDED = /^\/(dashboard|learn|checkout|payment|enrolled|login|register|forgot-password|reset-password|verify-email|mock-tests\/[^/]+\/(attempt|result))(\/|$)/;
const memory = new Set(); // popups already shown or closed during this page load

const id = (p) => `${p.id}-${p.version ?? 0}`;
const store = (p) => (p.frequency === 'session' ? window.sessionStorage : window.localStorage);
const today = () => new Date().toDateString();

function blocked(p) {
  if (memory.has(id(p))) return true;
  try {
    if (localStorage.getItem(`lx-popup-done-${id(p)}`)) return true; // closed or submitted earlier
    const seen = store(p).getItem(`lx-popup-${id(p)}`);
    return p.frequency === 'day' ? seen === today() : !!seen;
  } catch {
    return false;
  }
}
function markSeen(p) {
  memory.add(id(p));
  try { store(p).setItem(`lx-popup-${id(p)}`, p.frequency === 'day' ? today() : '1'); } catch { /* storage unavailable */ }
}
function markDone(p) {
  memory.add(id(p));
  try { localStorage.setItem(`lx-popup-done-${id(p)}`, '1'); } catch { /* storage unavailable */ }
}
const matches = (rule, path) => String(rule || '*').split(',').map((r) => r.trim()).filter(Boolean)
  .some((r) => r === '*' || (r.endsWith('*') ? path.startsWith(r.slice(0, -1)) : path === r));
const beacon = (p, type) => fetch(`${API_BASE}cms.php?action=popup-event&id=${encodeURIComponent(p.id)}${type ? `&type=${type}` : ''}`, { credentials: 'same-origin' }).catch(() => {});

function PopupDialog({ popup, onClose, onSubmitted }) {
  const ref = useRef(null);
  const enquiry = popup.kind === 'enquiry';
  useScrollLock(true);
  useFocusTrap(ref, true, { initialFocus: enquiry ? 'input[name="name"]' : '[data-popup-close]', onEscape: onClose });

  return (
    <div className="fixed inset-0 z-[80] overflow-y-auto print:hidden">
      <div aria-hidden="true" onClick={onClose} className="fixed inset-0 animate-fade-in bg-navy-deep/60 backdrop-blur-sm" />
      <div className="pointer-events-none relative flex min-h-full items-center justify-center p-4 sm:p-6">
        <div ref={ref} role="dialog" aria-modal="true" aria-labelledby="popup-title" aria-describedby={popup.body ? 'popup-body' : undefined}
          className={'pointer-events-auto relative w-full animate-scale-in overflow-hidden rounded-3xl bg-white shadow-[0_40px_90px_-30px_rgb(4_19_48/0.7)] ' + (enquiry ? (popup.image_url ? 'max-w-3xl' : 'max-w-lg') : 'max-w-md')}>
          <button type="button" data-popup-close onClick={onClose} aria-label="Close"
            className="absolute top-3 right-3 z-20 grid size-9 place-items-center rounded-full bg-white/90 text-navy shadow-md ring-1 ring-line backdrop-blur transition-transform hover:rotate-90">
            <Icon name="x" className="size-4" />
          </button>

          {enquiry ? (
            <div className={popup.image_url ? 'grid sm:grid-cols-[0.95fr_1.05fr]' : ''}>
              {/* Brand panel: headline + the counsellor photo (a cut-out sits on the bottom edge) */}
              <div className="relative isolate overflow-hidden bg-navy-mesh px-6 pt-7 text-white sm:px-8 sm:pt-9">
                <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40" />
                <div aria-hidden="true" className="absolute -bottom-16 left-1/2 -z-10 size-72 -translate-x-1/2 rounded-full bg-primary/50 blur-3xl" />
                <div className={popup.image_url ? 'relative z-10 max-w-[62%] pb-6 sm:max-w-none sm:pb-0' : 'pb-6'}>
                  <span className="eyebrow bg-white/15! text-gold!"><Icon name="phoneCall" className="size-3.5" />Free counselling</span>
                  <h2 id="popup-title" className="mt-3 pr-8 font-display text-xl leading-tight font-extrabold text-white sm:text-2xl">{popup.form_title || popup.title}</h2>
                  {popup.body && <p id="popup-body" className={'mt-2 text-sm leading-relaxed whitespace-pre-line text-white/85' + (popup.image_url ? ' hidden sm:block' : '')}>{popup.body}</p>}
                  {popup.image_url && (
                    <ul className="mt-4 hidden space-y-2 text-sm text-white/90 sm:block">
                      {['Expert guidance for your target exam', 'Right course & batch for you', 'Call back at your preferred time'].map((t) => (
                        <li key={t} className="flex items-center gap-2"><Icon name="checkCircle" className="size-4 shrink-0 text-gold" />{t}</li>
                      ))}
                    </ul>
                  )}
                </div>
                {popup.image_url && (
                  <img src={popup.image_url} alt="Laxyam Academy counsellor" loading="lazy"
                    className="pointer-events-none absolute right-0 bottom-0 h-[92%] max-h-48 w-auto object-contain object-bottom sm:relative sm:mx-auto sm:mt-4 sm:block sm:h-auto sm:max-h-72 sm:max-w-full" />
                )}
              </div>
              <div className="px-6 pt-5 pb-6 sm:px-8 sm:pt-8">
                {popup.image_url && <p className="mb-3 hidden font-display text-lg font-bold text-navy sm:block">Request a free callback</p>}
                <EnquiryForm source="popup" compact popupId={popup.id} consentText={popup.consent_text || undefined}
                  successMessage={popup.success_message || undefined} submitLabel={popup.cta_label || undefined} idPrefix={`popup-${popup.id}`} onDone={onSubmitted} />
              </div>
            </div>
          ) : (
            <>
              {popup.image_url && <img src={popup.image_url} alt="" className="max-h-64 w-full object-cover" />}
              <div className="p-6 sm:p-7">
                <h2 id="popup-title" className="pr-8 font-display text-xl leading-snug font-extrabold text-navy">{popup.title}</h2>
                {popup.body && <p id="popup-body" className="mt-2 text-sm leading-relaxed whitespace-pre-line text-muted">{popup.body}</p>}
                {popup.cta_label && popup.cta_url && (
                  <SmartLink to={popup.cta_url} onClick={() => { beacon(popup, 'click'); onClose(); }} className="btn-primary btn-lg mt-5 w-full">
                    {popup.cta_label}<Icon name="arrowRight" className="size-4" />
                  </SmartLink>
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

export default function Popups() {
  const { data } = useSite();
  const { pathname } = useLocation();
  const [open, setOpen] = useState(null);

  useEffect(() => {
    setOpen(null);
    if (EXCLUDED.test(pathname)) return undefined;
    const popup = (data?.popups || []).find((p) => matches(p.show_on, pathname) && !blocked(p));
    if (!popup) return undefined;
    let timer;
    const fire = () => {
      if (overlayOpen()) {
        // The visitor is using the menu or search: try again shortly
        timer = setTimeout(fire, 3000);
        return;
      }
      markSeen(popup);
      setOpen(popup);
      beacon(popup);
    };
    timer = setTimeout(fire, Math.max(0, Number(popup.delay) || 0) * 1000);
    return () => clearTimeout(timer);
  }, [pathname, data]);

  if (!open) return null;
  return (
    <PopupDialog
      key={open.id}
      popup={open}
      onClose={() => { markDone(open); setOpen(null); }}
      onSubmitted={() => markDone(open)}
    />
  );
}
