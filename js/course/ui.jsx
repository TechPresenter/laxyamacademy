// Shared pieces for the course pages (catalogue, course detail, lesson player, My Courses, wishlist).
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiUrl } from '../api.js';
import { Icon, Price, Rating, SmartLink, WishlistButton, CourseThumb, CategoryPill, DemoBadge, formatOfferDate, languageLabel, levelLabel, useApi } from '../components.jsx';

/** Icon per lesson type (lessons.lesson_type). */
export const LESSON_ICON = { video: 'playCircle', external_video: 'playCircle', pdf: 'file', text: 'book', live: 'video', quiz: 'clipboard' };
const LESSON_LABEL = { video: 'Video', external_video: 'Video', pdf: 'PDF notes', text: 'Reading', live: 'Live class', quiz: 'Practice test' };
export const lessonTypeLabel = (t) => LESSON_LABEL[t] || 'Lesson';

/** "1 lesson" / "3 lessons" */
export const plural = (n, one, many = one + 's') => `${n} ${Number(n) === 1 ? one : many}`;

/** 45 → "45 min", 135 → "2h 15m", 0/null → '' */
export function formatMinutes(min) {
  const m = Math.round(Number(min) || 0);
  if (m <= 0) return '';
  if (m < 60) return `${m} min`;
  const h = Math.floor(m / 60);
  return m % 60 ? `${h}h ${m % 60}m` : `${h}h`;
}

/** Access validity: null → lifetime; days / months / years otherwise (never "0 Months"). */
export function validityLabel(days) {
  const d = Number(days) || 0;
  if (d <= 0) return 'Lifetime access';
  if (d < 30) return plural(d, 'day');
  if (d < 365) return plural(Math.round(d / 30), 'month');
  const y = d / 365;
  return Number.isInteger(Math.round(y * 2) / 2) ? plural(Math.round(y), 'year') : plural(Math.round(d / 30), 'month');
}

/** Circular progress (0-100). */
export function ProgressRing({ value = 0, size = 56, stroke = 6, className = '', tone = 'text-primary' }) {
  const pct = Math.max(0, Math.min(100, Number(value) || 0));
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  return (
    <span className={'relative inline-grid shrink-0 place-items-center ' + className} style={{ width: size, height: size }}
      role="progressbar" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label="Course progress">
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="-rotate-90" aria-hidden="true">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="currentColor" strokeWidth={stroke} className="text-line" />
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="currentColor" strokeWidth={stroke} strokeLinecap="round"
          strokeDasharray={c} strokeDashoffset={c - (pct / 100) * c} className={tone + ' transition-[stroke-dashoffset] duration-700'} />
      </svg>
      <span className="absolute font-display text-[13px] font-extrabold text-navy">{Math.round(pct)}%</span>
    </span>
  );
}

/** Thin progress bar. */
export function ProgressBar({ value = 0, className = '', tone = 'from-primary to-violet', label = 'Course progress' }) {
  const pct = Math.max(0, Math.min(100, Number(value) || 0));
  return (
    <div className={'h-2 overflow-hidden rounded-full bg-line/70 ' + className} role="progressbar" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
      <div className={'h-full rounded-full bg-gradient-to-r transition-[width] duration-700 ' + tone} style={{ width: `${pct}%` }} />
    </div>
  );
}

// ------------------------------------------------------------------
// Promo banners (admin/banners.php) → cms.php?action=banners&placement=…
// ------------------------------------------------------------------
const BANNER_THEME = {
  blue: 'from-primary via-[#2f7bff] to-violet text-white',
  navy: 'from-navy-deep via-navy to-primary text-white',
  gold: 'from-gold via-[#ffdf7a] to-[#ffb020] text-navy',
  violet: 'from-violet via-[#8b6bff] to-primary text-white',
  green: 'from-emerald-600 via-teal to-emerald-500 text-white',
  red: 'from-coral via-red-500 to-[#ff7a45] text-white',
};

/** Fire-and-forget click counter (GET beacon). */
function trackBannerClick(id) {
  try {
    fetch(apiUrl('cms.php', { action: 'banner-click', id }), { credentials: 'same-origin', keepalive: true }).catch(() => {});
  } catch { /* ignore */ }
}

/** Renders nothing until the API returns at least one active banner. */
export function PromoBanners({ placement, className = '' }) {
  const { data } = useApi('cms.php', { action: 'banners', placement });
  const items = data?.items || [];
  if (!items.length) return null;
  return (
    <div className={'grid gap-4 ' + (items.length > 1 ? 'md:grid-cols-2 ' : '') + className}>
      {items.slice(0, 4).map((b) => {
        const theme = BANNER_THEME[b.theme] || BANNER_THEME.blue;
        const light = b.theme === 'gold';
        const inner = (
          <>
            <div aria-hidden="true" className={(light ? 'dot-grid-dark' : 'dot-grid') + ' absolute inset-0 opacity-50'} />
            <div aria-hidden="true" className="absolute -top-16 -right-10 size-48 rounded-full bg-white/15 blur-2xl" />
            <div className="relative flex min-w-0 flex-1 flex-col justify-center gap-1.5 p-5 sm:p-6">
              <p className="font-display text-lg leading-snug font-extrabold sm:text-xl">{b.title}</p>
              {b.subtitle && <p className={'text-sm ' + (light ? 'text-navy/80' : 'text-white/85')}>{b.subtitle}</p>}
              {b.cta_label && b.cta_url && (
                <span className={'mt-2 inline-flex w-fit items-center gap-1.5 rounded-full px-4 py-1.5 text-xs font-bold transition-transform group-hover:translate-x-0.5 ' + (light ? 'bg-navy text-white' : 'bg-white text-navy')}>
                  {b.cta_label}<Icon name="arrowRight" className="size-3.5" />
                </span>
              )}
            </div>
            {b.image_url && <img src={b.image_url} alt="" loading="lazy" className="relative hidden w-2/5 max-w-[220px] shrink-0 object-cover sm:block" />}
          </>
        );
        const cls = 'group relative isolate flex min-h-[120px] overflow-hidden rounded-2xl bg-gradient-to-br shadow-card transition-all duration-300 hover:-translate-y-0.5 hover:shadow-card-hover ' + theme;
        return b.cta_url ? (
          <SmartLink key={b.id} to={b.cta_url} onClick={() => trackBannerClick(b.id)} className={cls} aria-label={b.cta_label ? `${b.title} - ${b.cta_label}` : b.title}>{inner}</SmartLink>
        ) : <div key={b.id} className={cls}>{inner}</div>;
      })}
    </div>
  );
}

// ------------------------------------------------------------------
// Course videos (admin/videos.php, placement=course) → cms.php?action=videos
// ------------------------------------------------------------------
function VideoTile({ item, platform }) {
  const [playing, setPlaying] = useState(false);
  if (platform === 'youtube' && playing) {
    return (
      <div className="overflow-hidden rounded-xl bg-black">
        <iframe className="aspect-video w-full" src={`https://www.youtube-nocookie.com/embed/${item.video_id}?autoplay=1&rel=0`} title={item.title}
          allow="autoplay; encrypted-media; picture-in-picture" allowFullScreen />
      </div>
    );
  }
  const thumb = item.thumbnail_url || (platform === 'youtube' ? `https://i.ytimg.com/vi/${item.video_id}/hqdefault.jpg` : null);
  const body = (
    <>
      <span className={'relative block overflow-hidden rounded-xl ' + (platform === 'instagram' ? 'aspect-[4/5]' : 'aspect-video')}>
        {thumb ? <img src={thumb} alt="" loading="lazy" className="size-full object-cover transition-transform duration-500 group-hover:scale-105" />
          : <span className="grid size-full place-items-center bg-gradient-to-br from-[#f58529] via-[#dd2a7b] to-[#8134af] text-white"><Icon name="instagram" className="size-10" /></span>}
        <span className="absolute inset-0 bg-gradient-to-t from-navy-deep/60 to-transparent" />
        <span className="absolute inset-0 grid place-items-center">
          <span className={'grid size-12 place-items-center rounded-full text-white shadow-lg transition-transform group-hover:scale-110 ' + (platform === 'youtube' ? 'bg-[#FF0000]' : 'bg-white/25 backdrop-blur')}>
            <Icon name={platform === 'youtube' ? 'play' : 'instagram'} className="size-5" />
          </span>
        </span>
      </span>
      <span className="mt-2 line-clamp-2 block text-sm font-semibold text-navy">{item.title}</span>
    </>
  );
  return platform === 'youtube' ? (
    <button type="button" onClick={() => setPlaying(true)} className="group block w-full text-left" aria-label={`Play video: ${item.title}`}>{body}</button>
  ) : (
    <a href={item.url} target="_blank" rel="noopener noreferrer" className="group block" aria-label={`Watch on Instagram: ${item.title}`}>{body}</a>
  );
}

/** YouTube + Instagram videos for a placement; hidden when there are none (or the API is unavailable). */
export function CourseVideos({ placement = 'course', course, title = 'Watch & learn', className = '' }) {
  const { data } = useApi('cms.php', { action: 'videos', placement, course });
  const yt = data?.youtube?.items || [];
  const ig = data?.instagram?.items || [];
  if (!yt.length && !ig.length) return null;
  return (
    <div className={className}>
      {yt.length > 0 && (
        <div>
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 className="flex items-center gap-2 font-display font-bold text-navy"><Icon name="youtube" className="size-5 text-[#FF0000]" />{data.youtube.heading?.title || title}</h3>
            {data.youtube.heading?.channel_url && <a href={data.youtube.heading.channel_url} target="_blank" rel="noopener noreferrer" className="text-sm font-semibold text-primary hover:underline">View channel</a>}
          </div>
          <ul className="grid gap-4 sm:grid-cols-2">{yt.slice(0, 4).map((v) => <li key={v.id} className="min-w-0"><VideoTile item={v} platform="youtube" /></li>)}</ul>
        </div>
      )}
      {ig.length > 0 && (
        <div className={yt.length ? 'mt-6' : ''}>
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 className="flex items-center gap-2 font-display font-bold text-navy"><Icon name="instagram" className="size-5 text-[#dd2a7b]" />{data.instagram.heading?.title || 'On Instagram'}</h3>
            {data.instagram.heading?.profile_url && <a href={data.instagram.heading.profile_url} target="_blank" rel="noopener noreferrer" className="text-sm font-semibold text-primary hover:underline">Follow us</a>}
          </div>
          <ul className="grid grid-cols-2 gap-4 sm:grid-cols-4">{ig.slice(0, 4).map((v) => <li key={v.id} className="min-w-0"><VideoTile item={v} platform="instagram" /></li>)}</ul>
        </div>
      )}
    </div>
  );
}

// ------------------------------------------------------------------
// Horizontal course row (catalogue "list" view)
// ------------------------------------------------------------------
export function CourseRow({ course }) {
  const url = `/courses/${course.slug}`;
  const meta = [
    course.course_type === 'offline' && ['presentation', 'Offline classes'],
    course.duration && ['clock', course.duration],
    course.lessons_count > 0 && ['playCircle', plural(course.lessons_count, 'lesson')],
    course.level && ['barChart', levelLabel(course.level)],
    course.language && ['languages', languageLabel(course.language)],
  ].filter(Boolean);
  return (
    <article className="group card card-hover relative grid overflow-hidden sm:grid-cols-[minmax(0,260px)_minmax(0,1fr)]">
      <Link to={url} tabIndex={-1} aria-hidden="true" className="relative block overflow-hidden">
        <CourseThumb course={course} className="h-full min-h-[150px]" imgClassName="transition-transform duration-700 group-hover:scale-[1.05]" />
        {course.preview_lessons > 0 && (
          <span className="absolute bottom-3 left-3 flex items-center gap-1 rounded-full bg-white/95 px-2.5 py-1 text-[11px] font-bold text-navy shadow-sm">
            <Icon name="playCircle" className="size-3.5 text-primary" />Free preview
          </span>
        )}
      </Link>
      <div className="flex min-w-0 flex-col p-4 sm:p-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="mb-1.5 flex flex-wrap items-center gap-2">
              <CategoryPill category={course.category} />
              {course.is_demo && <DemoBadge />}
            </div>
            <h3 className="line-clamp-2 text-lg leading-snug font-bold"><Link to={url} className="transition-colors hover:text-primary">{course.title}</Link></h3>
            {course.subtitle && <p className="mt-0.5 line-clamp-1 text-sm font-medium text-muted">{course.subtitle}</p>}
          </div>
          <WishlistButton course={course} className="shrink-0 border border-line" />
        </div>
        {course.short_description && <p className="mt-2 line-clamp-2 text-sm leading-relaxed text-muted">{course.short_description}</p>}
        {meta.length > 0 && (
          <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 text-xs font-medium text-ink/75">
            {meta.map(([icon, text]) => <li key={icon} className="flex items-center gap-1.5"><Icon name={icon} className="size-3.5 text-primary" />{text}</li>)}
          </ul>
        )}
        <div className="mt-auto flex flex-wrap items-end justify-between gap-3 pt-4">
          <div className="space-y-1">
            <Rating rating={course.rating} small />
            <Price course={course} showOff />
            {course.offer_ends_at && <p className="text-[11px] font-semibold text-coral">Offer till {formatOfferDate(course.offer_ends_at)}</p>}
          </div>
          <div className="flex gap-2">
            <Link to={url} className="btn-light btn-sm py-2.5">View details</Link>
            {course.admission === 'enquiry'
              ? <Link to={`${url}#enquire`} className="btn-primary btn-sm py-2.5">Enquire Now</Link>
              : <Link to={`/checkout/${course.slug}`} className="btn-primary btn-sm py-2.5">{course.is_free ? 'Enroll Free' : 'Enroll Now'}</Link>}
          </div>
        </div>
      </div>
    </article>
  );
}

/** Dashboard page title used by My Courses and Wishlist. */
export function DashHeader({ title, subtitle, icon, action }) {
  return (
    <div className="relative mb-6 overflow-hidden rounded-2xl border border-line bg-mesh p-5 sm:p-6">
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 [mask-image:linear-gradient(to_left,black,transparent_70%)]" />
      <div className="relative flex flex-wrap items-center justify-between gap-4">
        <div className="flex min-w-0 items-center gap-4">
          {icon && <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-primary to-violet text-white shadow-glow"><Icon name={icon} className="size-6" /></span>}
          <div className="min-w-0">
            <h1 className="font-display text-2xl font-extrabold tracking-tight text-navy sm:text-3xl">{title}</h1>
            {subtitle && <p className="mt-0.5 text-sm text-muted sm:text-base">{subtitle}</p>}
          </div>
        </div>
        {action}
      </div>
    </div>
  );
}

/** Lock the page scroll while a drawer / modal is open. */
export function useBodyLock(locked) {
  useEffect(() => {
    if (!locked) return undefined;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = prev; };
  }, [locked]);
}

/** Close on Escape. */
export function useEscape(active, onEscape) {
  useEffect(() => {
    if (!active) return undefined;
    const onKey = (e) => { if (e.key === 'Escape') onEscape(); };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [active, onEscape]);
}
