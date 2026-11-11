import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { EmptyState, ErrorState, Icon, MicButton, PageHeader, Reveal, Skeleton, useApi, useSeo, useSite } from '../js/components.jsx';

const LABEL = { general: 'General', courses: 'Courses', tests: 'Mock Tests', payments: 'Payments', account: 'Account', live: 'Live Classes', resources: 'Resources' };
const ICONS = { general: 'help', courses: 'book', tests: 'clipboard', payments: 'creditCard', account: 'user', live: 'video', resources: 'file' };

/** Wraps matches of `q` in <mark> (plain text only; answers are stored as text). */
function highlight(text, q) {
  if (!q) return text;
  const i = text.toLowerCase().indexOf(q.toLowerCase());
  if (i < 0) return text;
  return <>{text.slice(0, i)}<mark className="rounded bg-gold/40 px-0.5 text-inherit">{text.slice(i, i + q.length)}</mark>{text.slice(i + q.length)}</>;
}

export default function Faq() {
  useSeo({ title: 'Frequently Asked Questions | Laxyam Academy', description: 'Answers about courses, live classes, mock tests, payments and your account at Laxyam Academy.' });
  const { data, loading, error, reload } = useApi('cms.php', { action: 'faqs' });
  const { data: layout } = useSite();
  const site = layout?.site;
  const [q, setQ] = useState('');
  const [cat, setCat] = useState('');
  const [open, setOpen] = useState(0); // index of the open question (one at a time); -1 = none
  const list = Array.isArray(data) ? data : data?.items || [];
  const term = q.trim();

  const items = useMemo(() => list.filter((f) =>
    (!cat || f.category === cat) && (!term || (f.question + ' ' + f.answer).toLowerCase().includes(term.toLowerCase()))), [list, term, cat]);
  useEffect(() => setOpen(term ? 0 : -1), [term, cat]);

  // FAQPage structured data for search engines
  useEffect(() => {
    if (!list.length) return undefined;
    const s = document.createElement('script');
    s.type = 'application/ld+json';
    s.textContent = JSON.stringify({
      '@context': 'https://schema.org', '@type': 'FAQPage',
      mainEntity: list.map((f) => ({ '@type': 'Question', name: f.question, acceptedAnswer: { '@type': 'Answer', text: f.answer } })),
    });
    document.head.appendChild(s);
    return () => s.remove();
  }, [list]);

  const cats = [...new Set(list.map((f) => f.category).filter(Boolean))];
  const countOf = (c) => list.filter((f) => f.category === c).length;

  return (
    <>
      <PageHeader eyebrow={<><Icon name="help" className="size-3.5" />Help centre</>} title="Frequently Asked" highlight="Questions" subtitle="Quick answers about courses, tests, payments and your account." crumbs={[{ to: '/', label: 'Home' }, { label: 'FAQs' }]} align="center">
        <form role="search" onSubmit={(e) => e.preventDefault()} className="mx-auto mt-7 flex max-w-xl items-center rounded-2xl border border-line bg-white pr-1.5 shadow-card transition focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15">
          <Icon name="search" className="ml-4 size-5 shrink-0 text-muted" />
          <label htmlFor="faq-q" className="sr-only">Search questions</label>
          <input id="faq-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search questions…" className="min-w-0 flex-1 bg-transparent px-3 py-3.5 text-sm focus:outline-none" />
          <MicButton compact onText={(t) => setQ(t)} />
        </form>
      </PageHeader>
      <section className="container-x max-w-4xl py-8 sm:py-10">
        {cats.length > 1 && (
          <div className="scrollbar-none -mx-4 mb-6 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:justify-center sm:px-0" role="group" aria-label="Filter by topic">
            <button type="button" onClick={() => setCat('')} aria-pressed={!cat} className={chip(!cat)}><Icon name="grid" className="size-4" />All <span className="opacity-70">{list.length}</span></button>
            {cats.map((c) => <button key={c} type="button" onClick={() => setCat(c)} aria-pressed={cat === c} className={chip(cat === c)}><Icon name={ICONS[c] || 'help'} className="size-4" />{LABEL[c] || c} <span className="opacity-70">{countOf(c)}</span></button>)}
          </div>
        )}
        {error ? <ErrorState error={error} onRetry={reload} /> : loading && !data ? (
          <div className="card divide-y divide-line" aria-busy="true">{[0, 1, 2, 3, 4].map((i) => <div key={i} className="p-5"><Skeleton className="h-5 w-3/4" /></div>)}</div>
        ) : items.length === 0 ? (
          <EmptyState icon="help" title="No matching questions" text="Try different words, or ask our team directly." action={<Link to="/contact" className="btn-primary">Ask us directly</Link>} />
        ) : (
          <>
            {term && <p className="mb-3 text-sm text-muted" aria-live="polite">{items.length} result{items.length === 1 ? '' : 's'} for “{term}”</p>}
            <ul className="space-y-3">
              {items.map((f, i) => {
                const isOpen = open === i;
                const id = `faq-${i}`;
                return (
                  <li key={f.question} className={'card overflow-hidden transition-shadow ' + (isOpen ? 'border-primary/30 shadow-card-hover' : '')}>
                    <h2>
                      <button type="button" id={`${id}-q`} aria-expanded={isOpen} aria-controls={`${id}-a`} onClick={() => setOpen(isOpen ? -1 : i)}
                        className="flex w-full items-center justify-between gap-4 p-5 text-left font-display font-bold text-navy transition hover:bg-surface/60 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-inset focus-visible:ring-primary/20">
                        <span className="min-w-0">{highlight(f.question, term)}</span>
                        <span className={'grid size-8 shrink-0 place-items-center rounded-full transition ' + (isOpen ? 'rotate-180 bg-primary text-white' : 'bg-primary-soft text-primary')}><Icon name="chevronDown" className="size-4" /></span>
                      </button>
                    </h2>
                    <div id={`${id}-a`} role="region" aria-labelledby={`${id}-q`} hidden={!isOpen} className="px-5 pb-5">
                      <p className="text-[15px] leading-relaxed whitespace-pre-line text-muted">{highlight(f.answer, term)}</p>
                    </div>
                  </li>
                );
              })}
            </ul>
          </>
        )}
        <Reveal className="relative isolate mt-10 overflow-hidden rounded-3xl bg-navy-mesh p-6 text-white sm:p-8">
          <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-30" />
          <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div><p className="font-display text-xl font-bold">Still have questions?</p><p className="mt-1 text-sm text-white/80">Our counsellors are happy to help you choose the right course.</p></div>
            <div className="flex flex-wrap gap-3">
              {site?.whatsapp && <a href={`https://wa.me/${site.whatsapp}?text=${encodeURIComponent('Hello Laxyam Academy, I have a question.')}`} target="_blank" rel="noopener noreferrer" className="btn-whatsapp"><Icon name="whatsapp" className="size-4" />WhatsApp</a>}
              <Link to="/contact" className="btn-gold">Contact us</Link>
            </div>
          </div>
        </Reveal>
      </section>
    </>
  );
}

const chip = (on) => 'inline-flex shrink-0 items-center gap-1.5 rounded-full px-4 py-2 text-sm font-semibold ring-1 ring-inset transition ' + (on ? 'bg-primary text-white ring-primary shadow-md' : 'bg-white text-ink ring-line hover:ring-primary/40');
