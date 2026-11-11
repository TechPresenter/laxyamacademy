// Homepage. One request (cms.php?action=home) feeds every block; blocks render in the admin's
// order (data.order, hidden blocks omitted) and fade in on scroll. No block shows invented data:
// a block without items is not rendered.
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiUrl, formatDate, formatDateTime } from '../js/api.js';
import {
  CourseCard, CourseCardSkeleton, DemoBadge, EnquiryForm, ErrorState, Icon, Reveal, SectionHeading, SmartLink, Stars,
  useApi, useCmsSeo, useSite, ViewAll,
} from '../js/components.jsx';
import Hero from '../js/home/Hero.jsx';
import { YouTubeSection, InstagramSection } from '../js/home/media.jsx';
import { NewsletterConsent, useNewsletterSignup } from '../js/home/newsletter.jsx';

/** Order used when the API sends no `order` (older backend). */
const DEFAULT_ORDER = [
  'hero', 'features', 'courses', 'categories', 'why_choose', 'stats', 'live_classes', 'job_alerts',
  'resources', 'success_stories', 'testimonials', 'videos_youtube', 'videos_instagram', 'newsletter', 'cta',
];

export default function Home() {
  const { data: site } = useSite();
  useCmsSeo('/', { title: site?.site?.default_title, description: site?.site?.default_description });
  const { data, loading, error, reload } = useApi('cms.php', { action: 'home' });

  const keys = useMemo(() => {
    const list = Array.isArray(data?.order) && data.order.length ? [...data.order] : [...DEFAULT_ORDER];
    // Promotional banners are not a CMS block: show them after the course grid (or after the hero)
    const at = list.indexOf('courses') >= 0 ? list.indexOf('courses') + 1 : list.indexOf('hero') + 1;
    list.splice(at, 0, 'banners');
    return list;
  }, [data]);

  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (!data) {
    return (
      <>
        <Hero hero={null} loading={loading} />
        <CourseGrid block={null} loading />
      </>
    );
  }

  const topCats = data.categories?.items || [];
  const render = (key, prev) => {
    switch (key) {
      case 'hero': return <Hero hero={data.hero} categories={topCats} features={data.features?.items || []} />;
      case 'features': return <FeatureStrip features={data.features} overlap={prev === 'hero' && !!data.hero} />;
      case 'courses': return <CourseGrid block={data.courses} />;
      case 'banners': return <PromoBanners items={data.banners} />;
      case 'categories': return <CategoryGrid block={data.categories} />;
      case 'why_choose': return <WhyChoose block={data.why_choose} />;
      case 'stats': return <StatsBand block={data.stats} />;
      case 'live_classes': return <LiveClasses block={data.live_classes} />;
      case 'job_alerts': return <JobAlerts items={data.job_alerts} heading={data.job_alerts_heading} />;
      case 'resources': return <Resources block={data.resources} />;
      case 'success_stories': return <SuccessStories block={data.success_stories} />;
      case 'testimonials': return <Testimonials block={data.testimonials} />;
      case 'videos_youtube': return <YouTubeSection block={data.videos?.youtube} className="section-y bg-surface" />;
      case 'videos_instagram': return <InstagramSection block={data.videos?.instagram} />;
      case 'newsletter': return <NewsletterBand block={data.newsletter} />;
      case 'cta': return <CtaBand cta={data.cta} features={data.features?.items || []} />;
      default: return null;
    }
  };

  return (
    <>
      {keys.map((key, i) => {
        const node = render(key, keys[i - 1]);
        if (!node) return null;
        // The hero animates itself on load; every other block reveals on scroll.
        return key === 'hero' ? <div key={key}>{node}</div> : <Reveal key={key}>{node}</Reveal>;
      })}
    </>
  );
}

// ------------------------------------------------------------------
// Feature strip (floats over the hero's bottom edge when it follows the hero)
// ------------------------------------------------------------------
const TINTS = ['#0B63F6', '#6D4AFF', '#0EA5A4', '#F04E23', '#FFB020', '#16A34A'];

function FeatureStrip({ features, overlap }) {
  const items = features?.items?.filter((f) => f?.title) || [];
  if (!items.length) return null;
  return (
    <section aria-label="Highlights" className={'relative z-10 ' + (overlap ? '-mt-8 sm:-mt-10' : 'pt-10')}>
      <div className="container-x">
        <ul className={'grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-line bg-line shadow-card-hover ' + (['', 'lg:grid-cols-1', 'lg:grid-cols-2', 'lg:grid-cols-3', 'lg:grid-cols-4'][Math.min(items.length, 4)])}>
          {items.slice(0, 4).map((f, i) => (
            <li key={f.title} className="group flex flex-col gap-3 bg-white p-4 transition-colors hover:bg-surface max-lg:odd:last:col-span-2 sm:flex-row sm:items-center sm:p-5">
              <span className="grid size-11 shrink-0 place-items-center rounded-xl transition-transform duration-300 group-hover:scale-110 sm:size-12"
                style={{ backgroundColor: TINTS[i % TINTS.length] + '14', color: TINTS[i % TINTS.length] }}>
                <Icon name={f.icon} className="size-5 sm:size-6" />
              </span>
              <span className="min-w-0">
                <span className="block font-display text-[15px] leading-tight font-bold text-navy">{f.title}</span>
                {f.text && <span className="mt-0.5 block text-xs leading-snug text-muted sm:text-sm">{f.text}</span>}
              </span>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

// ------------------------------------------------------------------
// Course grid with category tabs (snap-scroll row on phones, grid from sm)
// ------------------------------------------------------------------
function CourseGrid({ block, loading }) {
  const items = block?.items || [];
  const [tab, setTab] = useState('all');
  const tabs = useMemo(() => {
    const seen = new Map();
    items.forEach((c) => {
      const cat = c.category;
      if (!cat?.slug) return;
      const row = seen.get(cat.slug) || { ...cat, n: 0 };
      row.n += 1;
      seen.set(cat.slug, row);
    });
    return [...seen.values()].sort((a, b) => b.n - a.n).slice(0, 5);
  }, [items]);
  if (!loading && !items.length) return null;

  const shown = tab === 'all' ? items : items.filter((c) => c.category?.slug === tab);
  const viewAll = tab === 'all' ? '/courses' : `/courses?category=${encodeURIComponent(tab)}`;
  return (
    <section className="section-y">
      <div className="container-x">
        <SectionHeading eyebrow={<><Icon name="graduationCap" className="size-3.5" />Courses</>} title={block?.heading?.title || 'Popular courses'} subtitle={block?.heading?.subtitle}
          action={<ViewAll to={viewAll}>View all courses</ViewAll>} />
        {tabs.length > 1 && (
          <div role="group" aria-label="Filter courses by exam" className="scrollbar-none -mx-4 mb-6 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
            {[{ slug: 'all', name: 'All courses', n: items.length }, ...tabs].map((t) => {
              const active = tab === t.slug;
              return (
                <button key={t.slug} type="button" aria-pressed={active} onClick={() => setTab(t.slug)}
                  className={'inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition-all ' + (active ? 'bg-navy text-white shadow-card-hover' : 'border border-line bg-white text-ink hover:-translate-y-0.5 hover:border-primary hover:text-primary')}>
                  {t.slug !== 'all' && <span aria-hidden="true" className="size-2 rounded-full" style={{ backgroundColor: t.color || '#0B63F6' }} />}
                  {t.name}
                  <span className={'rounded-full px-1.5 text-[11px] ' + (active ? 'bg-white/20' : 'bg-surface text-muted')}>{t.n}</span>
                </button>
              );
            })}
          </div>
        )}
        <ul aria-live="polite" className="scrollbar-none -mx-4 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto px-4 pb-3 sm:mx-0 sm:scroll-px-0 sm:grid sm:grid-cols-2 sm:gap-6 sm:overflow-visible sm:px-0 sm:pb-0 lg:grid-cols-4">
          {(loading ? Array.from({ length: 4 }, () => null) : shown).map((c, i) => (
            <li key={c?.id || `s${i}`} className="w-[84%] shrink-0 snap-start sm:w-auto sm:min-w-0">
              {c ? <CourseCard course={c} /> : <CourseCardSkeleton />}
            </li>
          ))}
        </ul>
        {!loading && shown.length > 1 && <p className="mt-2 text-center text-xs text-muted sm:hidden">Swipe to see more →</p>}
      </div>
    </section>
  );
}

// ------------------------------------------------------------------
// Promotional banners (admin → Banners, placement home)
// ------------------------------------------------------------------
const BANNER_THEME = {
  blue: 'from-primary via-[#2f7bff] to-[#5b9bff] text-white',
  navy: 'from-navy-deep via-navy to-[#0B3A7A] text-white',
  gold: 'from-gold via-[#ffd659] to-[#ffb020] text-navy',
  violet: 'from-violet via-[#7d5cff] to-[#9f86ff] text-white',
  green: 'from-teal via-[#0f9b9a] to-emerald-600 text-white',
  red: 'from-coral via-[#f2643d] to-red-600 text-white',
};

/** Count a banner click (GET beacon; failures are irrelevant to the visitor). */
function trackBanner(id) {
  try {
    fetch(apiUrl('cms.php', { action: 'banner-click', id }), { credentials: 'same-origin', keepalive: true }).catch(() => {});
  } catch { /* ignore */ }
}

function PromoBanners({ items }) {
  const list = (items || []).filter((b) => b?.title);
  if (!list.length) return null;
  return (
    <section aria-label="Offers and announcements" className="pb-14 sm:pb-20">
      <ul className={'container-x grid gap-5 ' + (list.length > 1 ? 'md:grid-cols-2' : '')}>
        {list.map((b, i) => {
          const theme = BANNER_THEME[b.theme] || BANNER_THEME.blue;
          const dark = b.theme !== 'gold';
          const wide = list.length === 1 || (list.length % 2 === 1 && i === 0);
          return (
            <li key={b.id} className={'min-w-0 ' + (wide && list.length > 1 ? 'md:col-span-2' : '')}>
              <div className={'relative isolate flex h-full flex-col gap-5 overflow-hidden rounded-3xl bg-gradient-to-br p-6 shadow-card-hover sm:flex-row sm:items-center sm:p-8 ' + theme}>
                <div aria-hidden="true" className={'absolute inset-0 -z-10 opacity-50 ' + (dark ? 'dot-grid' : 'dot-grid-dark')} />
                <div aria-hidden="true" className="absolute -top-20 -right-16 -z-10 size-64 rounded-full bg-white/15 blur-2xl" />
                <div className="min-w-0 flex-1">
                  <p className={'font-display text-xl leading-tight font-extrabold sm:text-2xl ' + (wide ? 'lg:text-3xl' : '')}>{b.title}</p>
                  {b.subtitle && <p className={'mt-2 max-w-xl text-sm leading-relaxed sm:text-[15px] ' + (dark ? 'text-white/85' : 'text-navy/80')}>{b.subtitle}</p>}
                  {b.cta_label && b.cta_url && (
                    <SmartLink to={b.cta_url} onClick={() => trackBanner(b.id)} className={(dark ? 'btn-light' : 'btn-dark') + ' mt-5'}>
                      {b.cta_label} <Icon name="arrowRight" className="size-4" />
                    </SmartLink>
                  )}
                </div>
                {b.image_url && (
                  <img src={b.image_url} alt="" loading="lazy" className={'w-full shrink-0 rounded-2xl object-cover shadow-lg sm:w-48 ' + (wide ? 'lg:w-72' : '') + ' max-h-56'} />
                )}
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}

// ------------------------------------------------------------------
// Exam categories (top level, with course counts)
// ------------------------------------------------------------------
function CategoryGrid({ block }) {
  const items = (block?.items || []).filter((c) => !c.parent_id && Number(c.count ?? c.course_count ?? 0) > 0);
  if (!items.length) return null;
  return (
    <section className="section-y relative overflow-hidden bg-surface">
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 [mask-image:linear-gradient(to_bottom,black,transparent_70%)]" />
      <div className="container-x relative">
        <SectionHeading eyebrow={<><Icon name="target" className="size-3.5" />Exams</>} title={block.heading?.title || 'Exam categories'} subtitle={block.heading?.subtitle}
          action={<ViewAll to="/categories">View all categories</ViewAll>} />
        {/* Compact cards: icon beside the text (stacked on small phones), count under the name */}
        <ul className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4">
          {items.slice(0, 8).map((c, i) => {
            const n = Number(c.count ?? c.course_count ?? 0);
            const color = c.color || '#0B63F6';
            return (
              <Reveal as="li" key={c.slug} delay={(i % 4) * 60} className="min-w-0">
                <Link to={`/courses?category=${encodeURIComponent(c.slug)}`}
                  className="group relative flex h-full flex-col gap-3 overflow-hidden rounded-2xl border border-line bg-white p-3.5 shadow-card transition-all duration-300 hover:-translate-y-0.5 hover:border-[color-mix(in_srgb,var(--cat)_35%,transparent)] hover:shadow-card-hover sm:flex-row sm:items-start sm:p-4"
                  style={{ '--cat': color }}>
                  <span className="grid size-10 shrink-0 place-items-center rounded-xl text-white shadow-sm transition-transform duration-300 group-hover:scale-105 sm:size-11"
                    style={{ background: `linear-gradient(135deg, ${color}, color-mix(in srgb, ${color} 55%, #08264D))` }}>
                    <Icon name={c.icon} className="size-5" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="flex items-start justify-between gap-2">
                      <span className="line-clamp-2 font-display text-[15px] leading-snug font-extrabold text-navy">{c.name}</span>
                      <Icon name="arrowRight" className="mt-0.5 hidden size-4 shrink-0 text-muted transition-all group-hover:translate-x-0.5 group-hover:text-primary sm:block" />
                    </span>
                    {/* The wrapper hides it on phones: a display class on the clamped span itself would undo line-clamp */}
                    {c.description && <span className="mt-0.5 hidden sm:block"><span className="line-clamp-2 text-xs leading-snug text-muted">{c.description}</span></span>}
                    <span className="mt-2 inline-block rounded-full px-2 py-px text-[11px] font-bold" style={{ backgroundColor: color + '1A', color }}>{n} {n === 1 ? 'course' : 'courses'}</span>
                  </span>
                </Link>
              </Reveal>
            );
          })}
        </ul>
      </div>
    </section>
  );
}

// ------------------------------------------------------------------
// Why choose us
// ------------------------------------------------------------------
function WhyChoose({ block }) {
  const items = block?.items?.filter((i) => i?.title) || [];
  if (!items.length) return null;
  return (
    <section className="section-y">
      <div className="container-x">
        <SectionHeading align="center" eyebrow={<><Icon name="sparkles" className="size-3.5" />Why us</>} title={block.title} highlight={block.highlight} subtitle={block.subtitle} />
        {/* Icon beside the text keeps each point to two or three lines */}
        <ul className="grid gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-3">
          {items.map((item, i) => {
            const tint = TINTS[i % TINTS.length];
            return (
              <Reveal as="li" key={item.title} delay={(i % 3) * 80} className="min-w-0">
                <div className="group card card-hover relative flex h-full items-start gap-4 overflow-hidden p-4 sm:p-5">
                  <span aria-hidden="true" className="absolute inset-x-0 top-0 h-0.5 origin-left scale-x-0 transition-transform duration-500 group-hover:scale-x-100" style={{ background: `linear-gradient(90deg, ${tint}, #6D4AFF)` }} />
                  <span className="grid size-11 shrink-0 place-items-center rounded-xl transition-transform duration-300 group-hover:scale-105" style={{ backgroundColor: tint + '14', color: tint }}>
                    <Icon name={item.icon} className="size-5" />
                  </span>
                  <span className="min-w-0">
                    <h3 className="font-display text-base leading-snug font-bold text-navy">{item.title}</h3>
                    {item.text && <p className="mt-1 text-sm leading-relaxed text-muted">{item.text}</p>}
                  </span>
                </div>
              </Reveal>
            );
          })}
        </ul>
      </div>
    </section>
  );
}

// ------------------------------------------------------------------
// Stats band (only figures an admin approved are ever sent) with count-up
// ------------------------------------------------------------------
function parseStat(value) {
  const m = String(value ?? '').match(/^(\D*?)(\d[\d,]*(?:\.\d+)?)(.*)$/);
  if (!m) return null;
  const raw = m[2].replace(/,/g, '');
  return { prefix: m[1], target: Number(raw), decimals: (raw.split('.')[1] || '').length, suffix: m[3], grouped: m[2].includes(',') };
}

function CountUp({ value }) {
  const parsed = parseStat(value);
  const ref = useRef(null);
  const [n, setN] = useState(parsed ? 0 : null);
  useEffect(() => {
    if (!parsed) return undefined;
    const el = ref.current;
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (reduce || typeof IntersectionObserver === 'undefined') { setN(parsed.target); return undefined; }
    let raf = 0;
    const io = new IntersectionObserver(([entry]) => {
      if (!entry.isIntersecting) return;
      io.disconnect();
      const start = performance.now();
      const step = (t) => {
        const p = Math.min(1, (t - start) / 1400);
        setN(parsed.target * (1 - Math.pow(1 - p, 3)));
        if (p < 1) raf = requestAnimationFrame(step);
      };
      raf = requestAnimationFrame(step);
    }, { threshold: 0.4 });
    io.observe(el);
    return () => { io.disconnect(); cancelAnimationFrame(raf); };
  }, [value]); // eslint-disable-line react-hooks/exhaustive-deps
  if (!parsed) return <span>{value}</span>;
  const shown = n.toLocaleString('en-IN', { minimumFractionDigits: parsed.decimals, maximumFractionDigits: parsed.decimals, useGrouping: parsed.grouped });
  return (
    <span ref={ref}>
      <span aria-hidden="true">{parsed.prefix}{shown}{parsed.suffix}</span>
      <span className="sr-only">{value}</span>
    </span>
  );
}

function StatsBand({ block }) {
  const items = block?.items || [];
  if (!items.length) return null;
  return (
    <section aria-label="Academy in numbers" className="bg-navy-mesh relative isolate overflow-hidden text-white">
      <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-30" />
      <ul className={'container-x grid grid-cols-2 gap-6 py-12 sm:py-14 ' + (items.length >= 4 ? 'lg:grid-cols-4' : items.length === 3 ? 'lg:grid-cols-3' : '')}>
        {items.map((s) => (
          <li key={s.label} className="flex flex-col items-center gap-3 text-center sm:flex-row sm:text-left">
            <span className="glass grid size-14 shrink-0 place-items-center rounded-2xl text-gold"><Icon name={s.icon} className="size-7" /></span>
            <span>
              <span className="block font-display text-3xl font-extrabold sm:text-4xl"><CountUp value={s.value} /></span>
              <span className="text-sm text-white/80">{s.label}</span>
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

// ------------------------------------------------------------------
// Live classes
// ------------------------------------------------------------------
function LiveClasses({ block }) {
  const items = block?.items || [];
  if (!items.length) return null;
  return (
    <section className="section-y bg-surface">
      <div className="container-x">
        <SectionHeading eyebrow={<><Icon name="video" className="size-3.5" />Live</>} title={block.heading?.title || 'Upcoming live classes'} subtitle={block.heading?.subtitle}
          action={<ViewAll to="/live-classes">Full schedule</ViewAll>} />
        <ul className={'grid gap-5 sm:grid-cols-2 ' + (items.length === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4')}>
          {items.map((lc, i) => {
            const live = lc.state === 'live';
            const color = lc.category?.color || '#0B63F6';
            return (
              <Reveal as="li" key={lc.id} delay={(i % 4) * 70} className="min-w-0">
                <article className="card card-hover flex h-full flex-col overflow-hidden">
                  <div className="relative flex aspect-[16/7] items-end overflow-hidden p-4 text-white" style={{ background: `linear-gradient(135deg, ${color}, color-mix(in srgb, ${color} 40%, #041330))` }}>
                    <div aria-hidden="true" className="dot-grid absolute inset-0 opacity-50" />
                    <Icon name="video" className="absolute right-3 bottom-2 size-16 text-white/15" strokeWidth={1.5} />
                    <span className={'absolute top-3 left-3 flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wide ' + (live ? 'bg-red-600' : 'bg-white/20 backdrop-blur')}>
                      {live && <span className="size-1.5 animate-pulse rounded-full bg-white" />}
                      {live ? 'LIVE NOW' : 'UPCOMING'}
                    </span>
                    <span className="relative">
                      {lc.category?.name && <span className="block font-display text-lg leading-tight font-extrabold">{lc.category.name}</span>}
                      <span className="block text-xs font-medium text-white/80">{lc.duration_minutes ? `${lc.duration_minutes} min session` : 'Live session'}</span>
                    </span>
                  </div>
                  <div className="flex flex-1 flex-col p-4">
                    <h3 className="line-clamp-2 font-display font-bold text-navy">{lc.title}</h3>
                    {lc.instructor && <p className="mt-2 flex items-center gap-2 text-sm text-muted"><Icon name="user" className="size-4 shrink-0" />{lc.instructor}</p>}
                    <p className="mt-1.5 flex items-center gap-2 text-sm text-muted"><Icon name="calendar" className="size-4 shrink-0" /><time dateTime={lc.starts_at}>{formatDateTime(lc.starts_at)}</time></p>
                    {lc.is_demo && <div className="mt-3"><DemoBadge /></div>}
                    <div className="mt-auto pt-4">
                      {/* Meeting links are released by the server only after an eligibility check on the schedule page. */}
                      <Link to="/live-classes" className={'w-full ' + (live ? 'btn-primary' : 'btn-light')}>
                        {live ? <><span className="size-2 animate-pulse rounded-full bg-white" />Join live</> : <><Icon name="calendar" className="size-4" />View schedule</>}
                      </Link>
                    </div>
                  </div>
                </article>
              </Reveal>
            );
          })}
        </ul>
      </div>
    </section>
  );
}

// ------------------------------------------------------------------
// Govt job alerts with type tabs
// ------------------------------------------------------------------
const JOB_TYPE_LABEL = { notification: 'Recruitment', admit_card: 'Admit Card', result: 'Result', answer_key: 'Answer Key', syllabus: 'Syllabus' };
const JOB_TYPE_STYLE = {
  notification: 'bg-primary-soft text-primary',
  admit_card: 'bg-violet/10 text-violet',
  result: 'bg-emerald-50 text-emerald-700',
  answer_key: 'bg-amber-50 text-amber-700',
  syllabus: 'bg-teal/10 text-teal',
};
const typeLabel = (a) => a.type_label || JOB_TYPE_LABEL[a.type] || a.type;

function JobAlerts({ items, heading }) {
  const list = items || [];
  const [tab, setTab] = useState('all');
  const types = useMemo(() => [...new Set(list.map((a) => a.type).filter(Boolean))], [list]);
  if (!list.length) return null;
  const shown = tab === 'all' ? list : list.filter((a) => a.type === tab);
  return (
    <section className="section-y">
      <div className="container-x">
        {/* job_alerts_heading {title, highlight, subtitle} from the CMS; the defaults apply only when no title is set */}
        <SectionHeading eyebrow={<><Icon name="bell" className="size-3.5" />Job alerts</>}
          title={heading?.title || 'Latest Govt Jobs &'} highlight={heading?.title ? heading.highlight : 'Notifications'}
          subtitle={heading?.title ? heading.subtitle : 'Official recruitment updates for the exams you are preparing for.'}
          action={<ViewAll to="/jobs">All job updates</ViewAll>} />
        {types.length > 1 && (
          <div role="group" aria-label="Filter job updates by type" className="scrollbar-none -mx-4 mb-6 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0">
            {['all', ...types].map((t) => {
              const active = tab === t;
              return (
                <button key={t} type="button" aria-pressed={active} onClick={() => setTab(t)}
                  className={'shrink-0 rounded-full px-4 py-2 text-sm font-semibold transition-all ' + (active ? 'bg-navy text-white shadow-card-hover' : 'border border-line bg-white text-ink hover:border-primary hover:text-primary')}>
                  {t === 'all' ? 'All updates' : typeLabel(list.find((a) => a.type === t))}
                </button>
              );
            })}
          </div>
        )}
        <ul className="grid gap-4 md:grid-cols-2">
          {shown.map((a) => {
            const inner = (
              <>
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-primary-soft to-white text-primary ring-1 ring-primary/10"><Icon name="newspaper" className="size-6" /></span>
                <span className="min-w-0 flex-1">
                  <span className="flex flex-wrap items-center gap-2 text-[11px]">
                    <span className={'rounded-full px-2 py-0.5 font-bold uppercase ' + (JOB_TYPE_STYLE[a.type] || 'bg-surface text-ink')}>{typeLabel(a)}</span>
                    {a.is_new && <span className="animate-pulse rounded-full bg-coral px-2 py-0.5 font-bold text-white">NEW</span>}
                    {a.is_demo && <DemoBadge />}
                  </span>
                  <span className="mt-1.5 line-clamp-2 block font-display font-bold text-navy group-hover:text-primary">{a.title}</span>
                  <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                    {a.organization && <span className="truncate">{a.organization}</span>}
                    {a.last_date && <span className="inline-flex items-center gap-1 font-semibold text-coral"><Icon name="clock" className="size-3.5" />Last date: <time dateTime={a.last_date}>{a.last_date_label || formatDate(a.last_date)}</time></span>}
                  </span>
                </span>
                <Icon name={a.slug ? 'chevronRight' : 'externalLink'} className="size-5 shrink-0 text-muted transition-transform group-hover:translate-x-0.5 group-hover:text-primary" />
              </>
            );
            const cls = 'card group flex items-center gap-4 p-4 transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-card-hover';
            return (
              <li key={a.id || a.title} className="min-w-0">
                {a.slug
                  ? <Link to={`/jobs/${a.slug}`} className={cls}>{inner}</Link>
                  : <a href={a.official_url} target="_blank" rel="noopener noreferrer" className={cls}>{inner}</a>}
              </li>
            );
          })}
        </ul>
      </div>
    </section>
  );
}

// ------------------------------------------------------------------
// Free resources (resource categories)
// ------------------------------------------------------------------
const RESOURCE_COLS = ['', 'lg:grid-cols-1', 'lg:grid-cols-2', 'lg:grid-cols-3', 'lg:grid-cols-4', 'lg:grid-cols-5', 'lg:grid-cols-6'];

function Resources({ block }) {
  // Empty categories are not linked (the API sends count = published resources; older payloads have none)
  const items = (block?.items || []).filter((r) => r.count == null || Number(r.count) > 0);
  if (!items.length) return null;
  return (
    <section className="section-y bg-surface">
      <div className="container-x">
        <SectionHeading eyebrow={<><Icon name="book" className="size-3.5" />Resources</>} title={block.heading?.title || 'Study resources'} subtitle={block.heading?.subtitle}
          action={<ViewAll to="/resources">View all resources</ViewAll>} />
        <ul className={'grid grid-cols-2 gap-3 sm:grid-cols-3 ' + RESOURCE_COLS[Math.min(items.length, 6)]}>
          {items.map((r, i) => {
            const color = r.color || '#0B63F6';
            return (
              <Reveal as="li" key={r.slug} delay={(i % 6) * 50} className="min-w-0">
                <Link to={`/resources?category=${encodeURIComponent(r.slug)}`} className="group card card-hover flex h-full flex-col gap-2.5 p-3.5 sm:flex-row sm:items-center sm:gap-3 sm:p-4">
                  <span className="grid size-10 shrink-0 place-items-center rounded-xl transition-transform duration-300 group-hover:scale-105" style={{ backgroundColor: color + '1A', color }}>
                    <Icon name={r.icon} className="size-5" />
                  </span>
                  <span className="min-w-0">
                    <span className="block font-display text-sm font-bold text-navy group-hover:text-primary">{r.name}</span>
                    {r.subtitle && <span className="mt-0.5 line-clamp-2 text-xs text-muted">{r.subtitle}</span>}
                  </span>
                </Link>
              </Reveal>
            );
          })}
        </ul>
      </div>
    </section>
  );
}

// ------------------------------------------------------------------
// Success stories & testimonials (approved only)
// ------------------------------------------------------------------
function Avatar({ person, size = 'size-14' }) {
  if (person.photo_url) return <img src={person.photo_url} alt="" loading="lazy" width="56" height="56" className={size + ' shrink-0 rounded-full object-cover ring-2 ring-white shadow'} />;
  const initials = String(person.name || '?').split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
  return <span className={size + ' grid shrink-0 place-items-center rounded-full bg-gradient-to-br from-primary to-violet font-display font-bold text-white ring-2 ring-white shadow'}>{initials}</span>;
}

function SuccessStories({ block }) {
  const items = block?.items || [];
  if (!items.length) return null;
  return (
    <section className="section-y">
      <div className="container-x">
        <SectionHeading eyebrow={<><Icon name="trophy" className="size-3.5" />Results</>} title={block.heading?.title || 'Success stories'} subtitle={block.heading?.subtitle} />
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {items.map((s, i) => (
            <Reveal as="li" key={`${s.name}-${i}`} delay={(i % 4) * 70} className="min-w-0">
              <article className="card card-hover relative h-full overflow-hidden p-5 pt-6">
                <span aria-hidden="true" className="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-gold via-coral to-violet" />
                <div className="flex items-center gap-3">
                  <Avatar person={s} />
                  <div className="min-w-0">
                    <p className="truncate font-display font-bold text-navy">{s.name}</p>
                    {s.exam && <p className="truncate text-xs text-muted">{s.exam}</p>}
                  </div>
                </div>
                {s.result && <p className="mt-4 inline-flex items-center gap-1.5 rounded-full bg-gold/20 px-3 py-1 font-display text-sm font-extrabold text-navy"><Icon name="trophy" className="size-4 text-gold-dark" />{s.result}</p>}
                {s.content && <p className="mt-3 text-sm leading-relaxed text-muted">“{s.content}”</p>}
                {s.is_demo && <DemoBadge className="mt-3" />}
              </article>
            </Reveal>
          ))}
        </ul>
      </div>
    </section>
  );
}

function Testimonials({ block }) {
  const items = (block?.items || []).slice(0, 9);
  if (!items.length) return null;
  return (
    <section className="section-y bg-surface">
      <div className="container-x">
        <SectionHeading align="center" eyebrow={<><Icon name="heart" className="size-3.5" />Testimonials</>} title={block.heading?.title || 'What our students say'} subtitle={block.heading?.subtitle} />
        <ul className="gap-5 sm:columns-2 lg:columns-3">
          {items.map((t, i) => (
            <li key={`${t.name}-${i}`} className="mb-5 break-inside-avoid">
              <Reveal delay={(i % 3) * 80}>
                <figure className="card relative p-6">
                  <Icon name="quote" className="absolute top-5 right-5 size-9 text-primary/10" />
                  {t.rating ? <><Stars value={t.rating} /><span className="sr-only">{t.rating} out of 5</span></> : null}
                  <blockquote className="mt-3 text-[15px] leading-relaxed text-ink/85">{t.content}</blockquote>
                  <figcaption className="mt-5 flex items-center gap-3 border-t border-line pt-4">
                    <Avatar person={t} size="size-11" />
                    <span className="min-w-0">
                      <span className="block truncate font-display font-bold text-navy">{t.name}</span>
                      {t.exam && <span className="block truncate text-xs text-muted">{t.exam}</span>}
                    </span>
                    {t.is_demo && <DemoBadge className="ml-auto" />}
                  </figcaption>
                </figure>
              </Reveal>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}

// ------------------------------------------------------------------
// Newsletter band → POST enquiries.php?action=newsletter (source "home")
// ------------------------------------------------------------------
function NewsletterBand({ block }) {
  const [v, setV] = useState({ email: '', name: '', website: '' });
  const nl = useNewsletterSignup('home');
  const { state } = nl;
  // While this band is on the page the footer's own subscribe band is hidden (css/style.css), so
  // visitors never see two identical sign-up forms.
  useEffect(() => {
    if (!block) return undefined;
    document.body.classList.add('page-has-newsletter');
    return () => document.body.classList.remove('page-has-newsletter');
  }, [block]);
  if (!block) return null;
  const set = (k) => (e) => setV((x) => ({ ...x, [k]: e.target.value }));
  const submit = async (e) => {
    e.preventDefault();
    if (await nl.submit(v)) setV({ email: '', name: '', website: '' });
    else if (!nl.consentNeeded) document.getElementById('home-nl-email')?.focus();
  };
  const busy = state.status === 'busy';
  const emailErr = state.fields.email;
  return (
    <section className="section-y">
      <div className="container-x">
        <div className="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br from-primary via-[#2f6cf0] to-violet p-6 text-white shadow-card-hover sm:p-10 lg:p-12">
          <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40" />
          <div aria-hidden="true" className="absolute -top-24 -right-20 -z-10 size-72 rounded-full bg-gold/30 blur-3xl" />
          <div className="grid items-center gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]">
            <div className="min-w-0">
              <span className="glass inline-flex items-center gap-2 rounded-full px-3 py-1 font-display text-xs font-bold tracking-[0.12em] uppercase"><Icon name="mail" className="size-3.5" />Newsletter</span>
              <h2 className="mt-4 font-display text-2xl leading-tight font-extrabold text-white sm:text-3xl lg:text-4xl">{block.title || 'Get exam updates in your inbox'}</h2>
              {block.text && <p className="mt-3 max-w-lg text-[15px] leading-relaxed text-white/85">{block.text}</p>}
            </div>
            <div className="min-w-0">
              {state.status === 'ok' ? (
                <div role="status" className="glass flex items-center gap-4 rounded-2xl p-5">
                  <span className="grid size-12 shrink-0 animate-pop place-items-center rounded-full bg-white text-emerald-600"><Icon name="checkCircle" className="size-7" /></span>
                  <p className="text-sm font-semibold sm:text-base">{state.message}</p>
                </div>
              ) : (
                <form onSubmit={submit} noValidate className="space-y-3">
                  <div className="flex flex-col gap-3 sm:flex-row">
                    <div className="min-w-0 sm:w-2/5">
                      <label htmlFor="home-nl-name" className="sr-only">Your name (optional)</label>
                      <input id="home-nl-name" type="text" autoComplete="name" maxLength={120} value={v.name} onChange={set('name')} placeholder="Your name (optional)" className="input h-12 border-white/30" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <label htmlFor="home-nl-email" className="sr-only">Email address</label>
                      <input id="home-nl-email" type="email" inputMode="email" autoComplete="email" required maxLength={160} value={v.email} onChange={set('email')} placeholder="Your email address"
                        aria-invalid={!!emailErr} aria-describedby={emailErr ? 'home-nl-err' : undefined} className="input h-12 border-white/30" />
                    </div>
                  </div>
                  {emailErr && <p id="home-nl-err" className="text-sm font-semibold text-gold">{emailErr}</p>}
                  <input type="text" name="website" value={v.website} onChange={set('website')} tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />
                  {state.status === 'error' && !emailErr && state.message && <p role="alert" className="rounded-xl bg-white/15 px-3 py-2 text-sm font-semibold">{state.message}</p>}
                  {nl.consentNeeded && <NewsletterConsent id="home-nl-consent" checked={nl.consent} onChange={nl.setConsent} error={state.fields.consent} />}
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-xs text-white/75">No spam. Unsubscribe any time with one click. <Link to="/privacy-policy" className="font-semibold text-white underline-offset-2 hover:underline">Privacy Policy</Link></p>
                    <button type="submit" disabled={busy} className="btn-gold btn-lg shrink-0">
                      {busy ? <><span aria-hidden="true" className="size-4 animate-spin rounded-full border-2 border-navy border-t-transparent" />Subscribing…</> : <><Icon name="send" className="size-4" />{block.button_label || 'Subscribe'}</>}
                    </button>
                  </div>
                </form>
              )}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

// ------------------------------------------------------------------
// Closing CTA band with the callback form (source "home_cta")
// ------------------------------------------------------------------
function CtaBand({ cta, features }) {
  const { data: site } = useSite();
  if (!cta) return null;
  const whatsapp = (site?.site?.whatsapp || '').replace(/\D/g, '');
  const bullets = Array.isArray(cta.bullets) && cta.bullets.length
    ? cta.bullets.filter((b) => typeof b === 'string' && b.trim())
    : features.map((f) => f?.title).filter(Boolean).slice(0, 4);
  return (
    <section className="relative isolate overflow-hidden bg-navy text-white">
      <div aria-hidden="true" className="bg-animated-gradient absolute inset-0 -z-20 bg-[linear-gradient(125deg,#041330_0%,#08264D_40%,#0B3A7A_70%,#0B63F6_100%)]" />
      <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-25" />
      <div aria-hidden="true" className="absolute -top-28 -left-24 -z-10 size-96 rounded-full bg-gold/15 blur-3xl" />
      <div aria-hidden="true" className="absolute -right-24 -bottom-32 -z-10 size-96 rounded-full bg-violet/25 blur-3xl" />
      <div className="container-x grid items-center gap-10 py-14 sm:py-20 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <div className="min-w-0">
          {cta.script_text && <p className="font-script text-[28px] leading-none text-gold sm:text-[32px]">{cta.script_text}</p>}
          <h2 className="mt-3 font-display text-3xl leading-tight font-extrabold text-white sm:text-4xl">{cta.heading}</h2>
          {cta.text && <p className="mt-4 max-w-xl text-white/80 sm:text-lg">{cta.text}</p>}
          {bullets.length > 0 && (
            <ul className="mt-6 grid max-w-lg gap-3 sm:grid-cols-2">
              {bullets.map((t) => <li key={t} className="flex items-center gap-2 text-sm font-medium"><Icon name="checkCircle" className="size-5 shrink-0 text-gold" />{t}</li>)}
            </ul>
          )}
          <div className="mt-8 flex flex-wrap gap-3">
            {cta.primary_label && cta.primary_url && <SmartLink to={cta.primary_url} className="btn-gold btn-lg">{cta.primary_label} <Icon name="arrowRight" className="size-4" /></SmartLink>}
            {cta.secondary_label && cta.secondary_url && <SmartLink to={cta.secondary_url} className="btn-ghost-light btn-lg">{cta.secondary_label}</SmartLink>}
            {whatsapp && <a href={`https://wa.me/${whatsapp}`} target="_blank" rel="noopener noreferrer" className="btn-whatsapp btn-lg"><Icon name="whatsapp" className="size-5" />WhatsApp us</a>}
          </div>
          {cta.image_url && (/\.svg(\?|$)/i.test(cta.image_url)
            ? <img src={cta.image_url} alt="" loading="lazy" width="420" height="300" className="mt-10 hidden w-full max-w-sm drop-shadow-[0_20px_30px_rgb(0_0_0/0.35)] lg:block" />
            : (
              // A photo (e.g. the counsellor) is shown small, in a rounded frame with a soft glow
              <div className="relative mt-10 hidden w-60 lg:block xl:w-64">
                <div aria-hidden="true" className="absolute -inset-3 -z-10 rounded-[2rem] bg-gradient-to-br from-gold/40 via-primary/30 to-violet/40 blur-xl" />
                <img src={cta.image_url} alt="Laxyam Academy counsellor" loading="lazy" width="256" height="256" className="aspect-square w-full rounded-[1.75rem] object-cover object-top shadow-2xl ring-4 ring-white/20" />
                <span className="absolute -right-4 bottom-5 inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-xs font-bold text-navy shadow-lg">
                  <Icon name="headset" className="size-4 text-primary" />Counsellor on call
                </span>
              </div>
            ))}
        </div>
        <div className="min-w-0">
          <div className="relative rounded-3xl bg-white p-6 text-ink shadow-[0_30px_60px_-25px_rgb(0_0_0/0.6)] sm:p-8">
            <span aria-hidden="true" className="absolute -top-3 left-8 rounded-full bg-gold px-3 py-1 font-display text-[11px] font-extrabold tracking-wide text-navy uppercase shadow">Free counselling</span>
            <p className="font-display text-xl font-extrabold text-navy sm:text-2xl">{cta.form_title || 'Request a free callback'}</p>
            <p className="mt-1 mb-5 text-sm text-muted">Tell us your target exam and our counsellor will call you back.</p>
            <EnquiryForm source="home_cta" compact idPrefix="home-cta" />
          </div>
        </div>
      </div>
    </section>
  );
}
