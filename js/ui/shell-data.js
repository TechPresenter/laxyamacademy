// Constants and small helpers shared by the site shell (header, drawer, footer, search).

/** Used until cms.php?action=layout has loaded (admin → Navigation Menus replaces it). */
export const DEFAULT_NAV = [
  { url: '/', label: 'Home' },
  { url: '/courses', label: 'Courses', mega: true },
  { url: '/mock-tests', label: 'Mock Tests' },
  { url: '/live-classes', label: 'Live Classes' },
  { url: '/jobs', label: 'Jobs' },
  { url: '/about', label: 'About Us' },
  { url: '/contact', label: 'Contact Us' },
];

/** Icon per known site URL (drawer rows, search quick links). */
export const NAV_ICON = {
  '/': 'home', '/courses': 'graduationCap', '/categories': 'grid', '/mock-tests': 'clipboard', '/live-classes': 'video',
  '/resources': 'book', '/jobs': 'briefcase', '/blog': 'newspaper', '/about': 'users', '/careers': 'badge',
  '/contact': 'headset', '/faq': 'help',
};

/** Highlight badges (site_highlights.badge) → [label, classes]. */
export const HIGHLIGHT_BADGES = {
  new: ['New', 'bg-coral text-white'],
  hot: ['Hot', 'bg-gradient-to-r from-orange-500 to-red-500 text-white'],
  live: ['Live', 'bg-red-600 text-white'],
  result: ['Result', 'bg-emerald-600 text-white'],
  admit_card: ['Admit Card', 'bg-violet text-white'],
  update: ['Update', 'bg-primary text-white'],
  offer: ['Offer', 'bg-gold text-navy'],
};

/** Job alert types → [label, badge classes, icon]. */
export const JOB_TYPES = {
  notification: ['Recruitment', 'bg-primary-soft text-primary', 'megaphone'],
  admit_card: ['Admit Card', 'bg-violet/10 text-violet', 'ticket'],
  result: ['Result', 'bg-emerald-50 text-emerald-700', 'trophy'],
  answer_key: ['Answer Key', 'bg-amber-50 text-amber-700', 'checkCircle'],
  syllabus: ['Syllabus', 'bg-slate-100 text-slate-700', 'book'],
};

/** Public detail page of a job alert (jobs.php?action=alert&slug=…). */
export const jobAlertPath = (a) => (a?.slug ? `/jobs/${a.slug}` : '/jobs');

export const tel = (phone) => `tel:${(phone || '').replace(/[^\d+]/g, '')}`;
export const waLink = (number, text) => `https://wa.me/${(number || '').replace(/\D/g, '')}${text ? `?text=${encodeURIComponent(text)}` : ''}`;
export const DEFAULT_WA_MESSAGE = 'Hello Laxyam Academy, I would like to know more about your courses.';

/** Legal entity shown in the copyright bar when settings are not available. */
export const COMPANY_FALLBACK = {
  legal_name: 'LAXYAM INNOVATIVE EDUSKIL PRIVATE LIMITED',
  cin: 'U85491DL2026PTC470370',
};

/** Social networks (admin → Settings → Social media): [label, icon, brand background]. */
export const SOCIAL = {
  youtube: ['YouTube', 'youtube', '#FF0000'],
  facebook: ['Facebook', 'facebook', '#1877F2'],
  instagram: ['Instagram', 'instagram', 'linear-gradient(45deg, #F58529 0%, #DD2A7B 50%, #8134AF 100%)'],
  x: ['X (Twitter)', 'xBrand', '#000000'],
  linkedin: ['LinkedIn', 'linkedin', '#0A66C2'],
  telegram: ['Telegram', 'telegram', '#26A5E4'],
  whatsapp_channel: ['WhatsApp Channel', 'whatsapp', '#25D366'],
  threads: ['Threads', 'threads', '#000000'],
  pinterest: ['Pinterest', 'pinterest', '#E60023'],
};

/** Header/drawer nav items with the special menus they open. */
export function navItems(data) {
  const raw = data?.nav?.length ? data.nav : DEFAULT_NAV;
  return raw.filter((n) => n?.url && n?.label).map((n) => ({
    to: n.url,
    label: n.label,
    end: n.url === '/',
    kind: n.mega ? 'courses' : n.url === '/jobs' ? 'jobs' : null,
  }));
}
