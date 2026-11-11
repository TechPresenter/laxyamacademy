// Refer & Earn (/dashboard/refer): the student's referral code and link, the friend's offer, friends who
// joined and the reward coupons earned (api/students.php?action=referral, includes/referrals.php).
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { formatDate, formatPrice } from '../js/api.js';
import { EmptyState, ErrorState, Icon, Skeleton, useApi } from '../js/components.jsx';
import { DashTitle, Panel, Pill } from '../js/account/ui.jsx';

const STATUS = { available: ['green', 'Ready to use'], used: ['blue', 'Used'], expired: ['slate', 'Expired'], withdrawn: ['slate', 'Withdrawn'] };

export default function ReferEarn() {
  const { data, loading, error, reload } = useApi('students.php', { action: 'referral' });
  const [copied, setCopied] = useState(null);

  if (error) return <ErrorState error={error} onRetry={reload} />;
  if (loading && !data) return <div className="space-y-4"><Skeleton className="h-10 w-1/2" /><Skeleton className="h-48" /><Skeleton className="h-40" /></div>;
  if (!data.enabled) {
    return (
      <>
        <DashTitle title="Refer & Earn" icon="gift" />
        <EmptyState icon="gift" title="Referrals are not running right now" text="Check back later - the academy will announce when you can invite friends." />
      </>
    );
  }

  const copy = async (text, what) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(what);
      setTimeout(() => setCopied(null), 2000);
    } catch { /* clipboard blocked: the text stays selectable */ }
  };
  const message = `Join me on Laxyam Academy! Use my code ${data.code} to get ${data.friend_offer} on your first course: ${data.link}`;
  const available = data.rewards.filter((r) => r.status === 'available');

  return (
    <>
      <DashTitle title="Refer & Earn" icon="gift" subtitle="Invite friends who are preparing for exams. They save on their first course, you earn a reward." />
      <section className="relative isolate overflow-hidden rounded-3xl bg-navy-mesh p-6 text-white shadow-card sm:p-8">
        <div aria-hidden="true" className="absolute -top-16 -right-10 -z-10 size-64 rounded-full bg-gold/25 blur-3xl" />
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] lg:items-center">
          <div>
            <p className="text-sm font-semibold text-gold">Your friend gets</p>
            <p className="font-display text-3xl font-extrabold sm:text-4xl">{data.friend_offer}</p>
            <p className="mt-1 text-white/75">on their first course</p>
            <p className="mt-5 text-sm font-semibold text-gold">You get</p>
            <p className="font-display text-2xl font-extrabold">{formatPrice(data.reward_amount)} off your next course</p>
            <p className="mt-1 text-sm text-white/75">for every friend who buys a course · valid {data.reward_days} days</p>
          </div>
          <div className="rounded-2xl bg-white/10 p-5 ring-1 ring-white/15 backdrop-blur">
            <p className="text-xs font-semibold tracking-wide text-white/70 uppercase">Your referral code</p>
            <div className="mt-2 flex items-center gap-2">
              <span className="min-w-0 flex-1 truncate rounded-xl bg-white px-4 py-3 text-center font-mono text-xl font-extrabold tracking-[0.2em] text-navy">{data.code}</span>
              <button type="button" onClick={() => copy(data.code, 'code')} className="btn-gold shrink-0">{copied === 'code' ? <><Icon name="check" className="size-4" />Copied</> : <><Icon name="copy" className="size-4" />Copy</>}</button>
            </div>
            <div className="mt-4 grid gap-2 sm:grid-cols-2">
              <a href={`https://wa.me/?text=${encodeURIComponent(message)}`} target="_blank" rel="noopener noreferrer" className="btn-whatsapp"><Icon name="whatsapp" className="size-4" />Share on WhatsApp</a>
              <button type="button" onClick={() => copy(data.link, 'link')} className="btn-ghost-light"><Icon name="link" className="size-4" />{copied === 'link' ? 'Link copied' : 'Copy invite link'}</button>
            </div>
            <span className="sr-only" aria-live="polite">{copied ? `${copied === 'code' ? 'Code' : 'Link'} copied` : ''}</span>
          </div>
        </div>
      </section>

      <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px]">
        <Panel title="Your rewards" icon="gift" subtitle={`${data.friends_joined} friend${data.friends_joined === 1 ? '' : 's'} bought a course with your code`}>
          {data.rewards.length ? (
            <ul className="divide-y divide-line">
              {data.rewards.map((r, i) => {
                const [tone, label] = STATUS[r.status] || STATUS.available;
                return (
                  <li key={i} className="flex flex-wrap items-center gap-3 py-3">
                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-gold/15 text-gold-dark"><Icon name="gift" className="size-5" /></span>
                    <span className="min-w-0 flex-1">
                      <span className="block font-semibold text-navy">{formatPrice(r.amount)} off · thanks to {r.friend}</span>
                      <span className="block text-xs text-muted">Earned {formatDate(r.earned_at)}{r.expires_at && r.status === 'available' ? ` · use by ${formatDate(r.expires_at)}` : ''}</span>
                    </span>
                    <Pill tone={tone}>{label}</Pill>
                    {r.status === 'available' && (
                      <button type="button" onClick={() => copy(r.code, r.code)} className="btn-light btn-sm font-mono">{copied === r.code ? 'Copied' : r.code}</button>
                    )}
                  </li>
                );
              })}
            </ul>
          ) : (
            <p className="text-sm text-muted">No rewards yet. Share your code - when a friend buys their first course with it, your reward appears here.</p>
          )}
          {available.length > 0 && <p className="mt-3 text-sm text-muted">Use a reward code in the “Have a coupon?” box at checkout. <Link to="/courses" className="font-semibold text-primary hover:underline">Browse courses</Link></p>}
        </Panel>
        <Panel title="How it works" icon="info">
          <ol className="space-y-3 text-sm text-ink">
            {[
              ['share', 'Share your code or link with a friend preparing for an exam.'],
              ['tag', `Your friend enters it at checkout and gets ${data.friend_offer} on their first course.`],
              ['gift', `Once their payment is confirmed you get a ${formatPrice(data.reward_amount)} coupon for your next course.`],
            ].map(([ic, text], i) => (
              <li key={i} className="flex gap-3"><span className="grid size-8 shrink-0 place-items-center rounded-lg bg-primary-soft text-primary"><Icon name={ic} className="size-4" /></span><span className="pt-1">{text}</span></li>
            ))}
          </ol>
          <p className="mt-4 text-xs text-muted">Your own code cannot be used on your purchases. Rewards are for paid courses only; a refunded purchase cancels an unused reward.</p>
        </Panel>
      </div>
    </>
  );
}
