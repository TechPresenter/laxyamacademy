import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api, apiUrl, formatDateTime, formatPrice } from '../js/api.js';
import { Alert, ErrorState, Icon, Spinner, useSeo, useSite } from '../js/components.jsx';
import { invoiceHref, StatusBadge, whatsappHref } from '../js/commerce/ui.jsx';

const MAX_TRIES = 6;
const NOT_STARTED_TRIES = 2; // Cashfree reports no payment attempt at all: stop polling sooner

/** How many status polls to make: fewer when the gateway says the student never started paying. */
const pollLimit = (o) => (o?.gateway_state === 'not_attempted' ? NOT_STARTED_TRIES : MAX_TRIES);

/**
 * A payment arrived but its amount did not match the order (server: needs_review / gateway_state 'mismatch').
 * Access is never activated automatically for it and staff have been alerted - so no polling, no "try again".
 */
const inReview = (o) => !!o && !['paid', 'refunded', 'partially_refunded'].includes(o.status)
  && (o.needs_review === true || o.gateway_state === 'mismatch');

/**
 * Shows the server-verified order status. On arrival (e.g. back from Cashfree's hosted page) it first asks
 * the server to verify with the gateway - the order number in the URL is only a lookup key, never proof of
 * payment - then polls briefly in case the bank / webhook confirms later.
 * A paid order goes straight to the enrolment congratulations page (/enrolled/<slug>?order=…).
 */
export default function PaymentResult() {
  useSeo({ title: 'Payment Status | Laxyam Academy', robots: 'noindex,nofollow' });
  const navigate = useNavigate();
  const { data: site } = useSite();
  const [params] = useSearchParams();
  const orderNo = params.get('order') || '';
  const [order, setOrder] = useState(null);
  const [error, setError] = useState(null);
  const [tries, setTries] = useState(0);
  const [nonce, setNonce] = useState(0); // manual retry after a network error
  const [verified, setVerified] = useState(false);

  // 1. Server-side verification (Cashfree: Get Order + Get Payments). Errors are fine here: the status call
  //    below reports the stored state and re-checks pending Cashfree orders itself.
  useEffect(() => {
    if (!orderNo) return undefined;
    let alive = true;
    setVerified(false);
    api.post('payments.php', { order_number: orderNo }, { action: 'verify' })
      .catch(() => {})
      .finally(() => { if (alive) setVerified(true); });
    return () => { alive = false; };
  }, [orderNo, nonce]);

  // 2. Status, polled while pending.
  useEffect(() => {
    if (!orderNo || !verified) return undefined;
    let timer;
    api.get('orders.php', { action: 'status', order: orderNo })
      .then((o) => {
        setOrder(o);
        if (o.status === 'pending' && !inReview(o) && tries < pollLimit(o)) timer = setTimeout(() => setTries((t) => t + 1), 3000);
      })
      .catch(setError);
    return () => clearTimeout(timer);
  }, [orderNo, tries, nonce, verified]);

  // Google Ads purchase conversion (only when configured in admin → Integrations), once per order.
  useEffect(() => {
    if (order?.status !== 'paid' || !window.gtag || !window.LX_ADS_SEND_TO) return;
    const key = 'lx-conv-' + order.order_number;
    try { if (sessionStorage.getItem(key)) return; sessionStorage.setItem(key, '1'); } catch { /* ignore */ }
    window.gtag('event', 'conversion', { send_to: window.LX_ADS_SEND_TO, value: order.total, currency: 'INR', transaction_id: order.order_number });
  }, [order]);

  // Success → congratulations page (runs after the conversion effect above).
  useEffect(() => {
    if (order?.status === 'paid' && order.course?.slug) {
      navigate(`/enrolled/${order.course.slug}?order=${encodeURIComponent(order.order_number)}`, { replace: true });
    }
  }, [order, navigate]);

  if (!orderNo || error?.status === 404) {
    // No / unknown order number (orders.php status answers 404 for orders that aren't this student's).
    return (
      <div className="bg-mesh py-14 sm:py-20">
        <div className="container-x">
          <div className="card mx-auto max-w-lg p-8 text-center">
            <span className="mx-auto grid size-16 place-items-center rounded-2xl bg-primary-soft text-primary"><Icon name="receipt" className="size-7" /></span>
            <h1 className="mt-4 font-display text-2xl font-bold text-navy">We couldn’t find this order</h1>
            <p className="mt-2 text-sm text-muted">Your orders, invoices and payment status are always listed in My Purchases.</p>
            <Link to="/dashboard/purchases" className="btn-primary mt-6">Go to My Purchases</Link>
          </div>
        </div>
      </div>
    );
  }
  if (error) return <ErrorState error={error} title="We couldn’t check this payment." onRetry={() => { setError(null); setTries(0); setNonce((n) => n + 1); }} />;
  if (!order || (order.status === 'paid' && order.course?.slug)) return <Spinner label="Checking payment status…" className="min-h-[50vh]" />;

  const { status } = order;
  const review = inReview(order);
  const pending = status === 'pending' && !review;
  const polling = pending && tries < pollLimit(order);
  const notStarted = pending && !polling && order.gateway_state === 'not_attempted';
  const paid = status === 'paid';
  const refunded = status === 'refunded';
  const partial = status === 'partially_refunded';
  const failed = !paid && !pending && !refunded && !partial && !review; // failed | cancelled
  const accessActive = order.access?.status === 'active';
  const refundedAmount = Number(order.refunded_amount || 0) || (refunded ? order.total : 0);
  const wa = whatsappHref(site?.site?.whatsapp, review
    ? `Hi, my payment for order ${order.order_number} is under review. Please help me activate my course.`
    : `Hi, I need help with my order ${order.order_number}.`);

  const tone = paid ? 'emerald' : pending || review ? 'amber' : refunded || partial ? 'blue' : 'red';
  const ring = {
    emerald: 'bg-emerald-50 text-emerald-600 ring-emerald-100',
    amber: 'bg-amber-50 text-amber-600 ring-amber-100',
    blue: 'bg-blue-50 text-blue-600 ring-blue-100',
    red: 'bg-red-50 text-red-600 ring-red-100',
  }[tone];
  const title = paid ? 'Payment successful'
    : review ? 'We’re checking your payment'
    : pending ? (polling ? 'Confirming your payment…' : notStarted ? 'Payment not completed yet' : 'Payment confirmation pending')
    : refunded ? 'This order was refunded'
    : partial ? 'This order was partly refunded'
    : status === 'cancelled' ? 'Order cancelled' : 'Payment not completed';

  return (
    <div className="relative isolate overflow-hidden bg-mesh py-10 sm:py-16">
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:radial-gradient(circle_at_center,black,transparent_70%)]" />
      <div className="container-x">
        <div className="card mx-auto max-w-xl animate-scale-in p-6 text-center sm:p-9" role="status" aria-live="polite">
          <span className={'relative mx-auto grid size-20 place-items-center rounded-full ring-8 ' + ring}>
            {polling && <span aria-hidden="true" className="absolute inset-0 animate-spin rounded-full border-4 border-transparent border-t-amber-400 motion-reduce:animate-none" />}
            <Icon name={paid ? 'checkCircle' : review ? 'info' : pending ? 'clock' : refunded || partial ? 'refresh' : 'x'} className="size-9" />
          </span>
          <h1 className="mt-5 font-display text-2xl font-extrabold text-navy sm:text-3xl">{title}</h1>
          <div className="mt-3 flex flex-wrap items-center justify-center gap-2 text-sm text-muted">
            <span>Order <strong className="font-semibold text-ink">{order.order_number}</strong></span>
            <StatusBadge status={review ? 'review' : status} />
          </div>
          {order.course && <p className="mt-2 font-semibold text-ink">{order.course.title}</p>}

          <dl className="mx-auto mt-5 max-w-sm space-y-2 rounded-2xl bg-surface p-4 text-left text-sm">
            <div className="flex justify-between gap-3"><dt className="text-muted">Order amount</dt><dd className="font-bold text-ink">{formatPrice(order.total)}</dd></div>
            {order.paid_at && <div className="flex justify-between gap-3"><dt className="text-muted">Paid on</dt><dd className="font-semibold text-ink">{formatDateTime(order.paid_at)}</dd></div>}
            {(refunded || partial) && refundedAmount > 0 && (
              <>
                <div className="flex justify-between gap-3"><dt className="text-muted">Refunded</dt><dd className="font-semibold text-blue-700">− {formatPrice(refundedAmount)}</dd></div>
                <div className="flex justify-between gap-3 border-t border-line pt-2"><dt className="font-semibold text-navy">Net paid</dt><dd className="font-bold text-navy">{formatPrice(Math.max(0, order.total - refundedAmount))}</dd></div>
              </>
            )}
          </dl>

          {review && (
            <div className="mt-5 space-y-2 text-left text-sm">
              <Alert type="info">
                We received a payment for order <strong>{order.order_number}</strong>, but it needs a quick check by our team before your
                course can be activated, so access was not switched on automatically. Our team has been alerted and will contact you
                (usually within one working day) to activate your course or refund the payment.
              </Alert>
              <p className="text-muted"><strong className="text-ink">Please don’t pay again for this course.</strong> Quote the order number if you contact us.</p>
            </div>
          )}
          {pending && (
            <Alert type="info" className="mt-5 text-left">
              {polling
                ? 'This usually takes a few seconds. Please keep this page open.'
                : notStarted
                  ? `The payment was not completed on ${order.provider_label ? `${order.provider_label}’s` : 'the'} secure page, so no money was taken. You can complete it now - your order is saved.`
                  : 'We have not received confirmation from the bank yet. If money was deducted, your access will be activated automatically as soon as the bank confirms - you’ll also get an email. You can check again or look in My Purchases later.'}
            </Alert>
          )}
          {failed && (
            <div className="mt-5 space-y-2 text-sm text-muted">
              {order.last_attempt?.message && (
                <p className="mx-auto max-w-sm rounded-xl bg-red-50 px-3 py-2 text-red-700"><span className="font-semibold">Reason:</span> {order.last_attempt.message}</p>
              )}
              <p>No access was granted for this order. If money was deducted, your bank will reverse it automatically - or contact us with the order number.</p>
            </div>
          )}
          {(refunded || partial) && (
            <p className="mt-5 text-sm text-muted">
              {refunded
                ? 'Your payment was received and later refunded to the original payment method. Refunds usually reach your account within 5-7 working days.'
                : `Part of your payment (${formatPrice(refundedAmount || 0)}) was refunded to the original payment method.`}
              {' '}{accessActive ? 'Your course access is still active.' : 'Course access for this order has ended.'}
            </p>
          )}

          <div className="mt-7 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:justify-center">
            {notStarted && order.course && (
              <Link to={`/checkout/${order.course.slug}`} className="btn-primary"><Icon name="lock" className="size-4" />Complete payment</Link>
            )}
            {pending && !polling && (
              <button type="button" onClick={() => setTries(0)} className={notStarted ? 'btn-outline' : 'btn-primary'}><Icon name="refresh" className="size-4" />Check again</button>
            )}
            {failed && order.course && (
              <Link to={`/checkout/${order.course.slug}`} className="btn-primary"><Icon name="refresh" className="size-4" />Retry payment</Link>
            )}
            {paid && order.course && <Link to={`/learn/${order.course.slug}`} className="btn-primary">Start learning</Link>}
            {(partial || refunded) && accessActive && order.course && (
              <Link to={`/learn/${order.course.slug}`} className="btn-primary">Go to course</Link>
            )}
            {order.invoice?.invoice_number && (
              <a href={invoiceHref(order.order_number, order.invoice.url)} className="btn-outline"><Icon name="receipt" className="size-4" />Download invoice (PDF)</a>
            )}
            {!order.invoice?.invoice_number && order.receipt_number && (
              <a href={apiUrl('orders.php', { action: 'receipt', order: order.order_number })} target="_blank" rel="noopener" className="btn-outline">View receipt</a>
            )}
            <Link to="/dashboard/purchases" className="btn-light">My purchases</Link>
          </div>
          {(failed || pending || review || refunded || partial) && (
            <p className="mt-6 text-sm text-muted">
              Need help?{' '}
              {wa
                ? <a href={wa} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 font-semibold text-[#128C7E] hover:underline"><Icon name="whatsapp" className="size-4" />Chat on WhatsApp</a>
                : <Link to="/dashboard/support" className="font-semibold text-primary hover:underline">Contact support</Link>}
            </p>
          )}
        </div>
      </div>
    </div>
  );
}
