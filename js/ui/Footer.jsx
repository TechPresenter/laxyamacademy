// Site footer: newsletter CTA band + helpline card, link columns (accordions on phones),
// app badges, policies and the copyright bar (company legal name only - owner's choice).
import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { APP_BASE } from '../api.js';
import { Icon, Logo, SmartLink, useSite } from '../components.jsx';
import { NewsletterConsent, useNewsletterSignup } from '../home/newsletter.jsx';
import { liveCategories } from './Header.jsx';
import { useMediaQuery } from './overlay.js';
import { COMPANY_FALLBACK, DEFAULT_WA_MESSAGE, tel, waLink } from './shell-data.js';
import { SocialLinks } from './social.jsx';

const POLICIES = [
  ['/privacy-policy', 'Privacy Policy'], ['/terms', 'Terms & Conditions'], ['/refund-policy', 'Refund Policy'],
  ['/cancellation-policy', 'Cancellation Policy'], ['/disclaimer', 'Disclaimer'],
  ['/cookie-policy', 'Cookie Policy'], ['/faq', 'FAQs'],
];
const DEFAULT_LINKS = [
  ['/about', 'About Us'], ['/courses', 'All Courses'], ['/mock-tests', 'Mock Tests'], ['/live-classes', 'Live Classes'], ['/resources', 'Free Resources'],
  ['/jobs', 'Latest Govt Jobs'], ['/careers', 'Careers'], ['/blog', 'Blog'], ['/gallery', 'Gallery'], ['/contact', 'Contact Us'],
];
const NEWSLETTER_DEFAULT = {
  title: 'Never miss an exam update',
  text: 'Get recruitment notifications, admit card and result alerts, current affairs and preparation tips in your inbox.',
  button_label: 'Subscribe',
};
/** Footer sign-up: consent is asked explicitly only when the server says the address unsubscribed before. */
function Newsletter({ buttonLabel }) {
  const [email, setEmail] = useState('');
  const [hp, setHp] = useState('');
  const { state, submit: signup, consentNeeded, consent, setConsent } = useNewsletterSignup('footer');
  const submit = async (e) => {
    e.preventDefault();
    if (await signup({ email, website: hp })) setEmail('');
  };
  if (state.status === 'ok') {
    return (
      <div role="status" className="flex items-center gap-3 rounded-2xl bg-white/15 p-4 text-white ring-1 ring-white/25">
        <span className="grid size-10 shrink-0 animate-pop place-items-center rounded-full bg-emerald-500"><Icon name="check" className="size-5" /></span>
        <p className="text-sm font-semibold">{state.message}</p>
      </div>
    );
  }
  const busy = state.status === 'busy';
  const error = state.status === 'error' && !state.fields.consent ? (state.fields.email || state.message) : '';
  return (
    <form onSubmit={submit} noValidate className="w-full">
      <label htmlFor="nl-email" className="sr-only">Email address</label>
      <div className="flex flex-col gap-2 rounded-2xl bg-white p-1.5 shadow-lg sm:flex-row">
        <div className="flex min-w-0 flex-1 items-center gap-2 px-3">
          <Icon name="mail" className="size-5 shrink-0 text-muted" />
          <input id="nl-email" type="email" inputMode="email" autoComplete="email" required value={email}
            onChange={(e) => setEmail(e.target.value)}
            aria-invalid={!!error} aria-describedby={error ? 'nl-error' : 'nl-consent'}
            placeholder="Your email address" className="h-11 w-0 min-w-0 flex-1 bg-transparent text-sm text-ink placeholder:text-muted/80 focus:outline-none" />
        </div>
        <input type="text" name="website" value={hp} onChange={(e) => setHp(e.target.value)} tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />
        <button type="submit" disabled={busy} className="btn-dark h-11 shrink-0">
          {busy ? <span className="size-4 animate-spin rounded-full border-2 border-white border-t-transparent" aria-hidden="true" /> : <Icon name="send" className="size-4" />}
          {busy ? 'Subscribing…' : buttonLabel}
        </button>
      </div>
      {error && <p id="nl-error" role="alert" className="mt-2 text-sm font-semibold text-red-100">{error}</p>}
      {consentNeeded && <div className="mt-3"><NewsletterConsent id="nl-reconsent" checked={consent} onChange={setConsent} error={state.fields.consent} /></div>}
      <p id="nl-consent" className="mt-2 text-xs leading-relaxed text-white/80">
        By subscribing you agree to receive emails from Laxyam Academy. Unsubscribe anytime. <Link to="/privacy-policy" className="font-semibold text-white underline underline-offset-2">Privacy Policy</Link>
      </p>
    </form>
  );
}

/** Link column: a plain list on tablets/desktop, an accordion on phones. */
function Column({ title, children, wide }) {
  return (
    <details open={wide || undefined} className="group border-b border-white/10 md:border-0">
      <summary onClick={(e) => { if (wide) e.preventDefault(); }} tabIndex={wide ? -1 : undefined}
        className="flex cursor-pointer list-none items-center justify-between py-4 font-display text-base font-bold text-white md:mb-4 md:cursor-default md:py-0 [&::-webkit-details-marker]:hidden">
        <span className="after:mt-2 after:block after:h-0.5 after:w-8 after:rounded after:bg-gold max-md:after:hidden">{title}</span>
        <Icon name="chevronDown" className="size-5 text-white/60 transition-transform group-open:rotate-180 md:hidden" />
      </summary>
      <div className="pb-4 md:pb-0">{children}</div>
    </details>
  );
}

const linkCls = 'inline-flex items-center gap-1.5 transition-all hover:translate-x-0.5 hover:text-white';

export default function Footer() {
  const { data } = useSite();
  const site = data?.site || {};
  const wide = useMediaQuery('(min-width: 768px)');
  const year = new Date().getFullYear();
  const nl = { ...NEWSLETTER_DEFAULT, ...(data?.newsletter || {}) };
  const company = data?.company || {};
  const legal = company.legal_name || COMPANY_FALLBACK.legal_name;
  const bar = useRef(null);

  // Floating buttons step aside while the copyright bar is on screen (see .fab-tuck in style.css)
  useEffect(() => {
    const el = bar.current;
    if (!el || typeof IntersectionObserver === 'undefined') return undefined;
    const io = new IntersectionObserver(([entry]) => document.body.classList.toggle('fabs-tucked', entry.isIntersecting), { threshold: 0.15 });
    io.observe(el);
    return () => { io.disconnect(); document.body.classList.remove('fabs-tucked'); };
  }, []);

  const links = data?.footer_links?.length ? data.footer_links.map((l) => [l.url, l.label]) : DEFAULT_LINKS;
  const exams = liveCategories(data?.categories); // only exams that have published courses
  const contactIcon = 'grid size-9 shrink-0 place-items-center rounded-xl text-white shadow-sm';

  return (
    <footer className="relative mt-16 bg-navy-mesh text-white/75 print:hidden">
      {/* Newsletter CTA band (hidden by .page-has-newsletter while a page shows its own sign-up block, e.g. home) */}
      <div className="footer-nl-band container-x relative -translate-y-10">
        <div className="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br from-primary via-[#2b6dff] to-violet p-6 shadow-glow sm:p-8 lg:p-10">
          <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-50" />
          <div aria-hidden="true" className="absolute -top-20 -right-16 -z-10 size-64 rounded-full bg-gold/30 blur-3xl" />
          <div className="grid items-center gap-6 lg:grid-cols-[1.15fr_1fr_auto] lg:gap-8">
            <div>
              <span className="eyebrow bg-white/15! text-white!"><Icon name="bell" className="size-3.5" />Exam alerts</span>
              <h2 className="mt-3 font-display text-2xl leading-tight font-extrabold text-white sm:text-3xl">{nl.title}</h2>
              {nl.text && <p className="mt-2 text-sm leading-relaxed text-white/85 sm:text-base">{nl.text}</p>}
            </div>
            <Newsletter buttonLabel={nl.button_label || 'Subscribe'} />
            {site.phone && (
              <a href={tel(site.phone)} className="group flex items-center gap-3 rounded-2xl bg-white/12 px-4 py-3 text-white ring-1 ring-white/25 backdrop-blur transition-all hover:-translate-y-0.5 hover:bg-white/20">
                <span className="grid size-12 shrink-0 animate-pulse-ring place-items-center rounded-full bg-gold text-gold"><Icon name="phoneCall" className="size-5 text-navy" /></span>
                <span className="min-w-0">
                  <span className="block text-xs font-medium text-white/80">{site.helpline_label || 'Helpline'}</span>
                  <strong className="block font-display text-lg whitespace-nowrap">{site.phone}</strong>
                  {site.office_hours && <span className="block text-[11px] text-white/70">{site.office_hours}</span>}
                </span>
              </a>
            )}
          </div>
        </div>
      </div>

      <div className="footer-cols container-x -mt-2 grid gap-x-8 pb-10 md:grid-cols-2 md:gap-y-10 lg:grid-cols-[1.35fr_1fr_0.8fr_1.25fr_1.5fr]">
        <div className="pb-6 md:pb-0">
          <Link to="/" aria-label="Laxyam Academy home"><Logo variant="light" /></Link>
          <p className="mt-3 text-xs font-semibold tracking-wide text-white/70">CIN: {company.cin || COMPANY_FALLBACK.cin}</p>
          {data?.footer?.description && <p className="mt-5 max-w-sm text-sm leading-relaxed">{data.footer.description}</p>}
          {data?.social?.some((s) => s.url) && (
            <>
              <p className="mt-5 mb-2.5 text-sm font-semibold text-white">Follow us</p>
              <SocialLinks social={data.social} size="xs" />
            </>
          )}
          {(site.app_android_url || site.app_ios_url) && (
            <div className="mt-5 flex flex-wrap gap-2">
              {site.app_android_url && (
                <a href={site.app_android_url} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 rounded-xl bg-black px-3 py-2 text-xs text-white ring-1 ring-white/20 transition-transform hover:-translate-y-0.5">
                  <Icon name="googlePlay" className="size-5" /><span className="leading-tight"><span className="block text-[10px] opacity-75">GET IT ON</span><strong className="text-sm">Google Play</strong></span>
                </a>
              )}
              {site.app_ios_url && (
                <a href={site.app_ios_url} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 rounded-xl bg-black px-3 py-2 text-xs text-white ring-1 ring-white/20 transition-transform hover:-translate-y-0.5">
                  <Icon name="apple" className="size-5" /><span className="leading-tight"><span className="block text-[10px] opacity-75">Download on the</span><strong className="text-sm">App Store</strong></span>
                </a>
              )}
            </div>
          )}
        </div>

        <Column title="Quick Links" wide={wide}>
          <ul className="space-y-2.5 text-sm">
            {/* Free Resources lives here (not in the header), highlighted in gold - owner's choice */}
            <li>
              <Link to="/resources" className="group inline-flex items-center gap-1.5 rounded-full bg-gold/15 py-1 pr-2.5 pl-1 font-bold whitespace-nowrap text-gold ring-1 ring-gold/40 transition-all hover:-translate-y-0.5 hover:bg-gold hover:text-navy">
                <span className="grid size-6 place-items-center rounded-full bg-gold text-navy"><Icon name="book" className="size-3.5" /></span>
                Free Resources
                <span className="rounded-full bg-coral px-1.5 py-px text-[9px] font-extrabold tracking-wider text-white uppercase">Free</span>
              </Link>
            </li>
            {links.filter(([to]) => to !== '/resources').map(([to, label]) => <li key={to + label}><SmartLink to={to} className={linkCls}>{label}</SmartLink></li>)}
          </ul>
        </Column>
        {exams.length > 0 && (
          <Column title="Exams" wide={wide}>
            <ul className="space-y-2.5 text-sm">
              {exams.map((c) => <li key={c.slug}><Link to={`/courses?category=${c.slug}`} className={linkCls}>{c.name}</Link></li>)}
            </ul>
          </Column>
        )}
        {data?.footer_courses?.length > 0 && (
          <Column title="Popular Courses" wide={wide}>
            <ul className="space-y-2.5 text-sm">
              {data.footer_courses.map((c) => <li key={c.slug}><Link to={`/courses/${c.slug}`} className={linkCls}>{c.title}</Link></li>)}
            </ul>
          </Column>
        )}

        <div className="pt-6 md:pt-0">
          <h2 className="mb-4 font-display text-base font-bold text-white after:mt-2 after:block after:h-0.5 after:w-8 after:rounded after:bg-gold">Contact Us</h2>
          <ul className="space-y-3.5 text-sm">
            {site.phone && (
              <li><a href={tel(site.phone)} className="group flex items-center gap-3 hover:text-white"><span className={contactIcon + ' bg-primary'}><Icon name="phone" className="size-4" /></span><span><span className="block text-[11px] text-white/55">Call us</span><strong className="text-white">{site.phone}</strong></span></a></li>
            )}
            {site.whatsapp && (
              <li><a href={waLink(site.whatsapp, DEFAULT_WA_MESSAGE)} target="_blank" rel="noopener noreferrer" className="flex items-center gap-3 hover:text-white"><span className={contactIcon + ' bg-[#25D366]'}><Icon name="whatsapp" className="size-4" /></span><span><span className="block text-[11px] text-white/55">WhatsApp</span><strong className="text-white">Chat with us</strong></span></a></li>
            )}
            {site.email && (
              <li><a href={`mailto:${site.email}`} className="flex items-center gap-3 hover:text-white"><span className={contactIcon + ' bg-coral'}><Icon name="mail" className="size-4" /></span><span className="min-w-0"><span className="block text-[11px] text-white/55">Email</span><strong className="block text-white [overflow-wrap:anywhere] xl:whitespace-nowrap">{site.email}</strong></span></a></li>
            )}
            {site.address && (
              <li className="flex items-start gap-3">
                <span className={contactIcon + ' bg-gold text-navy!'}><Icon name="mapPin" className="size-4" /></span>
                <span>
                  <span className="block text-[11px] text-white/55">Visit us</span>
                  <span className="text-white/85">{site.address}</span>
                  <a href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(site.map_query || site.address)}`} target="_blank" rel="noopener noreferrer" className="mt-1 flex items-center gap-1 text-xs font-bold text-gold hover:underline">
                    Get directions <Icon name="externalLink" className="size-3" />
                  </a>
                </span>
              </li>
            )}
            {site.office_hours && <li className="flex items-center gap-3"><span className={contactIcon + ' bg-white/10'}><Icon name="clock" className="size-4" /></span><span>{site.office_hours}</span></li>}
          </ul>
        </div>
      </div>

      <div className="border-t border-white/10">
        <ul className="container-x grid grid-cols-2 gap-x-4 gap-y-2.5 py-5 text-xs sm:flex sm:flex-wrap sm:justify-center sm:gap-x-6">
          {POLICIES.map(([to, label]) => <li key={to}><Link to={to} className="transition-colors hover:text-white">{label}</Link></li>)}
          <li><a href={`${APP_BASE}sitemap.xml`} className="transition-colors hover:text-white">Sitemap</a></li>
        </ul>
      </div>

      <div ref={bar} className="border-t border-white/10 bg-navy-deep/70">
        <div className="container-x py-5 text-center text-xs leading-relaxed">
          <p className="text-white/85">© {year} {legal}. All rights reserved.</p>
        </div>
      </div>
    </footer>
  );
}
