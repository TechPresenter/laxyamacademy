// Enrolment congratulations page (/enrolled/:slug, students only). Shown after a free enrolment,
// a successful payment (PaymentResult redirects here) and from the payment_success email link.
import { useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { formatDate, formatDateTime, formatPrice } from '../js/api.js';
import { useAuth } from '../js/auth.jsx';
import { CourseThumb, ErrorState, Icon, Reveal, ShareButtons, Skeleton, useApi, useSeo, useSite } from '../js/components.jsx';
import { AnimatedCheck, CelebrateStyles, Confetti, invoiceHref, validityLabel, whatsappHref } from '../js/commerce/ui.jsx';

export default function Enrolled() {
  useSeo({ title: 'You’re enrolled! | Laxyam Academy', robots: 'noindex,nofollow' });
  const { slug } = useParams();
  const [params] = useSearchParams();
  const orderNo = params.get('order') || '';
  const { user } = useAuth();
  const { data: site } = useSite();
  const { data, loading, error, reload } = useApi('students.php', { action: 'enrolment', course: slug, order: orderNo });

  if (loading && !data) return <EnrolledSkeleton />;
  if (error) {
    if (error.status === 404 || error.code === 'not_enrolled') return <NotEnrolled slug={slug} />;
    return <ErrorState error={error} onRetry={reload} />;
  }

  const { course } = data;
  const active = data.status === 'active';
  const firstName = String(user?.name || '').trim().split(/\s+/)[0] || 'there';
  const learnUrl = `/learn/${course.slug}${data.next_lesson_id ? `/${data.next_lesson_id}` : ''}`;
  const started = Number(data.progress || 0) > 0;
  const shareUrl = course.share_url || `/courses/${course.slug}`;
  const wa = whatsappHref(site?.site?.whatsapp, `Hi, I just enrolled in "${course.title}" (${data.reference}) and need some help.`);
  const mail = data.confirmation_email || {};
  const order = data.order && ['paid', 'partially_refunded'].includes(data.order.status) ? data.order : null;

  const steps = [
    { done: true, icon: 'checkCircle', title: 'Enrolment confirmed', text: `Reference ${data.reference}` },
    course.available !== false && active && {
      icon: 'playCircle', title: started ? 'Continue where you left off' : 'Start your first lesson',
      text: started ? `You’re ${Math.round(data.progress)}% through the course.` : 'Jump straight into the course player.',
      to: learnUrl, cta: started ? 'Continue' : 'Start now', primary: true,
    },
    { icon: 'clipboard', title: 'Attempt a mock test', text: 'Check your level with exam-pattern tests.', to: '/mock-tests', cta: 'Mock tests' },
    { icon: 'video', title: 'Join live classes', text: 'See upcoming live sessions and add them to your plan.', to: '/dashboard/live-classes', cta: 'Live classes' },
    { icon: 'file', title: 'Download study resources', text: 'Notes, PDFs and practice material for your exam.', to: '/dashboard/resources', cta: 'Resources' },
  ].filter(Boolean);

  return (
    <div className="bg-surface pb-16">
      <CelebrateStyles />
      {/* Hero */}
      <section className="relative isolate overflow-hidden bg-navy-mesh text-white">
        <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-40 [mask-image:radial-gradient(circle_at_center,black,transparent_75%)]" />
        <div aria-hidden="true" className="absolute -top-24 left-1/2 -z-10 size-96 -translate-x-1/2 rounded-full bg-primary/40 blur-3xl" />
        {active && <Confetti />}
        <div className="container-x relative py-12 text-center sm:py-16">
          <div className="flex justify-center">{active ? <AnimatedCheck /> : <span className="grid size-24 place-items-center rounded-full bg-white/10 text-gold ring-8 ring-white/5"><Icon name="clock" className="size-10" /></span>}</div>
          {active ? (
            <>
              <p className="mt-6 font-script text-2xl text-gold sm:text-3xl">Welcome aboard!</p>
              <h1 className="mt-1 font-display text-3xl font-extrabold tracking-tight sm:text-4xl lg:text-5xl">
                Congratulations, {firstName}! <span className="text-gradient-gold">You’re enrolled</span>
              </h1>
              <p className="mx-auto mt-4 max-w-2xl text-base text-white/80 sm:text-lg">
                You now have access to <strong className="text-white">{course.title}</strong>. Your preparation starts today - let’s make it count.
              </p>
            </>
          ) : (
            <>
              <h1 className="mt-6 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">Your access to this course has ended</h1>
              <p className="mx-auto mt-4 max-w-2xl text-white/80">
                {data.expires_at ? `Access to ${course.title} expired on ${formatDate(data.expires_at)}.` : `Access to ${course.title} is no longer active.`} Renew to continue learning.
              </p>
            </>
          )}
          <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            {active && course.available !== false
              ? <Link to={learnUrl} className="btn-gold btn-lg"><Icon name="play" className="size-4" />{started ? 'Continue learning' : 'Start first lesson'}</Link>
              : <Link to={`/checkout/${course.slug}`} className="btn-gold btn-lg"><Icon name="refresh" className="size-4" />Renew access</Link>}
            <Link to="/dashboard/courses" className="btn-ghost-light btn-lg">Go to My Courses</Link>
          </div>
        </div>
      </section>

      {/* relative: the -mt-6 overlap must paint the cards above the (positioned) hero, not under it. */}
      <div className="container-x relative -mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
        <div className="min-w-0 space-y-6">
          {/* Course + enrolment details */}
          <Reveal className="card overflow-hidden">
            <div className="grid sm:grid-cols-[240px_minmax(0,1fr)]">
              {/* From sm up the thumbnail fills the 240px column at the card's height; its 16:9 aspect
                  ratio would otherwise widen it past the column and cover the details. */}
              <CourseThumb course={course} showBadge={false} className="w-full min-w-0 sm:h-full sm:min-h-44 sm:aspect-auto" />
              <div className="min-w-0 p-5 sm:p-6">
                <p className="text-xs font-bold tracking-wide uppercase" style={{ color: course.category?.color || '#0B63F6' }}>{course.category?.name}</p>
                <h2 className="mt-1 font-display text-xl font-bold text-navy">{course.title}</h2>
                <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                  <Info icon="ticket" k="Enrolment reference" v={<RefCopy value={data.reference} />} />
                  <Info icon="calendar" k="Enrolled on" v={formatDate(data.enrolled_at)} />
                  <Info icon="clock" k="Validity" v={validityLabel(course.validity_days, data.expires_at, formatDate)} />
                  <Info icon="barChart" k="Progress" v={`${Math.round(data.progress || 0)}% complete`} />
                </dl>
                {course.available === false && <p className="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900">This course is temporarily unavailable. We’ll email you when it’s back.</p>}
              </div>
            </div>
          </Reveal>

          {/* Next steps */}
          <Reveal className="card p-5 sm:p-6" delay={80}>
            <h2 className="font-display text-lg font-bold text-navy">Your next steps</h2>
            <p className="text-sm text-muted">A quick plan to get the most out of your course.</p>
            <ol className="mt-5 space-y-3">
              {steps.map((s, i) => (
                <li key={s.title} className={'flex flex-wrap items-center gap-3 rounded-2xl border p-3 sm:flex-nowrap sm:p-4 ' + (s.done ? 'border-emerald-200 bg-emerald-50/60' : 'border-line bg-white')}>
                  <span className={'grid size-10 shrink-0 place-items-center rounded-xl ' + (s.done ? 'bg-emerald-500 text-white' : 'bg-primary-soft text-primary')}>
                    {s.done ? <Icon name="check" className="size-5" /> : <Icon name={s.icon} className="size-5" />}
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block text-sm font-bold text-navy"><span className="sr-only">Step {i + 1}: </span>{s.title}{s.done && <span className="sr-only"> (done)</span>}</span>
                    <span className="block text-xs text-muted">{s.text}</span>
                  </span>
                  {s.to && <Link to={s.to} className={(s.primary ? 'btn-primary' : 'btn-light') + ' btn-sm w-full sm:w-auto'}>{s.cta}<Icon name="arrowRight" className="size-3.5" /></Link>}
                </li>
              ))}
            </ol>
          </Reveal>

          {/* Share */}
          <Reveal className="card relative overflow-hidden p-5 sm:p-6" delay={120}>
            <div aria-hidden="true" className="absolute -top-16 -right-16 size-48 rounded-full bg-gold/20 blur-2xl" />
            <h2 className="relative font-display text-lg font-bold text-navy">Share your achievement</h2>
            <p className="relative text-sm text-muted">Tell your friends you’ve started preparing - studying together keeps everyone motivated.</p>
            <ShareButtons
              url={shareUrl}
              title={`I just enrolled in ${course.title} at Laxyam Academy`}
              text={`I just enrolled in "${course.title}" at Laxyam Academy. Join me!`}
              label={null}
              className="relative mt-4 flex flex-wrap items-center gap-2"
            />
          </Reveal>
        </div>

        <aside className="min-w-0 space-y-6 lg:sticky lg:top-24">
          {order && (
            <Reveal className="card p-5 sm:p-6">
              <div className="flex items-center gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><Icon name="receipt" /></span>
                <h2 className="font-display text-lg font-bold text-navy">Payment summary</h2>
              </div>
              <dl className="mt-4 space-y-2.5 text-sm">
                <div className="flex justify-between gap-3"><dt className="text-muted">Order number</dt><dd className="font-semibold break-all text-ink">{order.order_number}</dd></div>
                {order.paid_at && <div className="flex justify-between gap-3"><dt className="text-muted">Paid on</dt><dd className="text-right font-semibold text-ink">{formatDateTime(order.paid_at)}</dd></div>}
                {order.invoice_number && <div className="flex justify-between gap-3"><dt className="text-muted">Invoice</dt><dd className="font-semibold break-all text-ink">{order.invoice_number}</dd></div>}
                <div className="flex justify-between gap-3 border-t border-dashed border-line pt-3"><dt className="font-bold text-navy">Amount paid</dt><dd className="font-display text-xl font-extrabold text-navy">{formatPrice(order.total)}</dd></div>
              </dl>
              {/* 100% coupon orders charge nothing, so no tax invoice is issued for them. */}
              {Number(order.total) <= 0 && <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">Your coupon covered the full price, so no invoice is issued for this order.</p>}
              {order.invoice_number && (
                <a href={invoiceHref(order.order_number, order.invoice_url)} className="btn-outline mt-5 w-full"><Icon name="file" className="size-4" />Download invoice (PDF)</a>
              )}
              <Link to="/dashboard/purchases" className="mt-3 block text-center text-sm font-semibold text-muted hover:text-primary">View all purchases</Link>
            </Reveal>
          )}

          <Reveal className="card p-5 sm:p-6" delay={60}>
            <EmailLine mail={mail} />
            <div className="mt-5 rounded-2xl bg-gradient-to-br from-[#0f3d2e] to-[#0b6b4f] p-4 text-white">
              <p className="font-display font-bold">Questions about your course?</p>
              <p className="mt-1 text-sm text-white/80">Our academic team is happy to help you plan your preparation.</p>
              {wa
                ? <a href={wa} target="_blank" rel="noopener noreferrer" className="btn-whatsapp mt-4 w-full"><Icon name="whatsapp" className="size-4" />WhatsApp support</a>
                : <Link to="/dashboard/support" className="btn-light mt-4 w-full">Contact support</Link>}
            </div>
          </Reveal>
        </aside>
      </div>
    </div>
  );
}

/** Honest confirmation-email line from email_log (never claims a send that didn't happen). */
function EmailLine({ mail }) {
  const to = mail.to;
  const { icon, cls, text } = mail.status === 'sent'
    ? { icon: 'mail', cls: 'bg-primary-soft text-primary', text: <>A confirmation email has been sent to <strong className="break-all text-ink">{to}</strong>.</> }
    : mail.status === 'queued' || mail.status === 'sending'
      ? { icon: 'send', cls: 'bg-primary-soft text-primary', text: <>A confirmation email is on its way to <strong className="break-all text-ink">{to}</strong>.</> }
      : mail.status === 'failed'
        ? { icon: 'alert', cls: 'bg-amber-50 text-amber-600', text: <>We couldn’t deliver the confirmation email to <strong className="break-all text-ink">{to}</strong>. Don’t worry - your enrolment is active and saved in My Courses.</> }
        : { icon: 'info', cls: 'bg-surface text-muted', text: <>Your enrolment is saved in <Link to="/dashboard/courses" className="font-semibold text-primary hover:underline">My Courses</Link>{to ? <>. Course updates go to <strong className="break-all text-ink">{to}</strong></> : null}.</> };
  return (
    <div className="flex items-start gap-3">
      <span className={'grid size-10 shrink-0 place-items-center rounded-xl ' + cls}><Icon name={icon} /></span>
      <p className="min-w-0 text-sm text-muted">{text}</p>
    </div>
  );
}

function Info({ icon, k, v }) {
  return (
    <div className="flex min-w-0 items-start gap-2.5">
      <Icon name={icon} className="mt-0.5 size-4 shrink-0 text-primary" />
      <div className="min-w-0">
        <dt className="text-xs text-muted">{k}</dt>
        <dd className="font-semibold text-ink">{v}</dd>
      </div>
    </div>
  );
}

function RefCopy({ value }) {
  const [copied, setCopied] = useState(false);
  const timer = useRef(null);
  useEffect(() => () => clearTimeout(timer.current), []);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      clearTimeout(timer.current);
      timer.current = setTimeout(() => setCopied(false), 1800);
    } catch { /* clipboard unavailable - the reference stays visible */ }
  };
  return (
    <span className="inline-flex items-center gap-1.5">
      <span className="font-mono">{value}</span>
      <button type="button" onClick={copy} aria-label={copied ? 'Reference copied' : 'Copy enrolment reference'} title="Copy" className="grid size-7 place-items-center rounded-md text-muted hover:bg-primary-soft hover:text-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
        <Icon name={copied ? 'check' : 'copy'} className="size-3.5" />
      </button>
      <span role="status" className="sr-only">{copied ? 'Copied' : ''}</span>
    </span>
  );
}

function NotEnrolled({ slug }) {
  return (
    <div className="bg-mesh py-14 sm:py-20">
      <div className="container-x">
        <div className="card mx-auto max-w-lg p-8 text-center">
          <span className="mx-auto grid size-16 place-items-center rounded-2xl bg-primary-soft text-primary"><Icon name="lock" className="size-7" /></span>
          <h1 className="mt-4 font-display text-2xl font-bold text-navy">You’re not enrolled in this course yet</h1>
          <p className="mt-2 text-sm text-muted">If you just paid, it can take a few seconds to confirm - check My Purchases. Otherwise, enrol from the course page.</p>
          <div className="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
            <Link to={`/courses/${slug}`} className="btn-primary">View course</Link>
            <Link to="/dashboard/purchases" className="btn-light">My purchases</Link>
          </div>
        </div>
      </div>
    </div>
  );
}

function EnrolledSkeleton() {
  return (
    <div className="bg-surface pb-16" role="status" aria-label="Loading your enrolment">
      <div className="bg-navy-mesh py-16">
        <div className="container-x flex flex-col items-center gap-4">
          <div className="skeleton size-24 rounded-full opacity-30" />
          <div className="skeleton h-9 w-80 max-w-full rounded-lg opacity-30" />
          <div className="skeleton h-4 w-64 max-w-full rounded opacity-30" />
        </div>
      </div>
      <div className="container-x relative -mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div className="card space-y-3 p-6"><Skeleton className="h-6 w-1/2" /><Skeleton className="h-4 w-3/4" /><Skeleton className="h-24" /></div>
        <div className="card space-y-3 p-6"><Skeleton className="h-6 w-1/2" /><Skeleton className="h-16" /></div>
      </div>
    </div>
  );
}
