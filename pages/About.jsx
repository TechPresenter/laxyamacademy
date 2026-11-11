// About page: CMS copy (cms_sections page_about), exams we cover, the legal entity behind the
// brand (layout.company), contact details and the About-page YouTube / Instagram videos.
import { Link } from 'react-router-dom';
import { ErrorState, Icon, PageHeader, Reveal, SectionHeading, Spinner, useApi, useCmsSeo, useSite } from '../js/components.jsx';
import { VideoSections } from '../js/home/media.jsx';

// Shown only until layout.company arrives (or if the API predates it)
const COMPANY_FALLBACK = { legal_name: 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED', cin: 'U85491DL2026PTC470370' };
const TINTS = ['#0B63F6', '#6D4AFF', '#0EA5A4', '#F04E23', '#FFB020', '#16A34A'];

export default function About() {
  useCmsSeo('/about', { title: 'About Us - Laxyam Academy', description: 'Laxyam Academy, Badarpur, New Delhi - competitive exam preparation for UPSC, SSC, Banking, State PSC and more.' });
  const { data: site } = useSite();
  const { data, loading, error, reload } = useApi('cms.php', { action: 'page', key: 'about' });
  const videos = useApi('cms.php', { action: 'videos', placement: 'about' });

  if (error && error.status !== 404) return <ErrorState error={error} onRetry={reload} />;

  const s = site?.site || {};
  const company = { ...COMPANY_FALLBACK, ...Object.fromEntries(Object.entries(site?.company || {}).filter(([, v]) => v)) };
  const address = company.address || s.address;
  const exams = (site?.mega?.length ? site.mega : site?.categories || []).filter((c) => c?.slug);
  const sections = data?.sections?.filter((x) => x.heading || x.html) || [];
  const phone = (s.phone || '').replace(/[^\d+]/g, '');
  const whatsapp = (s.whatsapp || '').replace(/\D/g, '');

  return (
    <>
      <PageHeader eyebrow={<><Icon name="info" className="size-3.5" />About us</>} title="About" highlight="Laxyam Academy"
        subtitle={s.tagline || undefined} crumbs={[{ to: '/', label: 'Home' }, { label: 'About Us' }]} />

      {loading ? <Spinner /> : data && (
        <section className="container-x grid items-center gap-12 py-12 sm:py-16 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
          <Reveal className="min-w-0">
            <span className="eyebrow">{s.tagline || 'Learn • Prepare • Achieve'}</span>
            {/* The banner already reads "About Laxyam Academy"; avoid printing the same line twice */}
            <h2 className="heading-lg mt-4">{data.title && data.title.trim().toLowerCase() !== 'about laxyam academy' ? data.title : 'Who we are'}</h2>
            {data.intro && <p className="mt-5 text-[15px] leading-relaxed text-muted sm:text-lg">{data.intro}</p>}
            <div className="mt-8 flex flex-wrap gap-3">
              <Link to="/courses" className="btn-primary btn-lg">Explore courses <Icon name="arrowRight" className="size-4" /></Link>
              <Link to="/contact" className="btn-light btn-lg"><Icon name="mapPin" className="size-4" />Visit or contact us</Link>
            </div>
          </Reveal>
          {data.image_url && (
            <Reveal delay={120} className="relative mx-auto w-full max-w-md min-w-0">
              <div aria-hidden="true" className="absolute inset-6 rounded-full bg-gradient-to-br from-primary/25 via-violet/20 to-gold/25 blur-3xl" />
              <div className="relative rounded-[2rem] border border-line bg-white/70 p-6 shadow-card-hover backdrop-blur">
                <img src={data.image_url} alt="" width="560" height="420" loading="lazy" className="mx-auto w-full" />
              </div>
              {exams.length > 0 && (
                <div className="glass-light absolute -bottom-5 left-4 flex animate-float items-center gap-3 rounded-2xl px-4 py-2.5 shadow-card-hover">
                  <span className="grid size-10 place-items-center rounded-xl bg-gradient-to-br from-primary to-violet text-white"><Icon name="target" className="size-5" /></span>
                  <span className="leading-tight"><span className="block font-display text-lg font-extrabold text-navy">{exams.length}</span><span className="block text-[11px] font-semibold text-muted">exam categories</span></span>
                </div>
              )}
            </Reveal>
          )}
        </section>
      )}

      {sections.length > 0 && (
        <section className="section-y bg-surface">
          <div className="container-x">
            <SectionHeading align="center" eyebrow={<><Icon name="sparkles" className="size-3.5" />Our approach</>} title="What we" highlight="stand for" />
            <ul className={'grid gap-5 sm:grid-cols-2 ' + (sections.length % 3 === 0 || sections.length > 4 ? 'lg:grid-cols-3' : sections.length === 4 ? 'lg:grid-cols-4' : '')}>
              {sections.map((x, i) => {
                const tint = TINTS[i % TINTS.length];
                return (
                  <Reveal as="li" key={x.heading || i} delay={(i % 3) * 80} className="min-w-0">
                    <article className="group card card-hover relative h-full overflow-hidden p-6 sm:p-7">
                      <span aria-hidden="true" className="absolute inset-x-0 top-0 h-1" style={{ background: `linear-gradient(90deg, ${tint}, #6D4AFF)` }} />
                      <span className="grid size-12 place-items-center rounded-2xl transition-transform duration-300 group-hover:scale-110" style={{ backgroundColor: tint + '14', color: tint }}>
                        <Icon name={x.icon || 'star'} className="size-6" />
                      </span>
                      <h3 className="mt-4 font-display text-lg font-bold text-navy">{x.heading}</h3>
                      <div className="prose-lite mt-2 text-sm text-muted" dangerouslySetInnerHTML={{ __html: x.html || '' }} />
                    </article>
                  </Reveal>
                );
              })}
            </ul>
          </div>
        </section>
      )}

      {exams.length > 0 && (
        <section className="section-y">
          <div className="container-x">
            <SectionHeading eyebrow={<><Icon name="graduationCap" className="size-3.5" />Exams</>} title="Exams we" highlight="prepare you for"
              action={<Link to="/categories" className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">All categories <Icon name="arrowRight" className="size-4" /></Link>} />
            <Reveal as="ul" className="flex flex-wrap gap-3">
              {exams.map((c) => (
                <li key={c.slug}>
                  <Link to={`/courses?category=${encodeURIComponent(c.slug)}`} className="group inline-flex items-center gap-2.5 rounded-2xl border border-line bg-white px-4 py-3 font-display text-sm font-bold text-navy shadow-card transition-all hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-card-hover">
                    {c.icon && <span className="grid size-8 place-items-center rounded-xl" style={{ backgroundColor: (c.color || '#0B63F6') + '1A', color: c.color || '#0B63F6' }}><Icon name={c.icon} className="size-4" /></span>}
                    {c.name}
                    {Number(c.count) > 0 && <span className="rounded-full bg-surface px-2 py-0.5 text-[11px] text-muted">{c.count}</span>}
                  </Link>
                </li>
              ))}
            </Reveal>
          </div>
        </section>
      )}

      {/* Company identity + contact */}
      <section className="pb-14 sm:pb-20">
        <div className="container-x grid gap-6 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
          <Reveal className="min-w-0">
            <div className="relative isolate h-full overflow-hidden rounded-3xl bg-navy p-6 text-white shadow-card-hover sm:p-8">
              <div aria-hidden="true" className="bg-navy-mesh absolute inset-0 -z-20" />
              <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-30" />
              <span className="glass inline-flex items-center gap-2 rounded-full px-3 py-1 font-display text-xs font-bold tracking-[0.12em] uppercase"><Icon name="building" className="size-3.5" />Company</span>
              <p className="mt-4 text-sm text-white/75">Laxyam Academy is a brand of</p>
              <p className="mt-1 font-display text-xl leading-snug font-extrabold tracking-wide text-white sm:text-2xl">{company.legal_name}</p>
              <dl className="mt-6 grid gap-4 text-sm sm:grid-cols-2">
                {company.cin && <Fact icon="file" label="Corporate Identity Number (CIN)" value={company.cin} mono />}
                {company.gstin && <Fact icon="receipt" label="GSTIN" value={company.gstin} mono />}
                {address && <Fact icon="mapPin" label="Address" value={address} wide={!company.gstin} />}
              </dl>
            </div>
          </Reveal>
          <Reveal delay={100} className="min-w-0">
            <div className="card h-full p-6 sm:p-8">
              <h2 className="font-display text-xl font-extrabold text-navy sm:text-2xl">Talk to our counsellors</h2>
              <p className="mt-1 text-sm text-muted">Questions about a course, batch timings or fees? We are happy to help.</p>
              <ul className="mt-6 space-y-4 text-sm">
                {s.address && <Info icon="mapPin" title="Visit us">{s.address}</Info>}
                {phone && <Info icon="phone" title="Call us"><a href={`tel:${phone}`} className="font-semibold hover:text-primary">{s.phone}</a></Info>}
                {s.email && <Info icon="mail" title="Email"><a href={`mailto:${s.email}`} className="font-semibold break-all hover:text-primary">{s.email}</a></Info>}
                {s.office_hours && <Info icon="clock" title="Office hours">{s.office_hours}</Info>}
              </ul>
              <div className="mt-7 flex flex-wrap gap-3">
                <Link to="/contact" className="btn-primary"><Icon name="send" className="size-4" />Send an enquiry</Link>
                {whatsapp && <a href={`https://wa.me/${whatsapp}`} target="_blank" rel="noopener noreferrer" className="btn-whatsapp"><Icon name="whatsapp" className="size-4" />WhatsApp</a>}
              </div>
            </div>
          </Reveal>
        </div>
      </section>

      <VideoSections videos={videos.data} youtubeClass="section-y bg-surface" instagramClass="section-y" />
    </>
  );
}

function Fact({ icon, label, value, mono, wide }) {
  return (
    <div className={'flex gap-3 ' + (wide ? 'sm:col-span-2' : '')}>
      <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-white/10 text-gold"><Icon name={icon} className="size-4" /></span>
      <div className="min-w-0">
        <dt className="text-xs font-semibold text-white/60">{label}</dt>
        <dd className={'mt-0.5 break-words text-white ' + (mono ? 'font-mono text-[13px] tracking-wide' : '')}>{value}</dd>
      </div>
    </div>
  );
}

function Info({ icon, title, children }) {
  return (
    <li className="flex gap-3">
      <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name={icon} className="size-5" /></span>
      <div className="min-w-0"><p className="font-semibold text-navy">{title}</p><div className="text-ink/80">{children}</div></div>
    </li>
  );
}
