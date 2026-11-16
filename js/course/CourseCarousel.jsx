// Sliding course row used on the homepage course block.
// Native scroll-snap does the sliding (touch swipe, trackpad); on top of that: arrow buttons that move one view
// from the card currently in front, mouse drag on desktop, page dots, keys on the focused row, and edge fades while
// more cards are hidden. Cards: about 1.15 per view on phones (the next card peeks in), then 2 / 3 / 4 from sm / lg / xl.
import { useCallback, useEffect, useRef, useState } from 'react';
import { CourseCard, CourseCardSkeleton, Icon } from '../components.jsx';

const reducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const behavior = () => (reducedMotion() ? 'auto' : 'smooth');

/** Page geometry of the row: card step, cards per view, pages, which page is showing, whether either end is reached. */
function measure(el) {
  const items = el ? el.children : [];
  if (!el || items.length === 0) return { step: 0, perView: 1, pages: 1, page: 0, canPrev: false, canNext: false, max: 0 };
  const step = items.length > 1 ? items[1].offsetLeft - items[0].offsetLeft : items[0].offsetWidth;
  const perView = Math.max(1, Math.floor((el.clientWidth + 8) / Math.max(1, step)));
  const pages = Math.max(1, Math.ceil(items.length / perView));
  const max = el.scrollWidth - el.clientWidth;
  const atEnd = el.scrollLeft >= max - 4;
  const page = atEnd ? pages - 1 : Math.min(pages - 1, Math.round(el.scrollLeft / Math.max(1, step * perView)));
  return { step, perView, pages, page, canPrev: el.scrollLeft > 4, canNext: !atEnd, max };
}

/**
 * courses: course objects for CourseCard; loading: show skeletons; toolbar: element shown left of the arrows
 * (e.g. category tabs); label: accessible name; fade: colour class of the edge fades (match the section background).
 */
export default function CourseCarousel({ courses = [], loading = false, toolbar = null, label = 'Courses', fade = 'from-white', cardProps = {} }) {
  const track = useRef(null);
  const drag = useRef(null);
  const snapTimer = useRef(0);
  const pending = useRef(null); // where a programmatic scroll is heading, so quick repeated clicks keep adding up
  const pendingTimer = useRef(0);
  const [geo, setGeo] = useState({ pages: 1, page: 0, canPrev: false, canNext: false, perView: 1, step: 0 });
  const list = loading ? Array.from({ length: 4 }, () => null) : courses;

  const update = useCallback(() => setGeo(measure(track.current)), []);

  useEffect(() => {
    const el = track.current;
    if (!el) return undefined;
    let frame = 0;
    const onScroll = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(update); };
    const onEnd = () => { pending.current = null; };
    el.addEventListener('scroll', onScroll, { passive: true });
    el.addEventListener('scrollend', onEnd);
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(onScroll) : null;
    ro?.observe(el);
    window.addEventListener('resize', onScroll);
    update();
    return () => {
      cancelAnimationFrame(frame);
      clearTimeout(snapTimer.current);
      clearTimeout(pendingTimer.current);
      el.removeEventListener('scroll', onScroll);
      el.removeEventListener('scrollend', onEnd);
      ro?.disconnect();
      window.removeEventListener('resize', onScroll);
    };
  }, [update]);

  // A new set of courses (another category tab) starts at the first card.
  const key = list.map((c) => c?.id ?? 's').join(',');
  useEffect(() => {
    if (track.current) track.current.scrollLeft = 0;
    pending.current = null;
    update();
  }, [key, update]);

  const scrollToLeft = (left) => {
    const el = track.current;
    if (!el) return;
    const target = Math.max(0, Math.min(left, el.scrollWidth - el.clientWidth));
    pending.current = target;
    clearTimeout(pendingTimer.current);
    pendingTimer.current = setTimeout(() => { pending.current = null; }, 900); // browsers without scrollend
    el.scrollTo({ left: target, behavior: behavior() });
  };

  /** Dots: jump to an absolute page. */
  const goToPage = (page) => {
    const g = measure(track.current);
    scrollToLeft(Math.max(0, Math.min(page, g.pages - 1)) * g.perView * g.step);
  };

  /** Arrows: one view on from the card in front (or from where a running scroll is going), so no card is skipped. */
  const move = (dir) => {
    const el = track.current;
    if (!el) return;
    const g = measure(el);
    const from = pending.current ?? el.scrollLeft;
    const idx = Math.round(from / Math.max(1, g.step));
    scrollToLeft((idx + dir * g.perView) * g.step);
  };

  // Mouse drag (touch and pens already scroll natively). Snap is switched off while dragging and the row then
  // settles on the nearest card; a drag never counts as a click on the card underneath.
  const onPointerDown = (e) => {
    if (e.pointerType !== 'mouse' || e.button !== 0) return;
    const el = track.current;
    clearTimeout(snapTimer.current);
    drag.current = { x: e.clientX, left: el.scrollLeft, moved: false };
    const onMove = (ev) => {
      const d = drag.current;
      if (!d) return;
      const dx = ev.clientX - d.x;
      if (!d.moved && Math.abs(dx) < 6) return;
      if (!d.moved) {
        d.moved = true;
        pending.current = null;
        el.style.scrollSnapType = 'none';
        el.style.scrollBehavior = 'auto';
        el.classList.add('cursor-grabbing', 'select-none');
      }
      el.scrollLeft = d.left - dx;
    };
    const up = () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', up);
      window.removeEventListener('pointercancel', up);
      const d = drag.current;
      if (!d?.moved) { drag.current = null; return; }
      el.classList.remove('cursor-grabbing', 'select-none');
      const g = measure(el);
      el.style.scrollBehavior = '';
      el.scrollTo({ left: Math.round(el.scrollLeft / Math.max(1, g.step)) * g.step, behavior: behavior() });
      // Give snapping back after the glide (unless another drag has started by then).
      snapTimer.current = setTimeout(() => { if (!drag.current?.moved) el.style.scrollSnapType = ''; }, 450);
      setTimeout(() => { if (drag.current === d) drag.current = null; }, 0);
    };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', up);
  };
  const onClickCapture = (e) => {
    if (drag.current?.moved) { e.preventDefault(); e.stopPropagation(); }
  };

  // Keyboard on the focused row: ← / → move one card, Home / End jump to the ends. Keys pressed inside a card
  // (its links and buttons) are left alone.
  const onKeyDown = (e) => {
    const el = track.current;
    if (!el || e.target !== el || e.altKey || e.ctrlKey || e.metaKey) return;
    const g = measure(el);
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
      e.preventDefault();
      const from = pending.current ?? el.scrollLeft;
      scrollToLeft((Math.round(from / Math.max(1, g.step)) + (e.key === 'ArrowRight' ? 1 : -1)) * g.step);
    } else if (e.key === 'Home' || e.key === 'End') {
      e.preventDefault();
      scrollToLeft(e.key === 'Home' ? 0 : g.max);
    }
  };

  const showNav = !loading && geo.pages > 1;
  return (
    <div role="region" aria-roledescription="carousel" aria-label={label}>
      {(toolbar || showNav) && (
        <div className="mb-4 flex items-start justify-between gap-3">
          <div className="min-w-0 flex-1">{toolbar}</div>
          {showNav && (
            <div className="hidden shrink-0 items-center gap-2 sm:flex">
              <ArrowButton dir="prev" disabled={!geo.canPrev} onClick={() => move(-1)} />
              <ArrowButton dir="next" disabled={!geo.canNext} onClick={() => move(1)} />
            </div>
          )}
        </div>
      )}

      <div className="relative">
        {/* Extra bottom padding (cancelled by the negative margin) keeps the hover lift and shadow from being cut off */}
        <ul
          ref={track}
          tabIndex={0}
          aria-label={label}
          aria-busy={loading || undefined}
          onPointerDown={onPointerDown}
          onClickCapture={onClickCapture}
          onKeyDown={onKeyDown}
          onDragStart={(e) => e.preventDefault()}
          className="scrollbar-none -mx-4 -mb-8 flex snap-x snap-mandatory scroll-px-4 gap-4 overflow-x-auto scroll-smooth px-4 pt-3 pb-12 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:-mx-3 sm:scroll-px-3 sm:gap-6 sm:px-3 motion-reduce:scroll-auto"
        >
          {list.map((c, i) => (
            <li
              key={c?.id ?? `s${i}`}
              aria-roledescription={c ? 'slide' : undefined}
              aria-label={c ? `${i + 1} of ${list.length}: ${c.title}` : undefined}
              className="w-[85%] max-w-[340px] shrink-0 snap-start sm:w-[calc((100%-1.5rem)/2)] sm:max-w-none lg:w-[calc((100%-3rem)/3)] xl:w-[calc((100%-4.5rem)/4)]"
            >
              {c ? <CourseCard course={c} {...cardProps} /> : <CourseCardSkeleton />}
            </li>
          ))}
        </ul>
        {/* Edge fades only while there is more to scroll to (from sm; phones see the next card peeking in) */}
        <div aria-hidden="true" className={'pointer-events-none absolute top-0 bottom-4 -left-3 hidden w-10 bg-gradient-to-r to-transparent transition-opacity duration-300 sm:block ' + fade + (geo.canPrev ? ' opacity-100' : ' opacity-0')} />
        <div aria-hidden="true" className={'pointer-events-none absolute top-0 bottom-4 -right-3 hidden w-10 bg-gradient-to-l to-transparent transition-opacity duration-300 sm:block ' + fade + (geo.canNext ? ' opacity-100' : ' opacity-0')} />
      </div>

      {showNav && (
        <div className="mt-3 flex items-center justify-center gap-2">
          <ArrowButton dir="prev" small disabled={!geo.canPrev} onClick={() => move(-1)} className="sm:hidden" />
          {geo.pages <= 12 ? (
            <div className="flex items-center" role="group" aria-label="Choose a page of courses">
              {Array.from({ length: geo.pages }, (_, p) => (
                <button
                  key={p}
                  type="button"
                  onClick={() => goToPage(p)}
                  aria-label={`Show page ${p + 1} of ${geo.pages}`}
                  aria-current={p === geo.page ? 'true' : undefined}
                  className="group grid h-7 min-w-6 place-items-center px-0.5"
                >
                  <span aria-hidden="true" className={'block h-2 rounded-full transition-all duration-300 ' + (p === geo.page ? 'w-7 bg-primary' : 'w-2 bg-line group-hover:bg-primary/40')} />
                </button>
              ))}
            </div>
          ) : (
            <span className="text-xs font-semibold text-muted tabular-nums">{geo.page + 1} / {geo.pages}</span>
          )}
          <ArrowButton dir="next" small disabled={!geo.canNext} onClick={() => move(1)} className="sm:hidden" />
        </div>
      )}
    </div>
  );
}

/** Round arrow. At the ends it is marked aria-disabled (not disabled) so keyboard focus is not lost. */
function ArrowButton({ dir, disabled, onClick, small = false, className = '' }) {
  return (
    <button
      type="button"
      onClick={disabled ? undefined : onClick}
      aria-disabled={disabled || undefined}
      aria-label={dir === 'prev' ? 'Previous courses' : 'Next courses'}
      className={'grid place-items-center rounded-full border border-line bg-white text-navy shadow-card transition-all duration-200 '
        + 'hover:border-primary hover:bg-primary hover:text-white hover:shadow-card-hover active:scale-95 '
        + 'aria-disabled:cursor-default aria-disabled:opacity-35 aria-disabled:hover:border-line aria-disabled:hover:bg-white aria-disabled:hover:text-navy aria-disabled:hover:shadow-card aria-disabled:active:scale-100 '
        + (small ? 'size-9 ' : 'size-11 ') + className}
    >
      <Icon name={dir === 'prev' ? 'chevronLeft' : 'chevronRight'} className={small ? 'size-4' : 'size-5'} />
    </button>
  );
}
