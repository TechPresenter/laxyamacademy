// Resource library (/resources) and resource details (/resources/:slug).
import { useEffect, useState } from 'react';
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom';
import { apiUrl, formatDate } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { DemoBadge, EmptyState, ErrorState, Icon, PageHeader, Pagination, Reveal, ShareButtons, Skeleton, formatBytes, useApi, useCmsSeo, useSeo } from '../js/components.jsx';
import { FileTypeIcon, fileTypeMeta } from '../js/content/shared.jsx';
import NotFound from './NotFound.jsx';

const ACCESS = {
  free: ['Free', 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'gift'],
  registered: ['Free with login', 'bg-blue-50 text-blue-700 ring-blue-200', 'user'],
  enrolled: ['Enrolled students', 'bg-amber-50 text-amber-800 ring-amber-200', 'lock'],
};

export default function Resources() {
  useCmsSeo('/resources', { title: 'Free Learning Resources - Laxyam Academy', description: 'Current affairs, previous year papers, NCERT notes, maps, quizzes and exam strategy resources.' });
  const { user } = useAuth();
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState(params.get('q') || '');
  const category = params.get('category') || '';
  const page = Number(params.get('page') || 1);
  const { data, loading, error, reload } = useApi('resources.php', { action: 'list', category, q: params.get('q') || '', page, u: user?.id || 0 });
  useEffect(() => setQ(params.get('q') || ''), [params]);
  const set = (c) => {
    const n = new URLSearchParams(params);
    Object.entries(c).forEach(([k, v]) => (v ? n.set(k, v) : n.delete(k)));
    if (!('page' in c)) n.delete('page');
    setParams(n);
  };
  const cats = data?.categories || [];
  const allCount = cats.reduce((s, c) => s + (Number(c.count) || 0), 0);
  const activeCat = cats.find((c) => c.slug === category);

  return (
    <>
      <PageHeader
        eyebrow={<><Icon name="book" className="size-3.5" />Study material library</>}
        title="Free Learning"
        highlight="Resources"
        subtitle="Notes, previous year papers, current affairs and official sources - curated for your preparation."
        crumbs={[{ to: '/', label: 'Home' }, { label: 'Free Resources' }]}
      >
        <form role="search" onSubmit={(e) => { e.preventDefault(); set({ q: q.trim() }); }} className="mt-7 flex max-w-2xl gap-2">
          <div className="flex min-w-0 flex-1 items-center rounded-2xl border border-line bg-white shadow-card transition focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15">
            <Icon name="search" className="ml-4 size-5 shrink-0 text-muted" />
            <label htmlFor="res-q" className="sr-only">Search resources</label>
            <input id="res-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search notes, papers, maps…" className="min-w-0 flex-1 bg-transparent px-3 py-3.5 text-sm focus:outline-none" />
          </div>
          <button className="btn-primary btn-lg shrink-0">Search</button>
        </form>
      </PageHeader>

      <section className="container-x grid gap-8 py-8 sm:py-10 lg:grid-cols-[250px_minmax(0,1fr)]">
        {/* Phones/tablets: horizontal chips. Desktop: sticky sidebar. */}
        <nav aria-label="Resource categories" className="min-w-0 lg:hidden">
          <div className="scrollbar-none -mx-4 flex snap-x gap-2 overflow-x-auto px-4 pb-1 [mask-image:linear-gradient(to_right,black_85%,transparent)] sm:mx-0 sm:px-0">
            <CatChip active={!category} onClick={() => set({ category: '' })} icon="grid" label="All" count={cats.length ? allCount : null} />
            {cats.map((c) => <CatChip key={c.slug} active={category === c.slug} onClick={() => set({ category: c.slug })} icon={c.icon} label={c.name} count={c.count} color={c.color} />)}
          </div>
        </nav>
        <aside className="hidden lg:block">
          <nav aria-label="Resource categories" className="card sticky top-24 p-3">
            <p className="px-3 pt-1 pb-2 text-xs font-bold tracking-wider text-muted uppercase">Categories</p>
            <ul className="space-y-1">
              <li><button type="button" onClick={() => set({ category: '' })} aria-pressed={!category} className={catBtn(!category)}><Icon name="grid" className="size-4" /><span className="flex-1 text-left">All resources</span>{cats.length > 0 && <span className="text-xs opacity-70">{allCount}</span>}</button></li>
              {cats.map((c) => (
                <li key={c.slug}>
                  <button type="button" onClick={() => set({ category: c.slug })} aria-pressed={category === c.slug} className={catBtn(category === c.slug)}>
                    <span className="grid size-7 shrink-0 place-items-center rounded-lg" style={category === c.slug ? undefined : { backgroundColor: (c.color || '#0B63F6') + '1A', color: c.color || '#0B63F6' }}><Icon name={c.icon} className="size-4" /></span>
                    <span className="flex-1 text-left">{c.name}</span><span className="text-xs opacity-70">{c.count}</span>
                  </button>
                </li>
              ))}
            </ul>
          </nav>
        </aside>
        <div className="min-w-0">
          {activeCat?.subtitle && <p className="mb-5 text-sm text-muted">{activeCat.subtitle}</p>}
          {error ? <ErrorState error={error} onRetry={reload} /> : loading && !data ? (
            <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">{[0, 1, 2, 3, 4, 5].map((i) => <li key={i} className="card overflow-hidden"><Skeleton className="aspect-[16/9]" rounded="rounded-none" /><div className="space-y-2 p-5"><Skeleton className="h-4 w-1/3" /><Skeleton className="h-5 w-4/5" /><Skeleton className="h-4 w-full" /></div></li>)}</ul>
          ) : data.items.length === 0 ? (
            <EmptyState icon="book" title="No resources found" text={params.get('q') || category ? 'Try another category or search term.' : 'New study material is added regularly - check back soon.'}
              action={params.get('q') || category ? <button type="button" onClick={() => setParams({})} className="btn-outline">Clear filters</button> : null} />
          ) : (
            <>
              <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                {data.items.map((r, i) => <ResourceCard key={r.id} r={r} delay={(i % 3) * 60} />)}
              </ul>
              <Pagination page={page} totalPages={data.pagination?.total_pages || 1} onChange={(p) => { set({ page: String(p) }); window.scrollTo({ top: 0, behavior: 'smooth' }); }} />
            </>
          )}
        </div>
      </section>
    </>
  );
}

function CatChip({ active, onClick, icon, label, count, color }) {
  return (
    <button type="button" onClick={onClick} aria-pressed={active}
      className={'inline-flex shrink-0 snap-start items-center gap-2 rounded-full px-4 py-2.5 text-sm font-semibold ring-1 ring-inset transition ' + (active ? 'bg-primary text-white ring-primary shadow-md' : 'bg-white text-ink ring-line hover:ring-primary/40')}>
      <Icon name={icon} className="size-4" style={active || !color ? undefined : { color }} />{label}
      {count !== null && count !== undefined && <span className={'rounded-full px-1.5 text-[11px] font-bold ' + (active ? 'bg-white/25' : 'bg-surface text-muted')}>{count}</span>}
    </button>
  );
}

const catBtn = (on) => 'flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-medium transition ' + (on ? 'bg-gradient-to-r from-primary to-[#2f7bff] text-white shadow-md' : 'text-ink hover:bg-surface');

function AccessBadge({ type }) {
  const [label, cls, icon] = ACCESS[type] || ACCESS.free;
  return <span className={'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ' + cls}><Icon name={icon} className="size-3" />{label}</span>;
}

/** Library card: thumbnail (or tinted file-type tile), category, title, size, access and lock state. */
export function ResourceCard({ r, delay = 0 }) {
  const color = r.category?.color || '#0B63F6';
  const meta = fileTypeMeta(r.file_type);
  const locked = r.locked ?? !r.can_access;
  return (
    <Reveal as="li" delay={delay} className="card card-hover group relative flex flex-col overflow-hidden">
      <div className="relative aspect-[16/9] overflow-hidden bg-surface">
        {r.thumbnail_url ? (
          <img src={r.thumbnail_url} alt="" loading="lazy" width="640" height="360" className="size-full object-cover transition-transform duration-500 group-hover:scale-105" />
        ) : (
          <div className="relative grid size-full place-items-center" style={{ background: `linear-gradient(135deg, ${color}22, ${meta.color}18)` }}>
            <div aria-hidden="true" className="dot-grid-dark absolute inset-0 opacity-70" />
            <span className="relative grid size-16 place-items-center rounded-2xl bg-white shadow-card" style={{ color }}><Icon name={r.category?.icon || meta.icon} className="size-8" /></span>
          </div>
        )}
        <span className="absolute top-3 left-3 rounded-lg px-2 py-1 text-[10px] font-extrabold tracking-wide text-white uppercase shadow" style={{ backgroundColor: meta.color }}>{meta.label}</span>
        {locked && <span className="absolute top-3 right-3 grid size-8 place-items-center rounded-full bg-navy/80 text-white backdrop-blur" title={r.lock_message || 'Locked'}><Icon name="lock" className="size-4" /><span className="sr-only">Locked</span></span>}
      </div>
      <div className="flex flex-1 flex-col p-5">
        {r.category?.name && <p className="text-xs font-bold" style={{ color }}>{r.category.name}</p>}
        <h3 className="mt-1 font-display leading-snug font-bold text-navy"><Link to={`/resources/${r.slug}`} className="after:absolute after:inset-0 hover:text-primary">{r.title}</Link></h3>
        {r.description && <p className="mt-2 line-clamp-2 text-sm text-muted">{r.description}</p>}
        {locked && r.lock_message && <p className="mt-3 flex items-start gap-1.5 text-xs text-amber-800"><Icon name="lock" className="mt-0.5 size-3.5 shrink-0" />{r.lock_message}</p>}
        <div className="mt-auto flex flex-wrap items-center gap-2 pt-4 text-xs text-muted">
          <AccessBadge type={r.access_type} />
          {r.file_size ? <span>{formatBytes(r.file_size)}</span> : null}
          {r.downloads > 0 && <span className="flex items-center gap-1"><Icon name="arrowUp" className="size-3 rotate-180" />{r.downloads}</span>}
          {r.is_demo && <DemoBadge />}
        </div>
      </div>
    </Reveal>
  );
}

/** Download / open action, or the lock reason with the right next step (login or enrol). */
function AccessAction({ r }) {
  const location = useLocation();
  const locked = r.locked ?? !r.can_access;
  if (locked) {
    const reason = r.lock_reason || 'login_required';
    return (
      <div className="space-y-3 rounded-2xl border border-amber-200 bg-amber-50/80 p-4">
        <p className="flex items-start gap-2 text-sm text-amber-900"><Icon name="lock" className="mt-0.5 size-4 shrink-0" />{r.lock_message || (reason === 'login_required' ? 'Log in to access this resource.' : 'Available to enrolled students only.')}</p>
        {reason === 'login_required' ? (
          <div className="flex flex-col gap-2">
            <Link to={`/login?next=${encodeURIComponent(location.pathname)}`} className="btn-primary w-full"><Icon name="user" className="size-4" />Log in to access</Link>
            <Link to={`/register?next=${encodeURIComponent(location.pathname)}`} className="btn-light w-full">Create a free account</Link>
          </div>
        ) : r.course ? (
          <Link to={`/courses/${r.course.slug}`} className="btn-primary w-full">Enrol in {r.course.title} <Icon name="arrowRight" className="size-4" /></Link>
        ) : (
          <Link to="/courses" className="btn-primary w-full">Explore courses</Link>
        )}
      </div>
    );
  }
  const link = r.file_type === 'link';
  const href = r.download_url || apiUrl('resources.php', { action: 'download', slug: r.slug });
  return (
    <a href={href} {...(link ? { target: '_blank', rel: 'noopener noreferrer' } : {})} className="btn-primary btn-lg w-full">
      <Icon name={link ? 'externalLink' : 'arrowUp'} className={'size-4 ' + (link ? '' : 'rotate-180')} /> {link ? 'Open resource' : 'Download'}
    </a>
  );
}

export function ResourceDetails() {
  const { slug } = useParams();
  const { user } = useAuth();
  const { data: r, loading, error, reload } = useApi('resources.php', { action: 'detail', slug, u: user?.id || 0 });
  useSeo({ title: r?.seo?.title, description: r?.seo?.description, image: r?.thumbnail_url || undefined });
  if (error?.status === 404) return <NotFound />;
  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading && !r) {
    return (
      <div aria-busy="true"><div className="bg-mesh"><div className="container-x space-y-4 py-12"><Skeleton className="h-4 w-56" /><Skeleton className="h-9 w-2/3" /></div></div>
        <div className="container-x grid gap-6 py-10 lg:grid-cols-[1fr_340px]"><Skeleton className="h-72" rounded="rounded-2xl" /><Skeleton className="h-60" rounded="rounded-2xl" /></div></div>
    );
  }
  const meta = fileTypeMeta(r.file_type);
  const color = r.category?.color || '#0B63F6';

  return (
    <>
      <PageHeader
        title={<span className="flex flex-wrap items-center gap-3">{r.title}{r.is_demo && <DemoBadge />}</span>}
        crumbs={[{ to: '/', label: 'Home' }, { to: '/resources', label: 'Resources' }, ...(r.category?.slug ? [{ to: `/resources?category=${r.category.slug}`, label: r.category.name }] : []), { label: r.title }]}
      >
        <div className="mt-4 flex flex-wrap items-center gap-2">
          {r.category?.name && <span className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold" style={{ backgroundColor: color + '1A', color }}><Icon name={r.category.icon} className="size-3.5" />{r.category.name}</span>}
          <AccessBadge type={r.access_type} />
          {r.published_at && <span className="text-xs text-muted">Published {formatDate(r.published_at)}</span>}
        </div>
      </PageHeader>
      <div className="container-x grid gap-6 py-10 lg:grid-cols-[minmax(0,1fr)_340px]">
        <Reveal as="article" className="card min-w-0 overflow-hidden">
          {r.thumbnail_url && <img src={r.thumbnail_url} alt="" width="1200" height="675" className="aspect-[16/9] w-full object-cover" />}
          <div className="p-6 sm:p-8">
            <h2 className="font-display text-xl font-bold text-navy">About this resource</h2>
            {r.description_html ? <div className="prose-lite mt-3 text-[15px] text-ink/90" dangerouslySetInnerHTML={{ __html: r.description_html }} /> : <p className="mt-3 text-muted">No description.</p>}
            {r.file_type === 'link' && <p className="mt-4 flex items-start gap-1.5 text-xs text-muted"><Icon name="info" className="mt-0.5 size-3.5 shrink-0" />This opens an external website. Laxyam Academy is not responsible for external content.</p>}
            <div className="mt-6 border-t border-line pt-5"><ShareButtons title={r.title} text={r.description || ''} size="sm" /></div>
          </div>
        </Reveal>
        <aside className="space-y-4 lg:sticky lg:top-24 lg:self-start">
          <div className="card space-y-5 p-5">
            <div className="flex items-center gap-3">
              <FileTypeIcon type={r.file_type} className="size-12" />
              <div className="min-w-0"><p className="font-display font-bold text-navy">{meta.label === 'Link' ? 'Web resource' : `${meta.label} file`}</p>{r.file_size ? <p className="text-xs text-muted">{formatBytes(r.file_size)}</p> : null}</div>
            </div>
            <dl className="grid grid-cols-2 gap-3 text-sm">
              <div className="rounded-xl bg-surface p-3"><dt className="text-xs text-muted">Access</dt><dd className="mt-0.5 font-semibold text-navy">{(ACCESS[r.access_type] || ACCESS.free)[0]}</dd></div>
              <div className="rounded-xl bg-surface p-3"><dt className="text-xs text-muted">Downloads</dt><dd className="mt-0.5 font-semibold text-navy">{Number(r.downloads || 0).toLocaleString('en-IN')}</dd></div>
              {r.course && <div className="col-span-2 rounded-xl bg-surface p-3"><dt className="text-xs text-muted">Linked course</dt><dd className="mt-0.5 font-semibold"><Link to={`/courses/${r.course.slug}`} className="text-primary hover:underline">{r.course.title}</Link></dd></div>}
            </dl>
            <AccessAction r={r} />
          </div>
        </aside>
      </div>
      {r.related?.length > 0 && (
        <section className="container-x pb-14" aria-labelledby="rel-res">
          <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
            <h2 id="rel-res" className="font-display text-2xl font-extrabold text-navy">Related resources</h2>
            {r.category?.slug && <Link to={`/resources?category=${r.category.slug}`} className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">More in {r.category.name} <Icon name="arrowRight" className="size-4" /></Link>}
          </div>
          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">{r.related.map((x, i) => <ResourceCard key={x.id} r={x} delay={i * 60} />)}</ul>
        </section>
      )}
    </>
  );
}
