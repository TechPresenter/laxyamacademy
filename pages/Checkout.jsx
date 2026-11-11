import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, formatPrice } from '../js/api.js';
import { Alert, Breadcrumbs, CourseThumb, ErrorState, Icon, Skeleton, useApi, useSeo, useSite } from '../js/components.jsx';
import { loadCashfree, TrustBadges, validityLabel } from '../js/commerce/ui.jsx';
import { forgetReferral, referralCode } from '../js/referral.js';

function loadRazorpay() {
  if (window.Razorpay) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://checkout.razorpay.com/v1/checkout.js';
    s.onload = resolve;
    s.onerror = () => reject(new Error('Could not load the payment window. Check your connection and try again.'));
    document.body.appendChild(s);
  });
}

const GSTIN_RE = /^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const FIELD_ORDER = ['name', 'email', 'phone', 'state', 'gstin', 'address'];

/** "+91 98765-43210" → "9876543210" (same normalisation as the server). */
const normalisePhone = (v) => String(v || '').replace(/[\s\-().]/g, '').replace(/^(?:\+91|0091|91(?=\d{10}$)|0(?=\d{10}$))/, '');

export default function Checkout() {
  useSeo({ title: 'Checkout | Laxyam Academy', robots: 'noindex,nofollow' });
  const { slug } = useParams();
  // A friend's referral code from a shared link is suggested; the server decides whether it applies.
  const [applied, setApplied] = useState(() => referralCode());
  const [billingState, setBillingState] = useState('');
  // The quote is re-priced on the server whenever the coupon or billing state (CGST+SGST vs IGST) changes.
  const { data, loading, error, reload } = useApi('orders.php', { action: 'quote', slug, coupon: applied, state: billingState });
  // A remembered referral code that does not apply here (own code, not a first purchase) is dropped quietly.
  useEffect(() => {
    if (data?.quote?.coupon_error && applied && applied === referralCode()) {
      forgetReferral();
      setApplied('');
    }
  }, [data, applied]);

  if (error && !data) return <ErrorState error={error} onRetry={reload} />;
  if (!data) return <CheckoutSkeleton />;
  if (data.course.is_free) return <FreeEnrol data={data} />;
  return (
    <PaidCheckout
      data={data}
      loading={loading}
      quoteError={error}
      reloadQuote={reload}
      applied={applied}
      setApplied={setApplied}
      onStateChange={setBillingState}
    />
  );
}

// ------------------------------------------------------------------
// Paid checkout: 1 Billing details → 2 Review & Pay
// ------------------------------------------------------------------
function PaidCheckout({ data, loading, quoteError, reloadQuote, applied, setApplied, onStateChange }) {
  const navigate = useNavigate();
  const { data: site } = useSite();
  const { course, quote } = data;
  const tax = quote.tax || data.tax || null;
  const states = data.states?.length ? data.states : [];
  const prefill = data.billing || {};
  const [step, setStep] = useState(1);
  const [values, setValues] = useState({
    name: prefill.name || '', email: prefill.email || '', phone: prefill.phone || '',
    state: prefill.state || '', gstin: prefill.gstin || '', address: prefill.address || '',
  });
  const [needGst, setNeedGst] = useState(!!prefill.gstin);
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState(null);
  // Cashfree: order number while the hosted payment page is opening (this tab is redirected).
  const [redirecting, setRedirecting] = useState(null);
  const topRef = useRef(null);
  // Only name the gateway when it can really take this payment; otherwise the wording stays neutral.
  const providerLabel = data.payments_configured ? (data.payment_provider_label || '') : '';
  const isCashfree = data.payment_provider === 'cashfree';
  // Payable order but no gateway keys: say so on step 1 already, before the student fills the form.
  const payUnavailable = !data.payments_configured && quote.total > 0;

  const blocked = data.already_enrolled || !!data.prerequisite;
  const stateCode = states.find((s) => s.name === values.state)?.code;

  const set = (name) => (e) => {
    const v = e.target.value;
    setValues((s) => ({ ...s, [name]: v }));
    if (errors[name]) setErrors((er) => ({ ...er, [name]: undefined }));
    if (name === 'state') onStateChange(v);
  };

  const validate = () => {
    const e = {};
    if (values.name.trim().length < 2) e.name = 'Enter the name to print on the invoice.';
    else if (values.name.trim().length > 160) e.name = 'Keep the name under 160 characters.';
    if (!EMAIL_RE.test(values.email.trim())) e.email = 'Enter a valid email address.';
    if (!/^[6-9]\d{9}$/.test(normalisePhone(values.phone))) e.phone = 'Enter a valid 10-digit Indian mobile number.';
    if (!values.state) e.state = 'Choose your state or union territory.';
    if (needGst) {
      const g = values.gstin.replace(/\s/g, '').toUpperCase();
      if (!GSTIN_RE.test(g)) e.gstin = 'Enter a valid 15-character GSTIN, e.g. 07ABCDE1234F1Z5.';
      else if (stateCode && g.slice(0, 2) !== stateCode) e.gstin = 'This GSTIN is registered in a different state than the one selected.';
    }
    if (values.address.length > 500) e.address = 'Keep the address under 500 characters.';
    return e;
  };

  const focusFirstError = (e) => {
    const first = FIELD_ORDER.find((k) => e[k]);
    if (first) setTimeout(() => document.getElementById(`bill-${first}`)?.focus(), 0);
  };

  const scrollTop = () => topRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });

  const goReview = (ev) => {
    ev.preventDefault();
    const e = validate();
    setErrors(e);
    if (Object.keys(e).length) { focusFirstError(e); return; }
    setMsg(null);
    setStep(2);
    scrollTop();
  };

  const billingPayload = () => ({
    name: values.name.trim(),
    email: values.email.trim(),
    phone: normalisePhone(values.phone),
    state: values.state,
    gstin: needGst ? values.gstin.replace(/\s/g, '').toUpperCase() : '',
    address: values.address.trim(),
  });

  const pay = async () => {
    setBusy(true);
    setMsg(null);
    try {
      // An invalid coupon is never sent: the student pays the full price instead of being blocked.
      const order = await api.post('orders.php', { slug: course.slug, coupon: quote.coupon ? applied : '', billing: billingPayload() }, { action: 'create' });
      if (order.status === 'paid') {
        navigate(order.next_url || `/enrolled/${course.slug}?order=${encodeURIComponent(order.order_number)}`, { replace: true });
        return;
      }
      if (!order.gateway) {
        navigate(`/payment/result?order=${order.order_number}`, { replace: true });
        return;
      }
      if (order.gateway.provider === 'cashfree') {
        // Hosted Cashfree checkout in this tab. Cashfree returns the student to /payment/result?order=…,
        // where the server asks Cashfree for the outcome (nothing in the URL is trusted).
        setRedirecting(order.order_number);
        const Cashfree = await loadCashfree();
        const cashfree = Cashfree({ mode: order.gateway.mode === 'production' ? 'production' : 'sandbox' });
        const result = await cashfree.checkout({ paymentSessionId: order.gateway.payment_session_id, redirectTarget: '_self' });
        // Only reached when the page could not be opened (a successful redirect leaves this page).
        if (result?.error) throw new Error(result.error.message || 'Could not open the secure payment page. Please try again.');
        return;
      }
      await loadRazorpay();
      const g = order.gateway;
      const rzp = new window.Razorpay({
        key: g.key,
        order_id: g.order_id,
        amount: g.amount,
        currency: g.currency,
        name: g.name,
        description: g.description,
        image: g.image,
        prefill: g.prefill,
        notes: g.notes,
        theme: g.theme || { color: '#0B63F6' },
        handler: async (resp) => {
          try {
            await api.post('payments.php', resp, { action: 'verify' });
          } catch {
            /* result page polls the order status; the webhook may still confirm it */
          }
          navigate(`/payment/result?order=${order.order_number}`, { replace: true });
        },
        modal: { ondismiss: () => setBusy(false) },
      });
      rzp.on('payment.failed', (resp) => {
        api.post('payments.php', { razorpay_order_id: g.order_id, error: resp.error }, { action: 'failed' }).catch(() => {});
        setMsg(resp.error?.description || 'Payment failed. No money was taken, or it will be refunded automatically by your bank.');
        setBusy(false);
      });
      rzp.open();
    } catch (e) {
      setBusy(false);
      setRedirecting(null);
      const billingErrors = Object.fromEntries(Object.entries(e.fields || {}).filter(([k]) => k.startsWith('billing.')).map(([k, v]) => [k.slice(8), v]));
      if (Object.keys(billingErrors).length) {
        setErrors(billingErrors);
        setStep(1);
        setMsg(e.message);
        focusFirstError(billingErrors);
        return;
      }
      if (e.code === 'coupon_invalid') {
        setApplied('');
        setMsg(`${e.fields?.coupon || 'This coupon is no longer valid.'} We removed it - please check the new total and pay again.`);
        return;
      }
      // The course / payment setup changed since the quote was loaded: re-quote so the page shows the
      // right state (enrolled notice, prerequisite, free-course enrolment, "payment unavailable").
      if (['already_enrolled', 'prerequisite', 'free_course', 'payments_not_configured'].includes(e.code)) reloadQuote();
      setMsg(e.message);
    }
  };

  const payDisabled = busy || loading || blocked;
  const payLabel = busy
    ? (isCashfree && quote.total > 0 ? 'Redirecting to secure payment…' : 'Opening secure payment…')
    : quote.total > 0 ? `Pay ${formatPrice(quote.total)} securely` : 'Complete enrolment';

  return (
    <div className="bg-surface pb-16">
      {redirecting && <RedirectingOverlay orderNumber={redirecting} provider={providerLabel} />}
      <section ref={topRef} className="relative isolate scroll-mt-24 overflow-hidden border-b border-line/60 bg-mesh">
        <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:linear-gradient(to_bottom,black,transparent_85%)]" />
        <div aria-hidden="true" className="absolute -top-24 -right-20 -z-10 size-72 rounded-full bg-primary/15 blur-3xl" />
        <div className="container-x py-6 sm:py-9">
          <Breadcrumbs items={[{ to: '/courses', label: 'Courses' }, { to: `/courses/${course.slug}`, label: course.title }, { label: 'Checkout' }]} />
          <div className="mt-4 flex flex-wrap items-end justify-between gap-5">
            <div className="min-w-0">
              <span className="eyebrow"><Icon name="lock" className="size-3.5" />Secure checkout</span>
              <h1 className="heading-lg mt-3 animate-fade-up">Complete your <span className="text-gradient">enrolment</span></h1>
            </div>
            <StepIndicator step={step} onStep={(n) => { if (n < step) setStep(n); }} />
          </div>
        </div>
      </section>

      <div className="container-x mt-6 grid gap-6 sm:mt-8 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
        <div className="min-w-0 space-y-5">
          {data.already_enrolled && (
            <Alert type="info">You are already enrolled in this course. <Link to={`/learn/${course.slug}`} className="font-semibold underline">Go to course</Link></Alert>
          )}
          {data.prerequisite && (
            <Alert type="warning">Please complete “{data.prerequisite}” before enrolling in this course. <Link to="/dashboard/courses" className="font-semibold underline">My courses</Link></Alert>
          )}
          {quoteError && <Alert>{quoteError.message} <button type="button" onClick={reloadQuote} className="font-semibold underline">Retry</button></Alert>}
          {step === 1 && payUnavailable && !blocked && <PaymentUnavailable id="pay-unavailable" site={site} course={course} />}

          {step === 1 ? (
            <form onSubmit={goReview} noValidate className="card animate-fade-up p-5 sm:p-7" aria-labelledby="bill-h">
              <div className="flex items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name="user" /></span>
                <div className="min-w-0">
                  <h2 id="bill-h" className="font-display text-xl font-bold text-navy">Billing details</h2>
                  <p className="text-sm text-muted">These details appear on your invoice. We’ve filled in what we know.</p>
                </div>
              </div>
              {msg && <Alert className="mt-5">{msg}</Alert>}
              <div className="mt-6 grid gap-4 sm:grid-cols-2">
                <BillField id="name" label="Full name" error={errors.name} className="sm:col-span-2">
                  <input id="bill-name" className="input" autoComplete="name" maxLength={160} value={values.name} onChange={set('name')} aria-invalid={!!errors.name} aria-describedby={errors.name ? 'bill-name-err' : undefined} required />
                </BillField>
                <BillField id="email" label="Email" error={errors.email} hint="Your invoice is emailed here. Course access and payment updates go to your account email.">
                  <input id="bill-email" type="email" className="input" autoComplete="email" maxLength={190} value={values.email} onChange={set('email')} aria-invalid={!!errors.email} aria-describedby={errors.email ? 'bill-email-err' : 'bill-email-hint'} required />
                </BillField>
                <BillField id="phone" label="Mobile number" error={errors.phone} hint="10-digit Indian mobile number.">
                  <div className="flex min-w-0">
                    <span className="inline-flex shrink-0 items-center rounded-l-xl border border-r-0 border-line bg-surface px-3 text-sm font-semibold text-muted">+91</span>
                    <input id="bill-phone" type="tel" inputMode="numeric" className="input min-w-0 rounded-l-none" autoComplete="tel-national" maxLength={16} value={values.phone} onChange={set('phone')} aria-invalid={!!errors.phone} aria-describedby={errors.phone ? 'bill-phone-err' : 'bill-phone-hint'} required />
                  </div>
                </BillField>
                <BillField id="state" label="State / Union territory" error={errors.state} hint={tax?.enabled ? 'Decides CGST + SGST or IGST on your invoice.' : undefined} className="sm:col-span-2">
                  <select id="bill-state" className="input" autoComplete="address-level1" value={values.state} onChange={set('state')} aria-invalid={!!errors.state} aria-describedby={errors.state ? 'bill-state-err' : tax?.enabled ? 'bill-state-hint' : undefined} required>
                    <option value="">Select your state</option>
                    {states.map((s) => <option key={s.name} value={s.name}>{s.name}</option>)}
                  </select>
                </BillField>
              </div>

              <div className="mt-5 rounded-2xl border border-line bg-surface/60 p-4">
                <label className="flex cursor-pointer items-start gap-3">
                  <input type="checkbox" className="mt-0.5 size-5 shrink-0 accent-primary" checked={needGst} onChange={(e) => { setNeedGst(e.target.checked); setErrors((er) => ({ ...er, gstin: undefined })); }} />
                  <span className="min-w-0">
                    <span className="block text-sm font-bold text-navy">I need a GST invoice</span>
                    <span className="block text-xs text-muted">Add your business GSTIN to claim input tax credit.</span>
                  </span>
                </label>
                {needGst && (
                  <BillField id="gstin" label="GSTIN" error={errors.gstin} hint="15 characters, e.g. 07ABCDE1234F1Z5" className="mt-4 animate-fade-in">
                    <input id="bill-gstin" className="input uppercase" maxLength={15} autoComplete="off" spellCheck={false} value={values.gstin} onChange={set('gstin')} aria-invalid={!!errors.gstin} aria-describedby={errors.gstin ? 'bill-gstin-err' : 'bill-gstin-hint'} />
                  </BillField>
                )}
              </div>

              <BillField id="address" label={<>Billing address <span className="font-normal text-muted">(optional)</span></>} error={errors.address} className="mt-5">
                <textarea id="bill-address" rows={3} className="input resize-y" autoComplete="street-address" maxLength={500} value={values.address} onChange={set('address')} aria-invalid={!!errors.address} aria-describedby={errors.address ? 'bill-address-err' : undefined} placeholder="House / street, city, PIN code" />
              </BillField>

              <div className="mt-7 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                <Link to={`/courses/${course.slug}`} className="btn-light"><Icon name="chevronLeft" className="size-4" />Back to course</Link>
                <button type="submit" disabled={blocked || payUnavailable} aria-describedby={payUnavailable ? 'pay-unavailable' : undefined} className="btn-primary btn-lg">Continue to review<Icon name="arrowRight" className="size-4" /></button>
              </div>
            </form>
          ) : (
            <section className="card animate-fade-up p-5 sm:p-7" aria-labelledby="review-h">
              <div className="flex items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><Icon name="clipboard" /></span>
                <div className="min-w-0">
                  <h2 id="review-h" className="font-display text-xl font-bold text-navy">Review &amp; pay</h2>
                  <p className="text-sm text-muted">
                    {quote.total > 0
                      ? 'Check your details, then pay securely with UPI, cards, net banking or wallets.'
                      : 'Check your details, then complete your enrolment - your coupon covers the full price.'}
                  </p>
                </div>
              </div>

              <div className="mt-6 rounded-2xl border border-line p-4 sm:p-5">
                <div className="flex items-center justify-between gap-3">
                  <h3 className="font-display text-sm font-bold tracking-wide text-muted uppercase">Billed to</h3>
                  <button type="button" onClick={() => setStep(1)} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-semibold text-primary hover:bg-primary-soft focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none">
                    <Icon name="edit" className="size-4" />Edit
                  </button>
                </div>
                <dl className="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                  <Detail k="Name" v={values.name.trim()} />
                  <Detail k="Email" v={values.email.trim()} />
                  <Detail k="Mobile" v={`+91 ${normalisePhone(values.phone)}`} />
                  <Detail k="State" v={values.state} />
                  {needGst && values.gstin && <Detail k="GSTIN" v={values.gstin.toUpperCase()} />}
                  {values.address.trim() && <Detail k="Address" v={values.address.trim()} className="sm:col-span-2" />}
                </dl>
              </div>

              <div className="mt-5 flex items-center justify-between gap-4 rounded-2xl bg-gradient-to-r from-navy to-[#0b3a78] p-4 text-white sm:p-5">
                <div className="min-w-0">
                  <p className="text-xs font-semibold tracking-wide text-white/70 uppercase">Amount payable</p>
                  <p className="truncate text-sm text-white/85">{course.title}</p>
                </div>
                <p className="shrink-0 font-display text-2xl font-extrabold text-gold sm:text-3xl">{formatPrice(quote.total)}</p>
              </div>

              {msg && <Alert className="mt-5">{msg}</Alert>}

              {payUnavailable ? (
                <PaymentUnavailable className="mt-5" site={site} course={course} />
              ) : (
                <button type="button" disabled={payDisabled} onClick={pay} aria-busy={busy} className="btn-primary btn-lg mt-6 w-full">
                  {busy ? <span aria-hidden="true" className="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" /> : <Icon name="lock" className="size-4" />}
                  {payLabel}
                </button>
              )}
              {data.payments_configured && quote.total > 0 && providerLabel && (
                <p className="mt-3 text-center text-xs text-muted">
                  <span className="inline-flex items-center gap-1.5 font-semibold"><Icon name="shield" className="size-3.5 shrink-0 text-emerald-600" />Secure payments by {providerLabel}</span>
                  {isCashfree && <span className="mt-0.5 block">You’ll complete the payment on Cashfree’s secure page.</span>}
                </p>
              )}
              {data.payment_mode === 'sandbox' && data.payments_configured && <p className="mt-2 text-center text-xs font-semibold text-amber-700">Sandbox mode - test payments only.</p>}
              <p className="mt-4 text-center text-xs text-muted">
                By paying you agree to our <Link to="/terms" className="underline hover:text-primary">Terms</Link> and <Link to="/refund-policy" className="underline hover:text-primary">Refund Policy</Link>.
              </p>
              <div className="mt-5 border-t border-line pt-4">
                <button type="button" onClick={() => setStep(1)} className="btn-light btn-sm"><Icon name="chevronLeft" className="size-4" />Back to billing details</button>
              </div>
            </section>
          )}
        </div>

        <aside className="min-w-0 space-y-5 lg:sticky lg:top-24">
          <OrderSummary course={course} quote={quote} tax={tax} loading={loading} applied={applied} setApplied={setApplied} />
          <TrustBadges provider={providerLabel} />
        </aside>
      </div>
    </div>
  );
}

/** No gateway keys on the server (quote: payments_configured=false): honest notice with real ways to enrol. */
function PaymentUnavailable({ id, className = '', site, course }) {
  const phone = site?.site?.phone;
  return (
    <div id={id} className={className}>
      <Alert type="warning">
        <span className="font-semibold">Online payment is not available right now.</span> To enrol,
        {phone && <> call <a href={`tel:${phone.replace(/\s/g, '')}`} className="font-semibold underline">{phone}</a> or</>}{' '}
        {course.admission === 'both'
          ? <Link to={`/courses/${course.slug}#enquire`} className="font-semibold underline">apply on the course page</Link>
          : <Link to={`/contact?course=${course.slug}`} className="font-semibold underline">send us an enquiry</Link>}.
      </Alert>
    </div>
  );
}

/** Full-screen "Redirecting to secure payment…" state while the Cashfree page opens in this tab. */
function RedirectingOverlay({ orderNumber, provider }) {
  const [slow, setSlow] = useState(false);
  useEffect(() => {
    const t = setTimeout(() => setSlow(true), 12000);
    return () => clearTimeout(t);
  }, []);
  return (
    <div className="fixed inset-0 z-[90] grid place-items-center bg-navy/60 p-4 backdrop-blur-sm animate-fade-in" role="alertdialog" aria-modal="true" aria-labelledby="redir-h" aria-describedby="redir-d">
      <div className="card w-full max-w-sm animate-scale-in p-7 text-center">
        <span className="relative mx-auto grid size-16 place-items-center rounded-full bg-primary-soft text-primary ring-8 ring-primary/10">
          <span aria-hidden="true" className="absolute inset-0 rounded-full border-4 border-transparent border-t-primary animate-spin motion-reduce:animate-none" />
          <Icon name="lock" className="size-6" />
        </span>
        <h2 id="redir-h" className="mt-5 font-display text-xl font-bold text-navy" role="status" aria-live="polite">Redirecting to secure payment…</h2>
        <p id="redir-d" className="mt-2 text-sm text-muted">
          You’ll pay on {provider || 'our payment partner'}’s secure page with UPI, cards, net banking or wallets, then come straight back here. Please don’t close this tab.
        </p>
        {slow && (
          <p className="mt-4 rounded-xl bg-surface p-3 text-xs text-muted animate-fade-in">
            Taking longer than usual?{' '}
            <Link to={`/payment/result?order=${encodeURIComponent(orderNumber)}`} replace className="font-semibold text-primary underline">Check payment status</Link>
          </p>
        )}
      </div>
    </div>
  );
}

function StepIndicator({ step, onStep }) {
  const steps = ['Billing details', 'Review & Pay'];
  return (
    <ol className="flex items-center gap-2 sm:gap-3" aria-label="Checkout progress">
      {steps.map((label, i) => {
        const n = i + 1;
        const done = step > n;
        const current = step === n;
        return (
          <li key={label} className="flex items-center gap-2 sm:gap-3">
            {i > 0 && <span aria-hidden="true" className={'h-0.5 w-6 rounded-full sm:w-10 ' + (step >= n ? 'bg-primary' : 'bg-line')} />}
            <button
              type="button"
              onClick={() => onStep(n)}
              disabled={!done}
              aria-current={current ? 'step' : undefined}
              className="flex items-center gap-2 rounded-full disabled:cursor-default focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
            >
              <span className={'grid size-8 shrink-0 place-items-center rounded-full text-sm font-bold transition-colors ' + (done ? 'bg-emerald-500 text-white' : current ? 'bg-primary text-white shadow-glow' : 'bg-white text-muted ring-1 ring-line')}>
                {done ? <Icon name="check" className="size-4" /> : n}
              </span>
              <span className={'text-sm font-semibold whitespace-nowrap ' + (current ? 'text-navy' : 'text-muted')}>{label}</span>
            </button>
          </li>
        );
      })}
    </ol>
  );
}

function BillField({ id, label, error, hint, className = '', children }) {
  return (
    <div className={'min-w-0 ' + className}>
      <label htmlFor={`bill-${id}`} className="label">{label}</label>
      {children}
      {hint && !error && <p id={`bill-${id}-hint`} className="mt-1 text-xs text-muted">{hint}</p>}
      {error && <p id={`bill-${id}-err`} className="mt-1 text-xs font-medium text-red-600">{error}</p>}
    </div>
  );
}

function Detail({ k, v, className = '' }) {
  return (
    <div className={'min-w-0 ' + className}>
      <dt className="text-xs text-muted">{k}</dt>
      <dd className="font-semibold break-words text-ink">{v}</dd>
    </div>
  );
}

function Row({ k, v, strong = false }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className={strong ? 'font-bold text-navy' : 'text-muted'}>{k}</dt>
      <dd className={'text-right ' + (strong ? 'font-display text-xl font-extrabold text-navy' : 'font-semibold text-ink')}>{v}</dd>
    </div>
  );
}

function OrderSummary({ course, quote, tax, loading, applied, setApplied }) {
  const [code, setCode] = useState(applied);
  useEffect(() => { setCode(applied); }, [applied]);
  const apply = (e) => {
    e.preventDefault();
    const c = code.trim().toUpperCase();
    if (c) setApplied(c);
  };
  const remove = () => { setApplied(''); setCode(''); };
  const gst = tax?.enabled && tax.rate > 0;
  const half = gst ? +(tax.rate / 2).toFixed(2) : 0;
  // Inter-state supply (billing state ≠ seller state) → IGST; same state → CGST + SGST.
  const inter = gst && (tax.intra_state === false || tax.igst > 0);
  const gstAmount = gst ? +(Number(tax.cgst || 0) + Number(tax.sgst || 0) + Number(tax.igst || 0)).toFixed(2) : 0;

  return (
    <section className="card overflow-hidden" aria-labelledby="sum-h">
      <CourseThumb course={course} showBadge={false} />
      <div className="p-5">
        <p className="text-xs font-bold tracking-wide uppercase" style={{ color: course.category?.color || '#0B63F6' }}>{course.category?.name}</p>
        <h2 id="sum-h" className="mt-1 font-display text-lg leading-snug font-bold text-navy">{course.title}</h2>
        {/* Classroom courses: the online access period is not the point, the classes at the centre are */}
        {course.course_type === 'offline'
          ? <p className="mt-2 flex items-center gap-1.5 text-sm text-muted"><Icon name="presentation" className="size-4" />Offline classroom course{course.duration ? ` · ${course.duration}` : ''}</p>
          : <p className="mt-2 flex items-center gap-1.5 text-sm text-muted"><Icon name="calendar" className="size-4" />{validityLabel(course.validity_days)}{course.validity_days ? ' from purchase' : ''}</p>}

        <form onSubmit={apply} className="mt-5" aria-label="Coupon">
          <label htmlFor="coupon" className="label">Have a coupon?</label>
          <div className="flex min-w-0 gap-2">
            <input id="coupon" value={code} onChange={(e) => setCode(e.target.value)} placeholder="Enter code" maxLength={40} className="input min-w-0 uppercase" aria-invalid={!!quote.coupon_error} aria-describedby={quote.coupon_error ? 'coupon-msg' : undefined} />
            <button type="submit" disabled={!code.trim() || loading} className="btn-dark shrink-0">Apply</button>
          </div>
          <div aria-live="polite">
            {quote.coupon_error && (
              <div id="coupon-msg" className="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
                <span className="min-w-0">{quote.coupon_error} You can still pay the full price.</span>
                <button type="button" onClick={remove} className="font-bold underline">Remove</button>
              </div>
            )}
            {quote.coupon && (
              <div className="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                <span className="flex min-w-0 items-center gap-1.5"><Icon name="tag" className="size-3.5 shrink-0" /><span><strong>{quote.coupon.code}</strong> applied{quote.coupon.description ? ` - ${quote.coupon.description}` : ''}</span></span>
                <button type="button" onClick={remove} className="font-bold underline">Remove</button>
              </div>
            )}
          </div>
        </form>

        <dl className={'mt-5 space-y-2.5 text-sm transition-opacity ' + (loading ? 'opacity-60' : '')} aria-busy={loading}>
          {quote.list_price > quote.subtotal && <Row k="List price" v={<s className="font-normal text-muted">{formatPrice(quote.list_price)}</s>} />}
          <Row k="Course price" v={formatPrice(quote.subtotal)} />
          {quote.discount > 0 && <Row k={`Coupon discount${quote.coupon ? ` (${quote.coupon.code})` : ''}`} v={<span className="text-emerald-700">− {formatPrice(quote.discount)}</span>} />}
          {gst && !tax.inclusive && (
            // GST charged on top of the (discounted) price: taxable value + tax = total payable.
            <div className="space-y-2 rounded-xl bg-surface p-3">
              <Row k="Taxable value" v={formatPrice(tax.taxable_value)} />
              {inter
                ? <Row k={`+ IGST @ ${tax.rate}%`} v={formatPrice(tax.igst)} />
                : <><Row k={`+ CGST @ ${half}%`} v={formatPrice(tax.cgst)} /><Row k={`+ SGST @ ${half}%`} v={formatPrice(tax.sgst)} /></>}
            </div>
          )}
          <div className="border-t border-dashed border-line pt-3"><Row strong k="Total payable" v={formatPrice(quote.total)} /></div>
          {gst && tax.inclusive && (
            // GST already inside the price: show how the total splits for the invoice.
            <div className="space-y-2 rounded-xl bg-surface p-3">
              <Row k="Includes GST" v={formatPrice(gstAmount)} />
              <Row k="Taxable value" v={formatPrice(tax.taxable_value)} />
              {inter
                ? <Row k={`IGST @ ${tax.rate}%`} v={formatPrice(tax.igst)} />
                : <><Row k={`CGST @ ${half}%`} v={formatPrice(tax.cgst)} /><Row k={`SGST @ ${half}%`} v={formatPrice(tax.sgst)} /></>}
            </div>
          )}
        </dl>
        <p className="mt-1 text-xs text-muted">
          {gst
            ? <>{tax.inclusive ? 'Price includes GST.' : `GST @ ${tax.rate}% is added to the course price.`}{tax.place_of_supply ? ` Place of supply: ${tax.place_of_supply} (${inter ? 'IGST' : 'CGST + SGST'}).` : ''}</>
            : 'Prices are inclusive of applicable taxes.'}
        </p>
        {/* Savings are measured before tax (net = price after coupon), so GST added on top doesn't shrink them. */}
        {quote.list_price > (quote.net ?? quote.total) && quote.total > 0 && (
          <p className="mt-3 rounded-lg bg-gold/15 px-3 py-2 text-center text-xs font-bold text-navy">You save {formatPrice(+(quote.list_price - (quote.net ?? quote.total)).toFixed(2))} on this course</p>
        )}
        {quote.total <= 0 && quote.coupon && (
          <p className="mt-3 rounded-lg bg-emerald-50 px-3 py-2 text-center text-xs font-bold text-emerald-800">Your coupon covers the full price - no payment needed.</p>
        )}
      </div>
    </section>
  );
}

function CheckoutSkeleton() {
  return (
    <div className="bg-surface pb-16" role="status" aria-label="Loading checkout">
      <div className="border-b border-line/60 bg-mesh">
        <div className="container-x space-y-3 py-9"><Skeleton className="h-4 w-48" /><Skeleton className="h-9 w-72 max-w-full" /></div>
      </div>
      <div className="container-x mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <div className="card space-y-4 p-6">
          <Skeleton className="h-6 w-40" />
          <div className="grid gap-4 sm:grid-cols-2">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-11" rounded="rounded-xl" />)}</div>
          <Skeleton className="h-20" rounded="rounded-2xl" />
        </div>
        <div className="card overflow-hidden"><Skeleton className="aspect-[16/9]" rounded="rounded-none" /><div className="space-y-3 p-5"><Skeleton className="h-5 w-3/4" /><Skeleton className="h-11" rounded="rounded-xl" /><Skeleton className="h-24" /></div></div>
      </div>
    </div>
  );
}

// ------------------------------------------------------------------
// Free course: one-click enrolment (prerequisite respected) → /enrolled/<slug>
// ------------------------------------------------------------------
function FreeEnrol({ data }) {
  const navigate = useNavigate();
  const { course } = data;
  const [state, setState] = useState({ busy: false, error: null });
  const enrol = async () => {
    setState({ busy: true, error: null });
    try {
      await api.post('lessons.php', { slug: course.slug }, { action: 'enrol-free' });
      navigate(`/enrolled/${course.slug}`, { replace: true });
    } catch (e) {
      setState({ busy: false, error: e.message });
    }
  };
  return (
    <div className="relative isolate overflow-hidden bg-mesh py-10 sm:py-16">
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:radial-gradient(circle_at_center,black,transparent_70%)]" />
      <div className="container-x">
        <div className="mx-auto max-w-lg">
          <Breadcrumbs items={[{ to: '/courses', label: 'Courses' }, { to: `/courses/${course.slug}`, label: course.title }, { label: 'Enrol' }]} />
          <div className="card mt-4 animate-scale-in overflow-hidden">
            <div className="relative">
              <CourseThumb course={course} showBadge={false} />
              <span className="absolute top-3 left-3 rounded-full bg-gradient-to-r from-amber-500 to-orange-500 px-3 py-1 text-xs font-bold tracking-wide text-white uppercase shadow-md">Free course</span>
            </div>
            <div className="p-6 text-center sm:p-8">
              <p className="text-xs font-bold tracking-wide uppercase" style={{ color: course.category?.color || '#0B63F6' }}>{course.category?.name}</p>
              <h1 className="mt-1 font-display text-2xl font-bold text-navy">{course.title}</h1>
              <p className="mt-2 text-sm text-muted">Free forever · {validityLabel(course.validity_days)} · Start right away</p>
              {data.prerequisite && (
                <Alert type="warning" className="mt-5 text-left">
                  Please complete “{data.prerequisite}” before enrolling in this course. <Link to="/dashboard/courses" className="font-semibold underline">My courses</Link>
                </Alert>
              )}
              {state.error && <Alert className="mt-5 text-left">{state.error}</Alert>}
              {data.already_enrolled ? (
                <Link to={`/learn/${course.slug}`} className="btn-primary btn-lg mt-6 w-full">Go to course<Icon name="arrowRight" className="size-4" /></Link>
              ) : (
                <button type="button" disabled={state.busy || !!data.prerequisite} onClick={enrol} aria-busy={state.busy} className="btn-primary btn-lg mt-6 w-full">
                  {state.busy ? 'Enrolling…' : <>Enrol for free<Icon name="arrowRight" className="size-4" /></>}
                </button>
              )}
              <Link to={`/courses/${course.slug}`} className="mt-4 inline-block text-sm font-semibold text-muted hover:text-primary">Back to course details</Link>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
