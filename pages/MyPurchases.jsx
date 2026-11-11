import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiUrl, formatDateTime, formatPrice } from '../js/api.js';
import { EmptyState, ErrorState, Icon, Reveal, Skeleton, useApi, useSeo } from '../js/components.jsx';
import { invoiceHref, StatusBadge } from '../js/commerce/ui.jsx';

const FILTERS = [
  ['all', 'All'],
  ['paid', 'Paid'],
  ['pending', 'Pending'],
  ['refunded', 'Refunds'],
  ['failed', 'Failed'],
];
const matches = (o, f) => f === 'all'
  || (f === 'refunded' ? ['refunded', 'partially_refunded'].includes(o.status)
    : f === 'failed' ? ['failed', 'cancelled'].includes(o.status)
      : o.status === f);

export default function MyPurchases() {
  useSeo({ title: 'My Purchases | Laxyam Academy', robots: 'noindex,nofollow' });
  const { data, loading, error, reload } = useApi('students.php', { action: 'purchases' });
  const [filter, setFilter] = useState('all');
  const orders = useMemo(() => (Array.isArray(data) ? data : []), [data]);
  const shown = orders.filter((o) => matches(o, filter));
  const stats = useMemo(() => {
    const paid = orders.filter((o) => ['paid', 'partially_refunded'].includes(o.status));
    return {
      spent: paid.reduce((sum, o) => sum + Number(o.total || 0) - refundOf(o), 0),
      saved: paid.reduce((sum, o) => sum + Number(o.discount || 0), 0),
      courses: new Set(paid.flatMap((o) => o.items.map((i) => i.slug))).size,
    };
  }, [orders]);

  if (error) return <ErrorState error={error} onRetry={reload} />;

  return (
    <div className="space-y-6">
      <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-navy via-[#0b3a78] to-primary p-5 text-white shadow-card sm:p-7">
        <div aria-hidden="true" className="dot-grid absolute inset-0 opacity-30 [mask-image:linear-gradient(to_left,black,transparent_70%)]" />
        <div className="relative flex flex-wrap items-end justify-between gap-4">
          <div className="min-w-0">
            <p className="text-xs font-bold tracking-[0.14em] text-gold uppercase">Billing</p>
            <h1 className="mt-1 font-display text-2xl font-extrabold sm:text-3xl">My purchases</h1>
            <p className="mt-1 text-sm text-white/75">Orders, GST invoices and receipts for your courses.</p>
          </div>
          {!loading && orders.length > 0 && (
            <dl className="grid w-full grid-cols-3 gap-2 sm:w-auto sm:gap-3">
              <Stat k="Total spent" v={formatPrice(Math.max(0, stats.spent))} />
              <Stat k="Saved" v={formatPrice(stats.saved)} />
              <Stat k="Courses" v={stats.courses} />
            </dl>
          )}
        </div>
      </div>

      {loading ? (
        <div className="space-y-4" role="status" aria-label="Loading purchases">
          {[0, 1, 2].map((i) => (
            <div key={i} className="card space-y-3 p-5"><Skeleton className="h-5 w-2/3" /><Skeleton className="h-3 w-1/3" /><Skeleton className="h-9 w-48" rounded="rounded-xl" /></div>
          ))}
        </div>
      ) : orders.length === 0 ? (
        <EmptyState
          icon="receipt"
          title="No purchases yet"
          text="When you buy a course, your order, invoice and receipt will appear here."
          action={<Link to="/courses" className="btn-primary">Browse courses<Icon name="arrowRight" className="size-4" /></Link>}
        />
      ) : (
        <>
          <div role="tablist" aria-label="Filter orders" className="scrollbar-none -mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
            {FILTERS.map(([key, label]) => {
              const count = orders.filter((o) => matches(o, key)).length;
              if (key !== 'all' && count === 0) return null;
              const on = filter === key;
              return (
                <button key={key} type="button" role="tab" aria-selected={on} onClick={() => setFilter(key)}
                  className={'inline-flex shrink-0 items-center gap-1.5 rounded-full px-4 py-2 text-sm font-semibold transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none ' + (on ? 'bg-navy text-white shadow-card' : 'border border-line bg-white text-muted hover:border-primary hover:text-primary')}>
                  {label}<span className={'rounded-full px-1.5 text-xs ' + (on ? 'bg-white/20' : 'bg-surface')}>{count}</span>
                </button>
              );
            })}
          </div>
          <ul className="space-y-4">
            {shown.map((o, i) => <OrderCard key={o.order_number} order={o} delay={Math.min(i, 5) * 50} />)}
          </ul>
        </>
      )}
    </div>
  );
}

/**
 * Refunded amount: students.php purchases sends `refunded_amount` (sum of PROCESSED refunds, 0 when none).
 * A fully refunded order whose refund was recorded outside the refunds table still counts as the full total.
 */
function refundOf(o) {
  const r = Number(o.refunded_amount);
  if (Number.isFinite(r) && r > 0) return r;
  return o.status === 'refunded' ? Number(o.total || 0) : 0;
}

function Stat({ k, v }) {
  return (
    <div className="min-w-0 rounded-xl bg-white/10 px-3 py-2 ring-1 ring-white/15 backdrop-blur">
      <dt className="truncate text-[11px] text-white/70">{k}</dt>
      <dd className="truncate font-display text-base font-extrabold sm:text-lg">{v}</dd>
    </div>
  );
}

function OrderCard({ order: o, delay }) {
  const course = o.items?.[0];
  const title = o.items?.length ? o.items.map((i) => i.title).join(', ') : 'Course order';
  const refundable = ['refunded', 'partially_refunded'].includes(o.status);
  const refunded = refundOf(o);
  const knownRefund = refunded > 0;
  const pending = o.status === 'pending';
  const failed = ['failed', 'cancelled'].includes(o.status);
  const hasAccess = ['paid', 'partially_refunded'].includes(o.status);

  return (
    <Reveal as="li" delay={delay} className="card card-hover overflow-hidden">
      <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-start sm:p-6">
        <span className={'grid size-12 shrink-0 place-items-center rounded-2xl ' + (hasAccess ? 'bg-emerald-50 text-emerald-600' : pending ? 'bg-amber-50 text-amber-600' : refundable ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-500')}>
          <Icon name={hasAccess ? 'graduationCap' : pending ? 'clock' : refundable ? 'refresh' : 'receipt'} className="size-6" />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
            <div className="min-w-0">
              <h2 className="font-display text-base font-bold break-words text-navy sm:text-lg">{title}</h2>
              <p className="mt-0.5 text-xs text-muted">
                Order <span className="font-semibold text-ink">{o.order_number}</span> · {formatDateTime(o.paid_at || o.created_at)}
              </p>
            </div>
            <div className="flex items-center gap-3 sm:flex-col sm:items-end sm:gap-1.5">
              <p className="font-display text-lg font-extrabold text-ink">{formatPrice(o.total)}</p>
              <StatusBadge status={o.status} />
            </div>
          </div>

          <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 rounded-xl bg-surface p-3 text-xs sm:grid-cols-4">
            <Cell k="Course price" v={formatPrice(o.subtotal)} />
            <Cell k="Discount" v={o.discount > 0 ? <span className="text-emerald-700">− {formatPrice(o.discount)}</span> : '-'} />
            {refundable ? (
              <>
                <Cell k="Refunded" v={knownRefund ? <span className="text-blue-700">− {formatPrice(refunded)}</span> : 'Partial'} />
                <Cell k="Net paid" v={knownRefund ? formatPrice(Math.max(0, o.total - refunded)) : '-'} />
              </>
            ) : (
              <>
                <Cell k="Total" v={formatPrice(o.total)} />
                <Cell k="Invoice" v={o.invoice?.invoice_number || (o.receipt_number ? `Receipt ${o.receipt_number}` : '-')} />
              </>
            )}
          </dl>
          {refundable && (
            <p className="mt-2 text-xs text-muted">
              {o.status === 'refunded'
                ? 'This payment was refunded to your original payment method.'
                : 'Part of this payment was refunded to your original payment method. Your course access continues.'}
            </p>
          )}
          {pending && <p className="mt-2 text-xs text-amber-800">This payment wasn’t completed. If money was deducted, it will be confirmed or reversed automatically.</p>}

          <div className="mt-4 flex flex-wrap gap-2">
            {hasAccess && course && <Link to={`/learn/${course.slug}`} className="btn-primary btn-sm"><Icon name="play" className="size-3.5" />Go to course</Link>}
            {pending && course && <Link to={`/checkout/${course.slug}`} className="btn-gold btn-sm"><Icon name="creditCard" className="size-3.5" />Complete payment</Link>}
            {failed && course && <Link to={`/checkout/${course.slug}`} className="btn-light btn-sm"><Icon name="refresh" className="size-3.5" />Try again</Link>}
            {o.invoice?.invoice_number && <a href={invoiceHref(o.order_number, o.invoice.url)} className="btn-outline btn-sm"><Icon name="file" className="size-3.5" />Download invoice (PDF)<span className="sr-only"> {o.invoice.invoice_number}</span></a>}
            {o.receipt_number && (
              <a href={apiUrl('orders.php', { action: 'receipt', order: o.order_number })} target="_blank" rel="noopener" className="btn-light btn-sm">
                <Icon name="receipt" className="size-3.5" />View receipt<span className="sr-only"> (opens in a new tab)</span>
              </a>
            )}
          </div>
        </div>
      </div>
    </Reveal>
  );
}

function Cell({ k, v }) {
  return (
    <div className="min-w-0">
      <dt className="text-muted">{k}</dt>
      <dd className="truncate font-semibold text-ink">{v}</dd>
    </div>
  );
}
