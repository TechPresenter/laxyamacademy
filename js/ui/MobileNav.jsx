// Mobile navigation (below xl): off-canvas drawer with icon groups + fixed bottom tab bar.
import { useRef, useState } from 'react';
import { Link, NavLink, useNavigate } from 'react-router-dom';
import { APP_BASE } from '../api.js';
import { useAuth } from '../auth.jsx';
import { Icon, Logo, SmartLink, useSite } from '../components.jsx';
import { Avatar, HighlightBadge, LanguageToggle, liveCategories } from './Header.jsx';
import { useFocusTrap, useScrollLock } from './overlay.js';
import { DEFAULT_WA_MESSAGE, NAV_ICON, tel, waLink } from './shell-data.js';
import { SocialLinks } from './social.jsx';

const TONES = {
  primary: 'bg-primary-soft text-primary',
  coral: 'bg-coral/10 text-coral',
  violet: 'bg-violet/10 text-violet',
  teal: 'bg-teal/10 text-teal',
};

function Row({ to, label, icon, tone, onClose, badge, children, end }) {
  const cls = ({ isActive }) => 'group flex min-w-0 flex-1 items-center gap-3 rounded-xl px-2 py-2 transition-colors ' + (isActive ? 'bg-primary-soft/70' : 'hover:bg-surface');
  const inner = (
    <>
      <span className={'grid size-10 shrink-0 place-items-center rounded-xl transition-transform group-hover:scale-105 ' + TONES[tone]}><Icon name={icon} className="size-5" /></span>
      <span className="min-w-0 flex-1 truncate font-semibold text-ink">{label}</span>
      {badge}
    </>
  );
  return (
    <div className="flex items-center gap-1">
      {/^(https?:|mailto:|tel:)/i.test(to)
        ? <SmartLink to={to} onClick={onClose} className="group flex min-w-0 flex-1 items-center gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-surface">{inner}</SmartLink>
        : <NavLink to={to} end={end} onClick={onClose} className={cls}>{inner}</NavLink>}
      {children}
    </div>
  );
}

function Toggle({ open, onClick, label, controls }) {
  return (
    <button type="button" onClick={onClick} aria-expanded={open} aria-controls={controls} aria-label={label}
      className="grid size-10 shrink-0 place-items-center rounded-xl text-muted transition-colors hover:bg-surface hover:text-primary">
      <Icon name="chevronDown" className={'size-5 transition-transform duration-200 ' + (open ? 'rotate-180 text-primary' : '')} />
    </button>
  );
}

/** Off-canvas menu. Closes on Escape, backdrop tap, any link tap and route change (Layout). */
export function MobileDrawer({ onClose, onSearch }) {
  const { data } = useSite();
  const { user, summary, logout } = useAuth();
  const navigate = useNavigate();
  const panel = useRef(null);
  const [coursesOpen, setCoursesOpen] = useState(false);
  const [highlightsOpen, setHighlightsOpen] = useState(false);
  useScrollLock(true);
  useFocusTrap(panel, true, { onEscape: onClose });

  const site = data?.site || {};
  const hdr = data?.header || {};
  const nav = data?.nav?.length ? data.nav : [];
  const labelFor = (url, fallback) => nav.find((n) => n.url === url)?.label || fallback;
  const highlights = data?.highlights || [];
  const newJobs = (data?.jobs_menu?.latest || []).filter((a) => a.is_new).length;
  const mega = liveCategories(data?.mega); // categories without published courses are not linked

  const groups = [
    { title: 'Learn', tone: 'primary', items: [
      { to: '/courses', label: labelFor('/courses', 'Courses'), courses: true },
      { to: '/mock-tests', label: labelFor('/mock-tests', 'Mock Tests') },
      { to: '/live-classes', label: labelFor('/live-classes', 'Live Classes') },
    ] },
    { title: 'Updates', tone: 'coral', items: [
      { to: '/jobs', label: labelFor('/jobs', 'Govt Jobs'), badge: newJobs > 0 ? `${newJobs} new` : null },
      ...(highlights.length ? [{ highlights: true, label: 'Highlights', icon: 'sparkles' }] : []),
      { to: '/blog', label: labelFor('/blog', 'Blog') },
    ] },
    { title: 'Academy', tone: 'violet', items: [
      { to: '/about', label: labelFor('/about', 'About Us') },
      { to: '/careers', label: labelFor('/careers', 'Careers') },
      { to: '/gallery', label: labelFor('/gallery', 'Gallery'), icon: 'camera' },
      { to: '/contact', label: labelFor('/contact', 'Contact Us') },
      { to: '/faq', label: labelFor('/faq', 'FAQs') },
    ] },
  ];
  // Admin nav items that the groups above do not cover
  const known = new Set(['/', ...groups.flatMap((g) => g.items.map((i) => i.to)).filter(Boolean)]);
  const extra = nav.filter((n) => n.url && !known.has(n.url));
  if (extra.length) groups.push({ title: 'More', tone: 'teal', items: extra.map((n) => ({ to: n.url, label: n.label })) });

  const staff = user?.type === 'staff';
  const unread = summary.unread_notifications || 0;

  return (
    <div className="fixed inset-0 z-[70] xl:hidden">
      <div aria-hidden="true" onClick={onClose} className="absolute inset-0 animate-fade-in bg-navy-deep/55 backdrop-blur-sm" />
      <aside ref={panel} id="mobile-drawer" role="dialog" aria-modal="true" aria-label="Site menu"
        className="absolute inset-y-0 right-0 flex w-[88vw] max-w-sm animate-slide-in-right flex-col bg-white shadow-2xl outline-none" tabIndex={-1}>
        <div className="flex h-16 shrink-0 items-center justify-between gap-3 border-b border-line px-4">
          <Link to="/" onClick={onClose} aria-label="Laxyam Academy home" className="min-w-0"><Logo size="sm" /></Link>
          <div className="flex shrink-0 items-center gap-2">
            {/* Only Hindi switch on phones (the top-bar toggle is hidden below md) */}
            {site.language_toggle && <LanguageToggle enabled variant="light" />}
            <button type="button" onClick={onClose} aria-label="Close menu" className="grid size-10 place-items-center rounded-full bg-surface text-navy transition-colors hover:bg-primary-soft hover:text-primary">
              <Icon name="x" className="size-5" />
            </button>
          </div>
        </div>

        <div className="scrollbar-thin flex-1 space-y-5 overflow-y-auto overscroll-contain px-4 py-4">
          {/* Account */}
          <div className="relative overflow-hidden rounded-2xl bg-navy-mesh p-4 text-white">
            <div aria-hidden="true" className="dot-grid absolute inset-0 opacity-40" />
            {user ? (
              <div className="relative">
                <div className="flex items-center gap-3">
                  <Avatar user={user} className="size-12 ring-2 ring-white/40" />
                  <div className="min-w-0">
                    <p className="truncate font-display font-bold">{user.name}</p>
                    <p className="truncate text-xs text-white/75">{user.email}</p>
                  </div>
                </div>
                <div className="mt-4 grid grid-cols-2 gap-2">
                  {staff ? (
                    <a href={APP_BASE + 'admin/'} className="btn-gold btn-sm"><Icon name="layoutDashboard" className="size-4" />Admin panel</a>
                  ) : (
                    <Link to="/dashboard" onClick={onClose} className="btn-gold btn-sm"><Icon name="layoutDashboard" className="size-4" />My Learning</Link>
                  )}
                  <button type="button" onClick={async () => { onClose(); await logout(); navigate('/'); }} className="btn-ghost-light btn-sm"><Icon name="logout" className="size-4" />Logout</button>
                </div>
                {!staff && (
                  <div className="mt-2 grid grid-cols-3 gap-2 text-center text-[11px] font-semibold">
                    {[['/dashboard/courses', 'Courses', 'graduationCap'], ['/dashboard/tests', 'Tests', 'clipboard'], ['/dashboard/notifications', 'Alerts', 'bell', unread]].map(([to, label, icon, count]) => (
                      <Link key={to} to={to} onClick={onClose} className="relative flex flex-col items-center gap-1 rounded-xl bg-white/10 py-2 transition-colors hover:bg-white/20">
                        <Icon name={icon} className="size-4 text-gold" />{label}
                        {count > 0 && <span className="absolute top-1 right-2 rounded-full bg-red-600 px-1.5 text-[10px] leading-4 font-bold">{count > 99 ? '99+' : count}</span>}
                      </Link>
                    ))}
                  </div>
                )}
              </div>
            ) : (
              <div className="relative">
                <p className="font-display text-lg font-bold">Start learning today</p>
                <p className="mt-1 text-xs text-white/80">One free account for your courses, live classes, mock tests and results.</p>
                <div className="mt-4 grid grid-cols-2 gap-2">
                  <Link to="/login" onClick={onClose} className="btn-ghost-light btn-sm">Login</Link>
                  <Link to="/register" onClick={onClose} className="btn-gold btn-sm">Sign Up</Link>
                </div>
              </div>
            )}
          </div>

          {/* Search shortcut */}
          {hdr.show_search !== false && (
            <button type="button" onClick={onSearch} className="flex w-full items-center gap-3 rounded-2xl border border-line bg-surface px-4 py-3 text-left text-sm text-muted transition-colors hover:border-primary">
              <Icon name="search" className="size-5 text-primary" />Search courses, tests, jobs…
            </button>
          )}

          {groups.map((g) => (
            <section key={g.title} aria-label={g.title}>
              <p className="mb-1.5 px-2 font-display text-[11px] font-bold tracking-[0.16em] text-muted uppercase">{g.title}</p>
              <ul className="space-y-0.5">
                {g.items.map((item) => {
                  if (item.highlights) {
                    return (
                      <li key="highlights">
                        <button type="button" onClick={() => setHighlightsOpen((o) => !o)} aria-expanded={highlightsOpen} aria-controls="drawer-highlights"
                          className="group flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors hover:bg-surface">
                          <span className={'grid size-10 shrink-0 place-items-center rounded-xl ' + TONES[g.tone]}><Icon name="sparkles" className="size-5" /></span>
                          <span className="flex-1 font-semibold text-ink">Highlights</span>
                          <span className="rounded-full bg-coral px-2 text-[11px] leading-5 font-bold text-white">{highlights.length}</span>
                          <Icon name="chevronDown" className={'mx-2.5 size-5 text-muted transition-transform ' + (highlightsOpen ? 'rotate-180 text-primary' : '')} />
                        </button>
                        {highlightsOpen && (
                          <ul id="drawer-highlights" className="mt-1 mb-2 ml-[52px] animate-slide-down space-y-1">
                            {highlights.map((h) => (
                              <li key={h.id}>
                                <SmartLink to={h.url || null} onClick={onClose} className="flex items-start gap-2 rounded-lg px-2 py-1.5 text-sm text-ink hover:bg-surface">
                                  <HighlightBadge badge={h.badge} className="mt-0.5" /><span className="min-w-0">{h.title}</span>
                                </SmartLink>
                              </li>
                            ))}
                          </ul>
                        )}
                      </li>
                    );
                  }
                  const badge = item.badge ? <span className="rounded-full bg-coral px-2 text-[11px] leading-5 font-bold text-white">{item.badge}</span> : null;
                  return (
                    <li key={item.to}>
                      <Row to={item.to} label={item.label} icon={item.icon || NAV_ICON[item.to] || 'arrowRight'} tone={g.tone} onClose={onClose} badge={badge}>
                        {item.courses && mega.length > 0 && <Toggle open={coursesOpen} onClick={() => setCoursesOpen((o) => !o)} label="Show course categories" controls="drawer-categories" />}
                      </Row>
                      {item.courses && coursesOpen && (
                        <ul id="drawer-categories" className="mt-1 mb-2 grid animate-slide-down grid-cols-2 gap-1.5 pl-2">
                          {mega.map((c) => (
                            <li key={c.slug}>
                              <Link to={`/courses?category=${c.slug}`} onClick={onClose} className="flex items-center gap-2 rounded-xl border border-line px-2.5 py-2 text-[13px] font-semibold text-ink transition-colors hover:border-primary">
                                <span className="grid size-7 shrink-0 place-items-center rounded-lg" style={{ backgroundColor: (c.color || '#0B63F6') + '1A', color: c.color || '#0B63F6' }}><Icon name={c.icon} className="size-3.5" /></span>
                                <span className="min-w-0 flex-1 truncate">{c.name}</span>
                                <span className="text-[11px] text-muted">{c.count}</span>
                              </Link>
                            </li>
                          ))}
                          <li className="col-span-2"><Link to="/courses" onClick={onClose} className="flex items-center justify-center gap-1.5 rounded-xl bg-primary-soft px-3 py-2 text-[13px] font-bold text-primary">All courses <Icon name="arrowRight" className="size-4" /></Link></li>
                        </ul>
                      )}
                    </li>
                  );
                })}
              </ul>
            </section>
          ))}

          {hdr.cta_label && hdr.cta_url && <SmartLink to={hdr.cta_url} onClick={onClose} className="btn-gold w-full">{hdr.cta_label}</SmartLink>}
        </div>

        <div className="shrink-0 space-y-3 border-t border-line bg-surface/70 px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
          {(site.phone || site.whatsapp) && (
            <div className="grid grid-cols-2 gap-2">
              {site.phone && <a href={tel(site.phone)} className="btn-primary btn-sm h-11"><Icon name="phone" className="size-4" />Call us</a>}
              {site.whatsapp && <a href={waLink(site.whatsapp, DEFAULT_WA_MESSAGE)} target="_blank" rel="noopener noreferrer" className="btn-whatsapp btn-sm h-11"><Icon name="whatsapp" className="size-4" />WhatsApp</a>}
            </div>
          )}
          {/* Six size-8 icons need ~232px, so they stay on one row even in a 320px-wide drawer */}
          <SocialLinks social={data?.social} size="sm" className="justify-center" />
        </div>
      </aside>
    </div>
  );
}
