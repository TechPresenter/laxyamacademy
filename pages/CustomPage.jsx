// Page-builder pages (/p/:slug). Blocks are resolved and sanitised by the server (includes/blocks.php).
// Consecutive YouTube "video" blocks are shown together as a video gallery.
import { useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { CourseCard, EnquiryForm, ErrorState, Icon, Reveal, SmartLink, Spinner, Stars, useApi, useSeo, useSite } from '../js/components.jsx';
import { InstagramCard, VideoModal, YouTubeCard, youtubeId, youtubeThumb } from '../js/home/media.jsx';
import { NewsletterConsent, useNewsletterSignup } from '../js/home/newsletter.jsx';
import NotFound from './NotFound.jsx';

export default function CustomPage() {
  const { slug } = useParams();
  const preview = new URLSearchParams(useLocation().search).has('preview');
  const { data, loading, error, reload } = useApi('cms.php', { action: 'custom-page', slug, ...(preview ? { preview: 1 } : {}) });
  useSeo({ title: data?.seo?.title, description: data?.seo?.description, robots: data?.status === 'published' ? undefined : 'noindex,nofollow' });
  if (error?.status === 404) return <NotFound />;
  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading) return <Spinner />;
  const blocks = groupVideos(data.blocks || []);
  return (
    <>
      {data.status !== 'published' && (
        <div role="status" className="bg-amber-100 py-2 text-center text-sm font-semibold text-amber-900">Preview - this page is a {data.status} and not visible to visitors.</div>
      )}
      {!blocks.some((b) => b.type === 'hero') && (
        <section className="bg-mesh relative isolate overflow-hidden border-b border-line/60">
          <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:linear-gradient(to_bottom,black,transparent_85%)]" />
          <div className="container-x py-10 sm:py-14"><h1 className="heading-lg animate-fade-up">{data.title}</h1></div>
        </section>
      )}
      {blocks.map((b, i) => (b.type === 'hero' ? <Block key={i} b={b} /> : <Reveal key={i}><Block b={b} /></Reveal>))}
    </>
  );
}

/** Video items from a gallery-type block (video_gallery / instagram), tolerant of field names. */
function galleryItems(d, platform) {
  const list = Array.isArray(d.items) ? d.items : Array.isArray(d.videos) ? d.videos : [];
  return list.map((v) => {
    if (!v || typeof v !== 'object') return null;
    const id = v.video_id || (platform === 'youtube' ? youtubeId(v.youtube_url || v.url) : (String(v.url || '').match(/instagram\.com\/(?:reels?|p|tv)\/([\w-]{5,40})/) || [])[1]);
    return id ? { id, video_id: id, title: v.title || v.caption || '', url: v.url || null, thumbnail_url: v.thumbnail_url || null } : null;
  }).filter(Boolean);
}

/** Merge runs of 2+ consecutive "video" blocks into one gallery block. */
function groupVideos(blocks) {
  const out = [];
  for (const b of blocks) {
    const isVideo = b.type === 'video' && b.data?.video_id;
    const last = out[out.length - 1];
    if (isVideo && last?.type === '_videos') {
      last.items.push(b.data);
    } else if (isVideo && last?.type === 'video' && last.data?.video_id) {
      out[out.length - 1] = { type: '_videos', items: [last.data, b.data] };
    } else {
      out.push(b);
    }
  }
  return out;
}

function Title({ children, center }) {
  if (!children) return null;
  return <h2 className={'heading-lg mb-8 ' + (center ? 'text-center' : '')}>{children}</h2>;
}

const wrap = 'container-x py-10 sm:py-14';

function Block({ b }) {
  const d = b.data || {};
  switch (b.type) {
    case 'hero':
      return (
        <section className="bg-mesh relative isolate overflow-hidden border-b border-line/60">
          <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:linear-gradient(to_bottom,black,transparent_85%)]" />
          <div aria-hidden="true" className="absolute -top-28 -right-24 -z-10 size-80 rounded-full bg-primary/15 blur-3xl" />
          <div aria-hidden="true" className="absolute -bottom-32 -left-20 -z-10 size-72 rounded-full bg-gold/20 blur-3xl" />
          <div className={'container-x grid items-center gap-10 py-14 sm:py-20 ' + (d.image_url ? 'lg:grid-cols-2' : 'text-center')}>
            <div className={'min-w-0 ' + (d.image_url ? '' : 'mx-auto max-w-3xl')}>
              {d.heading && <h1 className="heading-xl animate-fade-up">{d.heading}</h1>}
              {d.subheading && <p className="mt-5 animate-fade-up text-lg leading-relaxed whitespace-pre-line text-muted [animation-delay:80ms]">{d.subheading}</p>}
              {d.button_label && d.button_url && (
                <SmartLink to={d.button_url} className="btn-primary btn-lg mt-8 animate-fade-up [animation-delay:160ms]">{d.button_label} <Icon name="arrowRight" className="size-4" /></SmartLink>
              )}
            </div>
            {d.image_url && <img src={d.image_url} alt="" className="mx-auto w-full max-w-lg animate-fade-in rounded-3xl shadow-card-hover" />}
          </div>
        </section>
      );
    case 'text':
      return (
        <section className={wrap}>
          <div className="prose-lite mx-auto max-w-3xl text-[16px] leading-relaxed text-ink/90 [&_h2]:mt-8 [&_h2]:mb-3 [&_h2]:font-display [&_h2]:text-2xl [&_h2]:font-extrabold [&_h2]:text-navy [&_h3]:mt-6 [&_h3]:font-display [&_h3]:text-xl [&_h3]:font-bold [&_h3]:text-navy [&_img]:rounded-2xl [&_table]:w-full [&_table]:overflow-hidden [&_table]:rounded-xl [&_td]:border [&_td]:border-line [&_td]:p-2.5 [&_th]:border [&_th]:border-line [&_th]:bg-surface [&_th]:p-2.5 [&_th]:text-left [&_blockquote]:border-l-4 [&_blockquote]:border-primary [&_blockquote]:pl-4 [&_blockquote]:italic"
            dangerouslySetInnerHTML={{ __html: d.html || '' }} />
        </section>
      );
    case 'image':
      return d.image_url ? (
        <figure className={wrap + ' text-center'}>
          <img src={d.image_url} alt={d.alt || ''} loading="lazy" className="mx-auto max-h-[560px] rounded-2xl shadow-card" />
          {d.caption && <figcaption className="mt-3 text-sm text-muted">{d.caption}</figcaption>}
        </figure>
      ) : null;
    case 'video':
      return d.video_id ? <VideoBlock id={d.video_id} caption={d.caption} /> : null;
    case '_videos':
      return <VideoGallery items={b.items.map((v) => ({ id: v.video_id, video_id: v.video_id, title: v.caption || '', thumbnail_url: youtubeThumb(v.video_id), url: `https://www.youtube.com/watch?v=${v.video_id}` }))} />;
    case 'video_gallery':
    case 'youtube_gallery':
      return <VideoGallery title={d.title} items={galleryItems(d, 'youtube')} />;
    case 'instagram':
      return <VideoGallery title={d.title} items={galleryItems(d, 'instagram')} platform="instagram" />;
    case 'buttons':
      return (d.items || []).length ? (
        <section className={wrap + ' flex flex-wrap justify-center gap-3'}>
          {d.items.map(([l, u], i) => <SmartLink key={i} to={u} className={i === 0 ? 'btn-primary btn-lg' : 'btn-light btn-lg'}>{l}{i === 0 && <Icon name="arrowRight" className="size-4" />}</SmartLink>)}
        </section>
      ) : null;
    case 'course_grid':
      return (d.courses || []).length ? (
        <section className={wrap}>
          <Title>{d.title}</Title>
          <ul className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">{d.courses.map((c) => <li key={c.id} className="min-w-0"><CourseCard course={c} /></li>)}</ul>
        </section>
      ) : null;
    case 'category_grid':
      return (d.categories || []).length ? (
        <section className={wrap}>
          <Title>{d.title}</Title>
          <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-5">
            {d.categories.map((c) => {
              const color = c.color || '#0B63F6';
              const n = Number(c.course_count ?? c.count ?? 0);
              return (
                <li key={c.slug} className="min-w-0">
                  <Link to={`/courses?category=${encodeURIComponent(c.slug)}`} className="group card card-hover flex h-full flex-col items-center gap-3 p-4 text-center sm:p-5">
                    <span className="grid size-12 place-items-center rounded-2xl text-white shadow-md transition-transform group-hover:scale-110" style={{ background: `linear-gradient(135deg, ${color}, color-mix(in srgb, ${color} 55%, #08264D))` }}>
                      <Icon name={c.icon} className="size-6" />
                    </span>
                    <span className="font-display text-sm font-bold text-navy group-hover:text-primary">{c.name}</span>
                    {n > 0 && <span className="text-xs font-semibold text-muted">{n} {n === 1 ? 'course' : 'courses'}</span>}
                  </Link>
                </li>
              );
            })}
          </ul>
        </section>
      ) : null;
    case 'features':
      return (d.items || []).length ? (
        <section className={wrap}>
          <Title>{d.title}</Title>
          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {d.items.map(([t, x], i) => (
              <li key={i} className="group card card-hover p-6">
                <span className="grid size-11 place-items-center rounded-2xl bg-primary-soft text-primary transition-transform group-hover:scale-110"><Icon name="checkCircle" className="size-6" /></span>
                <h3 className="mt-4 font-display font-bold text-navy">{t}</h3>
                {x && <p className="mt-1.5 text-sm leading-relaxed text-muted">{x}</p>}
              </li>
            ))}
          </ul>
        </section>
      ) : null;
    case 'stats':
      return (d.items || []).length ? (
        <section className="bg-navy-mesh relative isolate overflow-hidden text-white">
          <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-30" />
          <ul className="container-x grid grid-cols-2 gap-6 py-12 text-center lg:grid-cols-4">
            {d.items.map(([v, l], i) => <li key={i}><span className="block font-display text-3xl font-extrabold sm:text-4xl">{v}</span><span className="text-sm text-white/80">{l}</span></li>)}
          </ul>
        </section>
      ) : null;
    case 'testimonials':
      return d.items?.length ? (
        <section className={wrap}>
          <Title>{d.title}</Title>
          <ul className="grid gap-5 md:grid-cols-3">
            {d.items.map((t, i) => (
              <li key={i} className="min-w-0">
                <figure className="card relative h-full p-6">
                  <Icon name="quote" className="absolute top-5 right-5 size-8 text-primary/10" />
                  {t.rating ? <><Stars value={t.rating} /><span className="sr-only">{t.rating} out of 5</span></> : null}
                  <blockquote className="mt-3 text-sm leading-relaxed text-ink/85">{t.content}</blockquote>
                  <figcaption className="mt-4 border-t border-line pt-3">
                    <span className="block font-display font-bold text-navy">{t.name}</span>
                    {t.exam_name && <span className="block text-xs text-muted">{t.exam_name}</span>}
                  </figcaption>
                </figure>
              </li>
            ))}
          </ul>
        </section>
      ) : null;
    case 'faqs':
      return (d.items || []).length ? (
        <section className={wrap + ' max-w-3xl'}>
          <Title center>{d.title}</Title>
          <div className="space-y-3">
            {d.items.map(([q, a], i) => (
              <details key={i} className="group card overflow-hidden open:shadow-card-hover">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-3 p-5 font-display font-bold text-navy [&::-webkit-details-marker]:hidden">
                  {q}
                  <span className="grid size-8 shrink-0 place-items-center rounded-full bg-primary-soft text-primary transition-transform group-open:rotate-180"><Icon name="chevronDown" className="size-4" /></span>
                </summary>
                <p className="px-5 pb-5 text-sm leading-relaxed text-muted">{a}</p>
              </details>
            ))}
          </div>
        </section>
      ) : null;
    case 'cta':
      return (
        <section className="container-x py-10 sm:py-14">
          <div className="relative isolate overflow-hidden rounded-3xl bg-navy px-6 py-12 text-center text-white shadow-card-hover sm:px-12">
            <div aria-hidden="true" className="bg-animated-gradient absolute inset-0 -z-20 bg-[linear-gradient(125deg,#041330_0%,#08264D_40%,#0B3A7A_70%,#0B63F6_100%)]" />
            <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-30" />
            {d.heading && <h2 className="font-display text-3xl leading-tight font-extrabold text-white sm:text-4xl">{d.heading}</h2>}
            {d.text && <p className="mx-auto mt-3 max-w-2xl text-white/80 sm:text-lg">{d.text}</p>}
            {d.button_label && d.button_url && <SmartLink to={d.button_url} className="btn-gold btn-lg mt-7">{d.button_label} <Icon name="arrowRight" className="size-4" /></SmartLink>}
          </div>
        </section>
      );
    case 'contact':
      return <ContactBlock title={d.title} />;
    case 'newsletter':
      return <NewsletterBlock title={d.title} text={d.text} />;
    case 'spacer':
      return <div aria-hidden="true" className={{ sm: 'h-6', md: 'h-12', lg: 'h-24' }[d.size] || 'h-12'} />;
    case 'divider':
      return <hr className="container-x my-6 border-line" />;
    default:
      return null;
  }
}

/** One inline YouTube player (privacy-enhanced embed loads only after a click). */
function VideoBlock({ id, caption }) {
  const [play, setPlay] = useState(false);
  return (
    <figure className="container-x max-w-4xl py-10 sm:py-14">
      <div className="overflow-hidden rounded-3xl bg-navy shadow-card-hover ring-1 ring-line">
        {play ? (
          <iframe className="aspect-video w-full" src={`https://www.youtube-nocookie.com/embed/${encodeURIComponent(id)}?autoplay=1&rel=0&modestbranding=1`} title={caption || 'YouTube video'} allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen />
        ) : (
          <button type="button" onClick={() => setPlay(true)} className="group relative block aspect-video w-full" aria-label={`Play video${caption ? ': ' + caption : ''}`}>
            <img src={youtubeThumb(id)} alt="" loading="lazy" className="size-full object-cover opacity-85 transition-transform duration-700 group-hover:scale-105" />
            <span className="absolute inset-0 bg-gradient-to-t from-navy-deep/60 to-transparent" />
            <span className="absolute inset-0 grid place-items-center">
              <span className="grid size-16 place-items-center rounded-full bg-[#FF0000] text-white shadow-lg transition-transform group-hover:scale-110 sm:size-20"><Icon name="play" className="ml-1 size-7 sm:size-8" /></span>
            </span>
          </button>
        )}
      </div>
      {caption && <figcaption className="mt-3 text-center text-sm text-muted">{caption}</figcaption>}
    </figure>
  );
}

/** Grid of video cards that play in a modal. */
function VideoGallery({ title, items, platform = 'youtube' }) {
  const [playing, setPlaying] = useState(null);
  if (!items.length) return null;
  const insta = platform === 'instagram';
  return (
    <section className={wrap}>
      <Title>{title}</Title>
      <ul className={insta ? 'grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4' : 'grid gap-5 sm:grid-cols-2 lg:grid-cols-3'}>
        {items.map((v) => (
          <li key={v.video_id} className="min-w-0">
            {insta ? <InstagramCard video={v} onPlay={setPlaying} /> : <YouTubeCard video={v} onPlay={setPlaying} />}
          </li>
        ))}
      </ul>
      <VideoModal video={playing} onClose={() => setPlaying(null)} />
    </section>
  );
}

function ContactBlock({ title }) {
  const { data: site } = useSite();
  const s = site?.site || {};
  const phone = (s.phone || '').replace(/[^\d+]/g, '');
  return (
    <section className={wrap}>
      <div className="grid gap-8 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
        <div className="min-w-0">
          <h2 className="heading-lg">{title || 'Contact us'}</h2>
          <p className="mt-3 text-muted">Send us your question and our team will get back to you.</p>
          <ul className="mt-6 space-y-4 text-sm">
            {s.address && <li className="flex gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name="mapPin" /></span><span className="pt-2 text-ink/85">{s.address}</span></li>}
            {phone && <li className="flex gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name="phone" /></span><a href={`tel:${phone}`} className="pt-2 font-semibold text-ink hover:text-primary">{s.phone}</a></li>}
            {s.email && <li className="flex gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name="mail" /></span><a href={`mailto:${s.email}`} className="pt-2 font-semibold break-all text-ink hover:text-primary">{s.email}</a></li>}
          </ul>
        </div>
        <div className="card min-w-0 p-6 sm:p-8">
          <EnquiryForm source="contact_form" idPrefix="page-contact" submitLabel="Send enquiry" />
        </div>
      </div>
    </section>
  );
}

/** Newsletter sign-up → POST enquiries.php?action=newsletter (source "page"). */
function NewsletterBlock({ title, text }) {
  const [email, setEmail] = useState('');
  const [website, setWebsite] = useState('');
  const nl = useNewsletterSignup('page');
  const state = { status: nl.state.status, field: nl.state.fields.email || '', message: nl.state.fields.email ? '' : nl.state.message };
  const submit = async (e) => {
    e.preventDefault();
    if (await nl.submit({ email, website })) setEmail('');
  };
  return (
    <section className="container-x py-10 sm:py-14">
      <div className="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br from-primary via-[#2f6cf0] to-violet px-6 py-10 text-center text-white shadow-card-hover sm:px-12">
        <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40" />
        <h2 className="font-display text-2xl font-extrabold text-white sm:text-3xl">{title || 'Get exam updates'}</h2>
        {text && <p className="mx-auto mt-2 max-w-xl text-white/85">{text}</p>}
        {state.status === 'ok' ? (
          <p role="status" className="glass mx-auto mt-6 flex max-w-md items-center justify-center gap-2 rounded-2xl p-4 font-semibold"><Icon name="checkCircle" className="size-5" />{state.message}</p>
        ) : (
          <form onSubmit={submit} noValidate className="mx-auto mt-6 max-w-lg">
            <div className="flex flex-col gap-2 sm:flex-row">
              <label htmlFor="page-nl" className="sr-only">Email address</label>
              <input id="page-nl" type="email" inputMode="email" autoComplete="email" maxLength={160} className="input h-12 min-w-0 flex-1" value={email} onChange={(e) => setEmail(e.target.value)}
                placeholder="Your email address" aria-invalid={!!state.field} aria-describedby={state.field ? 'page-nl-err' : undefined} />
              <input type="text" name="website" value={website} onChange={(e) => setWebsite(e.target.value)} tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />
              <button type="submit" disabled={state.status === 'busy'} className="btn-gold btn-lg shrink-0">{state.status === 'busy' ? 'Subscribing…' : 'Subscribe'}</button>
            </div>
            {state.field && <p id="page-nl-err" className="mt-2 text-sm font-semibold text-gold">{state.field}</p>}
            {state.status === 'error' && state.message && <p role="alert" className="mt-2 text-sm font-semibold">{state.message}</p>}
            {nl.consentNeeded && <div className="mt-3"><NewsletterConsent id="page-nl-consent" checked={nl.consent} onChange={nl.setConsent} error={nl.state.fields.consent} /></div>}
            <p className="mt-3 text-xs text-white/70">No spam. Unsubscribe any time.</p>
          </form>
        )}
      </div>
    </section>
  );
}
