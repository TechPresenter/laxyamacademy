// Govt job alert detail page (/jobs/:slug): GET jobs.php?action=alert&slug=[&preview=1]
// -> { alert: {...card fields, details (sanitised HTML), important_dates [{label, date, is_date}], application_fee,
//      age_limit, selection_process, extra_links [{label, url}], updated_at, share_url, seo {title, description},
//      preview, status}, related: [card, …] }
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { formatDate } from '../js/api.js';
import { CategoryPill, DemoBadge, ErrorState, Icon, PageHeader, Reveal, ShareButtons, Skeleton, useApi, useSeo } from '../js/components.jsx';
import { DeadlineChip, JOB_TYPE, JobTypeBadge, NewBadge, daysUntil, formatDay, postedLabel } from '../js/content/shared.jsx';
import { PrepareCta } from './Jobs.jsx';

const https = (u) => (typeof u === 'string' && /^https:\/\//i.test(u) ? u : null);
const PREVIEW_STATUS = { draft: 'a draft', archived: 'archived', published: 'scheduled and not live yet' };

export default function JobDetails() {
  const { slug } = useParams();
  const [params] = useSearchParams();
  // ?preview=1 lets staff with cms.manage open a draft / scheduled / archived alert (ignored for everyone else)
  const { data, loading, error, reload } = useApi('jobs.php', { action: 'alert', slug, preview: params.get('preview') === '1' ? 1 : '' });
  const a = data?.alert;
  const related = data?.related || [];
  const t = JOB_TYPE[a?.type] || JOB_TYPE.notification;
  useSeo({
    title: a?.seo?.title || (a ? `${a.title} - ${a.type_label || t.label} | Laxyam Academy` : 'Govt Job Alert | Laxyam Academy'),
    description: a?.seo?.description || a?.summary || undefined,
    robots: error?.status === 404 || a?.preview ? 'noindex,follow' : undefined,
  });

  if (error?.status === 404) return <AlertNotFound />;
  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading && !a) return <DetailSkeleton />;

  // important_dates: "date" is 'YYYY-MM-DD' when is_date, otherwise a note such as "To be announced"
  const dates = a.important_dates?.length
    ? a.important_dates
    : [a.apply_start && { label: 'Application starts', date: a.apply_start, is_date: true }, a.last_date && { label: 'Last date to apply', date: a.last_date, is_date: true }].filter(Boolean);
  const links = (a.extra_links || []).filter((l) => https(l?.url));
  const official = https(a.official_url);
  // Admin → Jobs: "Separate the stages with → or new lines"
  const steps = String(a.selection_process || '').split(/\s*(?:→|->|\r?\n)\s*/).map((s) => s.trim()).filter(Boolean);
  const closed = !!a.closed;
  const posted = postedLabel(a.published_at);
  const updated = a.updated_at && a.published_at && a.updated_at.slice(0, 10) !== a.published_at.slice(0, 10) ? formatDate(a.updated_at) : '';

  return (
    <>
      {a.preview && (
        <div role="status" className="border-b border-amber-200 bg-amber-50">
          <p className="container-x flex items-center gap-2 py-2.5 text-sm font-medium text-amber-900">
            <Icon name="eye" className="size-4 shrink-0" />Staff preview - this alert is {PREVIEW_STATUS[a.status] || a.status} and is not visible to the public.
          </p>
        </div>
      )}
      <PageHeader
        title={a.title}
        crumbs={[{ to: '/', label: 'Home' }, { to: '/jobs', label: 'Govt Jobs' }, { to: `/jobs?type=${a.type}`, label: a.type_label || t.plural }, { label: a.title }]}
      >
        <div className="mt-4 flex flex-wrap items-center gap-2">
          <JobTypeBadge type={a.type} label={a.type_label} />
          {a.is_new && <NewBadge />}
          <DeadlineChip lastDate={a.last_date} closed={closed} closingSoon={a.closing_soon} daysLeft={a.days_left} />
          <CategoryPill category={a.category} />
          {a.is_demo && <DemoBadge />}
        </div>
        {a.organization && <p className="mt-3 flex items-center gap-2 text-base font-semibold text-ink"><Icon name="landmark" className="size-5 shrink-0 text-primary" />{a.organization}</p>}
        <dl className="mt-6 grid max-w-4xl grid-cols-2 gap-3 sm:grid-cols-4">
          {posted && <HeroFact icon="calendar" label="Posted" value={posted} />}
          {a.last_date && <HeroFact icon="clock" label="Last date" value={formatDay(a.last_date)} tone={closed ? 'muted' : a.closing_soon ? 'danger' : undefined} />}
          {a.vacancies && <HeroFact icon="users" label="Vacancies" value={a.vacancies} />}
          {a.qualification && <HeroFact icon="graduationCap" label="Qualification" value={a.qualification} />}
        </dl>
        <div className="mt-6 flex flex-wrap gap-3">
          {official && <a href={official} target="_blank" rel="noopener noreferrer" className="btn-primary btn-lg">Official website <Icon name="externalLink" className="size-4" /></a>}
          <a href="#share" className="btn-light btn-lg"><Icon name="share" className="size-4" />Share</a>
        </div>
      </PageHeader>

      <section className="container-x grid gap-8 py-10 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div className="min-w-0 space-y-6">
          {a.summary && (
            <Reveal className="card p-6">
              <h2 className="font-display text-xl font-bold text-navy">Overview</h2>
              <p className="mt-2 leading-relaxed text-ink/90">{a.summary}</p>
            </Reveal>
          )}

          {dates.length > 0 && (
            <Reveal className="card overflow-hidden">
              <h2 className="flex items-center gap-2 border-b border-line px-6 py-4 font-display text-xl font-bold text-navy"><Icon name="calendar" className="size-5 text-primary" />Important dates</h2>
              <table className="w-full text-sm">
                <caption className="sr-only">Important dates for {a.title}</caption>
                <tbody className="divide-y divide-line">
                  {dates.map((d, i) => {
                    const past = d.is_date && daysUntil(d.date) < 0;
                    return (
                      <tr key={i} className="even:bg-surface/60">
                        <th scope="row" className="w-1/2 px-4 py-3 text-left font-medium text-muted sm:px-6">{d.label}</th>
                        <td className={'px-4 py-3 sm:px-6 ' + (!d.is_date ? 'font-medium text-muted italic' : past ? 'font-semibold text-muted' : 'font-semibold text-ink')}>
                          {d.is_date ? formatDay(d.date) : d.date}
                          {past && <span className="sr-only"> (passed)</span>}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </Reveal>
          )}

          {(a.application_fee || a.age_limit || steps.length > 0) && (
            <Reveal className="grid gap-4 sm:grid-cols-2">
              {a.application_fee && <InfoTile icon="creditCard" title="Application fee" color="text-violet bg-violet/10">{a.application_fee}</InfoTile>}
              {a.age_limit && <InfoTile icon="user" title="Age limit" color="text-teal bg-teal/10">{a.age_limit}</InfoTile>}
              {steps.length > 0 && (
                <div className="card p-5 sm:col-span-2">
                  <h2 className="flex items-center gap-2 font-display font-bold text-navy"><span className="grid size-9 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><Icon name="target" className="size-5" /></span>Selection process</h2>
                  {steps.length > 1 ? (
                    <ol className="mt-4 flex flex-wrap items-center gap-2">
                      {steps.map((s, i) => (
                        <li key={i} className="flex items-center gap-2">
                          <span className="inline-flex items-center gap-2 rounded-full bg-surface px-3 py-1.5 text-sm font-semibold text-ink ring-1 ring-line"><span className="grid size-5 place-items-center rounded-full bg-primary text-[11px] text-white">{i + 1}</span>{s}</span>
                          {i < steps.length - 1 && <Icon name="chevronRight" className="size-4 text-muted" />}
                        </li>
                      ))}
                    </ol>
                  ) : <p className="mt-3 text-sm text-ink/90">{steps[0]}</p>}
                </div>
              )}
            </Reveal>
          )}

          {a.details && (
            <Reveal className="card p-6">
              <h2 className="font-display text-xl font-bold text-navy">Full details</h2>
              {/* Sanitised server-side (format_job_alert → sanitize_html). Wide tables scroll inside the card on phones. */}
              <div className="prose-lite mt-3 overflow-x-auto text-[15px] text-ink/90 [&_table]:my-4 [&_table]:w-full [&_table]:min-w-[28rem] [&_table]:text-sm [&_td]:border [&_td]:border-line [&_td]:px-3 [&_td]:py-2 [&_th]:border [&_th]:border-line [&_th]:bg-surface [&_th]:px-3 [&_th]:py-2 [&_th]:text-left" dangerouslySetInnerHTML={{ __html: a.details }} />
            </Reveal>
          )}

          {(official || links.length > 0) && (
            <Reveal className="card p-6">
              <h2 className="flex items-center gap-2 font-display text-xl font-bold text-navy"><Icon name="link" className="size-5 text-primary" />Important links</h2>
              <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                {official && <LinkRow url={official} label="Official website / notification" primary />}
                {links.map((l, i) => <LinkRow key={i} url={l.url} label={l.label || 'Open link'} />)}
              </ul>
              <p className="mt-4 text-xs text-muted">Links open the official website in a new tab. Always verify details on the official notification.</p>
            </Reveal>
          )}
          {updated && <p className="flex items-center gap-1.5 text-xs text-muted"><Icon name="history" className="size-3.5" />Last updated {updated}</p>}
        </div>

        <aside className="space-y-5 lg:sticky lg:top-24 lg:self-start">
          <div id="share" className="card scroll-mt-28 p-5">
            <h2 className="font-display font-bold text-navy">Share with friends</h2>
            <p className="mt-1 text-sm text-muted">Help a fellow aspirant never miss a deadline.</p>
            <ShareButtons url={a.share_url} title={a.title} text={`${a.type_label || t.label}: ${a.title}${a.last_date ? ` - last date ${formatDay(a.last_date)}` : ''}`} label={null} size="sm" className="mt-4 flex flex-wrap items-center gap-2" />
          </div>
          <div className="card relative overflow-hidden p-5">
            <div aria-hidden="true" className="absolute -top-10 -right-10 size-32 rounded-full bg-primary/10 blur-2xl" />
            <span className="eyebrow">Prepare smarter</span>
            <h2 className="mt-3 font-display text-lg font-bold text-navy">{a.category?.name ? `${a.category.name} courses & tests` : 'Courses & mock tests'}</h2>
            <p className="mt-1 text-sm text-muted">Crack this exam with expert-led courses and full-length mock tests.</p>
            <div className="mt-4 flex flex-col gap-2">
              <Link to={a.category?.slug ? `/courses?category=${encodeURIComponent(a.category.slug)}` : '/courses'} className="btn-primary">View courses <Icon name="arrowRight" className="size-4" /></Link>
              <Link to="/mock-tests" className="btn-light">Take a mock test</Link>
            </div>
          </div>
          <p className="flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50/80 p-3.5 text-xs text-amber-900">
            <Icon name="shield" className="mt-0.5 size-4 shrink-0" />
            <span>Laxyam Academy is not a recruiting body and never collects application fees. <Link to="/disclaimer" className="font-semibold underline">Disclaimer</Link></span>
          </p>
        </aside>
      </section>

      {related.length > 0 && (
        <section className="container-x pb-4" aria-labelledby="related-heading">
          <h2 id="related-heading" className="mb-5 font-display text-2xl font-extrabold text-navy">Related alerts</h2>
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {related.slice(0, 6).map((r, i) => <RelatedAlert key={r.id || r.slug} r={r} delay={i * 60} />)}
          </ul>
        </section>
      )}

      <div className="container-x pb-14"><PrepareCta category={a.category} /></div>
    </>
  );
}

function HeroFact({ icon, label, value, tone }) {
  return (
    <div className="glass-light min-w-0 rounded-2xl p-3.5 shadow-card">
      <dt className="flex items-center gap-1.5 text-xs font-semibold text-muted"><Icon name={icon} className="size-3.5" />{label}</dt>
      <dd className={'mt-1 line-clamp-2 font-display text-sm font-bold break-words sm:text-base ' + (tone === 'danger' ? 'text-red-600' : tone === 'muted' ? 'text-muted line-through' : 'text-navy')} title={String(value)}>{value}</dd>
    </div>
  );
}

function InfoTile({ icon, title, color, children }) {
  return (
    <div className="card p-5">
      <h2 className="flex items-center gap-2 font-display font-bold text-navy"><span className={'grid size-9 place-items-center rounded-xl ' + color}><Icon name={icon} className="size-5" /></span>{title}</h2>
      <p className="mt-3 text-sm leading-relaxed whitespace-pre-line text-ink/90">{children}</p>
    </div>
  );
}

function LinkRow({ url, label, primary }) {
  return (
    <li>
      <a href={url} target="_blank" rel="noopener noreferrer"
        className={'group flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-sm font-semibold transition-all hover:-translate-y-0.5 hover:shadow-card ' + (primary ? 'border-primary/30 bg-primary-soft text-primary' : 'border-line bg-white text-ink hover:border-primary/40')}>
        <span className="min-w-0 truncate">{label}</span>
        <Icon name="externalLink" className="size-4 shrink-0 transition-transform group-hover:translate-x-0.5" />
      </a>
    </li>
  );
}

function RelatedAlert({ r, delay }) {
  const t = JOB_TYPE[r.type] || JOB_TYPE.notification;
  return (
    <Reveal as="li" delay={delay} className="card card-hover relative overflow-hidden p-5">
      <span aria-hidden="true" className={'absolute inset-x-0 top-0 h-1 ' + t.dot} />
      <div className="flex flex-wrap items-center gap-2"><JobTypeBadge type={r.type} label={r.type_label} />{r.is_new && <NewBadge />}</div>
      <h3 className="mt-2.5 line-clamp-2 font-display font-bold text-navy"><Link to={`/jobs/${r.slug}`} className="after:absolute after:inset-0 hover:text-primary">{r.title}</Link></h3>
      {r.organization && <p className="mt-1 truncate text-sm text-muted">{r.organization}</p>}
      {r.last_date && (
        <p className={'mt-2 text-xs font-semibold ' + (r.closed ? 'text-muted' : r.closing_soon ? 'text-red-600' : 'text-ink')}>
          {r.closed ? `Closed on ${formatDay(r.last_date)}` : `Last date: ${formatDay(r.last_date)}`}
        </p>
      )}
    </Reveal>
  );
}

function AlertNotFound() {
  return (
    <section className="bg-mesh">
      <div className="container-x py-20 text-center">
        <span className="mx-auto grid size-16 place-items-center rounded-2xl bg-white text-primary shadow-card"><Icon name="bell" className="size-8" /></span>
        <h1 className="heading-lg mt-5">This alert is no longer available</h1>
        <p className="mx-auto mt-3 max-w-md text-muted">It may have been removed or the link is incorrect. See the latest recruitment notices, admit cards and results.</p>
        <div className="mt-7 flex flex-wrap justify-center gap-3">
          <Link to="/jobs" className="btn-primary">All job alerts</Link>
          <Link to="/courses" className="btn-light">Explore courses</Link>
        </div>
      </div>
    </section>
  );
}

function DetailSkeleton() {
  return (
    <div aria-busy="true" aria-label="Loading job alert">
      <div className="bg-mesh"><div className="container-x space-y-4 py-12"><Skeleton className="h-4 w-48" /><Skeleton className="h-9 w-3/4" /><Skeleton className="h-5 w-1/3" /><div className="grid max-w-4xl grid-cols-2 gap-3 sm:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-16" rounded="rounded-2xl" />)}</div></div></div>
      <div className="container-x grid gap-8 py-10 lg:grid-cols-[1fr_340px]"><div className="space-y-6"><Skeleton className="h-40" rounded="rounded-2xl" /><Skeleton className="h-56" rounded="rounded-2xl" /></div><Skeleton className="h-64" rounded="rounded-2xl" /></div>
    </div>
  );
}
