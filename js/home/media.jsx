// Video building blocks shared by Home, About and page-builder pages:
// a lightweight accessible modal, YouTube video grid and Instagram reel cards.
// Data shape (cms.php?action=videos / action=home → videos):
//   { youtube: { heading: {title, subtitle, channel_url}|null, items: [{id, title, description, video_id, url, thumbnail_url}] },
//     instagram: { heading: {title, subtitle, profile_url}|null, items: [...] } }
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Icon, Reveal, SectionHeading } from '../components.jsx';

/** 11-character YouTube id from a watch / youtu.be / shorts / embed / live link (or null). */
export function youtubeId(url) {
  const m = String(url || '').match(/(?:youtu\.be\/|[?&]v=|\/embed\/|\/shorts\/|\/live\/)([\w-]{11})(?![\w-])/);
  return m ? m[1] : null;
}
export const youtubeThumb = (id) => `https://i.ytimg.com/vi/${encodeURIComponent(id)}/hqdefault.jpg`;
const youtubeEmbed = (id) => `https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?autoplay=1&rel=0&modestbranding=1`;
const instagramEmbed = (code) => `https://www.instagram.com/reel/${encodeURIComponent(code)}/embed`;

const FOCUSABLE = 'a[href], button:not([disabled]), iframe, input, select, textarea, [tabindex]:not([tabindex="-1"])';

/**
 * Centered dialog over a dark backdrop. Escape / backdrop click closes, focus moves to the close
 * button and is kept inside, the page behind does not scroll, focus returns to the opener on close.
 */
export function Modal({ open, onClose, title, children, className = 'max-w-4xl' }) {
  const box = useRef(null);
  const closeRef = useRef(onClose);
  closeRef.current = onClose;

  useEffect(() => {
    if (!open) return undefined;
    const opener = document.activeElement;
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    box.current?.querySelector('[data-close]')?.focus();
    const onKey = (e) => {
      if (e.key === 'Escape') closeRef.current?.();
      if (e.key !== 'Tab' || !box.current) return;
      const items = [...box.current.querySelectorAll(FOCUSABLE)];
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = overflow;
      opener?.focus?.();
    };
  }, [open]);

  if (!open) return null;
  return createPortal(
    <div className="fixed inset-0 z-[120] flex items-center justify-center p-4 sm:p-6">
      <div aria-hidden="true" onClick={() => closeRef.current?.()} className="absolute inset-0 animate-fade-in bg-navy-deep/85 backdrop-blur-sm" />
      <div ref={box} role="dialog" aria-modal="true" aria-label={title} className={'relative w-full animate-scale-in ' + className}>
        <div className="mb-3 flex items-center justify-between gap-3 text-white">
          <p className="min-w-0 truncate font-display text-sm font-bold sm:text-base">{title}</p>
          <button data-close type="button" onClick={() => closeRef.current?.()} aria-label="Close"
            className="grid size-10 shrink-0 place-items-center rounded-full bg-white/10 text-white transition-colors hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold">
            <Icon name="x" />
          </button>
        </div>
        {children}
      </div>
    </div>,
    document.body,
  );
}

/** Plays one video in the modal. video = {platform: 'youtube'|'instagram', video_id, title, url}. */
export function VideoModal({ video, onClose }) {
  if (!video) return null;
  const insta = video.platform === 'instagram';
  const title = video.title || (insta ? 'Instagram reel' : 'YouTube video');
  return (
    <Modal open onClose={onClose} title={title} className={insta ? 'max-w-[420px]' : 'max-w-5xl'}>
      {insta ? (
        <div className="mx-auto overflow-hidden rounded-2xl bg-white shadow-2xl" style={{ width: 'min(100%, calc((100dvh - 9rem) * 0.5625))' }}>
          <iframe src={instagramEmbed(video.video_id)} title={title} className="aspect-[9/16] w-full" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; clipboard-write" allowFullScreen />
        </div>
      ) : (
        <div className="overflow-hidden rounded-2xl bg-black shadow-2xl">
          <iframe src={youtubeEmbed(video.video_id)} title={title} className="aspect-video w-full" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen />
        </div>
      )}
      {video.url && (
        <p className="mt-3 text-center">
          <a href={video.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-sm font-semibold text-white/85 hover:text-white">
            Open on {insta ? 'Instagram' : 'YouTube'} <Icon name="externalLink" className="size-4" />
          </a>
        </p>
      )}
    </Modal>
  );
}

/** One YouTube video card: thumbnail with a play button; opens the modal. */
export function YouTubeCard({ video, onPlay, featured = false }) {
  const thumb = video.thumbnail_url || youtubeThumb(video.video_id);
  return (
    <button type="button" onClick={() => onPlay({ ...video, platform: 'youtube' })} aria-label={`Play video: ${video.title || 'YouTube video'}`}
      className="group card card-hover flex h-full w-full flex-col overflow-hidden text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
      <span className="relative block aspect-video overflow-hidden bg-navy">
        <img src={thumb} alt="" loading="lazy" width="480" height="360" className="size-full object-cover transition-transform duration-700 group-hover:scale-105" />
        <span className="absolute inset-0 bg-gradient-to-t from-navy-deep/70 via-transparent to-transparent" />
        <span className="absolute inset-0 grid place-items-center">
          <span className={'relative grid place-items-center rounded-full bg-[#FF0000] text-white shadow-lg transition-transform duration-300 group-hover:scale-110 ' + (featured ? 'size-16 sm:size-20' : 'size-14')}>
            <span aria-hidden="true" className="absolute inset-0 animate-pulse-ring rounded-full" />
            <Icon name="play" className={featured ? 'ml-1 size-7 sm:size-8' : 'ml-0.5 size-6'} />
          </span>
        </span>
        <span className="absolute top-3 left-3 flex items-center gap-1.5 rounded-full bg-white/95 px-2.5 py-1 text-[11px] font-bold text-navy shadow-sm">
          <Icon name="youtube" className="size-3.5 text-[#FF0000]" />YouTube
        </span>
      </span>
      <span className="flex flex-1 flex-col p-4">
        <span className="line-clamp-2 font-display text-[15px] leading-snug font-bold text-navy group-hover:text-primary">{video.title}</span>
        {video.description && <span className="mt-1 line-clamp-2 text-sm text-muted">{video.description}</span>}
      </span>
    </button>
  );
}

/** Reel-style 9:16 card for an Instagram video. */
export function InstagramCard({ video, onPlay }) {
  return (
    <button type="button" onClick={() => onPlay({ ...video, platform: 'instagram' })} aria-label={`Watch reel: ${video.title || 'Instagram reel'}`}
      className="group relative block aspect-[9/16] w-full overflow-hidden rounded-2xl bg-gradient-to-br from-[#833AB4] via-[#E1306C] to-[#F77737] text-left shadow-card transition-all duration-300 hover:-translate-y-1 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
      {video.thumbnail_url ? (
        <img src={video.thumbnail_url} alt="" loading="lazy" className="absolute inset-0 size-full object-cover transition-transform duration-700 group-hover:scale-105" />
      ) : (
        <span aria-hidden="true" className="dot-grid absolute inset-0 opacity-60" />
      )}
      <span className="absolute inset-0 bg-gradient-to-t from-black/75 via-black/10 to-black/20" />
      <span className="absolute top-3 right-3 grid size-9 place-items-center rounded-full bg-white/20 text-white backdrop-blur"><Icon name="instagram" className="size-4.5" /></span>
      <span className="absolute inset-0 grid place-items-center">
        <span className="grid size-14 place-items-center rounded-full bg-white/25 text-white ring-1 ring-white/50 backdrop-blur transition-transform duration-300 group-hover:scale-110">
          <Icon name="play" className="ml-0.5 size-6" />
        </span>
      </span>
      <span className="absolute inset-x-0 bottom-0 p-4">
        <span className="line-clamp-3 font-display text-sm leading-snug font-bold text-white drop-shadow">{video.title}</span>
      </span>
    </button>
  );
}

/** YouTube section: heading, featured first video + grid, channel button. Renders nothing without items. */
export function YouTubeSection({ block, className = 'section-y' }) {
  const [playing, setPlaying] = useState(null);
  const items = (block?.items || []).filter((v) => v.video_id);
  if (!items.length) return null;
  const heading = block.heading || {};
  const channel = heading.channel_url;
  return (
    <section className={className} aria-label={heading.title || 'YouTube videos'}>
      <div className="container-x">
        <SectionHeading eyebrow={<><Icon name="youtube" className="size-3.5 text-[#FF0000]" />YouTube</>} title={heading.title || 'Watch on YouTube'} subtitle={heading.subtitle}
          action={channel && (
            <a href={channel} target="_blank" rel="noopener noreferrer" className="btn bg-[#FF0000] text-white shadow-[0_6px_18px_-6px_rgb(255_0_0/0.55)] hover:-translate-y-0.5 hover:bg-[#d90000]">
              <Icon name="youtube" className="size-5" />Visit our channel
            </a>
          )} />
        <ul className={items.length === 1 ? 'grid max-w-3xl' : 'grid gap-5 sm:grid-cols-2 ' + (items.length >= 3 ? 'lg:grid-cols-3' : '')}>
          {items.map((v, i) => (
            <Reveal as="li" key={v.id || v.video_id} delay={(i % 3) * 80} className="min-w-0">
              <YouTubeCard video={v} onPlay={setPlaying} featured={items.length === 1} />
            </Reveal>
          ))}
        </ul>
      </div>
      <VideoModal video={playing} onClose={() => setPlaying(null)} />
    </section>
  );
}

/** Instagram reels row: snap-scroll on phones, grid from sm up; profile button. */
export function InstagramSection({ block, className = 'section-y' }) {
  const [playing, setPlaying] = useState(null);
  const items = (block?.items || []).filter((v) => v.video_id);
  if (!items.length) return null;
  const heading = block.heading || {};
  const profile = heading.profile_url;
  return (
    <section className={className} aria-label={heading.title || 'Instagram reels'}>
      <div className="container-x">
        <SectionHeading eyebrow={<><Icon name="instagram" className="size-3.5 text-[#E1306C]" />Instagram</>} title={heading.title || 'Follow us on Instagram'} subtitle={heading.subtitle}
          action={profile && (
            <a href={profile} target="_blank" rel="noopener noreferrer" className="btn bg-gradient-to-r from-[#833AB4] via-[#E1306C] to-[#F77737] text-white shadow-[0_6px_18px_-6px_rgb(225_48_108/0.6)] hover:-translate-y-0.5">
              <Icon name="instagram" className="size-5" />Follow on Instagram
            </a>
          )} />
        <ul className="scrollbar-none -mx-4 flex snap-x snap-mandatory gap-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:grid sm:grid-cols-3 sm:overflow-visible sm:px-0 lg:grid-cols-5">
          {items.map((v, i) => (
            <Reveal as="li" key={v.id || v.video_id} delay={(i % 5) * 70} className="w-[62%] shrink-0 snap-start sm:w-auto sm:min-w-0">
              <InstagramCard video={v} onPlay={setPlaying} />
            </Reveal>
          ))}
        </ul>
      </div>
      <VideoModal video={playing} onClose={() => setPlaying(null)} />
    </section>
  );
}

/** Both platforms for a placement payload ({youtube, instagram}). */
export function VideoSections({ videos, youtubeClass, instagramClass }) {
  if (!videos) return null;
  return (
    <>
      <YouTubeSection block={videos.youtube} className={youtubeClass} />
      <InstagramSection block={videos.instagram} className={instagramClass} />
    </>
  );
}
