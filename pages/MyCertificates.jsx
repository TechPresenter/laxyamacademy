import { useState } from 'react';
import { Link } from 'react-router-dom';
import { api, apiUrl, formatDate } from '../js/api.js';
import { EmptyState, ErrorState, Icon, Reveal, Skeleton, useApi } from '../js/components.jsx';
import { DashTitle } from '../js/account/ui.jsx';

const certUrl = (no) => apiUrl('students.php', { action: 'certificate', no });
/** Public check printed on every certificate (no login): students.php?action=verify-certificate&no=… */
const verifyUrl = (no) => apiUrl('students.php', { action: 'verify-certificate', no });

/** Opens the printable certificate in a new tab and starts printing once it has loaded (same origin). */
function printCertificate(no) {
  const w = window.open(certUrl(no), '_blank');
  if (!w) {
    window.location.href = certUrl(no); // popup blocked: open it here, the browser's print works from there
    return;
  }
  w.addEventListener('load', () => {
    try {
      w.focus();
      w.print();
    } catch {
      /* the tab stays open for a manual print */
    }
  }, { once: true });
}

export default function MyCertificates() {
  const { data, loading, error, reload } = useApi('students.php', { action: 'certificates' });
  // Enrolled courses, to show completed ones that are still waiting for their certificate.
  const courses = useApi('students.php', { action: 'courses' });
  const [copied, setCopied] = useState(null);
  if (error && !data) return <ErrorState error={error} onRetry={reload} />;
  const list = Array.isArray(data) ? data : [];
  const issued = new Set(list.map((c) => c.slug));
  const pending = (Array.isArray(courses.data) ? courses.data : [])
    .filter((c) => (c.enrolment?.completed_at || Number(c.enrolment?.progress) >= 100) && !issued.has(c.slug));

  const copy = async (no) => {
    try {
      await navigator.clipboard.writeText(no);
      setCopied(no);
      setTimeout(() => setCopied((c) => (c === no ? null : c)), 2000);
    } catch {
      setCopied(null);
    }
  };

  return (
    <>
      <DashTitle title="My Certificates" icon="badge" subtitle="Certificates are issued by the academy after you complete 100% of a course." />
      {!(loading && !data) && pending.length > 0 && <PendingCertificates courses={pending} />}
      {loading && !data ? (
        <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">{[0, 1, 2].map((i) => <li key={i}><Skeleton className="h-72" rounded="rounded-2xl" /></li>)}</ul>
      ) : list.length === 0 && pending.length > 0 ? null : list.length === 0 ? (
        <EmptyState icon="badge" title="No certificates yet" text="Complete all lessons of a course to become eligible. Your certificate will appear here once it is issued."
          action={<Link to="/dashboard/courses" className="btn-primary">Continue learning <Icon name="arrowRight" className="size-4" /></Link>} />
      ) : (
        <ul className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {list.map((c, i) => (
            <Reveal as="li" key={c.certificate_no} delay={Math.min(i, 5) * 60} className="card card-hover flex min-w-0 flex-col overflow-hidden">
              {/* Certificate preview */}
              <div className="relative isolate bg-navy-mesh p-5 text-center text-white">
                <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-30" />
                <div className="rounded-xl border-2 border-gold/60 px-4 py-5 outline outline-1 outline-offset-4 outline-white/20">
                  <Icon name="badge" className="mx-auto size-10 text-gold" />
                  <p className="mt-2 text-[10px] font-bold tracking-[0.25em] text-white/70 uppercase">Certificate of completion</p>
                  <p className="mt-2 line-clamp-2 font-display text-base leading-snug font-bold">{c.title}</p>
                </div>
              </div>
              <div className="flex flex-1 flex-col p-5">
                <dl className="grid grid-cols-2 gap-3 text-xs">
                  <div className="min-w-0"><dt className="text-muted">Certificate no.</dt><dd className="mt-0.5 flex items-center gap-1 font-semibold text-ink"><span className="truncate">{c.certificate_no}</span>
                    <button type="button" onClick={() => copy(c.certificate_no)} className="shrink-0 rounded p-0.5 text-muted hover:text-primary" aria-label={`Copy certificate number ${c.certificate_no}`}><Icon name={copied === c.certificate_no ? 'check' : 'copy'} className="size-3.5" /></button>
                  </dd></div>
                  <div><dt className="text-muted">Issued on</dt><dd className="mt-0.5 font-semibold text-ink">{formatDate(c.issued_at)}</dd></div>
                </dl>
                <span className="sr-only" aria-live="polite">{copied === c.certificate_no ? 'Certificate number copied' : ''}</span>
                <div className="mt-auto pt-5">
                  <div className="grid grid-cols-2 gap-2">
                    <a href={certUrl(c.certificate_no)} target="_blank" rel="noopener" className="btn-primary btn-sm"><Icon name="eye" className="size-4" /> View</a>
                    <button type="button" onClick={() => printCertificate(c.certificate_no)} className="btn-light btn-sm"><Icon name="file" className="size-4" /> Print / PDF</button>
                  </div>
                  <VerifyBlock no={c.certificate_no} />
                </div>
                {c.slug && <Link to={`/courses/${c.slug}`} className="mt-3 text-center text-xs font-semibold text-primary hover:underline">View course</Link>}
              </div>
            </Reveal>
          ))}
        </ul>
      )}
    </>
  );
}

/** Courses the student has completed (100%) whose certificate the academy has not issued yet. */
function PendingCertificates({ courses }) {
  return (
    <section aria-labelledby="cert-pending" className="card mb-6 overflow-hidden">
      <div className="flex items-start gap-3 border-b border-line bg-gradient-to-r from-amber-50 to-white p-5">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-100 text-amber-600"><Icon name="clock" className="size-5" /></span>
        <div className="min-w-0">
          <h2 id="cert-pending" className="font-display text-lg font-bold text-navy">Completed - certificate on its way</h2>
          <p className="mt-0.5 text-sm text-muted">Well done! The academy issues your certificate after reviewing your completion. It will appear here, and you will be notified once it is ready.</p>
        </div>
      </div>
      <ul className="divide-y divide-line">
        {courses.map((c) => (
          <li key={c.slug} className="flex items-start gap-4 px-5 py-4 sm:items-center">
            <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-green-50 text-green-600"><Icon name="checkCircle" className="size-5" /></span>
            {/* Phones: status + link on their own row under the title; wider screens: one row */}
            <div className="min-w-0 flex-1 sm:flex sm:items-center sm:gap-4">
              <div className="min-w-0 flex-1">
                <p className="font-semibold break-words text-ink sm:truncate">{c.title}</p>
                <p className="text-xs text-muted">{c.enrolment?.completed_at ? `Completed on ${formatDate(c.enrolment.completed_at)}` : 'All lessons completed'}</p>
              </div>
              <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-2 sm:mt-0 sm:shrink-0">
                <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-200"><Icon name="clock" className="size-3.5" /> Certificate pending</span>
                <Link to={`/courses/${c.slug}`} className="text-xs font-semibold text-primary hover:underline">View course</Link>
              </div>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}

/**
 * Runs the same public verification an employer or institute would (the URL printed on the
 * certificate) and shows what it discloses; "Copy link" copies that public verification URL.
 */
function VerifyBlock({ no }) {
  const [state, setState] = useState({ status: 'idle' });
  const [copied, setCopied] = useState(false);

  const verify = async () => {
    setState({ status: 'busy' });
    try {
      setState({ status: 'done', result: await api.get('students.php', { action: 'verify-certificate', no }) });
    } catch (e) {
      setState({ status: 'error', message: e.message });
    }
  };
  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(verifyUrl(no));
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };
  const r = state.result;

  return (
    <div className="mt-2">
      <div className="grid grid-cols-2 gap-2">
        <button type="button" onClick={verify} disabled={state.status === 'busy'} aria-busy={state.status === 'busy'} className="btn-outline btn-sm">
          <Icon name="shield" className="size-4" /> {state.status === 'busy' ? 'Checking…' : 'Verify'}
        </button>
        <button type="button" onClick={copyLink} className="btn-light btn-sm" aria-label={`Copy public verification link for certificate ${no}`}>
          <Icon name={copied ? 'check' : 'link'} className="size-4" /> {copied ? 'Copied' : 'Copy link'}
        </button>
      </div>
      <div aria-live="polite">
        {state.status === 'done' && r?.valid && (
          <div className="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-900 animate-fade-in">
            <p className="flex items-center gap-1.5 font-bold"><Icon name="checkCircle" className="size-4 shrink-0" /> Valid certificate · {r.issued_by}</p>
            <p className="mt-1">Issued to <strong>{r.student_name}</strong> for <strong>{r.course}</strong>{r.issued_at ? ` on ${formatDate(r.issued_at)}` : ''}.</p>
            <p className="mt-1 text-emerald-800/80">This is what anyone checking certificate {r.certificate_no} sees.</p>
          </div>
        )}
        {state.status === 'done' && r && !r.valid && (
          <p className="mt-3 rounded-xl bg-red-50 p-3 text-xs font-semibold text-red-700">Certificate {r.certificate_no} could not be verified. Please contact support.</p>
        )}
        {state.status === 'error' && <p className="mt-3 rounded-xl bg-red-50 p-3 text-xs font-semibold text-red-700">{state.message}</p>}
        {copied && <span className="sr-only">Verification link copied</span>}
      </div>
    </div>
  );
}
