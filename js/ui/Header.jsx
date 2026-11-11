// Site header: top bar (call / WhatsApp / email, highlights ticker, language, social) and the
// sticky header (desktop nav with the compact Courses mega menu, Jobs and Highlights menus,
// search, helpline, CTA and account). Below xl the nav lives in the mobile drawer.
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link, NavLink, useMatch, useNavigate } from 'react-router-dom';
import { APP_BASE, formatPrice } from '../api.js';
import { useAuth } from '../auth.jsx';
import { Icon, Logo, SmartLink, useSite } from '../components.jsx';
import { useMenuGroup } from './menus.js';
import { DEFAULT_WA_MESSAGE, HIGHLIGHT_BADGES, navItems, tel, waLink } from './shell-data.js';
import { SocialLinks } from './social.jsx';

const isExternal = (to) => /^(https?:|mailto:|tel:)/i.test(to || '');

/** Exam categories worth linking from menus: those with published courses (count from cms.php layout). */
export const liveCategories = (list) => (list || []).filter((c) => c?.slug && (c.count == null || Number(c.count) > 0));

/** Round avatar (photo or initial). */
export function Avatar({ user, className = 'size-9' }) {
  if (user?.avatar_url) return <img src={user.avatar_url} alt="" width="44" height="44" className={'shrink-0 rounded-full object-cover ' + className} />;
  return (
    <span aria-hidden="true" className={'grid shrink-0 place-items-center rounded-full bg-gradient-to-br from-primary to-violet font-display text-sm font-bold text-white ' + className}>
      {(user?.name || '?').trim().charAt(0).toUpperCase()}
    </span>
  );
}

export function HighlightBadge({ badge, className = '' }) {
  const [label, cls] = HIGHLIGHT_BADGES[badge] || HIGHLIGHT_BADGES.new;
  return <span className={'shrink-0 rounded-full px-1.5 py-px text-[10px] leading-4 font-extrabold tracking-wide uppercase ' + cls + ' ' + className}>{label}</span>;
}

// ------------------------------------------------------------------
// Top bar
// ------------------------------------------------------------------
/** Hindi / English toggle using Google's website translator (loaded only when Hindi is chosen). */
export function LanguageToggle({ enabled, variant = 'dark', className = '' }) {
  const isHindi = typeof document !== 'undefined' && /googtrans=\/en\/hi/.test(document.cookie);
  useEffect(() => {
    if (!enabled || !isHindi || document.getElementById('gt-script')) return;
    window.lxTranslateInit = () => new window.google.translate.TranslateElement({ pageLanguage: 'en', includedLanguages: 'hi,en', autoDisplay: false }, 'gt-el');
    const div = document.createElement('div');
    div.id = 'gt-el';
    div.style.display = 'none';
    document.body.appendChild(div);
    const s = document.createElement('script');
    s.id = 'gt-script';
    s.src = 'https://translate.google.com/translate_a/element.js?cb=lxTranslateInit';
    document.body.appendChild(s);
  }, [enabled, isHindi]);
  if (!enabled) return null;
  const toggle = () => {
    const host = window.location.hostname;
    const value = isHindi ? '' : '/en/hi';
    const expire = isHindi ? '; expires=Thu, 01 Jan 1970 00:00:00 GMT' : '';
    document.cookie = `googtrans=${value}; path=/${expire}`;
    document.cookie = `googtrans=${value}; path=/; domain=${host}${expire}`;
    window.location.reload();
  };
  // variant 'dark' = on the navy top bar; 'light' = on white surfaces (mobile drawer)
  const tone = variant === 'light'
    ? 'h-8 border-line bg-white text-navy hover:border-primary hover:text-primary'
    : 'h-7 border-white/30 text-white hover:bg-white/10';
  return (
    <button type="button" onClick={toggle} translate="no" aria-label={isHindi ? 'Switch to English' : 'हिंदी में देखें'}
      className={'inline-flex shrink-0 items-center gap-1 rounded-full border px-2.5 text-xs font-bold transition-colors ' + tone + ' ' + className}>
      <Icon name="languages" className="size-3.5" />{isHindi ? 'English' : 'हिंदी'}
    </button>
  );
}

/** Scrolling highlights (admin → Highlights); falls back to the single announcement line. */
function Ticker({ items, announcement }) {
  if (!items.length) {
    if (!announcement?.text) return <div className="min-w-0 flex-1" />;
    return (
      <div className="flex min-w-0 flex-1 items-center justify-center gap-2">
        <Icon name="megaphone" className="size-3.5 shrink-0 text-gold" />
        <SmartLink to={announcement.link || null} className="truncate font-medium text-white hover:text-gold">{announcement.text}</SmartLink>
      </div>
    );
  }
  // Repeat short lists so one loop is wider than the bar; the second copy makes the loop seamless
  const loop = items.length < 4 ? Array.from({ length: Math.ceil(4 / items.length) }, () => items).flat() : items;
  const row = (copy) => (
    <ul className="flex shrink-0 items-center" aria-hidden={copy || undefined}>
      {loop.map((h, i) => {
        const repeat = copy || i >= items.length;
        return (
          <li key={`${h.id}-${i}`} aria-hidden={repeat || undefined} className="flex shrink-0 items-center gap-2 pr-6">
            <HighlightBadge badge={h.badge} />
            <SmartLink to={h.url || null} tabIndex={repeat ? -1 : undefined} className="font-medium whitespace-nowrap text-white transition-colors hover:text-gold">{h.title}</SmartLink>
            <span aria-hidden="true" className="ml-4 size-1 rounded-full bg-white/35" />
          </li>
        );
      })}
    </ul>
  );
  return (
    <div role="region" aria-label="Latest highlights" className="group flex min-w-0 flex-1 items-center gap-2 overflow-hidden">
      <span className="hidden shrink-0 items-center gap-1 rounded-full bg-gold px-2 py-0.5 text-[11px] font-extrabold text-navy sm:flex">
        <Icon name="sparkles" className="size-3" />Highlights
      </span>
      <div className="relative min-w-0 flex-1 overflow-hidden [mask-image:linear-gradient(90deg,transparent,black_4%,black_96%,transparent)]">
        <div className="flex w-max animate-marquee group-focus-within:[animation-play-state:paused] group-hover:[animation-play-state:paused]" style={{ animationDuration: `${Math.max(24, loop.length * 7)}s` }}>
          {row(false)}
          {row(true)}
        </div>
      </div>
    </div>
  );
}

export function TopBar() {
  const { data } = useSite();
  const site = data?.site || {};
  return (
    <div className="relative bg-navy text-[13px] text-white/85 print:hidden">
      <div aria-hidden="true" className="pointer-events-none absolute inset-0 bg-gradient-to-r from-primary/30 via-transparent to-violet/25" />
      <div className="container-x relative flex h-10 items-center gap-2.5 sm:gap-4">
        <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
          {site.phone && (
            <a href={tel(site.phone)} aria-label={`Call ${site.phone}`} className="flex h-8 min-w-8 items-center justify-center gap-1.5 rounded-full bg-white/10 px-2 font-semibold text-white transition-colors hover:bg-white/20 sm:px-2.5">
              <Icon name="phone" className="size-3.5 text-gold" /><span className="hidden sm:inline">{site.phone}</span>
            </a>
          )}
          {site.whatsapp && (
            <a href={waLink(site.whatsapp, DEFAULT_WA_MESSAGE)} target="_blank" rel="noopener noreferrer" aria-label="Chat on WhatsApp"
              className="flex h-8 min-w-8 items-center justify-center gap-1.5 rounded-full bg-[#25D366] px-2 font-semibold text-white shadow-sm transition-colors hover:bg-[#1ebe5b] sm:px-2.5">
              <Icon name="whatsapp" className="size-3.5" /><span className="hidden md:inline">WhatsApp</span>
            </a>
          )}
          {site.email && (
            <a href={`mailto:${site.email}`} className="hidden h-8 items-center gap-1.5 rounded-full px-2 transition-colors hover:bg-white/10 hover:text-white xl:flex">
              <Icon name="mail" className="size-3.5 text-gold" />{site.email}
            </a>
          )}
          {data?.certification && (
            <Link to="/about#iso-certification" title={`${data.certification.standard} certified · Certificate ${data.certification.number}`}
              className="flex h-8 items-center gap-1.5 rounded-full bg-gold/15 px-2.5 font-semibold text-gold ring-1 ring-gold/40 transition-colors hover:bg-gold/25">
              <Icon name="shield" className="size-3.5" /><span className="sm:hidden">ISO Certified</span><span className="hidden sm:inline">{data.certification.standard} Certified</span>
            </Link>
          )}
        </div>
        <Ticker items={data?.highlights || []} announcement={data?.announcement} />
        <div className="hidden shrink-0 items-center gap-3 md:flex">
          <LanguageToggle enabled={site.language_toggle} />
          {data?.header?.show_social !== false && <SocialLinks social={data?.social} size="xs" className="hidden gap-1.5 lg:flex" />}
        </div>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------
// Dropdown panels
// ------------------------------------------------------------------
function CoursesMega({ mega, panelProps, onClose }) {
  const [active, setActive] = useState(mega[0]?.slug);
  const pointer = useRef('mouse');
  const cat = mega.find((c) => c.slug === active) || mega[0];
  const tint = (c) => (/^#[0-9a-f]{6}$/i.test(c.color || '') ? c.color : '#0B63F6');
  return (
    <div {...panelProps} className="absolute top-full left-0 z-50 pt-3">
      <div className="grid w-[640px] max-w-[calc(100vw-2rem)] animate-slide-down grid-cols-[220px_1fr] overflow-hidden rounded-2xl border border-line bg-white shadow-card-hover ring-1 ring-navy/5">
        {/* Sized to its content; only very short windows scroll the list */}
        <ul aria-label="Exam categories" className="scrollbar-thin max-h-[calc(100vh-9rem)] space-y-0.5 overflow-y-auto border-r border-line bg-surface/80 p-2">
          {mega.map((c) => {
            const on = c.slug === cat.slug;
            return (
              <li key={c.slug}>
                <Link data-menu-item to={`/courses?category=${c.slug}`}
                  onPointerDown={(e) => { pointer.current = e.pointerType; }}
                  onPointerEnter={(e) => { if (e.pointerType === 'mouse') setActive(c.slug); }}
                  onKeyDown={() => { pointer.current = 'keyboard'; }}
                  onFocus={() => setActive(c.slug)}
                  onClick={(e) => {
                    // Touch: the first tap previews the category, a second tap opens it
                    if (pointer.current !== 'mouse' && pointer.current !== 'keyboard' && !on) {
                      e.preventDefault();
                      setActive(c.slug);
                      return;
                    }
                    onClose();
                  }}
                  className={'flex items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm font-semibold transition-all ' + (on ? 'bg-white text-primary shadow-sm ring-1 ring-line' : 'text-ink hover:bg-white/70')}>
                  <span className="grid size-8 shrink-0 place-items-center rounded-lg" style={{ backgroundColor: tint(c) + '1A', color: tint(c) }}><Icon name={c.icon} className="size-4" /></span>
                  <span className="min-w-0 flex-1 truncate">{c.name}</span>
                  <span className={'rounded-full px-1.5 text-[11px] font-bold ' + (on ? 'bg-primary text-white' : 'bg-white text-muted ring-1 ring-line')}>{c.count}</span>
                </Link>
              </li>
            );
          })}
        </ul>
        <div className="flex min-w-0 flex-col p-4">
          <div className="mb-3 flex items-center justify-between gap-3">
            <p className="flex min-w-0 items-center gap-2 font-display text-sm font-bold text-navy">
              <span className="size-2 shrink-0 rounded-full" style={{ backgroundColor: tint(cat) }} /><span className="truncate">{cat.name} courses</span>
            </p>
            <Link data-menu-item to={`/courses?category=${cat.slug}`} onClick={onClose} className="shrink-0 text-xs font-bold text-primary hover:underline">View all{cat.count ? ` (${cat.count})` : ''} →</Link>
          </div>
          {cat.courses?.length ? (
            <ul className="grid gap-1">
              {cat.courses.slice(0, 4).map((c) => (
                <li key={c.slug}>
                  <Link data-menu-item to={`/courses/${c.slug}`} onClick={onClose} className="group flex items-center gap-3 rounded-xl p-2 transition-colors hover:bg-surface">
                    <span className="grid size-11 shrink-0 place-items-center rounded-xl text-white shadow-sm transition-transform duration-300 group-hover:scale-105" style={{ background: `linear-gradient(135deg, ${tint(cat)}, color-mix(in srgb, ${tint(cat)} 45%, #08264D))` }}>
                      <Icon name={cat.icon} className="size-5" />
                    </span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-semibold text-ink transition-colors group-hover:text-primary">{c.title}</span>
                      {c.subtitle && <span className="block truncate text-xs text-muted">{c.subtitle}</span>}
                    </span>
                    <span className="shrink-0 text-right">
                      <span className={'block text-sm font-extrabold ' + (c.is_free ? 'text-emerald-600' : 'text-navy')}>{c.is_free ? 'Free' : formatPrice(c.price)}</span>
                      {c.badge && !(c.is_free && /^\s*free\s*$/i.test(c.badge)) && <span className="text-[10px] font-bold tracking-wide text-coral uppercase">{c.badge}</span>}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <div className="grid flex-1 place-items-center rounded-xl bg-surface p-6 text-center">
              <div>
                <p className="text-sm font-semibold text-navy">New {cat.name} courses are coming soon.</p>
                <Link data-menu-item to={`/contact?subject=${encodeURIComponent(`${cat.name} courses`)}`} onClick={onClose} className="mt-2 inline-block text-xs font-bold text-primary hover:underline">Ask us about {cat.name} →</Link>
              </div>
            </div>
          )}
          <div className="mt-auto flex flex-wrap gap-2 border-t border-line pt-3">
            <Link data-menu-item to="/courses" onClick={onClose} className="btn-primary btn-sm">Browse all courses</Link>
            <Link data-menu-item to="/mock-tests" onClick={onClose} className="btn-light btn-sm"><Icon name="clipboard" className="size-3.5" />Free mock tests</Link>
          </div>
        </div>
      </div>
    </div>
  );
}

function MoreMenu({ items, menu }) {
  return (
    <div {...menu.rootProps} className="relative">
      <button type="button" {...menu.triggerProps} className={'flex items-center gap-1 rounded-full py-2 pr-2 pl-3 text-[14px] font-semibold transition-colors ' + (menu.open ? 'bg-primary-soft text-primary' : 'text-ink hover:bg-surface hover:text-primary')}>
        More<Icon name="chevronDown" className={'size-4 transition-transform duration-200 ' + (menu.open ? 'rotate-180' : '')} />
      </button>
      {menu.open && (
        <div {...menu.panelProps} className="absolute top-full right-0 z-50 pt-3">
          <ul className="w-56 animate-slide-down rounded-2xl border border-line bg-white p-2 shadow-card-hover">
            {items.map((n) => (
              <li key={n.to + n.i}>
                <SmartLink data-menu-item to={n.to} onClick={menu.close} className="flex items-center justify-between rounded-xl px-3 py-2.5 text-sm font-semibold text-ink transition-colors hover:bg-surface hover:text-primary">
                  {n.label}<Icon name="chevronRight" className="size-4 text-muted" />
                </SmartLink>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

function NavItem({ n, data, menu }) {
  const jobsMenu = data?.jobs_menu;
  const isJobs = n.kind === 'jobs';
  // Jobs is a plain, highlighted link (owner's choice: no Jobs dropdown); only Courses opens a menu.
  const mega = useMemo(() => liveCategories(data?.mega), [data?.mega]);
  const hasMega = n.kind === 'courses' && mega.length > 0;
  const dropdown = hasMega;
  const hasNew = isJobs && jobsMenu?.latest?.some((a) => a.is_new);
  const external = isExternal(n.to);
  const match = useMatch({ path: external ? '/__external' : n.to.split(/[?#]/)[0], end: !!n.end });
  const active = !external && !!match;

  const label = (
    <>
      {n.label}
      {isJobs && (hasNew ? (
        <span className="rounded-full bg-coral px-1.5 py-px text-[9px] leading-tight font-extrabold tracking-wider text-white uppercase shadow-sm">New</span>
      ) : (
        <span aria-hidden="true" className="relative flex size-2">
          <span className="absolute inline-flex size-full animate-ping rounded-full bg-coral opacity-60" />
          <span className="relative inline-flex size-2 rounded-full bg-coral" />
        </span>
      ))}
    </>
  );
  const linkClass = 'flex items-center gap-1.5 py-2 text-[14px] font-semibold whitespace-nowrap transition-colors ' + (dropdown ? 'pr-0.5 pl-3 ' : 'px-3 ') + (active || menu.open ? 'text-primary' : 'text-ink hover:text-primary');

  return (
    <div {...(dropdown ? menu.rootProps : {})} className="relative">
      <div className={'flex items-center rounded-full transition-colors ' + (active || menu.open ? 'bg-primary-soft' : 'hover:bg-surface')}>
        {external
          ? <SmartLink to={n.to} className={linkClass}>{label}</SmartLink>
          : <NavLink to={n.to} end={n.end} onClick={menu.close} className={linkClass}>{label}</NavLink>}
        {dropdown && (
          <button type="button" {...menu.triggerProps} aria-label={`${n.label} menu`} className="mr-1 grid size-7 place-items-center rounded-full text-muted transition-colors hover:text-primary">
            <Icon name="chevronDown" className={'size-4 transition-transform duration-200 ' + (menu.open ? 'rotate-180 text-primary' : '')} />
          </button>
        )}
      </div>
      {menu.open && hasMega && <CoursesMega mega={mega} panelProps={menu.panelProps} onClose={menu.close} />}
    </div>
  );
}

const NAV_GAP = 2; // gap-0.5

/**
 * Desktop nav with "priority+" overflow: Courses and Jobs always stay in the bar; other items
 * move under "More" from the end when the space left by the logo and the right-hand controls
 * (CTA, helpline, account…) runs out. Widths come from an invisible copy of the items, re-measured
 * on resize and when fonts or labels change.
 */
function DesktopNav({ nav, data, menus }) {
  const items = nav.map((n, i) => ({ ...n, i }));
  const plain = items.filter((n) => !n.kind);
  const navRef = useRef(null);
  const ghostRef = useRef(null);
  const itemsRef = useRef(items);
  itemsRef.current = items;
  const [fit, setFit] = useState(plain.length);

  const measure = useRef(null);
  measure.current = () => {
    const navEl = navRef.current;
    const ghost = ghostRef.current;
    const avail = navEl?.clientWidth;
    if (!avail || !ghost) return; // nav hidden below xl
    const all = itemsRef.current;
    const widths = [...ghost.children].map((li) => li.getBoundingClientRect().width);
    const more = widths[all.length] || 80;
    let used = all.filter((n) => n.kind).reduce((sum, n) => sum + widths[n.i] + NAV_GAP, 0);
    const rest = all.filter((n) => !n.kind);
    let k = 0;
    for (const n of rest) {
      const w = widths[n.i] + NAV_GAP;
      const needMore = k + 1 < rest.length;
      if (used + w + (needMore ? more + NAV_GAP : 0) > avail) break;
      used += w;
      k += 1;
    }
    setFit(k); // no re-render when unchanged
  };
  // Re-check after every render (labels, account controls, CTA or highlights may have changed)…
  useLayoutEffect(() => measure.current());
  // …and when the viewport or the fonts change
  useEffect(() => {
    const run = () => measure.current();
    window.addEventListener('resize', run);
    document.fonts?.ready?.then(run).catch(() => {});
    let ro;
    if (typeof ResizeObserver !== 'undefined') {
      ro = new ResizeObserver(run);
      ro.observe(navRef.current);
      ro.observe(ghostRef.current);
    }
    return () => {
      window.removeEventListener('resize', run);
      ro?.disconnect();
    };
  }, []);

  const inline = new Set([...items.filter((n) => n.kind), ...plain.slice(0, fit)].map((n) => n.i));
  const overflow = items.filter((n) => !inline.has(n.i));
  const ghostLink = 'flex items-center gap-1.5 py-2 text-[14px] font-semibold whitespace-nowrap';
  return (
    <nav ref={navRef} aria-label="Main" className="relative ml-2 hidden min-w-0 flex-1 xl:block">
      <ul className="flex items-center justify-center gap-0.5">
        {items.filter((n) => inline.has(n.i)).map((n) => (
          <li key={n.to + n.i}>
            <NavItem n={n} data={data} menu={menus.bind(`nav-${n.i}`, { hover: true })} />
          </li>
        ))}
        {overflow.length > 0 && <li><MoreMenu items={overflow} menu={menus.bind('more', { hover: true })} /></li>}
      </ul>
      {/* Invisible copy used only to measure item widths */}
      <ul ref={ghostRef} aria-hidden="true" className="pointer-events-none invisible absolute top-0 left-0 flex w-max">
        {items.map((n) => {
          const dropdown = (n.kind === 'courses' && liveCategories(data?.mega).length > 0) || n.kind === 'jobs';
          return (
            <li key={n.to + n.i} className="flex items-center">
              <span className={ghostLink + (dropdown ? ' pr-0.5 pl-3' : ' px-3')}>
                {n.label}{n.kind === 'jobs' && <span className="px-1.5 text-[9px] font-extrabold tracking-wider uppercase">New</span>}
              </span>
              {dropdown && <span className="mr-1 size-7" />}
            </li>
          );
        })}
        <li><span className={ghostLink + ' pr-2 pl-3'}>More<span className="size-4" /></span></li>
      </ul>
    </nav>
  );
}

function HighlightsMenu({ items, menu }) {
  return (
    <div {...menu.rootProps} className="relative hidden lg:block">
      <button type="button" {...menu.triggerProps} aria-label={`Highlights (${items.length})`} title="Highlights"
        className={'relative grid size-10 place-items-center rounded-full border transition-all ' + (menu.open ? 'border-primary bg-primary-soft text-primary' : 'border-line bg-white text-navy hover:border-primary hover:text-primary')}>
        <Icon name="sparkles" className="size-[18px]" />
        <span className="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full bg-coral px-1 text-[10px] leading-5 font-bold text-white ring-2 ring-white">{items.length}</span>
      </button>
      {menu.open && (
        <div {...menu.panelProps} className="absolute top-full right-0 z-50 pt-3">
          <div className="w-80 animate-slide-down overflow-hidden rounded-2xl border border-line bg-white shadow-card-hover">
            <p className="flex items-center gap-2 bg-gradient-to-r from-violet to-primary px-4 py-3 font-display text-sm font-bold text-white">
              <Icon name="sparkles" className="size-4 text-gold" />Highlights &amp; announcements
            </p>
            <ul className="scrollbar-thin max-h-96 overflow-y-auto p-2">
              {items.map((h) => (
                <li key={h.id}>
                  <SmartLink data-menu-item to={h.url || null} onClick={menu.close} className="flex items-start gap-3 rounded-xl p-2.5 transition-colors hover:bg-surface">
                    <HighlightBadge badge={h.badge} className="mt-0.5" />
                    <span className="min-w-0 flex-1 text-sm font-semibold text-ink">{h.title}</span>
                    {h.url && <Icon name="arrowRight" className="mt-0.5 size-4 shrink-0 text-muted" />}
                  </SmartLink>
                </li>
              ))}
            </ul>
          </div>
        </div>
      )}
    </div>
  );
}

function UserMenu({ user, menu }) {
  const { summary, logout } = useAuth();
  const navigate = useNavigate();
  const staff = user.type === 'staff';
  const unread = summary.unread_notifications || 0;
  const items = staff ? [] : [
    ['/dashboard', 'My Learning', 'layoutDashboard'],
    ['/dashboard/courses', 'My Courses', 'graduationCap'],
    ['/dashboard/tests', 'My Tests', 'clipboard'],
    ['/dashboard/purchases', 'Purchases & Invoices', 'receipt'],
    ['/dashboard/wishlist', 'Wishlist', 'heart'],
    ['/dashboard/notifications', 'Notifications', 'bell', unread],
    ['/dashboard/profile', 'My Profile', 'user'],
    ['/dashboard/settings', 'Settings', 'settings'],
  ];
  const row = 'flex w-full items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition-colors';
  return (
    <div className="flex items-center gap-1.5 sm:gap-2">
      {!staff && (
        <Link to="/dashboard/notifications" aria-label={`Notifications${unread ? ` (${unread} unread)` : ''}`}
          className="relative hidden size-10 place-items-center rounded-full border border-line bg-white text-navy transition-colors hover:border-primary hover:text-primary sm:grid">
          <Icon name="bell" className="size-[18px]" />
          {unread > 0 && <span className="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[10px] leading-5 font-bold text-white ring-2 ring-white">{unread > 99 ? '99+' : unread}</span>}
        </Link>
      )}
      <div {...menu.rootProps} className="relative">
        <button type="button" {...menu.triggerProps} aria-label={`Account menu for ${user.name}`}
          className={'relative flex items-center gap-2 rounded-full border bg-white p-0.5 transition-colors sm:py-1 sm:pr-3 sm:pl-1 ' + (menu.open ? 'border-primary' : 'border-line hover:border-primary')}>
          <Avatar user={user} className="size-9 sm:size-8" />
          <span className="hidden max-w-28 truncate text-sm font-semibold text-navy sm:block">{(user.name || '').split(' ')[0]}</span>
          <Icon name="chevronDown" className={'hidden size-4 text-muted transition-transform sm:block ' + (menu.open ? 'rotate-180' : '')} />
          {!staff && unread > 0 && <span aria-hidden="true" className="absolute -top-0.5 -right-0.5 size-3 rounded-full bg-red-600 ring-2 ring-white sm:hidden" />}
        </button>
        {menu.open && (
          <div {...menu.panelProps} className="absolute top-full right-0 z-50 pt-2">
            <div className="w-64 animate-slide-down overflow-hidden rounded-2xl border border-line bg-white shadow-card-hover">
              <div className="flex items-center gap-3 bg-navy-mesh p-4 text-white">
                <Avatar user={user} className="size-11 ring-2 ring-white/40" />
                <div className="min-w-0">
                  <p className="truncate font-display font-bold">{user.name}</p>
                  <p className="truncate text-xs text-white/75">{user.email}</p>
                  {staff && <span className="mt-1 inline-block rounded-full bg-gold px-2 py-px text-[10px] font-extrabold tracking-wide text-navy uppercase">Staff account</span>}
                </div>
              </div>
              <ul className="p-2">
                {staff && (
                  <li>
                    <a data-menu-item href={APP_BASE + 'admin/'} className={row + ' text-ink hover:bg-surface'}>
                      <Icon name="layoutDashboard" className="size-4 text-primary" />Admin panel<Icon name="externalLink" className="ml-auto size-3.5 text-muted" />
                    </a>
                  </li>
                )}
                {items.map(([to, label, icon, count]) => (
                  <li key={to}>
                    <Link data-menu-item to={to} onClick={menu.close} className={row + ' text-ink hover:bg-surface'}>
                      <Icon name={icon} className="size-4 text-primary" />{label}
                      {count > 0 && <span className="ml-auto rounded-full bg-red-600 px-1.5 text-[10px] leading-5 font-bold text-white">{count > 99 ? '99+' : count}</span>}
                    </Link>
                  </li>
                ))}
                <li className="mt-1 border-t border-line pt-1">
                  <button data-menu-item type="button" onClick={async () => { menu.close(); await logout(); navigate('/'); }} className={row + ' text-red-600 hover:bg-red-50'}>
                    <Icon name="logout" className="size-4" />Logout
                  </button>
                </li>
              </ul>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

function Helpline({ site }) {
  const label = site.helpline_label || 'Helpline';
  return (
    <a href={tel(site.phone)} title={`${label}: ${site.phone}`} aria-label={`Call ${label} ${site.phone}`}
      className="group hidden items-center gap-2.5 rounded-full p-0.5 transition-colors hover:bg-emerald-50 xl:flex 2xl:pr-3">
      <span className="relative grid size-10 place-items-center rounded-full bg-emerald-50 text-emerald-600 ring-1 ring-emerald-100 transition-colors group-hover:bg-emerald-500 group-hover:text-white">
        <Icon name="phoneCall" className="size-[18px]" />
        <span aria-hidden="true" className="absolute top-0.5 right-0.5 size-2 rounded-full bg-emerald-500 ring-2 ring-white" />
      </span>
      <span className="hidden leading-tight 2xl:block">
        <span className="block text-[11px] font-medium text-muted">{label}</span>
        <span className="text-sm font-bold whitespace-nowrap text-navy">{site.phone}</span>
      </span>
    </a>
  );
}

// ------------------------------------------------------------------
// Header
// ------------------------------------------------------------------
export function Header({ onSearch, onMenu, menuOpen }) {
  const { user } = useAuth();
  const { data } = useSite();
  const site = data?.site || {};
  const hdr = data?.header || {};
  const nav = navItems(data);
  const menus = useMenuGroup();
  const highlights = data?.highlights || [];
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    const on = () => setScrolled(window.scrollY > 8);
    on();
    window.addEventListener('scroll', on, { passive: true });
    return () => window.removeEventListener('scroll', on);
  }, []);

  return (
    <header className={'sticky top-0 z-40 border-b bg-white/90 backdrop-blur-xl transition-shadow duration-300 print:hidden ' + (scrolled ? 'border-line/80 shadow-[0_10px_30px_-14px_rgb(16_42_84/0.22)]' : 'border-line/60')}>
      <div className="mx-auto flex h-16 w-full max-w-[1440px] items-center 2xl:max-w-[1600px] gap-2 px-4 sm:gap-3 sm:px-6 lg:px-8 xl:h-[72px]">
        <Link to="/" aria-label="Laxyam Academy home" onClick={menus.close} className="shrink-0 rounded-lg"><Logo size="sm" /></Link>
        <DesktopNav nav={nav} data={data} menus={menus} />
        <div className="ml-auto flex shrink-0 items-center gap-1.5 sm:gap-2">
          {highlights.length > 0 && <HighlightsMenu items={highlights} menu={menus.bind('highlights')} />}
          {hdr.show_search !== false && (
            <button type="button" onClick={onSearch} aria-label="Search courses, tests, jobs and more" title="Search (Ctrl + K)"
              className="grid size-10 place-items-center rounded-full border border-line bg-white text-navy transition-all hover:border-primary hover:text-primary hover:shadow-card">
              <Icon name="search" className="size-[18px]" />
            </button>
          )}
          {site.phone && hdr.show_helpline !== false && <Helpline site={site} />}
          {hdr.cta_label && hdr.cta_url && <SmartLink to={hdr.cta_url} className="btn-gold btn-sm hidden h-10 px-4 text-[13px] lg:inline-flex">{hdr.cta_label}</SmartLink>}
          {user ? <UserMenu user={user} menu={menus.bind('account')} /> : (
            <>
              <Link to="/login" className="btn-outline btn-sm hidden h-10 px-4 text-[13px] sm:inline-flex">Login</Link>
              <Link to="/register" className="btn-primary btn-sm hidden h-10 px-4 text-[13px] sm:inline-flex">Sign Up</Link>
            </>
          )}
          <button type="button" onClick={onMenu} aria-label="Open menu" aria-expanded={!!menuOpen} aria-controls="mobile-drawer"
            className="grid size-10 place-items-center rounded-xl text-navy transition-colors hover:bg-surface xl:hidden">
            <Icon name="menu" className="size-6" />
          </button>
        </div>
      </div>
    </header>
  );
}
