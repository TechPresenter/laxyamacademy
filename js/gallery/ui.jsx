// Gallery building blocks shared by pages/Gallery.jsx and pages/GalleryAlbum.jsx.
// Data: gallery.php?action=albums → { items: [{id, title, slug, description, event_date, cover_url, photo_count, video_count, url}] }
import { Link } from 'react-router-dom';
import { formatDate } from '../api.js';
import { Icon, Reveal, Skeleton } from '../components.jsx';

export const plural = (n, one, many = one + 's') => `${n} ${n === 1 ? one : many}`;

/** "12 photos · 2 videos" pieces with icons (renders nothing for empty counts). */
export function AlbumCounts({ photos = 0, videos = 0, className = '' }) {
  return (
    <span className={'flex flex-wrap items-center gap-x-3 gap-y-1 ' + className}>
      {photos > 0 && <span className="inline-flex items-center gap-1.5"><Icon name="camera" className="size-3.5" />{plural(photos, 'photo')}</span>}
      {videos > 0 && <span className="inline-flex items-center gap-1.5"><Icon name="playCircle" className="size-3.5" />{plural(videos, 'video')}</span>}
    </span>
  );
}

/**
 * Album card: cover with a slow hover zoom under a navy gradient, title, date and counts.
 * Two soft "prints" peek out above the cover so it reads as an album, not a single photo.
 */
export function AlbumCard({ album, delay = 0, as: Heading = 'h2' }) {
  const date = album.event_date ? formatDate(album.event_date) : '';
  return (
    <Reveal as="li" delay={delay} className="min-w-0 pt-2">
      <Link to={album.url || `/gallery/${album.slug}`} className="group relative block rounded-2xl focus-visible:outline-none">
        <span aria-hidden="true" className="absolute inset-x-6 -top-2 h-10 rounded-2xl bg-primary/15 transition-transform duration-500 group-hover:-translate-y-1.5 motion-reduce:transition-none" />
        <span aria-hidden="true" className="absolute inset-x-3 -top-1 h-10 rounded-2xl bg-primary/25 transition-transform duration-500 group-hover:-translate-y-1 motion-reduce:transition-none" />
        <span className="relative block overflow-hidden rounded-2xl bg-navy shadow-card ring-1 ring-navy/5 transition-all duration-500 group-hover:-translate-y-1 group-hover:shadow-card-hover group-focus-visible:ring-4 group-focus-visible:ring-primary/40 motion-reduce:transition-none">
          <span className="relative block aspect-[4/3]">
            {album.cover_url ? (
              <img src={album.cover_url} alt="" loading="lazy" decoding="async" width="900" height="675"
                className="absolute inset-0 size-full object-cover transition-transform duration-[1200ms] ease-out group-hover:scale-110 motion-reduce:transition-none" />
            ) : (
              <span aria-hidden="true" className="absolute inset-0 grid place-items-center bg-gradient-to-br from-primary via-violet to-navy text-white/80">
                <Icon name="camera" className="size-12" />
              </span>
            )}
            <span aria-hidden="true" className="absolute inset-0 bg-gradient-to-t from-navy-deep/90 via-navy-deep/25 to-transparent transition-opacity duration-500 group-hover:opacity-95" />
            {date && (
              <span className="absolute top-3 left-3 inline-flex items-center gap-1.5 rounded-full bg-white/90 px-2.5 py-1 text-[11px] font-bold text-navy shadow-sm backdrop-blur">
                <Icon name="calendar" className="size-3.5 text-primary" /><time dateTime={album.event_date}>{date}</time>
              </span>
            )}
            {album.video_count > 0 && (
              <span className="absolute top-3 right-3 inline-flex items-center gap-1 rounded-full bg-[#FF0000] px-2.5 py-1 text-[11px] font-bold text-white shadow-sm">
                <Icon name="play" className="size-3" />{album.video_count > 1 ? `${album.video_count} videos` : 'Video'}
              </span>
            )}
            <span className="absolute inset-x-0 bottom-0 p-4 sm:p-5">
              <Heading className="line-clamp-2 font-display text-lg leading-snug font-extrabold text-white drop-shadow-sm sm:text-xl">{album.title}</Heading>
              <AlbumCounts photos={album.photo_count} videos={album.video_count} className="mt-1.5 text-xs font-semibold text-white/85" />
              <span className="mt-3 inline-flex translate-y-1 items-center gap-1.5 rounded-full bg-gold px-3 py-1.5 text-xs font-bold text-navy opacity-0 shadow-glow-gold transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100 group-focus-visible:translate-y-0 group-focus-visible:opacity-100 [@media(hover:none)]:translate-y-0 [@media(hover:none)]:opacity-100">
                View album <Icon name="arrowRight" className="size-3.5 transition-transform group-hover:translate-x-0.5" />
              </span>
            </span>
          </span>
        </span>
      </Link>
    </Reveal>
  );
}

export function AlbumCardSkeleton() {
  return (
    <li className="pt-2" aria-hidden="true">
      <div className="relative overflow-hidden rounded-2xl shadow-card">
        <Skeleton className="aspect-[4/3]" rounded="rounded-2xl" />
        <div className="absolute inset-x-0 bottom-0 space-y-2 p-5"><Skeleton className="h-5 w-3/4 opacity-70" /><Skeleton className="h-3 w-1/3 opacity-70" /></div>
      </div>
    </li>
  );
}

/** Closing call to action shown under the gallery (real links only). */
export function GalleryCta() {
  return (
    <Reveal className="relative isolate mt-14 overflow-hidden rounded-3xl bg-navy-mesh p-6 text-white sm:p-10">
      <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40 [mask-image:linear-gradient(to_left,black,transparent_75%)]" />
      <div aria-hidden="true" className="absolute -top-16 -right-10 -z-10 size-56 rounded-full bg-gold/25 blur-3xl" />
      <div className="flex flex-wrap items-center justify-between gap-6">
        <div className="min-w-0 max-w-xl">
          <p className="font-script text-2xl text-gold">Your moment could be next</p>
          <p className="mt-1 font-display text-2xl leading-tight font-extrabold sm:text-3xl">Prepare with mentors who celebrate every win</p>
        </div>
        <div className="flex flex-wrap gap-3">
          <Link to="/courses" className="btn-gold">Explore courses <Icon name="arrowRight" className="size-4" /></Link>
          <Link to="/contact" className="btn-ghost-light">Talk to a counsellor</Link>
        </div>
      </div>
    </Reveal>
  );
}
