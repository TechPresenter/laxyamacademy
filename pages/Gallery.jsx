// Photo & video gallery index (/gallery): album cards managed in admin/gallery.php.
import { Link } from 'react-router-dom';
import { EmptyState, ErrorState, Icon, PageHeader, useApi, useSeo } from '../js/components.jsx';
import { AlbumCard, AlbumCardSkeleton, GalleryCta, plural } from '../js/gallery/ui.jsx';

export default function Gallery() {
  // Same title/description index.php serves to crawlers for /gallery
  useSeo({
    title: 'Photo & Video Gallery | Laxyam Academy',
    description: 'Photos and videos from Laxyam Academy - classroom moments, events, topper felicitations and campus life.',
  });
  const { data, loading, error, reload } = useApi('gallery.php', { action: 'albums' });
  const albums = data?.items || [];
  const photos = albums.reduce((s, a) => s + (a.photo_count || 0), 0);
  const videos = albums.reduce((s, a) => s + (a.video_count || 0), 0);

  return (
    <div className="pb-16">
      <PageHeader
        crumbs={[{ to: '/', label: 'Home' }, { label: 'Gallery' }]}
        eyebrow={<><Icon name="camera" className="size-3.5" />Gallery</>}
        title="Moments at"
        highlight="Laxyam Academy"
        subtitle="Classroom energy, events, topper felicitations and campus life - in photos and videos."
      >
        {albums.length > 0 && (
          <ul className="mt-6 flex flex-wrap gap-2 text-sm font-semibold text-navy" aria-label="Gallery summary">
            {[['grid', plural(albums.length, 'album')], ['camera', plural(photos, 'photo')], ...(videos ? [['playCircle', plural(videos, 'video')]] : [])].map(([icon, label]) => (
              <li key={icon} className="inline-flex items-center gap-1.5 rounded-full bg-white/85 px-3 py-1.5 shadow-sm ring-1 ring-line backdrop-blur">
                <Icon name={icon} className="size-4 text-primary" />{label}
              </li>
            ))}
          </ul>
        )}
      </PageHeader>

      <section className="container-x pt-10 sm:pt-14" aria-label="Albums">
        {error ? (
          <ErrorState error={error} onRetry={reload} title="We couldn’t load the gallery." />
        ) : loading && !data ? (
          <ul className="grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-3" aria-busy="true">
            {[0, 1, 2, 3, 4, 5].map((i) => <AlbumCardSkeleton key={i} />)}
          </ul>
        ) : albums.length ? (
          <ul className="grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
            {albums.map((a, i) => <AlbumCard key={a.id} album={a} delay={(i % 3) * 90} />)}
          </ul>
        ) : (
          <EmptyState icon="camera" title="Our gallery is getting ready"
            text="Photos and videos from our classes, events and results celebrations will appear here soon."
            action={<div className="flex flex-wrap justify-center gap-3"><Link to="/courses" className="btn-primary">Explore courses</Link><Link to="/contact" className="btn-light">Contact us</Link></div>} />
        )}
        <GalleryCta />
      </section>
    </div>
  );
}
