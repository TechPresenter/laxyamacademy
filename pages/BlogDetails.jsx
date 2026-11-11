import { useEffect, useMemo } from 'react';
import { Link, useParams } from 'react-router-dom';
import { formatDate } from '../js/api.js';
import { Breadcrumbs, DemoBadge, ErrorState, Icon, Reveal, ShareButtons, Skeleton, useApi, useSeo } from '../js/components.jsx';
import NotFound from './NotFound.jsx';
import { PostCard } from './Blog.jsx';

/** Minutes to read at ~200 words per minute (from the sanitised article HTML). */
function readingMinutes(html) {
  const words = String(html || '').replace(/<[^>]+>/g, ' ').trim().split(/\s+/).filter(Boolean).length;
  return Math.max(1, Math.round(words / 200));
}

const ARTICLE = 'prose-lite text-[17px] leading-[1.8] text-ink/90 [&_a]:text-primary [&_a]:underline [&_blockquote]:my-6 [&_blockquote]:rounded-r-xl [&_blockquote]:border-l-4 [&_blockquote]:border-primary [&_blockquote]:bg-primary-soft/50 [&_blockquote]:px-5 [&_blockquote]:py-3 [&_blockquote]:text-navy [&_h2]:mt-10 [&_h2]:mb-3 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-extrabold [&_h2]:text-navy [&_h3]:mt-8 [&_h3]:mb-2 [&_h3]:font-display [&_h3]:text-xl [&_h3]:font-bold [&_h3]:text-navy [&_img]:my-6 [&_img]:rounded-2xl [&_li]:mb-1.5 [&_ol]:mb-5 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:mb-5 [&_table]:my-6 [&_table]:w-full [&_table]:text-sm [&_td]:border [&_td]:border-line [&_td]:px-3 [&_td]:py-2 [&_th]:border [&_th]:border-line [&_th]:bg-surface [&_th]:px-3 [&_th]:py-2 [&_ul]:mb-5 [&_ul]:list-disc [&_ul]:pl-6';

export default function BlogDetails() {
  const { slug } = useParams();
  const { data: p, loading, error, reload } = useApi('cms.php', { action: 'blog-post', slug });
  useSeo({ title: p?.seo?.title, description: p?.seo?.description, image: p?.image_url });
  const minutes = useMemo(() => readingMinutes(p?.html), [p?.html]);

  // Article structured data (only for real, published posts)
  useEffect(() => {
    if (!p || p.is_demo) return undefined;
    const s = document.createElement('script');
    s.type = 'application/ld+json';
    s.textContent = JSON.stringify({
      '@context': 'https://schema.org', '@type': 'Article', headline: p.title, datePublished: p.published_at, dateModified: p.updated_at,
      author: { '@type': 'Organization', name: 'Laxyam Academy' }, image: p.image_url || undefined,
    });
    document.head.appendChild(s);
    return () => s.remove();
  }, [p]);

  if (error?.status === 404) return <NotFound />;
  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading && !p) {
    return (
      <div aria-busy="true"><div className="bg-mesh"><div className="container-x max-w-3xl space-y-4 py-12"><Skeleton className="h-4 w-40" /><Skeleton className="h-10 w-full" /><Skeleton className="h-10 w-2/3" /><Skeleton className="h-4 w-1/2" /></div></div>
        <div className="container-x max-w-3xl space-y-3 py-10">{[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-4 w-full" />)}</div></div>
    );
  }

  return (
    <div className="pb-14">
      <header className="relative isolate overflow-hidden border-b border-line/60 bg-mesh">
        <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:linear-gradient(to_bottom,black,transparent_85%)]" />
        <div className="container-x max-w-3xl py-8 sm:py-12">
          <Breadcrumbs items={[{ to: '/', label: 'Home' }, { to: '/blog', label: 'Blog' }, { label: p.title }]} />
          {p.category && <Link to={`/blog?category=${p.category.slug}`} className="eyebrow mt-6">{p.category.name}</Link>}
          <h1 className="mt-4 animate-fade-up font-display text-3xl leading-tight font-extrabold tracking-tight text-navy sm:text-[2.6rem]">{p.title}</h1>
          {p.excerpt && <p className="mt-4 text-lg leading-relaxed text-muted">{p.excerpt}</p>}
          <div className="mt-6 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-muted">
            <span className="flex items-center gap-2 font-semibold text-ink"><span className="grid size-9 place-items-center rounded-full bg-gradient-to-br from-primary to-violet text-white"><Icon name="user" className="size-4" /></span>{p.author}</span>
            <span className="flex items-center gap-1.5"><Icon name="calendar" className="size-4" /><time dateTime={p.published_at}>{formatDate(p.published_at)}</time></span>
            <span className="flex items-center gap-1.5"><Icon name="clock" className="size-4" />{minutes} min read</span>
            {p.is_demo && <DemoBadge />}
          </div>
        </div>
      </header>

      <article className="container-x max-w-3xl py-8 sm:py-10">
        {p.image_url && <img src={p.image_url} alt="" width="1200" height="675" className="mb-8 aspect-[16/9] w-full rounded-2xl object-cover shadow-card" />}
        <div className={ARTICLE} dangerouslySetInnerHTML={{ __html: p.html }} />
        {p.tags?.length > 0 && (
          <ul className="mt-8 flex flex-wrap gap-2" aria-label="Tags">
            {p.tags.map((t) => <li key={t}><Link to={`/blog?tag=${encodeURIComponent(t)}`} className="rounded-full bg-surface px-3 py-1 text-sm font-medium text-ink ring-1 ring-line transition hover:text-primary hover:ring-primary/40">#{t}</Link></li>)}
          </ul>
        )}
        <div className="mt-8 rounded-2xl border border-line bg-surface/60 p-5">
          <p className="mb-3 font-display font-bold text-navy">Found this useful? Share it with your study group.</p>
          <ShareButtons title={p.title} text={p.excerpt || ''} label={null} />
        </div>
        <Reveal className="relative isolate mt-10 overflow-hidden rounded-3xl bg-navy-mesh p-6 text-white sm:p-8">
          <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40 [mask-image:linear-gradient(to_left,black,transparent_70%)]" />
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div><p className="font-script text-xl text-gold">Turn reading into results</p><p className="font-display text-xl font-bold">Ready to start your preparation?</p></div>
            <div className="flex flex-wrap gap-3"><Link to="/courses" className="btn-gold">Explore courses</Link><Link to="/mock-tests" className="btn-ghost-light">Free mock tests</Link></div>
          </div>
        </Reveal>
      </article>

      {p.related?.length > 0 && (
        <section className="container-x" aria-labelledby="more-articles">
          <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
            <h2 id="more-articles" className="font-display text-2xl font-extrabold text-navy">More articles</h2>
            <Link to="/blog" className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">View all <Icon name="arrowRight" className="size-4" /></Link>
          </div>
          <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">{p.related.map((r, i) => <PostCard key={r.id} post={r} delay={i * 60} />)}</ul>
        </section>
      )}
    </div>
  );
}
