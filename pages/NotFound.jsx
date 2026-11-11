import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Icon, MicButton, useSeo } from '../js/components.jsx';

const POPULAR = [
  ['/courses', 'Courses', 'book', 'from-primary to-[#2f7bff]'],
  ['/mock-tests', 'Mock Tests', 'clipboard', 'from-violet to-[#8f74ff]'],
  ['/live-classes', 'Live Classes', 'video', 'from-coral to-[#ff7a45]'],
  ['/resources', 'Free Resources', 'file', 'from-teal to-[#22c3c1]'],
  ['/jobs', 'Govt Jobs', 'briefcase', 'from-[#f2b200] to-gold'],
  ['/blog', 'Blog', 'newspaper', 'from-navy to-[#0b3a8c]'],
];

/** Friendly 404 with a course search and the most visited sections. */
export default function NotFound() {
  useSeo({ title: 'Page not found | Laxyam Academy', robots: 'noindex,follow' });
  const navigate = useNavigate();
  const [q, setQ] = useState('');
  const search = (text) => {
    const t = text.trim();
    navigate(t ? `/courses?q=${encodeURIComponent(t)}` : '/courses');
  };

  return (
    <section className="relative isolate overflow-hidden bg-mesh">
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:radial-gradient(circle_at_center,black,transparent_75%)]" />
      <div aria-hidden="true" className="absolute -top-24 left-1/2 -z-10 size-96 -translate-x-1/2 rounded-full bg-primary/15 blur-3xl" />
      <div className="container-x py-16 text-center sm:py-24">
        <p aria-hidden="true" className="animate-fade-up font-display text-[6.5rem] leading-none font-extrabold tracking-tighter sm:text-[9rem]"><span className="text-gradient">404</span></p>
        <p className="mt-2 font-script text-2xl text-coral">Oops - this page took a different route</p>
        <h1 className="heading-lg mt-3">Page not found</h1>
        <p className="mx-auto mt-3 max-w-md text-muted">The page you are looking for doesn’t exist or has been moved. Search our courses or jump to a popular section.</p>

        <form role="search" onSubmit={(e) => { e.preventDefault(); search(q); }} className="mx-auto mt-8 flex max-w-xl gap-2">
          <div className="flex min-w-0 flex-1 items-center rounded-2xl border border-line bg-white pr-1.5 shadow-card transition focus-within:border-primary focus-within:ring-4 focus-within:ring-primary/15">
            <Icon name="search" className="ml-4 size-5 shrink-0 text-muted" />
            <label htmlFor="nf-q" className="sr-only">Search courses</label>
            <input id="nf-q" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search courses, e.g. SSC CGL" className="min-w-0 flex-1 bg-transparent px-3 py-3.5 text-left text-sm focus:outline-none" />
            <MicButton compact onText={(t, final) => { setQ(t); if (final) search(t); }} />
          </div>
          <button className="btn-primary btn-lg shrink-0">Search</button>
        </form>

        <ul className="mx-auto mt-10 grid max-w-3xl grid-cols-2 gap-3 sm:grid-cols-3">
          {POPULAR.map(([to, label, icon, tone]) => (
            <li key={to}>
              <Link to={to} className="card card-hover group flex items-center gap-3 p-4 text-left">
                <span className={'grid size-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br text-white shadow-sm transition-transform group-hover:scale-110 ' + tone}><Icon name={icon} className="size-5" /></span>
                <span className="min-w-0 truncate font-display text-sm font-bold text-navy">{label}</span>
              </Link>
            </li>
          ))}
        </ul>

        <div className="mt-10 flex flex-wrap justify-center gap-3">
          <Link to="/" className="btn-primary"><Icon name="home" className="size-4" />Go to homepage</Link>
          <Link to="/contact" className="btn-light">Contact support</Link>
        </div>
      </div>
    </section>
  );
}
