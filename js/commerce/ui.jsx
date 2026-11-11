// Shared UI for the checkout, payment result, enrolment and purchases pages (commerce-fe).
import { apiUrl } from '../api.js';
import { Icon } from '../components.jsx';

/**
 * GST invoice PDF link for an order. The API returns an absolute url (orders.php?action=invoice&order=…,
 * owner session required); build the same link if an older payload has the number but no url.
 */
export const invoiceHref = (orderNumber, url) => url || (orderNumber ? apiUrl('orders.php', { action: 'invoice', order: orderNumber }) : null);

/** Order status → label + badge colours (orders.status values). */
export const ORDER_STATUS = {
  paid: { label: 'Paid', className: 'bg-emerald-50 text-emerald-700 ring-emerald-200', icon: 'checkCircle' },
  pending: { label: 'Payment pending', className: 'bg-amber-50 text-amber-800 ring-amber-200', icon: 'clock' },
  failed: { label: 'Failed', className: 'bg-red-50 text-red-700 ring-red-200', icon: 'x' },
  cancelled: { label: 'Cancelled', className: 'bg-slate-100 text-slate-600 ring-slate-200', icon: 'x' },
  refunded: { label: 'Refunded', className: 'bg-blue-50 text-blue-800 ring-blue-200', icon: 'refresh' },
  partially_refunded: { label: 'Partially refunded', className: 'bg-indigo-50 text-indigo-800 ring-indigo-200', icon: 'refresh' },
  // Not an orders.status value: a received payment that staff must check (orders.php status needs_review).
  review: { label: 'Under review', className: 'bg-amber-50 text-amber-800 ring-amber-200', icon: 'info' },
};

export function StatusBadge({ status, className = '' }) {
  const s = ORDER_STATUS[status] || { label: String(status || '').replace(/_/g, ' '), className: 'bg-slate-100 text-slate-600 ring-slate-200', icon: 'info' };
  return (
    <span className={'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold capitalize ring-1 ' + s.className + ' ' + className}>
      <Icon name={s.icon} className="size-3.5" />{s.label}
    </span>
  );
}

/**
 * Cashfree JS SDK v3 (https://sdk.cashfree.com/js/v3/cashfree.js), loaded on demand - only when a
 * checkout actually starts a Cashfree payment. Resolves with the global Cashfree() factory.
 */
let cashfreeSdk = null;
export function loadCashfree() {
  if (window.Cashfree) return Promise.resolve(window.Cashfree);
  if (cashfreeSdk) return cashfreeSdk;
  cashfreeSdk = new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://sdk.cashfree.com/js/v3/cashfree.js';
    s.async = true;
    s.onload = () => (window.Cashfree ? resolve(window.Cashfree) : reject(new Error('The secure payment page could not start. Please try again.')));
    s.onerror = () => {
      s.remove();
      cashfreeSdk = null; // allow a retry
      reject(new Error('Could not load the secure payment page. Check your connection and try again.'));
    };
    document.body.appendChild(s);
  });
  return cashfreeSdk;
}

/** Secure payment / instant access / invoice reassurance row. `provider` = gateway name from the quote (e.g. "Cashfree"). */
export function TrustBadges({ className = '', dark = false, provider = '' }) {
  const items = [
    ['shield', 'Secure payment', `Processed by ${provider || 'our secure payment partner'}. We never see your card or UPI PIN.`],
    ['sparkles', 'Instant access', 'Your course unlocks as soon as payment is verified.'],
    ['receipt', 'GST invoice', 'Download a PDF invoice from My Purchases anytime.'],
  ];
  return (
    <ul className={'grid gap-3 sm:grid-cols-3 lg:grid-cols-1 ' + className}>
      {items.map(([icon, title, text]) => (
        <li key={title} className={'flex min-w-0 items-start gap-3 rounded-xl p-3 ' + (dark ? 'bg-white/10 text-white' : 'bg-surface')}>
          <span className={'grid size-9 shrink-0 place-items-center rounded-lg ' + (dark ? 'bg-white/15 text-gold' : 'bg-white text-emerald-600 shadow-card')}>
            <Icon name={icon} className="size-[18px]" />
          </span>
          <span className="min-w-0">
            <span className={'block text-sm font-bold ' + (dark ? 'text-white' : 'text-navy')}>{title}</span>
            <span className={'block text-xs leading-relaxed ' + (dark ? 'text-white/75' : 'text-muted')}>{text}</span>
          </span>
        </li>
      ))}
    </ul>
  );
}

/** "180 days access" / "Access until 12 Mar 2027" / "Lifetime access". */
export function validityLabel(validityDays, expiresAt, formatDate) {
  if (expiresAt && formatDate) return `Access until ${formatDate(expiresAt)}`;
  if (validityDays) return `${validityDays} days access`;
  return 'Lifetime access';
}

/** wa.me link for the academy WhatsApp number from site settings (digits only), or null. */
export function whatsappHref(number, text) {
  const digits = String(number || '').replace(/\D/g, '');
  if (!digits) return null;
  return `https://wa.me/${digits}${text ? `?text=${encodeURIComponent(text)}` : ''}`;
}

// ------------------------------------------------------------------
// Celebration: CSS-only confetti burst + animated check mark.
// Hidden / static under prefers-reduced-motion.
// ------------------------------------------------------------------
const CONFETTI_COLORS = ['#0B63F6', '#FFC928', '#F04E23', '#6D4AFF', '#0EA5A4', '#22C55E'];

// Deterministic "random" spread so renders are stable (no Math.random in render).
const PIECES = Array.from({ length: 36 }, (_, i) => {
  const r = (n) => ((Math.sin((i + 1) * n) + 1) / 2);
  return {
    x: Math.round((r(12.9898) - 0.5) * 520),
    y: Math.round(-140 - r(78.233) * 220),
    rot: Math.round(r(3.17) * 720 - 360),
    delay: Math.round(r(5.71) * 260),
    size: 6 + Math.round(r(9.13) * 6),
    round: i % 3 === 0,
    color: CONFETTI_COLORS[i % CONFETTI_COLORS.length],
  };
});

const CELEBRATE_CSS = `
@keyframes lx-confetti {
  0% { transform: translate(-50%, 0) rotate(0deg) scale(.4); opacity: 0; }
  12% { opacity: 1; }
  55% { transform: translate(calc(-50% + var(--x)), var(--y)) rotate(calc(var(--r) * .6)) scale(1); opacity: 1; }
  100% { transform: translate(calc(-50% + var(--x) * 1.15), calc(var(--y) + 260px)) rotate(var(--r)) scale(.9); opacity: 0; }
}
@keyframes lx-check-draw { to { stroke-dashoffset: 0; } }
@keyframes lx-check-pop { 0% { transform: scale(.6); opacity: 0; } 60% { transform: scale(1.08); opacity: 1; } 100% { transform: scale(1); } }
.lx-confetti-piece { position: absolute; left: 50%; top: 55%; opacity: 0; animation: lx-confetti 1.9s cubic-bezier(.22,1,.36,1) forwards; }
.lx-check-ring { animation: lx-check-pop .6s cubic-bezier(.34,1.56,.64,1) both; }
.lx-check-circle { stroke-dasharray: 160; stroke-dashoffset: 160; animation: lx-check-draw .7s .15s ease-out forwards; }
.lx-check-mark { stroke-dasharray: 40; stroke-dashoffset: 40; animation: lx-check-draw .4s .65s ease-out forwards; }
@media (prefers-reduced-motion: reduce) {
  .lx-confetti { display: none; }
  .lx-check-ring, .lx-check-circle, .lx-check-mark { animation: none; stroke-dashoffset: 0; opacity: 1; transform: none; }
}
`;

export function Confetti() {
  return (
    <div aria-hidden="true" className="lx-confetti pointer-events-none absolute inset-0 overflow-hidden">
      {PIECES.map((p, i) => (
        <span
          key={i}
          className="lx-confetti-piece"
          style={{
            width: p.size, height: p.round ? p.size : p.size * 0.45, background: p.color,
            borderRadius: p.round ? '9999px' : '2px', animationDelay: `${p.delay}ms`,
            '--x': `${p.x}px`, '--y': `${p.y}px`, '--r': `${p.rot}deg`,
          }}
        />
      ))}
    </div>
  );
}

/** Large animated success tick (green). */
export function AnimatedCheck({ className = 'size-24' }) {
  return (
    <span className={'lx-check-ring relative grid place-items-center rounded-full bg-gradient-to-br from-emerald-400 to-emerald-600 text-white shadow-[0_18px_40px_-12px_rgb(16_185_129/0.7)] ring-8 ring-emerald-100 ' + className}>
      <svg viewBox="0 0 52 52" className="size-[62%]" fill="none" stroke="currentColor" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
        <circle className="lx-check-circle" cx="26" cy="26" r="24" strokeOpacity=".35" />
        <path className="lx-check-mark" d="M15 27l7 7 15-16" />
      </svg>
    </span>
  );
}

/** Keyframes for Confetti / AnimatedCheck - render once on the page that uses them. */
export function CelebrateStyles() {
  return <style>{CELEBRATE_CSS}</style>;
}
