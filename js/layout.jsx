// Site chrome around every page: top bar, sticky header, mobile drawer + bottom tab bar,
// search overlay, footer, floating WhatsApp / call / back-to-top, popups and the chat assistant.
// The pieces live in js/ui/*; this file wires them to the route.
//
// z-index scale: tab bar & sticky header 40 · floating buttons & chat launcher 45 · header
// dropdowns 50 · chat panel 55 · search overlay 60 · mobile drawer 70 · popups 80.
import { lazy, Suspense, useEffect, useState } from 'react';
import { Outlet, useLocation } from 'react-router-dom';
import { API_BASE, captureAttribution } from './api.js';
import { useAuth } from './auth.jsx';
import { Icon, Logo, useSite } from './components.jsx';
import { captureReferral } from './referral.js';
import Footer from './ui/Footer.jsx';
import { Header, TopBar } from './ui/Header.jsx';
import { MobileDrawer } from './ui/MobileNav.jsx';
import { isTypingTarget } from './ui/overlay.js';
import Popups from './ui/Popups.jsx';
import { DEFAULT_WA_MESSAGE, tel, waLink } from './ui/shell-data.js';

export { SocialLinks } from './ui/social.jsx';

// Loaded as separate chunks when first needed
const ChatAssistant = lazy(() => import('./chatbot.jsx'));
const SearchOverlay = lazy(() => import('./ui/SearchOverlay.jsx'));

const EXAM_ROOM = /^\/mock-tests\/[^/]+\/attempt\//;
// Checkout, payment result and enrolment confirmation: no floating buttons over the form / totals
// (chatbot.jsx hides its launcher on the same routes).
const PURCHASE_FLOW = /^\/(?:checkout|payment|enrolled)(?:\/|$)/;

// ------------------------------------------------------------------
// Floating actions (WhatsApp, call, back to top) in the bottom-right corner
// ------------------------------------------------------------------
function FloatingActions() {
  const { data } = useSite();
  const site = data?.site || {};
  const [showTop, setShowTop] = useState(false);
  useEffect(() => {
    const onScroll = () => setShowTop(window.scrollY > 600);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);
  if (!site.phone && !site.whatsapp && !showTop) return null;
  const tip = 'pointer-events-none absolute top-1/2 right-full mr-3 hidden -translate-y-1/2 rounded-full bg-navy px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-white opacity-0 shadow-lg transition-opacity duration-200 group-hover:opacity-100 group-focus-visible:opacity-100 sm:block';
  return (
    <div className="fab-tuck pointer-events-none fixed right-4 bottom-[var(--fab-bottom)] z-[45] flex flex-col items-end gap-3 sm:right-6 print:hidden">
      {showTop && (
        <button type="button" onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })} aria-label="Back to top"
          className="group pointer-events-auto relative grid size-11 animate-pop place-items-center rounded-full border border-line bg-white/95 text-navy shadow-card-hover backdrop-blur transition-all hover:-translate-y-0.5 hover:text-primary">
          <Icon name="arrowUp" className="size-5" /><span className={tip}>Back to top</span>
        </button>
      )}
      {site.phone && (
        <a href={tel(site.phone)} aria-label={`Call ${site.phone}`}
          className="group pointer-events-auto relative grid size-12 place-items-center rounded-full bg-gradient-to-br from-primary to-[#2f7bff] text-white shadow-glow transition-transform hover:-translate-y-0.5">
          <Icon name="phone" className="size-5" /><span className={tip}>Call {site.phone}</span>
        </a>
      )}
      {site.whatsapp && (
        <a href={waLink(site.whatsapp, DEFAULT_WA_MESSAGE)} target="_blank" rel="noopener noreferrer" aria-label="Chat with us on WhatsApp"
          className="group pointer-events-auto relative grid size-14 animate-pulse-ring place-items-center rounded-full bg-[#25D366] text-[#25D366] shadow-[0_12px_28px_-10px_rgb(37_211_102/0.8)] transition-transform hover:-translate-y-0.5">
          <Icon name="whatsapp" className="size-7 text-white" /><span className={tip}>Chat on WhatsApp</span>
        </a>
      )}
    </div>
  );
}

/** Instant jump to the top on route change (html has scroll-behavior: smooth for in-page anchors). */
function ScrollToTop() {
  const { pathname, hash } = useLocation();
  useEffect(() => {
    if (hash && hash !== '#chat') {
      const el = document.getElementById(decodeURIComponent(hash.slice(1)));
      if (el) {
        el.scrollIntoView();
        return;
      }
    }
    window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
  }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps
  return null;
}

/** Shown to visitors while maintenance mode is on (staff can keep browsing). */
function Maintenance({ message }) {
  const { data } = useSite();
  const site = data?.site;
  return (
    <main id="main" className="relative isolate grid min-h-screen place-items-center overflow-hidden bg-mesh p-6 text-center">
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10" />
      <div className="card max-w-lg p-8 sm:p-10">
        <Logo className="mx-auto" />
        <span className="mx-auto mt-8 grid size-14 place-items-center rounded-2xl bg-primary-soft text-primary"><Icon name="settings" className="size-7 animate-[spin_6s_linear_infinite]" /></span>
        <h1 className="heading-lg mt-5">We’ll be back soon</h1>
        <p className="mt-3 text-muted">{message}</p>
        {(site?.phone || site?.whatsapp) && (
          <div className="mt-6 flex flex-wrap justify-center gap-2">
            {site.phone && <a href={tel(site.phone)} className="btn-primary"><Icon name="phone" className="size-4" />Call {site.phone}</a>}
            {site.whatsapp && <a href={waLink(site.whatsapp, DEFAULT_WA_MESSAGE)} target="_blank" rel="noopener noreferrer" className="btn-whatsapp"><Icon name="whatsapp" className="size-4" />WhatsApp us</a>}
          </div>
        )}
      </div>
    </main>
  );
}

/** First-party page-view beacon + first-touch campaign attribution. */
function Tracker() {
  const { pathname } = useLocation();
  useEffect(() => {
    captureAttribution();
    // Admin → Settings → Analytics can switch this off; track.php checks it server-side.
    const q = new URLSearchParams(window.location.search);
    const body = JSON.stringify({ path: pathname, referrer: document.referrer, w: window.innerWidth, utm_source: q.get('utm_source'), utm_medium: q.get('utm_medium'), utm_campaign: q.get('utm_campaign') });
    const url = API_BASE + 'track.php';
    // Beacon cannot send the CSRF header; track.php does not change user data and is rate limited.
    if (!navigator.sendBeacon?.(url, new Blob([body], { type: 'application/json' }))) {
      fetch(url, { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, keepalive: true, credentials: 'same-origin' }).catch(() => {});
    }
  }, [pathname]); // eslint-disable-line react-hooks/exhaustive-deps
  return null;
}

export function Layout() {
  const { data } = useSite();
  const { user } = useAuth();
  const { pathname, key, search: query } = useLocation();
  const [drawer, setDrawer] = useState(false);
  const [search, setSearch] = useState(false);
  const examRoom = EXAM_ROOM.test(pathname);
  const searchEnabled = data?.header?.show_search !== false;

  // A friend's referral link (?ref=CODE) on any page: remembered for the checkout
  useEffect(() => { captureReferral(query); }, [query]);

  // Every navigation (also taps on links to the current page) closes the drawer and search
  useEffect(() => {
    setDrawer(false);
    setSearch(false);
  }, [key]);

  // Ctrl/Cmd + K or "/" opens search
  useEffect(() => {
    if (examRoom || !searchEnabled) return undefined;
    const onKey = (e) => {
      if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) {
        e.preventDefault();
        setSearch(true);
      } else if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !isTypingTarget(e.target)) {
        e.preventDefault();
        setSearch(true);
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [examRoom, searchEnabled]);

  // Distraction-free exam room: no site chrome (css/style.css re-pins the exam toolbar)
  useEffect(() => {
    document.body.classList.toggle('exam-mode', examRoom);
    return () => document.body.classList.remove('exam-mode');
  }, [examRoom]);

  if (data?.maintenance?.on && user?.type !== 'staff') return <Maintenance message={data.maintenance.message} />;
  return (
    <div className="flex min-h-screen flex-col">
      <a href="#main" className="sr-only z-[90] rounded-lg bg-primary px-4 py-2 font-semibold text-white focus:not-sr-only focus:fixed focus:top-2 focus:left-2">Skip to content</a>
      <ScrollToTop />
      <Tracker />
      {!examRoom && data?.header?.show_top_bar !== false && <TopBar />}
      {!examRoom && <Header onSearch={() => setSearch(true)} onMenu={() => setDrawer(true)} menuOpen={drawer} />}
      <main id="main" className="flex-1"><Outlet /></main>
      {!examRoom && <Footer />}
      {!examRoom && !PURCHASE_FLOW.test(pathname) && <FloatingActions />}
      {!examRoom && <Popups />}
      {drawer && <MobileDrawer onClose={() => setDrawer(false)} onSearch={() => { setDrawer(false); setSearch(true); }} />}
      {search && <Suspense fallback={null}><SearchOverlay onClose={() => setSearch(false)} /></Suspense>}
      {data?.chatbot && <Suspense fallback={null}><ChatAssistant /></Suspense>}
    </div>
  );
}
