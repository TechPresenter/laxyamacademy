// One gallery album (/gallery/:slug): justified photo grid (keeps every photo's shape, no layout shift),
// YouTube items as play tiles, an accessible lightbox, share buttons and more albums.
import { lazy, Suspense, useCallback, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { formatDate } from '../js/api.js';
import { EmptyState, ErrorState, Icon, PageHeader, Reveal, ShareButtons, Skeleton, useApi, useSeo } from '../js/components.jsx';
import { AlbumCard, AlbumCounts, GalleryCta } from '../js/gallery/ui.jsx';
import NotFound from './NotFound.jsx';

const Lightbox = lazy(() => import('../js/gallery/Lightbox.jsx'));
const ratioOf = (it) => (it.type === 'youtube' ? 16 / 9 : it.width && it.height ? it.width / it.height : 4 / 3);

/** One tile of the justified grid: its width grows with the photo's aspect ratio, its height comes from the ratio. */
function Tile({ item, index, total, onOpen }) {
  const r = Math.min(3, Math.max(0.4, ratioOf(item)));
  const video = item.type === 'youtube';
  const label = `${item.caption || item.alt || (video ? 'Video' : 'Photo')} - ${video ? 'play video' : 'open photo'} ${index + 1} of ${total}`;
  return (
    <Reveal as="li" delay={(index % 6) * 50} className="relative min-w-0" style={{ flexGrow: r * 100, width: `calc(${r.toFixed(4)} * var(--row-h))` }}>
      <span aria-hidden="true" className="block" style={{ paddingBottom: `${(100 / r).toFixed(3)}%` }} />
      <button type="button" onClick={() => onOpen(index)} aria-label={label}
        className="group absolute inset-0 overflow-hidden rounded-xl bg-line/60 shadow-card ring-1 ring-navy/5 transition-shadow duration-300 hover:shadow-card-hover focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-primary sm:rounded-2xl">
        <img src={item.thumb_url} alt={item.alt || ''} loading={index < 8 ? 'eager' : 'lazy'} decoding="async"
          width={item.width || undefined} height={item.height || undefined}
          className="absolute inset-0 size-full object-cover transition-transform duration-700 ease-out group-hover:scale-105 motion-reduce:transition-none" />
        <span aria-hidden="true" className={'absolute inset-0 bg-gradient-to-t from-navy-deep/75 via-transparent to-transparent transition-opacity duration-300 ' + (video ? 'opacity-70' : 'opacity-0 group-hover:opacity-100 group-focus-visible:opacity-100')} />
        {video ? (
          <>
            <span aria-hidden="true" className="absolute inset-0 grid place-items-center">
              <span className="relative grid size-12 place-items-center rounded-full bg-[#FF0000] text-white shadow-lg transition-transform duration-300 group-hover:scale-110 sm:size-14">
                <span className="absolute inset-0 animate-pulse-ring rounded-full motion-reduce:animate-none" />
                <Icon name="play" className="ml-0.5 size-5 sm:size-6" />
              </span>
            </span>
            <span aria-hidden="true" className="absolute top-2 left-2 inline-flex items-center gap-1 rounded-full bg-white/95 px-2 py-0.5 text-[10px] font-bold text-navy shadow-sm sm:top-3 sm:left-3 sm:text-[11px]">
              <Icon name="youtube" className="size-3.5 text-[#FF0000]" />Video
            </span>
          </>
        ) : (
          <span aria-hidden="true" className="absolute top-2 right-2 grid size-8 scale-90 place-items-center rounded-full bg-white/90 text-navy opacity-0 shadow transition-all duration-300 group-hover:scale-100 group-hover:opacity-100 group-focus-visible:opacity-100 sm:top-3 sm:right-3">
            <Icon name="search" className="size-4" />
          </span>
        )}
        {item.caption && (
          <span aria-hidden="true" className={'absolute inset-x-0 bottom-0 line-clamp-2 p-2.5 text-left text-xs leading-snug font-semibold text-white transition-all duration-300 sm:p-3 sm:text-[13px] ' + (video ? '' : 'translate-y-1 opacity-0 group-hover:translate-y-0 group-hover:opacity-100 group-focus-visible:opacity-100')}>
            {item.caption}
          </span>
        )}
      </button>
    </Reveal>
  );
}

function AlbumSkeleton() {
  return (
    <div aria-busy="true">
      <div className="border-b border-line/60 bg-mesh"><div className="container-x space-y-4 py-10 sm:py-14"><Skeleton className="h-4 w-48" /><Skeleton className="h-10 w-2/3 max-w-xl" /><Skeleton className="h-4 w-1/2 max-w-md" /></div></div>
      <div className="container-x flex flex-wrap gap-3 pt-10 [--row-h:130px] sm:[--row-h:190px] lg:[--row-h:230px]">
        {[1.5, 0.75, 1.33, 1.5, 1, 1.78, 0.8, 1.5].map((r, i) => (
          <div key={i} className="min-w-0" style={{ flexGrow: r * 100, width: `calc(${r} * var(--row-h))` }}><div className="skeleton rounded-2xl" style={{ paddingBottom: `${100 / r}%` }} /></div>
        ))}
      </div>
    </div>
  );
}

export default function GalleryAlbum() {
  const { slug } = useParams();
  const { data, loading, error, reload } = useApi('gallery.php', { action: 'album', slug });
  const [open, setOpen] = useState(null);
  const album = data?.album;
  const items = data?.items || [];
  const more = data?.more || [];
  const close = useCallback(() => setOpen(null), []);

  const date = album?.event_date ? formatDate(album.event_date) : '';
  const desc = album?.description || '';
  // Short one-paragraph descriptions go in the header; long or multi-line ones get their own block (keeps line breaks).
  const shortDesc = desc && desc.length <= 240 && !desc.includes('\n') ? desc : '';
  useSeo({
    title: album ? `${album.title} - Photo Gallery | Laxyam Academy` : 'Photo Gallery | Laxyam Academy',
    description: album ? (desc || `Photos and videos from ${album.title}${date ? ` (${date})` : ''} at Laxyam Academy.`).slice(0, 160) : undefined,
    image: album?.cover_url || undefined,
  });

  if (error?.status === 404) return <NotFound />;
  if (error) return <ErrorState error={error} onRetry={reload} title="We couldn’t load this album." />;
  if (loading && !data) return <AlbumSkeleton />;

  return (
    <div className="pb-16">
      <PageHeader
        crumbs={[{ to: '/', label: 'Home' }, { to: '/gallery', label: 'Gallery' }, { label: album.title }]}
        eyebrow={date ? <><Icon name="calendar" className="size-3.5" /><time dateTime={album.event_date}>{date}</time></> : <><Icon name="camera" className="size-3.5" />Album</>}
        title={album.title}
        subtitle={shortDesc || undefined}
        actions={<Link to="/gallery" className="btn-light"><Icon name="chevronLeft" className="size-4" />All albums</Link>}
      >
        <div className="mt-6 flex flex-wrap items-center gap-x-6 gap-y-4">
          {items.length > 0 && <AlbumCounts photos={album.photo_count} videos={album.video_count} className="text-sm font-semibold text-navy [&_svg]:text-primary" />}
          <ShareButtons url={album.share_url} title={album.title} text={`${album.title} - photos from Laxyam Academy`} label="Share album:" size="sm" />
        </div>
      </PageHeader>

      <section className="container-x pt-8 sm:pt-12" aria-label={`${album.title} photos and videos`}>
        {desc && !shortDesc && <Reveal as="p" className="mb-8 max-w-3xl text-[15px] leading-relaxed whitespace-pre-line text-ink/85 sm:text-base">{desc}</Reveal>}
        {items.length ? (
          <ul className="flex flex-wrap gap-2 [--row-h:128px] after:grow-[1000000] after:content-[''] min-[400px]:[--row-h:150px] sm:gap-3 sm:[--row-h:190px] lg:[--row-h:240px]">
            {items.map((it, i) => <Tile key={it.id} item={it} index={i} total={items.length} onOpen={setOpen} />)}
          </ul>
        ) : (
          <EmptyState icon="camera" title="Photos coming soon" text="We are still adding the photos and videos for this album. Please check back shortly."
            action={<Link to="/gallery" className="btn-primary">Browse other albums</Link>} />
        )}

        {more.length > 0 && (
          <div className="mt-16">
            <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
              <h2 className="font-display text-2xl font-extrabold text-navy sm:text-[28px]">More albums</h2>
              <Link to="/gallery" className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">View all <Icon name="arrowRight" className="size-4" /></Link>
            </div>
            <ul className="grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
              {more.map((a, i) => <AlbumCard key={a.id} album={a} delay={i * 90} as="h3" />)}
            </ul>
          </div>
        )}
        <GalleryCta />
      </section>

      {open !== null && items[open] && (
        <Suspense fallback={null}>
          <Lightbox items={items} index={open} onIndex={setOpen} onClose={close} title={album.title} />
        </Suspense>
      )}
    </div>
  );
}
