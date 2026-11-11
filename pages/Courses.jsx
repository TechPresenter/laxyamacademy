import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { formatPrice } from '../js/api.js';
import {
  CourseCard, CourseCardSkeleton, EmptyState, ErrorState, Icon, MicButton, PageHeader, Pagination, Reveal, Skeleton,
  courseTypeLabel, languageLabel, levelLabel, useApi, useSeo,
} from '../js/components.jsx';
import { CourseRow, CourseVideos, PromoBanners, useBodyLock, useEscape } from '../js/course/ui.jsx';

const SORTS = [
  ['popular', 'Most popular'],
  ['newest', 'Newest first'],
  ['rating', 'Highest rated'],
  ['price_asc', 'Price: low to high'],
  ['price_desc', 'Price: high to low'],
];
const FORMATS = ['offline', 'live', 'recorded', 'hybrid'];
const LEVELS = ['beginner', 'intermediate', 'advanced', 'all'];
const LANGS = ['hindi', 'english', 'bilingual'];
const RATINGS = [4.5, 4, 3.5, 3];
// URL keys that count as filters (q is the search; sort/page/view are not filters)
const FILTER_KEYS = ['q', 'category', 'pricing', 'type', 'level', 'language', 'price_max', 'rating_min'];

const list = (v) => (v ? v.split(',').filter(Boolean) : []);
const VIEW_KEY = 'lx-course-view';

/** URL params → courses.php?action=list query. pricing=free|paid maps onto the price filters. */
function toQuery(params, extra = {}) {
  const pricing = params.get('pricing');
  return {
    action: 'list',
    q: params.get('q') || '',
    category: params.get('category') || '',
    type: params.get('type') || '',
    language: params.get('language') || '',
    level: params.get('level') || '',
    price_min: pricing === 'paid' ? 1 : '',
    price_max: pricing === 'free' ? 0 : params.get('price_max') || '',
    rating_min: params.get('rating_min') || '',
    sort: params.get('sort') || 'popular',
    page: params.get('page') || 1,
    per_page: 12,
    ...extra,
  };
}

/** Returns a copy of params with `changes` applied (empty values delete the key; page resets). */
function applyChanges(params, changes) {
  const next = new URLSearchParams(params);
  for (const [k, v] of Object.entries(changes)) {
    if (v === '' || v === null || v === undefined || (Array.isArray(v) && !v.length)) next.delete(k);
    else next.set(k, Array.isArray(v) ? v.join(',') : String(v));
  }
  if (!('page' in changes)) next.delete('page');
  return next;
}

export default function Courses() {
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState(params.get('q') || '');
  const [drawer, setDrawer] = useState(false);
  const [view, setView] = useState(() => {
    try { return localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid'; } catch { return 'grid'; }
  });

  const { data, loading, error, reload } = useApi('courses.php', toQuery(params));
  const facets = data?.facets;
  const pag = data?.pagination;
  const category = data?.category;

  // SEO: category SEO when one category is selected, otherwise the CMS entry for /courses
  const cms = useApi('cms.php', { action: 'seo', path: '/courses' });
  useSeo({
    title: category?.seo?.title || cms.data?.title || 'Course Catalogue - Laxyam Academy',
    description: category?.seo?.description || cms.data?.meta_description || cms.data?.description,
    canonical: category ? undefined : cms.data?.canonical,
    robots: cms.data?.robots,
    image: cms.data?.og_image,
  });

  useEffect(() => setQ(params.get('q') || ''), [params]);
  useEffect(() => { try { localStorage.setItem(VIEW_KEY, view); } catch { /* ignore */ } }, [view]);

  const update = (changes) => setParams(applyChanges(params, changes));
  const changePage = (p) => {
    update({ page: p });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  const clearAll = () => {
    const next = new URLSearchParams();
    if (params.get('sort')) next.set('sort', params.get('sort'));
    setParams(next);
  };

  const cats = facets?.categories || [];
  const topCats = cats.filter((c) => !c.parent_id && c.course_count > 0);
  const chips = activeChips(params, cats, facets);
  const filterCount = chips.filter((c) => c.key !== 'q').length;

  return (
    <>
      <PageHeader
        crumbs={[{ to: '/', label: 'Home' }, { to: '/courses', label: 'Courses' }, ...(category ? [{ label: category.name }] : [])]}
        eyebrow={<><Icon name="graduationCap" className="size-3.5" />{category ? 'Exam category' : 'Course catalogue'}</>}
        title={category ? category.name : 'Find the course that gets you'}
        highlight={category ? 'courses' : 'selected'}
        subtitle={category?.description || 'Classroom coaching for government job exams - Railway, SSC, Banking, Police and State exams - plus foundation courses in Maths, Reasoning, GK/GS and English, with regular mock tests.'}
      >
        <form
          role="search"
          onSubmit={(e) => { e.preventDefault(); update({ q: q.trim() }); }}
          className="mt-7 flex w-full max-w-3xl min-w-0 items-center gap-1.5 rounded-2xl border border-line bg-white p-1.5 shadow-card-hover sm:gap-2 sm:p-2"
        >
          <label htmlFor="course-q" className="sr-only">Search courses</label>
          <Icon name="search" className="ml-2 size-5 shrink-0 text-muted sm:ml-3" />
          <input
            id="course-q" type="search" size={1} value={q} onChange={(e) => setQ(e.target.value)} maxLength={100}
            placeholder="Search courses, exams or topics…"
            className="w-0 min-w-0 flex-1 bg-transparent px-1.5 py-2.5 text-[15px] text-ink placeholder:text-muted/70 focus:outline-none sm:px-2"
          />
          {/* EN/हिं toggle only from sm up, so the bar fits 360px phones */}
          <span className="shrink-0 sm:hidden"><MicButton compact onText={(text, final) => { setQ(text); if (final) update({ q: text }); }} /></span>
          <span className="hidden shrink-0 sm:block"><MicButton onText={(text, final) => { setQ(text); if (final) update({ q: text }); }} /></span>
          <button className="btn-primary shrink-0 px-4 sm:px-6"><span className="hidden sm:inline">Search</span><Icon name="arrowRight" className="size-4 sm:hidden" /><span className="sr-only sm:hidden">Search</span></button>
        </form>
        {topCats.length > 0 && (
          <div className="mt-4 flex flex-wrap items-center gap-2 text-sm">
            <span className="font-medium text-muted">Popular:</span>
            {topCats.slice(0, 7).map((c) => (
              <button key={c.slug} type="button" onClick={() => update({ category: c.slug, q: '' })}
                className="rounded-full border border-line bg-white/80 px-3 py-1 font-medium text-ink backdrop-blur transition-colors hover:border-primary hover:text-primary">
                {c.name}
              </button>
            ))}
          </div>
        )}
        {data?.stats && (
          <ul className="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm font-semibold text-navy">
            {[['grid', data.stats.courses, 'courses'], ['playCircle', data.stats.lessons, 'lessons'], ['layers', data.stats.categories, 'exam categories']].filter(([, n]) => n > 0).map(([icon, n, label]) => (
              <li key={label} className="flex items-center gap-2"><span className="grid size-8 place-items-center rounded-lg bg-white text-primary shadow-card"><Icon name={icon} className="size-4" /></span>{n} {label}</li>
            ))}
          </ul>
        )}
      </PageHeader>

      <div className="container-x pt-6">
        <PromoBanners placement="courses" />
      </div>

      <section className="container-x grid gap-8 py-8 lg:grid-cols-[280px_minmax(0,1fr)] lg:py-10">
        <aside className="hidden min-w-0 lg:block" aria-label="Course filters">
          <div className="card scrollbar-thin sticky top-24 max-h-[calc(100vh-7rem)] overflow-y-auto p-5">
            <div className="mb-4 flex items-center justify-between">
              <h2 className="flex items-center gap-2 font-display text-lg font-bold"><Icon name="sliders" className="size-5 text-primary" />Filters</h2>
              {filterCount > 0 && <button type="button" onClick={clearAll} className="text-sm font-semibold text-primary hover:underline">Clear all</button>}
            </div>
            {facets ? <Filters params={params} onChange={update} facets={facets} /> : <FilterSkeleton />}
          </div>
        </aside>

        <div className="min-w-0">
          {/* Toolbar */}
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div className="min-w-0">
              <h2 className="font-display text-xl font-extrabold text-navy sm:text-2xl">
                {params.get('q') ? <>Results for <span className="text-primary">“{params.get('q')}”</span></> : category ? `${category.name} courses` : 'All courses'}
              </h2>
              <p className="text-sm text-muted" aria-live="polite">
                {pag ? (pag.total ? `Showing ${(pag.page - 1) * pag.per_page + 1}–${Math.min(pag.total, pag.page * pag.per_page)} of ${pag.total} ${pag.total === 1 ? 'course' : 'courses'}` : 'No courses found') : 'Loading courses…'}
              </p>
            </div>
            <div className="flex min-w-0 flex-wrap items-center gap-2">
              <button type="button" onClick={() => setDrawer(true)} className="btn-light lg:hidden" aria-haspopup="dialog">
                <Icon name="sliders" className="size-4" />Filters{filterCount > 0 && <span className="grid size-5 place-items-center rounded-full bg-primary text-[11px] text-white">{filterCount}</span>}
              </button>
              <label htmlFor="sort" className="sr-only">Sort courses</label>
              <select id="sort" value={params.get('sort') || 'popular'} onChange={(e) => update({ sort: e.target.value === 'popular' ? '' : e.target.value })} className="input w-auto py-2 pr-8">
                {SORTS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
              <div className="hidden rounded-xl border border-line bg-white p-1 sm:flex" role="group" aria-label="Layout">
                {[['grid', 'grid', 'Grid view'], ['list', 'menu', 'List view']].map(([v, icon, label]) => (
                  <button key={v} type="button" onClick={() => setView(v)} aria-pressed={view === v} aria-label={label} title={label}
                    className={'grid size-8 place-items-center rounded-lg transition-colors ' + (view === v ? 'bg-primary text-white shadow-sm' : 'text-muted hover:text-primary')}>
                    <Icon name={icon} className="size-4" />
                  </button>
                ))}
              </div>
            </div>
          </div>

          {/* Quick category chips */}
          {topCats.length > 0 && (
            <div className="scrollbar-none -mx-4 mb-4 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:px-0">
              <button type="button" onClick={() => update({ category: '' })} className={chip(!params.get('category'))}><Icon name="grid" className="size-4" />All</button>
              {topCats.map((c) => (
                <button key={c.slug} type="button" onClick={() => update({ category: c.slug })} className={chip(params.get('category') === c.slug)}>
                  <Icon name={c.icon} className="size-4" />{c.name}<span className="text-xs opacity-70">{c.course_count}</span>
                </button>
              ))}
            </div>
          )}

          {/* Active filter chips */}
          {chips.length > 0 && (
            <div className="mb-5 flex flex-wrap items-center gap-2">
              {chips.map((c) => (
                <button key={c.key + c.value} type="button" onClick={() => update(c.remove)}
                  className="group inline-flex max-w-full items-center gap-1.5 rounded-full border border-primary/25 bg-primary-soft py-1 pr-2 pl-3 text-xs font-semibold text-primary transition-colors hover:border-primary"
                  aria-label={`Remove filter ${c.label}`}>
                  <span className="truncate">{c.label}</span><Icon name="x" className="size-3.5 shrink-0 opacity-70 group-hover:opacity-100" />
                </button>
              ))}
              <button type="button" onClick={clearAll} className="text-xs font-bold text-muted underline-offset-2 hover:text-danger hover:underline">Clear all</button>
            </div>
          )}

          {error ? (
            <ErrorState error={error} onRetry={reload} />
          ) : loading && !data ? (
            <div className={view === 'list' ? 'space-y-4' : 'grid gap-5 sm:grid-cols-2 xl:grid-cols-3'}>{Array.from({ length: 6 }, (_, i) => <CourseCardSkeleton key={i} />)}</div>
          ) : data.items.length ? (
            <>
              {view === 'list' ? (
                <ul className={'space-y-4 transition-opacity ' + (loading ? 'opacity-60' : '')}>
                  {data.items.map((c, i) => <Reveal as="li" key={c.id} delay={Math.min(i, 5) * 50}><CourseRow course={c} /></Reveal>)}
                </ul>
              ) : (
                <ul className={'grid gap-5 transition-opacity sm:grid-cols-2 xl:grid-cols-3 ' + (loading ? 'opacity-60' : '')}>
                  {data.items.map((c, i) => <Reveal as="li" key={c.id} delay={(i % 3) * 70} className="min-w-0"><CourseCard course={c} /></Reveal>)}
                </ul>
              )}
              <Pagination page={pag.page} totalPages={pag.total_pages} onChange={changePage} />
              {pag.total_pages > 1 && <p className="mt-3 text-center text-xs text-muted">Page {pag.page} of {pag.total_pages}</p>}
            </>
          ) : (
            <NoResults params={params} topCats={topCats} onClear={clearAll} onPick={(slug) => update({ category: slug, q: '' })} />
          )}
        </div>
      </section>

      {/* Admin → Videos with placement "Courses page" (hidden when there are none) */}
      <CourseVideos placement="courses" title="Watch & learn" className="container-x pb-12" />

      {drawer && facets && <FilterDrawer params={params} facets={facets} onClose={() => setDrawer(false)} onApply={(next) => { setParams(next); setDrawer(false); }} />}
    </>
  );
}

const chip = (active) =>
  'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3.5 py-2 text-sm font-semibold transition-all ' +
  (active ? 'border-primary bg-primary text-white shadow-[0_6px_18px_-8px_rgb(11_99_246/0.7)]' : 'border-line bg-white text-ink hover:border-primary hover:text-primary');

/** Human-readable chips for every active filter, each with the change that removes it. */
function activeChips(params, cats, facets) {
  const out = [];
  if (params.get('q')) out.push({ key: 'q', value: params.get('q'), label: `“${params.get('q')}”`, remove: { q: '' } });
  const multi = (key, labelFn) => {
    const cur = list(params.get(key));
    cur.forEach((v) => out.push({ key, value: v, label: labelFn(v), remove: { [key]: cur.filter((x) => x !== v) } }));
  };
  multi('category', (s) => cats.find((c) => c.slug === s)?.name || s);
  if (params.get('pricing')) out.push({ key: 'pricing', value: params.get('pricing'), label: params.get('pricing') === 'free' ? 'Free courses' : 'Paid courses', remove: { pricing: '' } });
  multi('type', courseTypeLabel);
  multi('level', levelLabel);
  multi('language', languageLabel);
  if (params.get('price_max') && params.get('pricing') !== 'free') {
    out.push({ key: 'price_max', value: params.get('price_max'), label: `Up to ${formatPrice(params.get('price_max'))}`, remove: { price_max: '' } });
  }
  if (params.get('rating_min') && facets?.ratings) out.push({ key: 'rating_min', value: params.get('rating_min'), label: `${params.get('rating_min')}★ & up`, remove: { rating_min: '' } });
  return out;
}

/** The filter groups; works on any params object so the mobile drawer can edit a draft. */
function Filters({ params, onChange, facets }) {
  const toggle = (key, value) => {
    const cur = list(params.get(key));
    onChange({ [key]: cur.includes(value) ? cur.filter((x) => x !== value) : [...cur, value] });
  };
  const has = (key, v) => list(params.get(key)).includes(v);
  const cats = facets.categories || [];
  const parents = cats.filter((c) => !c.parent_id);
  const children = (id) => cats.filter((c) => c.parent_id === id);
  const levelCounts = facets.levels_inclusive || facets.levels || {};
  const langCounts = facets.languages_inclusive || facets.languages || {};
  const pricing = params.get('pricing') || '';

  return (
    <div className="space-y-5">
      <FilterGroup title="Exam category">
        {parents.filter((p) => p.course_count > 0 || has('category', p.slug)).map((p) => (
          <div key={p.slug}>
            <Check label={p.name} count={p.course_count} checked={has('category', p.slug)} onChange={() => toggle('category', p.slug)} />
            {children(p.id).filter((c) => c.course_count > 0 || has('category', c.slug)).length > 0 && (
              <div className="mt-2 ml-6 space-y-2 border-l border-line pl-3">
                {children(p.id).filter((c) => c.course_count > 0 || has('category', c.slug)).map((c) => (
                  <Check key={c.slug} label={c.name} count={c.course_count} checked={has('category', c.slug)} onChange={() => toggle('category', c.slug)} />
                ))}
              </div>
            )}
          </div>
        ))}
      </FilterGroup>

      <FilterGroup title="Price type">
        <div className="grid grid-cols-3 gap-1.5 rounded-xl bg-surface p-1" role="radiogroup" aria-label="Price type">
          {[['', 'All'], ['free', 'Free'], ['paid', 'Paid']].map(([v, l]) => (
            <button key={v || 'all'} type="button" role="radio" aria-checked={pricing === v} onClick={() => onChange({ pricing: v, ...(v === 'free' ? { price_max: '' } : {}) })}
              className={'rounded-lg py-1.5 text-sm font-semibold transition-all ' + (pricing === v ? 'bg-white text-primary shadow-card' : 'text-muted hover:text-ink')}>
              {l}
            </button>
          ))}
        </div>
      </FilterGroup>

      {FORMATS.some((t) => facets.types?.[t]) && (
        <FilterGroup title="Format">
          {FORMATS.filter((t) => facets.types?.[t] || has('type', t)).map((t) => (
            <Check key={t} label={courseTypeLabel(t)} count={facets.types?.[t] || 0} checked={has('type', t)} onChange={() => toggle('type', t)} />
          ))}
        </FilterGroup>
      )}

      <FilterGroup title="Level">
        {LEVELS.map((v) => (
          <Check key={v} label={levelLabel(v)} count={levelCounts[v] || 0} checked={has('level', v)} onChange={() => toggle('level', v)} />
        ))}
        <p className="text-[11px] leading-snug text-muted">“All levels” courses are included with every level.</p>
      </FilterGroup>

      <FilterGroup title="Language">
        {LANGS.map((v) => (
          <Check key={v} label={languageLabel(v)} count={langCounts[v] || 0} checked={has('language', v)} onChange={() => toggle('language', v)} />
        ))}
      </FilterGroup>

      {facets.max_price > 0 && pricing !== 'free' && <PriceFilter params={params} onChange={onChange} facets={facets} />}

      {/* facets.ratings = {"4.5": n, "4": n, "3.5": n, "3": n} counted with the other active filters; the group is
          hidden while no course qualifies for any step (unless a rating filter is already applied). */}
      {facets.ratings && (RATINGS.some((r) => Number(facets.ratings[String(r)] || 0) > 0) || params.get('rating_min')) && (
        <FilterGroup title="Rating">
          {RATINGS.map((r) => (
            <label key={r} className="flex cursor-pointer items-center justify-between gap-2 text-sm">
              <span className="flex items-center gap-2.5">
                <input type="radio" name="rating_min" checked={params.get('rating_min') === String(r)} onChange={() => onChange({ rating_min: String(r) })} className="size-4 accent-primary" />
                <span className="flex items-center gap-1 text-ink"><Icon name="star" className="size-4 text-gold" />{r} & up</span>
              </span>
              <span className="shrink-0 rounded-full bg-surface px-2 py-px text-xs font-medium text-muted">{facets.ratings[String(r)] ?? 0}</span>
            </label>
          ))}
          {params.get('rating_min') && <button type="button" onClick={() => onChange({ rating_min: '' })} className="text-xs font-semibold text-primary hover:underline">Any rating</button>}
        </FilterGroup>
      )}
    </div>
  );
}

/** Max-price slider: step-aligned end so it can reach the most expensive course; the far right = no limit. */
function PriceFilter({ params, onChange, facets }) {
  const step = facets.price_step || 500;
  const sliderMax = facets.price_slider_max || Math.ceil(facets.max_price / step) * step;
  const current = Number(params.get('price_max')) || sliderMax;
  const [draft, setDraft] = useState(current);
  useEffect(() => setDraft(current), [current]);
  const commit = useCallback((v) => {
    const value = Number(v);
    onChange({ price_max: value >= facets.max_price ? '' : value });
  }, [onChange, facets.max_price]);
  // Commit shortly after the thumb stops moving (keyboard + pointer)
  useEffect(() => {
    if (draft === current) return undefined;
    const t = setTimeout(() => commit(draft), 450);
    return () => clearTimeout(t);
  }, [draft]); // eslint-disable-line react-hooks/exhaustive-deps
  const presets = [500, 1000, 2000, 5000].filter((p) => p < facets.max_price);
  const unlimited = draft >= facets.max_price;
  return (
    <FilterGroup title="Maximum price">
      <div className="flex items-baseline justify-between text-sm">
        <span className="text-muted">Up to</span>
        <span className="font-display font-extrabold text-navy">{unlimited ? 'Any price' : formatPrice(draft)}</span>
      </div>
      <input type="range" min="0" max={sliderMax} step={step} value={Math.min(draft, sliderMax)} onChange={(e) => setDraft(Number(e.target.value))}
        className="w-full accent-primary" aria-label="Maximum price" aria-valuetext={unlimited ? 'Any price' : formatPrice(draft)} />
      <div className="flex justify-between text-xs text-muted"><span>₹0</span><span>{formatPrice(sliderMax)}+</span></div>
      {presets.length > 0 && (
        <div className="flex flex-wrap gap-1.5 pt-1">
          {presets.map((p) => (
            <button key={p} type="button" onClick={() => { setDraft(p); onChange({ price_max: p }); }}
              className={'rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors ' + (Number(params.get('price_max')) === p ? 'border-primary bg-primary-soft text-primary' : 'border-line text-ink hover:border-primary')}>
              Under {formatPrice(p)}
            </button>
          ))}
        </div>
      )}
    </FilterGroup>
  );
}

function FilterGroup({ title, children }) {
  return (
    <fieldset className="min-w-0 border-b border-line pb-5 last:border-0 last:pb-0">
      <legend className="mb-3 font-display text-sm font-bold tracking-wide text-navy uppercase">{title}</legend>
      <div className="space-y-2.5">{children}</div>
    </fieldset>
  );
}

function Check({ label, count, checked, onChange }) {
  return (
    <label className="group flex cursor-pointer items-center justify-between gap-2 text-sm">
      <span className="flex min-w-0 items-center gap-2.5">
        <input type="checkbox" checked={checked} onChange={onChange} className="size-4 shrink-0 rounded border-line accent-primary" />
        <span className={'truncate transition-colors group-hover:text-primary ' + (checked ? 'font-semibold text-primary' : 'text-ink')}>{label}</span>
      </span>
      <span className="shrink-0 rounded-full bg-surface px-2 py-px text-xs font-medium text-muted">{count}</span>
    </label>
  );
}

function FilterSkeleton() {
  return <div className="space-y-4">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} className="h-5" />)}</div>;
}

/** Mobile bottom sheet: edits a draft and applies it on "Show N courses" (live count from the API). */
function FilterDrawer({ params, facets, onClose, onApply }) {
  const [draft, setDraft] = useState(() => new URLSearchParams(params));
  const count = useApi('courses.php', toQuery(draft, { per_page: 1, page: 1 }));
  useBodyLock(true);
  useEscape(true, onClose);
  const change = (changes) => setDraft((d) => applyChanges(d, changes));
  const reset = () => {
    const next = new URLSearchParams();
    if (draft.get('q')) next.set('q', draft.get('q'));
    if (draft.get('sort')) next.set('sort', draft.get('sort'));
    setDraft(next);
  };
  const total = count.data?.pagination?.total;
  const dirtyCount = useMemo(() => FILTER_KEYS.filter((k) => k !== 'q' && draft.get(k)).length, [draft]);
  return (
    <div className="fixed inset-0 z-[60] lg:hidden" role="dialog" aria-modal="true" aria-labelledby="filter-title">
      <button type="button" aria-label="Close filters" onClick={onClose} className="absolute inset-0 animate-fade-in bg-navy-deep/55 backdrop-blur-[2px]" />
      <div className="absolute inset-x-0 bottom-0 flex max-h-[88dvh] animate-slide-up flex-col rounded-t-3xl bg-white shadow-[0_-20px_60px_-20px_rgb(8_38_77/0.5)]">
        <div className="mx-auto mt-2.5 h-1.5 w-12 rounded-full bg-line" aria-hidden="true" />
        <div className="flex items-center justify-between border-b border-line px-5 py-3">
          <h2 id="filter-title" className="font-display text-lg font-bold">Filter courses</h2>
          <button type="button" onClick={onClose} aria-label="Close filters" className="grid size-10 place-items-center rounded-xl hover:bg-surface"><Icon name="x" /></button>
        </div>
        <div className="scrollbar-thin min-h-0 flex-1 overflow-y-auto px-5 py-5">
          <Filters params={draft} onChange={change} facets={facets} />
        </div>
        <div className="safe-bottom grid grid-cols-[auto_minmax(0,1fr)] gap-3 border-t border-line bg-white px-5 pt-3 pb-3">
          <button type="button" onClick={reset} disabled={!dirtyCount} className="btn-light">Clear all</button>
          <button type="button" onClick={() => onApply(draft)} className="btn-primary">
            {count.loading ? 'Updating…' : total === undefined ? 'Apply filters' : total ? `Show ${total} ${total === 1 ? 'course' : 'courses'}` : 'No courses - apply anyway'}
          </button>
        </div>
      </div>
    </div>
  );
}

/** Empty result: what to try next, the real categories and a few popular courses. */
function NoResults({ params, topCats, onClear, onPick }) {
  const popular = useApi('courses.php', { action: 'list', sort: 'popular', per_page: 3 });
  const q = params.get('q');
  return (
    <div className="space-y-8">
      <EmptyState
        icon="search"
        title={q ? `No courses found for “${q}”` : 'No courses match these filters'}
        text={q ? 'Check the spelling, try a broader word (for example the exam name) or remove a filter.' : 'Try removing a filter or widening the price range.'}
        action={(
          <div className="flex flex-col items-center gap-4">
            <button type="button" onClick={onClear} className="btn-primary"><Icon name="refresh" className="size-4" />Clear all filters</button>
            {topCats.length > 0 && (
              <div className="flex flex-wrap justify-center gap-2">
                {topCats.slice(0, 8).map((c) => (
                  <button key={c.slug} type="button" onClick={() => onPick(c.slug)} className={chip(false) + ' py-1.5 text-xs'}><Icon name={c.icon} className="size-3.5" />{c.name}</button>
                ))}
              </div>
            )}
            <Link to="/contact" className="text-sm font-semibold text-primary hover:underline">Can’t find your exam? Ask our counsellors</Link>
          </div>
        )}
      />
      {popular.data?.items?.length > 0 && (
        <div>
          <h3 className="mb-4 font-display text-lg font-bold text-navy">Popular with students</h3>
          <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">{popular.data.items.map((c) => <li key={c.id} className="min-w-0"><CourseCard course={c} /></li>)}</ul>
        </div>
      )}
    </div>
  );
}
