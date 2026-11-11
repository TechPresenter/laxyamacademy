import { useState } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { api } from '../js/api.js';
import { DEFAULT_CONSENT_TEXT, Icon, MicButton, PageHeader, Reveal, useCmsSeo, useSite } from '../js/components.jsx';
import { SocialLinks } from '../js/ui/social.jsx';

// Registered company (also returned by cms.php?action=layout → company; settings win when filled in)
const LEGAL_NAME = 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED';
const LEGAL_CIN = 'U85491DL2026PTC470370';
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const EMPTY = { name: '', email: '', phone: '', subject: '', message: '', consent: false, website: '' };

export default function Contact() {
  useCmsSeo('/contact', { title: 'Contact Us - Laxyam Academy', description: 'Call, WhatsApp or email Laxyam Academy, visit our New Delhi centre, or send us an enquiry about courses, batches and admissions.' });
  const { data } = useSite();
  const site = data?.site;
  const company = data?.company;
  const location = useLocation();
  const [params] = useSearchParams();
  const course = params.get('course') || '';
  const initialSubject = params.get('subject') || (course ? 'Course enquiry' : '');

  const [form, setForm] = useState({ ...EMPTY, subject: initialSubject });
  const [status, setStatus] = useState({ state: 'idle', message: '', fields: {} });
  const set = (k) => (e) => {
    const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
    setForm((f) => ({ ...f, [k]: value }));
    if (status.fields[k]) setStatus((s) => ({ ...s, fields: { ...s.fields, [k]: undefined } }));
  };

  const validate = () => {
    const f = {};
    if (form.name.trim().length < 2) f.name = 'Please enter your full name.';
    if (!form.email.trim() && !form.phone.trim()) f.email = 'Enter an email or a phone number so we can reply.';
    if (form.email.trim() && !EMAIL_RE.test(form.email.trim())) f.email = 'Enter a valid email address.';
    if (form.message.trim().length < 5) f.message = 'Please write a short message (at least 5 characters).';
    if (!form.consent) f.consent = 'Please tick the box so we can reply to you.';
    return f;
  };

  const submit = async (e) => {
    e.preventDefault();
    const found = validate();
    if (Object.keys(found).length) {
      setStatus({ state: 'error', message: 'Please correct the highlighted fields.', fields: found });
      document.getElementById(Object.keys(found)[0])?.focus();
      return;
    }
    setStatus({ state: 'sending', message: '', fields: {} });
    try {
      const res = await api.post('enquiries.php', {
        name: form.name.trim(),
        email: form.email.trim() || undefined,
        phone: form.phone.trim() || undefined,
        subject: form.subject.trim() || undefined,
        message: form.message.trim(),
        course: course || undefined,
        course_slug: course || undefined,
        source: 'contact_form',
        consent: form.consent,
        page_url: (location.pathname + location.search).slice(0, 250),
        website: form.website,
      });
      setStatus({ state: 'sent', message: res?.message || 'Thank you! Our team will get back to you shortly.', fields: {} });
      setForm(EMPTY);
    } catch (err) {
      setStatus({ state: 'error', message: err.message, fields: err.fields || {} });
      const first = Object.keys(err.fields || {})[0];
      if (first) document.getElementById(first === 'course_slug' ? 'subject' : first)?.focus();
    }
  };

  const err = (k) => status.fields[k] && <p id={`${k}-err`} className="mt-1 text-xs font-medium text-red-600">{status.fields[k]}</p>;
  const field = (k) => ({ id: k, name: k, value: form[k], onChange: set(k), 'aria-invalid': !!status.fields[k], 'aria-describedby': status.fields[k] ? `${k}-err` : undefined });

  const phone = site?.phone?.replace(/\s/g, '');
  const waText = encodeURIComponent('Hello Laxyam Academy, I would like to know more about your courses.');
  const mapQuery = site?.map_query || site?.address;
  const actions = [
    phone && { href: `tel:${phone}`, icon: 'phoneCall', title: 'Call us', value: site.phone, note: site.office_hours || site.helpline_label, bg: 'bg-gradient-to-br from-primary to-[#2f7bff]' },
    site?.whatsapp && { href: `https://wa.me/${site.whatsapp}?text=${waText}`, icon: 'whatsapp', title: 'WhatsApp', value: 'Chat with a counsellor', note: 'Usually replies within minutes', bg: 'bg-[#25D366]', external: true },
    site?.email && { href: `mailto:${site.email}`, icon: 'mail', title: 'Email', value: site.email, note: 'We reply within one working day', bg: 'bg-gradient-to-br from-coral to-[#ff7a45]' },
    mapQuery && { href: `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(mapQuery)}`, icon: 'mapPin', title: 'Visit us', value: site?.address || mapQuery, note: 'Open in Google Maps', bg: 'bg-gradient-to-br from-violet to-[#8f74ff]', external: true },
  ].filter(Boolean);
  const hasSocial = data?.social?.some((s) => s.url);

  return (
    <>
      <PageHeader
        eyebrow={<><Icon name="headset" className="size-3.5" />We are here to help</>}
        title="Contact"
        highlight="Us"
        subtitle="Questions about courses, batches, fees or admissions? Call, WhatsApp, email or send us a message - our counsellors will guide you."
        crumbs={[{ to: '/', label: 'Home' }, { label: 'Contact Us' }]}
      />

      <div className="bg-surface">
        {actions.length > 0 && (
          <div className="container-x relative z-10 -mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {actions.map((a, i) => (
              <Reveal key={a.title} delay={i * 60} className="min-w-0">
                <a href={a.href} {...(a.external ? { target: '_blank', rel: 'noopener noreferrer' } : {})} className="card card-hover group flex h-full min-w-0 items-start gap-4 p-5">
                  <span className={'grid size-12 shrink-0 place-items-center rounded-2xl text-white shadow-md transition-transform group-hover:scale-110 ' + a.bg}><Icon name={a.icon} className="size-6" /></span>
                  <span className="min-w-0">
                    <span className="block font-display font-bold text-navy">{a.title}</span>
                    <span className="block truncate text-sm font-semibold text-ink" title={a.value}>{a.value}</span>
                    {a.note && <span className="mt-0.5 block text-xs text-muted">{a.note}</span>}
                  </span>
                </a>
              </Reveal>
            ))}
          </div>
        )}

        <div className="container-x grid gap-6 py-10 pb-14 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
          <Reveal as="form" onSubmit={submit} noValidate className="card min-w-0 p-6 sm:p-8" aria-labelledby="enq-heading">
            <h2 id="enq-heading" className="font-display text-2xl font-extrabold text-navy">Send an enquiry</h2>
            <p className="mt-1 text-sm text-muted">{course ? <>About course: <span className="font-semibold text-ink">{course.replace(/-/g, ' ')}</span></> : 'Fields marked * are required.'}</p>

            {status.state === 'sent' && (
              <div role="status" className="mt-5 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                <Icon name="checkCircle" className="size-5 shrink-0" /><span>{status.message}</span>
              </div>
            )}
            {status.state === 'error' && status.message && <p role="alert" className="mt-5 rounded-2xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{status.message}</p>}

            <div className="mt-6 grid gap-4 sm:grid-cols-2">
              <div className="min-w-0 sm:col-span-2">
                <label htmlFor="name" className="label">Full name *</label>
                <input {...field('name')} required maxLength={120} autoComplete="name" className="input" placeholder="Your name" />
                {err('name')}
              </div>
              <div className="min-w-0">
                <label htmlFor="email" className="label">Email</label>
                <input {...field('email')} type="email" maxLength={190} autoComplete="email" inputMode="email" className="input" placeholder="you@example.com" />
                {err('email')}
              </div>
              <div className="min-w-0">
                <label htmlFor="phone" className="label">Phone</label>
                <input {...field('phone')} type="tel" maxLength={16} autoComplete="tel" inputMode="tel" className="input" placeholder="10-digit mobile" />
                {err('phone')}
              </div>
              <div className="min-w-0 sm:col-span-2">
                <label htmlFor="subject" className="label">Subject</label>
                <input {...field('subject')} maxLength={160} className="input" placeholder="e.g. UPSC foundation batch timings" />
                {err('subject')}{err('course_slug')}{err('course')}
              </div>
              <div className="min-w-0 sm:col-span-2">
                <div className="flex items-center justify-between gap-2">
                  <label htmlFor="message" className="label">Message *</label>
                  <MicButton onText={(text, final) => final && setForm((f) => ({ ...f, message: (f.message ? f.message + ' ' : '') + text }))} />
                </div>
                <textarea {...field('message')} required rows={5} maxLength={3000} className="input resize-y" placeholder="Tell us your target exam, preferred batch or your question." />
                {err('message')}
              </div>
              {/* Honeypot for bots - hidden from people and assistive tech */}
              <div className="hidden" aria-hidden="true">
                <label htmlFor="website">Website</label>
                <input id="website" name="website" tabIndex={-1} autoComplete="off" value={form.website} onChange={set('website')} />
              </div>
              <div className="sm:col-span-2">
                <label htmlFor="consent" className="flex cursor-pointer items-start gap-2.5 text-xs leading-relaxed text-muted">
                  <input {...field('consent')} type="checkbox" checked={form.consent} value={undefined} className="mt-0.5 size-4 shrink-0 rounded border-line accent-primary" />
                  <span>{DEFAULT_CONSENT_TEXT} <Link to="/privacy-policy" className="font-semibold text-primary hover:underline">Privacy Policy</Link></span>
                </label>
                {err('consent')}
              </div>
            </div>
            <p className="mt-4 text-xs text-muted">Provide an email or phone number so we can reply.</p>
            <button type="submit" disabled={status.state === 'sending'} className="btn-primary btn-lg mt-5 w-full sm:w-auto">
              {status.state === 'sending' ? <><span className="size-4 animate-spin rounded-full border-2 border-white border-t-transparent" aria-hidden="true" />Sending…</> : <><Icon name="send" className="size-4" />Send message</>}
            </button>
          </Reveal>

          <aside className="min-w-0 space-y-5">
            {mapQuery && (
              <Reveal className="card overflow-hidden">
                <iframe
                  title="Laxyam Academy location map"
                  src={`https://maps.google.com/maps?q=${encodeURIComponent(mapQuery)}&z=15&output=embed`}
                  loading="lazy"
                  referrerPolicy="no-referrer-when-downgrade"
                  className="h-64 w-full border-0"
                />
                <div className="flex items-start gap-3 p-4">
                  <Icon name="mapPin" className="mt-0.5 size-5 shrink-0 text-primary" />
                  <div className="min-w-0 text-sm">
                    {site?.address && <p className="text-ink">{site.address}</p>}
                    <a href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(mapQuery)}`} target="_blank" rel="noopener noreferrer" className="mt-1 inline-flex items-center gap-1.5 font-semibold text-primary hover:underline">
                      Get directions <Icon name="externalLink" className="size-4" />
                    </a>
                  </div>
                </div>
              </Reveal>
            )}
            {site?.office_hours && (
              <Reveal className="card flex items-start gap-4 p-5">
                <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-primary-soft text-primary"><Icon name="clock" /></span>
                <div className="text-sm"><p className="font-display font-bold text-navy">Office hours</p><p className="mt-0.5 whitespace-pre-line text-muted">{site.office_hours}</p></div>
              </Reveal>
            )}
            {hasSocial && (
              <Reveal className="card p-5">
                <p className="font-display font-bold text-navy">Follow us</p>
                <p className="mt-0.5 text-sm text-muted">Daily current affairs, exam updates and free classes.</p>
                <SocialLinks social={data.social} className="mt-4" />
              </Reveal>
            )}
            <Reveal className="relative isolate overflow-hidden rounded-2xl bg-navy-mesh p-5 text-white shadow-card">
              <div aria-hidden="true" className="dot-grid absolute inset-0 -z-10 opacity-30" />
              <p className="flex items-center gap-2 text-xs font-bold tracking-widest text-gold uppercase"><Icon name="building" className="size-4" />Registered company</p>
              <p className="mt-2 font-display font-extrabold tracking-wide">{company?.legal_name || LEGAL_NAME}</p>
              <dl className="mt-3 space-y-1.5 text-sm text-white/80">
                <div><dt className="inline text-white/60">CIN: </dt><dd className="inline font-semibold break-all text-white">{company?.cin || LEGAL_CIN}</dd></div>
                {company?.gstin && <div><dt className="inline text-white/60">GSTIN: </dt><dd className="inline font-semibold break-all text-white">{company.gstin}</dd></div>}
                {company?.address && <div><dt className="sr-only">Registered address</dt><dd>{company.address}</dd></div>}
              </dl>
              <p className="mt-3 text-xs text-white/60">Laxyam Academy is a brand of {company?.legal_name || LEGAL_NAME}.</p>
            </Reveal>
          </aside>
        </div>
      </div>
    </>
  );
}
