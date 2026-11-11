// Homepage hero: animated navy → blue gradient with glow orbs and a dot grid, CMS copy (headline,
// highlight, Caveat tagline, description), primary / demo-video CTAs, call / WhatsApp quick actions
// and the CMS hero photo (a person cut-out that fills the right column and stands on the bottom edge).
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Icon, SmartLink, useSite } from '../components.jsx';
import { VideoModal, youtubeId } from './media.jsx';

/**
 * hero: cms hero block (null when hidden), features: features block items (the first one becomes the floating
 * chip), loading: home request in flight. (`categories` is still accepted from Home but no longer shown:
 * the owner wants no counts in the hero.)
 */
export default function Hero({ hero, features = [], loading }) {
  const { data: site } = useSite();
  const [demo, setDemo] = useState(null);

  if (!hero) {
    // Placeholder band only while the request is running; a hidden hero renders nothing.
    return loading ? (
      <section aria-busy="true" aria-label="Loading" className="relative overflow-hidden bg-navy">
        <div className="container-x grid gap-10 py-16 lg:grid-cols-2 lg:py-24">
          <div className="space-y-5">
            <div className="h-14 w-full max-w-lg rounded-xl bg-white/10" />
            <div className="h-14 w-4/5 max-w-md rounded-xl bg-white/10" />
            <div className="h-4 w-full max-w-md rounded bg-white/10" />
            <div className="h-14 w-full max-w-xl rounded-2xl bg-white/10" />
          </div>
          <div className="hidden aspect-[9/8] rounded-full bg-white/5 lg:block" />
        </div>
      </section>
    ) : null;
  }

  const s = site?.site || {};
  const feature = features.find((f) => f?.title);

  // Eyebrow: dedicated CMS text, else the site tagline (skipped when it repeats the hero tagline)
  const eyebrow = hero.eyebrow || (s.tagline && s.tagline !== hero.tagline ? s.tagline : '');
  // Secondary CTA: a YouTube demo opens in a modal, any other link navigates, nothing set → no button
  const demoUrl = hero.demo_video_url || hero.secondary_url || '';
  const demoId = youtubeId(demoUrl);
  const phone = (s.phone || '').replace(/[^\d+]/g, '');
  const whatsapp = (s.whatsapp || '').replace(/\D/g, '');
  const hasImage = !!hero.image_url;
  const align = hasImage ? '' : 'justify-center';
  const iso = site?.certification; // ISO badge beside the eyebrow (admin → Settings → Company & Invoicing)

  return (
    <section className="relative isolate overflow-hidden text-white">
      {/* Background: panning gradient, glow orbs, masked dot grid */}
      <div aria-hidden="true" className="bg-animated-gradient absolute inset-0 -z-20 bg-[linear-gradient(125deg,#041330_0%,#08264D_30%,#0B3A7A_55%,#0B63F6_80%,#08264D_100%)]" />
      <div aria-hidden="true" className="absolute -top-32 -left-24 -z-10 size-[420px] animate-float-slow rounded-full bg-primary/40 blur-3xl" />
      <div aria-hidden="true" className="absolute top-1/3 -right-32 -z-10 size-[460px] animate-float rounded-full bg-violet/30 blur-3xl" />
      <div aria-hidden="true" className="absolute -bottom-40 left-1/3 -z-10 size-[380px] rounded-full bg-gold/15 blur-3xl" />
      <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40 [mask-image:radial-gradient(ellipse_at_top_left,black_20%,transparent_70%)]" />

      {/* With a photo the art column sits flush on the hero's bottom edge, so only the text column carries bottom padding. */}
      <div className={'container-x relative grid gap-x-10 gap-y-8 pt-12 sm:pt-16 lg:pt-20 ' + (hasImage ? 'lg:grid-cols-[minmax(0,1.08fr)_minmax(0,0.92fr)]' : 'pb-16 lg:pb-24')}>
        <div className={'min-w-0 ' + (hasImage ? 'self-center lg:pb-20' : 'mx-auto max-w-3xl text-center')}>
          {(eyebrow || iso) && (
            <div className={'flex animate-fade-up flex-wrap items-center gap-2.5 ' + align}>
              {eyebrow && (
                <span className="glass inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 font-display text-xs font-bold tracking-[0.12em] text-white uppercase">
                  <span className="relative flex size-2"><span className="absolute inset-0 animate-ping rounded-full bg-gold opacity-75" /><span className="relative size-2 rounded-full bg-gold" /></span>
                  {eyebrow}
                </span>
              )}
              {iso && (
                <Link to="/about#iso-certification" className="inline-flex items-center gap-1.5 rounded-full bg-gradient-to-r from-gold to-[#ff9f1c] px-3.5 py-1.5 font-display text-xs font-extrabold tracking-wide text-navy uppercase shadow-[0_6px_18px_-6px_rgb(255_176_32/0.7)] transition-transform hover:-translate-y-0.5">
                  <Icon name="shield" className="size-3.5" />{iso.standard} Certified
                </Link>
              )}
            </div>
          )}
          <h1 className={'animate-fade-up font-display text-[2.5rem] leading-[1.06] font-extrabold tracking-tight text-white [animation-delay:60ms] sm:text-[3.4rem] lg:text-[3.5rem] xl:text-[4.35rem] ' + (eyebrow || iso ? 'mt-5' : '')}>
            {hero.heading}{' '}
            {hero.highlight && (
              <span className="underline-swash isolate">
                <span className="bg-gradient-to-r from-[#7DB3FF] via-[#B7A6FF] to-[#FF9F7A] bg-clip-text text-transparent">{hero.highlight}</span>
              </span>
            )}
          </h1>
          {hero.tagline && <p className="mt-5 animate-fade-up font-script text-[30px] leading-none text-gold [animation-delay:120ms] sm:text-[38px]">{hero.tagline}</p>}
          {hero.description && (
            <p className={'mt-5 max-w-xl animate-fade-up text-[15px] leading-relaxed text-white/80 [animation-delay:160ms] sm:text-lg ' + (hasImage ? '' : 'mx-auto')}>{hero.description}</p>
          )}

          {(hero.primary_label || (hero.secondary_label && (demoId || hero.secondary_url))) && (
            <div className={'mt-8 flex animate-fade-up flex-wrap items-center gap-3 [animation-delay:200ms] ' + align}>
              {hero.primary_label && (
                <SmartLink to={hero.primary_url || '/courses'} className="btn-gold btn-lg">
                  {hero.primary_label} <Icon name="arrowRight" className="size-4" />
                </SmartLink>
              )}
              {hero.secondary_label && demoId && (
                <button type="button" onClick={() => setDemo({ platform: 'youtube', video_id: demoId, title: hero.secondary_label, url: `https://www.youtube.com/watch?v=${demoId}` })} className="btn-ghost-light btn-lg">
                  <span className="grid size-7 place-items-center rounded-full bg-white text-primary"><Icon name="play" className="ml-0.5 size-3.5" /></span>
                  {hero.secondary_label}
                </button>
              )}
              {hero.secondary_label && !demoId && hero.secondary_url && (
                <SmartLink to={hero.secondary_url} className="btn-ghost-light btn-lg">{hero.secondary_label} <Icon name="arrowRight" className="size-4" /></SmartLink>
              )}
            </div>
          )}

          {(phone || whatsapp) && (
            <div className={'mt-7 flex animate-fade-up flex-wrap items-center gap-x-6 gap-y-3 text-sm [animation-delay:240ms] ' + align}>
              {phone && (
                <a href={`tel:${phone}`} className="group inline-flex items-center gap-2 font-semibold text-white/90 hover:text-white">
                  <span className="grid size-9 place-items-center rounded-full bg-white/10 ring-1 ring-white/20 transition-colors group-hover:bg-white/20"><Icon name="phoneCall" className="size-4" /></span>
                  {s.phone}
                </a>
              )}
              {whatsapp && (
                <a href={`https://wa.me/${whatsapp}`} target="_blank" rel="noopener noreferrer" className="group inline-flex items-center gap-2 font-semibold text-white/90 hover:text-white">
                  <span className="grid size-9 place-items-center rounded-full bg-[#25D366] text-white shadow-[0_4px_14px_-4px_rgb(37_211_102/0.8)]"><Icon name="whatsapp" className="size-4" /></span>
                  WhatsApp us
                </a>
              )}
            </div>
          )}
        </div>

        {hasImage && (
          // Person cut-out (CMS hero.image_url) with a brand glow behind her and one small glass feature chip in the
          // empty top-left area (above the open hand), clear of face and hand.
          // < lg: below the text, max 360px tall, standing on the bottom edge.
          // lg+: the column stretches to the text's height; the photo box fills it from the headline down to the
          // hero's bottom edge (bleeding a little into the gap and the page margin) and the image is contained in it,
          // bottom-right aligned - so any uploaded image keeps its proportions.
          <div className="relative mx-auto w-full max-w-[408px] min-w-0 animate-fade-in self-end [animation-delay:150ms] lg:mx-0 lg:min-h-[520px] lg:max-w-none lg:self-stretch">
            <div className="relative lg:absolute lg:inset-y-0 lg:-right-4 lg:-left-6 xl:-right-8 xl:-left-16">
              <div aria-hidden="true" className="pointer-events-none absolute inset-0">
                <span className="absolute top-[6%] left-[14%] aspect-square w-[80%] rounded-full bg-gradient-to-br from-primary/70 via-violet/45 to-transparent blur-3xl lg:max-h-full" />
                <span className="absolute top-[2%] right-[3%] aspect-square w-[78%] rounded-full border-[3px] border-gold/70 [mask-image:linear-gradient(to_bottom,black_30%,transparent_68%)] lg:top-[4%]" />
                <span className="absolute top-[10%] right-[11%] aspect-square w-[62%] rounded-full border border-white/15 bg-white/[0.04]" />
              </div>
              <img
                src={hero.image_url} alt={hero.image_alt || 'Laxyam Academy counsellor'} width="900" height="794" fetchPriority="high" decoding="async"
                className="relative mx-auto block h-auto max-h-[360px] w-full object-contain object-bottom [mask-image:linear-gradient(to_bottom,black_92%,transparent)] lg:absolute lg:inset-0 lg:size-full lg:max-h-none lg:object-[right_bottom]"
              />

              {/* Owner: no counts in the hero - only the feature chip floats over the photo */}
              {feature && (
                <div className="glass-light absolute top-[2%] left-0 hidden max-w-[48%] animate-float-slow items-center gap-3 rounded-2xl px-3.5 py-2.5 text-ink shadow-card-hover [animation-delay:1.2s] sm:flex lg:left-[10%] lg:max-w-[42%]">
                  <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-gold to-[#ff9f1c] text-navy"><Icon name={feature.icon} className="size-5" /></span>
                  <span className="min-w-0 leading-tight">
                    <span className="block truncate font-display text-sm font-extrabold text-navy">{feature.title}</span>
                    {feature.text && <span className="block truncate text-[11px] font-medium text-muted">{feature.text}</span>}
                  </span>
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Soft fade into the page */}
      <div aria-hidden="true" className="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-white/30 to-transparent" />
      <VideoModal video={demo} onClose={() => setDemo(null)} />
    </section>
  );
}
