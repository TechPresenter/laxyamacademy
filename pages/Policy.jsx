import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { formatDate } from '../js/api.js';
import { ErrorState, Icon, PageHeader, Skeleton, useApi, useSeo, useSite } from '../js/components.jsx';
import NotFound from './NotFound.jsx';

const LEGAL_NAME = 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED';
const LEGAL_CIN = 'U85491DL2026PTC470370';
const RELATED = [['/privacy-policy', 'Privacy Policy'], ['/terms', 'Terms & Conditions'], ['/refund-policy', 'Refund Policy'], ['/cancellation-policy', 'Cancellation Policy'], ['/disclaimer', 'Disclaimer'], ['/cookie-policy', 'Cookie Policy']];

const PATHS = { privacy: '/privacy-policy', terms: '/terms', refund: '/refund-policy', cancellation: '/cancellation-policy', disclaimer: '/disclaimer', cookies: '/cookie-policy' };

const slugify =(s, i) => 'sec-' + (i + 1) + '-' + String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40);

/** Privacy, Terms, Refund and other legal pages - content managed in admin CMS (cms.php?action=page). */
export default function Policy({ pageKey }) {
  const { data, loading, error, reload } = useApi('cms.php', { action: 'page', key: pageKey });
  const { data: layout } = useSite();
  const company = layout?.company;
  useSeo({ title: data ? `${data.title} | Laxyam Academy` : 'Laxyam Academy', description: data?.intro || undefined });
  const sections = (data?.sections || []).map((s, i) => ({ ...s, id: slugify(s.heading, i) }));
  const active = useActiveSection(sections.map((s) => s.id));

  if (error?.status === 404) return <NotFound />;
  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading && !data) {
    return (
      <div aria-busy="true"><div className="bg-mesh"><div className="container-x space-y-4 py-12"><Skeleton className="h-4 w-40" /><Skeleton className="h-10 w-80 max-w-full" /></div></div>
        <div className="container-x max-w-3xl space-y-3 py-10">{[0, 1, 2, 3, 4, 5].map((i) => <Skeleton key={i} className="h-4 w-full" />)}</div></div>
    );
  }

  const toc = sections.filter((s) => s.heading);
  return (
    <>
      <PageHeader
        eyebrow={<><Icon name="shield" className="size-3.5" />Legal</>}
        title={data.title}
        crumbs={[{ to: '/', label: 'Home' }, { label: data.title }]}
        subtitle={data.updated_at ? `Last updated ${formatDate(data.updated_at)}` : null}
        actions={<button type="button" onClick={() => window.print()} className="btn-light print:hidden"><Icon name="file" className="size-4" />Print / save PDF</button>}
      />
      <div className="container-x grid gap-10 py-10 lg:grid-cols-[260px_minmax(0,1fr)]">
        {toc.length > 1 && (
          <>
            {/* Mobile: collapsible contents */}
            <details className="card group p-4 lg:hidden print:hidden">
              <summary className="flex cursor-pointer list-none items-center justify-between font-display font-bold text-navy [&::-webkit-details-marker]:hidden">
                On this page <Icon name="chevronDown" className="size-5 text-primary transition-transform group-open:rotate-180" />
              </summary>
              <TocList toc={toc} active={active} />
            </details>
            <nav aria-label="On this page" className="hidden lg:block print:hidden">
              <div className="sticky top-24 card p-4">
                <p className="px-2 pb-2 text-xs font-bold tracking-wider text-muted uppercase">On this page</p>
                <TocList toc={toc} active={active} />
              </div>
            </nav>
          </>
        )}
        <article className={'min-w-0 ' + (toc.length > 1 ? '' : 'lg:col-span-2 lg:mx-auto lg:max-w-3xl')}>
          {data.intro && <p className="mb-8 rounded-2xl border border-primary/15 bg-primary-soft/50 p-5 text-[15px] leading-relaxed text-navy">{data.intro}</p>}
          <div className="max-w-3xl space-y-10">
            {sections.map((s, i) => (
              <section key={s.id} id={s.id} className="scroll-mt-28" aria-labelledby={s.heading ? `${s.id}-h` : undefined}>
                {s.heading && (
                  <h2 id={`${s.id}-h`} className="mb-3 flex items-baseline gap-3 font-display text-xl font-extrabold text-navy sm:text-2xl">
                    <span className="text-sm font-bold text-primary tabular-nums">{String(i + 1).padStart(2, '0')}</span>{s.heading}
                  </h2>
                )}
                <div className="prose-lite text-[15.5px] leading-[1.8] text-ink/90" dangerouslySetInnerHTML={{ __html: s.html }} />
              </section>
            ))}
          </div>
          <div className="mt-12 max-w-3xl rounded-2xl border border-line bg-surface p-5 text-sm text-muted">
            <p>This policy is issued by <strong className="text-ink">{company?.legal_name || LEGAL_NAME}</strong> (CIN {company?.cin || LEGAL_CIN}), which operates Laxyam Academy{company?.address ? `, ${company.address}` : ''}.</p>
            <p className="mt-2">Questions? <Link to="/contact" className="font-semibold text-primary hover:underline">Contact us</Link>.</p>
          </div>
          <nav aria-label="Other policies" className="mt-8 max-w-3xl print:hidden">
            <p className="mb-3 text-xs font-bold tracking-wider text-muted uppercase">Other policies</p>
            <ul className="flex flex-wrap gap-2">
              {RELATED.filter(([to]) => to !== PATHS[pageKey]).map(([to, label]) => (
                <li key={to}><Link to={to} className="inline-block rounded-full bg-white px-3.5 py-1.5 text-sm font-medium text-ink ring-1 ring-line transition hover:text-primary hover:ring-primary/40">{label}</Link></li>
              ))}
            </ul>
          </nav>
        </article>
      </div>
    </>
  );
}

function TocList({ toc, active }) {
  return (
    <ol className="mt-2 space-y-0.5 text-sm">
      {toc.map((s, i) => (
        <li key={s.id}>
          <a href={`#${s.id}`} aria-current={active === s.id ? 'location' : undefined}
            onClick={(e) => { e.preventDefault(); document.getElementById(s.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' }); history.replaceState(null, '', `#${s.id}`); }}
            className={'flex gap-2 rounded-lg px-2 py-1.5 transition ' + (active === s.id ? 'bg-primary-soft font-semibold text-primary' : 'text-muted hover:bg-surface hover:text-ink')}>
            <span className="tabular-nums opacity-60">{i + 1}.</span><span className="min-w-0">{s.heading}</span>
          </a>
        </li>
      ))}
    </ol>
  );
}

/** Id of the section currently at the top of the viewport (for the contents highlight). */
function useActiveSection(ids) {
  const [active, setActive] = useState(null);
  const key = ids.join('|');
  useEffect(() => {
    if (!key || typeof IntersectionObserver === 'undefined') return undefined;
    const els = key.split('|').map((id) => document.getElementById(id)).filter(Boolean);
    const io = new IntersectionObserver((entries) => {
      const top = entries.filter((e) => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
      if (top) setActive(top.target.id);
    }, { rootMargin: '-96px 0px -65% 0px' });
    els.forEach((el) => io.observe(el));
    return () => io.disconnect();
  }, [key]);
  return active;
}
