// Full-screen photo / video viewer for the gallery.
// Keyboard: ← → (previous / next), Home / End, Escape closes; focus is trapped and returns to the tile.
// Touch: swipe left / right to browse, swipe down to close. Neighbouring photos are preloaded.
// YouTube items play in a youtube-nocookie iframe that only exists while the item is on screen.
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from '../components.jsx';
import { useFocusTrap, useScrollLock } from '../ui/overlay.js';

const embedUrl = (id) => `https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?autoplay=1&rel=0&modestbranding=1&playsinline=1`;
const ratioOf = (it) => (it.type === 'youtube' ? 16 / 9 : it.width && it.height ? it.width / it.height : 4 / 3);
const reducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

/**
 * items: [{id, type: 'image'|'youtube', image_url, thumb_url, youtube_id, width, height, caption, alt}]
 * index: open item; onIndex(i) changes it; onClose() closes; title: album name (shown in the top bar).
 */
export default function Lightbox({ items, index, onIndex, onClose, title }) {
  const box = useRef(null);
  const closeBtn = useRef(null);
  const strip = useRef(null);
  const touch = useRef(null);
  const [dx, setDx] = useState(0);
  const [loaded, setLoaded] = useState({});
  const n = items.length;
  const item = items[index];
  const go = useCallback((d) => { setDx(0); onIndex((index + d + n) % n); }, [index, n, onIndex]);

  useScrollLock(true);
  useFocusTrap(box, true, { onEscape: onClose, initialFocus: closeBtn });

  // Arrow keys / Home / End (Escape and Tab are handled by the focus trap)
  useEffect(() => {
    const onKey = (e) => {
      if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || n < 2) return;
      const to = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: n - 1 }[e.key];
      if (to === undefined) return;
      e.preventDefault();
      setDx(0);
      onIndex((to + n) % n);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [index, n, onIndex]);

  // Preload the previous and next photos so browsing feels instant
  useEffect(() => {
    if (n < 2) return;
    [index + 1, index - 1].forEach((i) => {
      const it = items[(i + n) % n];
      if (it?.type === 'image' && it.image_url) {
        const img = new Image();
        img.decoding = 'async';
        img.src = it.image_url;
      }
    });
  }, [index, items, n]);

  // Keep the active thumbnail visible in the film strip
  useEffect(() => {
    strip.current?.querySelector('[aria-current="true"]')?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: reducedMotion() ? 'auto' : 'smooth' });
  }, [index]);

  if (!item) return null;
  const isVideo = item.type === 'youtube';
  const ratio = ratioOf(item);
  const state = loaded[item.id]; // undefined (loading) | 'ok' | 'error' (the thumbnail stays visible)
  const done = state === 'ok';
  const kind = isVideo ? 'Video' : 'Photo';

  const onTouchStart = (e) => {
    if (e.touches.length !== 1) { touch.current = null; return; }
    touch.current = { x: e.touches[0].clientX, y: e.touches[0].clientY };
  };
  const onTouchMove = (e) => {
    const s = touch.current;
    if (!s || e.touches.length !== 1) return;
    const mx = e.touches[0].clientX - s.x;
    if (Math.abs(mx) > Math.abs(e.touches[0].clientY - s.y) && n > 1) setDx(mx);
  };
  const onTouchEnd = (e) => {
    const s = touch.current;
    touch.current = null;
    if (!s) return;
    const t = e.changedTouches[0];
    const mx = t.clientX - s.x;
    const my = t.clientY - s.y;
    if (n > 1 && Math.abs(mx) > 50 && Math.abs(mx) > Math.abs(my) * 1.2) go(mx < 0 ? 1 : -1);
    else if (my > 90 && Math.abs(my) > Math.abs(mx) * 1.5) onClose();
    else setDx(0);
  };

  const navBtn = 'absolute top-1/2 z-10 grid size-11 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white ring-1 ring-white/20 backdrop-blur transition-all hover:bg-white/25 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold sm:size-12';
  const topBtn = 'grid size-10 shrink-0 place-items-center rounded-full bg-white/10 text-white ring-1 ring-white/15 transition-colors hover:bg-white/25 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold';

  return createPortal(
    <div ref={box} role="dialog" aria-modal="true" aria-label={`${title ? title + ' - ' : ''}photo viewer`}
      className="fixed inset-0 z-[150] flex animate-fade-in flex-col bg-[#030b1c]/95 text-white outline-none backdrop-blur-md motion-reduce:animate-none" tabIndex={-1}>
      {/* Top bar: counter, album, open original, close */}
      <div className="flex h-16 shrink-0 items-center gap-3 px-3 sm:px-5">
        <span className="rounded-full bg-white/10 px-3 py-1 font-display text-sm font-bold tabular-nums ring-1 ring-white/15">{index + 1} / {n}</span>
        <p className="min-w-0 flex-1 truncate text-sm font-semibold text-white/80">{title}</p>
        {isVideo ? (
          <a href={`https://www.youtube.com/watch?v=${encodeURIComponent(item.youtube_id)}`} target="_blank" rel="noopener noreferrer" className={topBtn} aria-label="Watch on YouTube (opens a new tab)" title="Watch on YouTube">
            <Icon name="youtube" className="size-[18px]" />
          </a>
        ) : (
          <a href={item.image_url} target="_blank" rel="noopener noreferrer" className={topBtn} aria-label="Open the full-size photo in a new tab" title="Full size">
            <Icon name="externalLink" className="size-[18px]" />
          </a>
        )}
        <button ref={closeBtn} type="button" onClick={onClose} className={topBtn} aria-label="Close viewer" title="Close (Esc)">
          <Icon name="x" className="size-5" />
        </button>
      </div>

      {/* Stage */}
      <div className="relative min-h-0 flex-1 overflow-hidden px-2 sm:px-20" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
        onTouchStart={onTouchStart} onTouchMove={onTouchMove} onTouchEnd={onTouchEnd} style={{ touchAction: 'pan-y pinch-zoom' }}>
        {/* Size container: the frame fits the free space at the item's own aspect ratio (no cropping, no jump) */}
        <div className="flex size-full items-center justify-center [container-type:size]" onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
          style={{ transform: dx ? `translateX(${dx}px)` : undefined, transition: dx ? 'none' : 'transform .25s ease' }}>
          <figure key={item.id} className="relative m-0 max-h-full max-w-full animate-scale-in overflow-hidden rounded-xl bg-white/5 shadow-2xl ring-1 ring-white/10 motion-reduce:animate-none"
            style={{ aspectRatio: String(ratio), width: `min(100cqw, calc(100cqh * ${ratio}))` }}>
            {isVideo ? (
              <iframe src={embedUrl(item.youtube_id)} title={item.caption || item.alt || 'YouTube video'} className="absolute inset-0 size-full"
                allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen referrerPolicy="strict-origin-when-cross-origin" />
            ) : (
              <>
                {/* The grid thumbnail (already cached) shows at once; the full photo fades in over it */}
                {item.thumb_url && item.thumb_url !== item.image_url && (
                  <img src={item.thumb_url} alt="" aria-hidden="true" className={'absolute inset-0 size-full object-contain transition-opacity duration-300 ' + (done ? 'opacity-0' : 'opacity-100 blur-[2px]')} />
                )}
                <img src={item.image_url} alt={item.alt || item.caption || ''} width={item.width || undefined} height={item.height || undefined} decoding="async"
                  onLoad={() => setLoaded((l) => ({ ...l, [item.id]: 'ok' }))} onError={() => setLoaded((l) => ({ ...l, [item.id]: 'error' }))}
                  className={'absolute inset-0 size-full object-contain transition-opacity duration-300 ' + (done || item.thumb_url === item.image_url ? 'opacity-100' : 'opacity-0')} />
                {!state && (
                  <span aria-hidden="true" className="absolute right-3 bottom-3 size-6 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                )}
              </>
            )}
          </figure>
        </div>
        {n > 1 && (
          <>
            <button type="button" onClick={() => go(-1)} className={navBtn + ' left-2 sm:left-5'} aria-label={`Previous (${((index - 1 + n) % n) + 1} of ${n})`} title="Previous (←)">
              <Icon name="chevronLeft" className="size-6" />
            </button>
            <button type="button" onClick={() => go(1)} className={navBtn + ' right-2 sm:right-5'} aria-label={`Next (${((index + 1) % n) + 1} of ${n})`} title="Next (→)">
              <Icon name="chevronRight" className="size-6" />
            </button>
          </>
        )}
      </div>

      {/* Caption + film strip */}
      <div className="shrink-0 px-4 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))] sm:px-6">
        <p className="mx-auto min-h-6 max-w-3xl text-center text-sm leading-relaxed text-white/90 sm:text-base">
          {item.caption || <span className="text-white/50">{kind} {index + 1} of {n}</span>}
        </p>
        <p className="sr-only" aria-live="polite">{`${kind} ${index + 1} of ${n}${item.caption ? ': ' + item.caption : ''}`}</p>
        {n > 1 && (
          <div ref={strip} className="scrollbar-none mx-auto mt-3 hidden w-fit max-w-[min(64rem,100%)] gap-2 overflow-x-auto px-1 py-1 sm:flex" role="group" aria-label="All photos and videos">
            {items.map((it, i) => (
              <button key={it.id} type="button" onClick={() => { setDx(0); onIndex(i); }} aria-current={i === index ? 'true' : undefined}
                aria-label={`${it.type === 'youtube' ? 'Video' : 'Photo'} ${i + 1}${it.caption ? ': ' + it.caption : ''}`}
                className={'relative size-14 shrink-0 overflow-hidden rounded-lg ring-2 transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold ' + (i === index ? 'opacity-100 ring-gold' : 'opacity-50 ring-transparent hover:opacity-90')}>
                <img src={it.thumb_url} alt="" loading="lazy" decoding="async" className="size-full object-cover" />
                {it.type === 'youtube' && <span className="absolute inset-0 grid place-items-center bg-black/30"><Icon name="play" className="size-4" /></span>}
              </button>
            ))}
          </div>
        )}
      </div>
    </div>,
    document.body,
  );
}
