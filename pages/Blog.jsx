import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { formatDate } from '../js/api.js';
import { DemoBadge, EmptyState, ErrorState, Icon, PageHeader, Pagination, Reveal, Skeleton, useApi, useCmsSeo } from '../js/components.jsx';

/** Blog list (/blog): search, category / tag filters, featured newest post, paginated cards. */
export default function Blog() {
  useCmsSeo('/blog', { title: 'Blog - Exam Preparation Tips | Laxyam Academy', description: 'Exam strategy, preparation tips and updates for UPSC, SSC, Banking and other competitive exams.' });
  const [params, setParams] = useSearchParams();
  const category = params.get('category') || '';
  const tag = params.get('tag') || '';
  const query = params.get('q') || '';
  const page = Number(params.get('page') || 1);
  const [q, setQ] = useState(query);
  useEffect(() => setQ(query), [query]);
  const { data, loading, error, reload } = useApi('cms.php', { action: 'blog', category, tag, q: query, page });
  const set = (c) => {
    const n = new URLSearchParams();
    Object.entries({ category, tag, q: query, ...c }).forEach(([k, v]) => v && n.set(k, v));
    if (!('page' in c)) n.delete('page');
    setParams(n);
  };

  const items = data?.items || [];
  // The newest post is featured on the unfiltered first page
  const featured = page === 1 && !category && !tag && !query ? items[0] : null;
  const rest = featured ? items.slice(1) : items;
  const filtered = !!(category || tag || query);

  return (
    <>
      <PageHeader
        eyebrow={<><Icon name="newspaper" className="size-3.5" />Insights & strategy</>}
        title="Laxyam"
        highlight="Blog"
        subtitle="Preparation strategy, study tips and exam updates from our faculty."
        crumbs={[{ to: '/', label: 'Home' }, { label: 'Blog' }]}
      >
        <form role="search" onSubmit={(e) => { e.preventDefault(); set({ q: q.trim(), category: '', tag: '' }); }} className="mt-7 flex max-w-2xl gap-2">
          <div className="flex min-w-0 flex-1 items-center rounded-2xl border border-line bg-white shadow-card transition focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15">
            <Icon name="search" className="ml-4 size-5 shrink-0 text-muted" />
            <label htmlFor="blog-q" className="sr-only">Search articles</label>
            <input id="blog-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search articles…" className="min-w-0 flex-1 bg-transparent px-3 py-3.5 text-sm focus:outline-none" />
          </div>
          <button className="btn-primary btn-lg shrink-0">Search</button>
        </form>
      </PageHeader>
      <section className="container-x py-8 sm:py-10">
        <div className="scrollbar-none -mx-4 mb-8 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0" role="group" aria-label="Filter by category">
          <button type="button" onClick={() => setParams({})} aria-pressed={!filtered} className={chip(!filtered)}>All</button>
          {data?.categories?.map((c) => <button key={c.slug} type="button" onClick={() => setParams({ category: c.slug })} aria-pressed={category === c.slug} className={chip(category === c.slug)}>{c.name} <span className="opacity-70">({c.count})</span></button>)}
          {tag && <button type="button" onClick={() => set({ tag: '' })} className={chip(true)} aria-label={`Remove tag ${tag}`}>#{tag} <Icon name="x" className="size-3.5" /></button>}
          {query && <button type="button" onClick={() => set({ q: '' })} className={chip(true)} aria-label={`Clear search ${query}`}>“{query}” <Icon name="x" className="size-3.5" /></button>}
        </div>
        {error ? <ErrorState error={error} onRetry={reload} /> : loading && !data ? (
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" aria-busy="true">{[0, 1, 2].map((i) => <div key={i} className="card overflow-hidden"><Skeleton className="aspect-[16/9]" rounded="rounded-none" /><div className="space-y-2 p-5"><Skeleton className="h-3 w-1/3" /><Skeleton className="h-5 w-4/5" /><Skeleton className="h-4 w-full" /></div></div>)}</div>
        ) : items.length === 0 ? (
          <EmptyState icon="newspaper" title={filtered ? 'No matching articles' : 'No articles yet'} text={filtered ? 'Try another search or category.' : 'Our faculty are writing - check back soon.'}
            action={filtered ? <button type="button" onClick={() => setParams({})} className="btn-outline">Show all articles</button> : null} />
        ) : (
          <>
            {featured && <FeaturedPost post={featured} />}
            {rest.length > 0 && (
              <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                {rest.map((p, i) => <PostCard key={p.id} post={p} delay={(i % 3) * 60} />)}
              </ul>
            )}
            <Pagination page={page} totalPages={data.pagination?.total_pages || 1} onChange={(p) => { set({ page: String(p) }); window.scrollTo({ top: 0, behavior: 'smooth' }); }} />
          </>
        )}
      </section>
    </>
  );
}

const chip = (on) => 'inline-flex shrink-0 items-center gap-1.5 rounded-full px-4 py-2 text-sm font-semibold ring-1 ring-inset transition ' + (on ? 'bg-primary text-white ring-primary shadow-md' : 'bg-white text-ink ring-line hover:ring-primary/40');

function Cover({ post, className = '' }) {
  return post.image_url ? (
    <img src={post.image_url} alt="" loading="lazy" width="640" height="360" className={'size-full object-cover transition-transform duration-500 group-hover:scale-105 ' + className} />
  ) : (
    <div className={'relative grid size-full place-items-center bg-gradient-to-br from-primary via-violet to-navy ' + className}>
      <div aria-hidden="true" className="dot-grid absolute inset-0 opacity-50" />
      <Icon name="newspaper" className="size-14 text-white/40" strokeWidth={1.5} />
    </div>
  );
}

function FeaturedPost({ post }) {
  return (
    <Reveal className="card card-hover group relative mb-8 grid overflow-hidden lg:grid-cols-[1.3fr_1fr]">
      <div className="aspect-[16/9] overflow-hidden lg:aspect-auto lg:min-h-80"><Cover post={post} /></div>
      <div className="flex flex-col justify-center p-6 sm:p-8">
        <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
          <span className="eyebrow">Featured</span>
          {post.category && <span className="font-semibold text-primary">{post.category.name}</span>}
          <span>{formatDate(post.published_at)}</span>
          {post.is_demo && <DemoBadge />}
        </div>
        <h2 className="mt-3 font-display text-2xl leading-tight font-extrabold text-navy sm:text-3xl"><Link to={`/blog/${post.slug}`} className="after:absolute after:inset-0 hover:text-primary">{post.title}</Link></h2>
        {post.excerpt && <p className="mt-3 line-clamp-3 text-muted">{post.excerpt}</p>}
        <p className="mt-5 flex items-center gap-2 text-sm text-muted"><span className="grid size-8 place-items-center rounded-full bg-primary-soft text-primary"><Icon name="user" className="size-4" /></span>{post.author}</p>
        <span className="mt-5 inline-flex items-center gap-1.5 font-semibold text-primary">Read article <Icon name="arrowRight" className="size-4 transition-transform group-hover:translate-x-1" /></span>
      </div>
    </Reveal>
  );
}

export function PostCard({ post, delay = 0 }) {
  return (
    <Reveal as="li" delay={delay} className="card card-hover group relative flex flex-col overflow-hidden">
      <div className="aspect-[16/9] overflow-hidden"><Cover post={post} /></div>
      <div className="flex flex-1 flex-col p-5">
        <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
          {post.category && <span className="font-semibold text-primary">{post.category.name}</span>}
          <span>{formatDate(post.published_at)}</span>
          {post.is_demo && <DemoBadge />}
        </div>
        <h2 className="mt-2 font-display text-lg leading-snug font-bold text-navy"><Link to={`/blog/${post.slug}`} className="after:absolute after:inset-0 hover:text-primary">{post.title}</Link></h2>
        {post.excerpt && <p className="mt-2 line-clamp-3 text-sm text-muted">{post.excerpt}</p>}
        <span className="mt-auto inline-flex items-center gap-1 pt-4 text-sm font-semibold text-primary">Read more <Icon name="arrowRight" className="size-4 transition-transform group-hover:translate-x-1" /></span>
      </div>
    </Reveal>
  );
}
