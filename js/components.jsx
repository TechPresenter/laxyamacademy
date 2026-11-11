// Shared UI: icons, logo, data hooks, SEO, forms, voice input, course cards
// and generic states. Site chrome (header/footer) lives in layout.jsx.
import { createContext, useContext, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { api, APP_BASE, formatPrice } from './api.js';
import { useAuth } from './auth.jsx';

export const asset = (path) => APP_BASE + path.replace(/^\//, '');

// ------------------------------------------------------------------
// Icons (inline SVG, 24x24 stroke icons + a few filled brand marks)
// ------------------------------------------------------------------
const STROKE = {
  mapPin: <><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z" /><circle cx="12" cy="10" r="3" /></>,
  phone: <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" />,
  mail: <><rect x="2" y="4" width="20" height="16" rx="2" /><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7" /></>,
  clock: <><circle cx="12" cy="12" r="10" /><path d="M12 6v6l4 2" /></>,
  search: <><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></>,
  menu: <path d="M4 6h16M4 12h16M4 18h16" />,
  x: <path d="M18 6 6 18M6 6l12 12" />,
  arrowRight: <path d="M5 12h14M12 5l7 7-7 7" />,
  chevronLeft: <path d="m15 18-6-6 6-6" />,
  chevronRight: <path d="m9 18 6-6-6-6" />,
  chevronDown: <path d="m6 9 6 6 6-6" />,
  playCircle: <><circle cx="12" cy="12" r="10" /><path d="m10 8 6 4-6 4Z" /></>,
  user: <><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></>,
  users: <><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" /><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></>,
  clipboard: <><rect x="8" y="2" width="8" height="4" rx="1" /><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2" /><path d="m9 14 2 2 4-4" /></>,
  video: <><path d="m16 13 5.2 3.5a.5.5 0 0 0 .8-.4V7.9a.5.5 0 0 0-.8-.4L16 11" /><rect x="2" y="6" width="14" height="12" rx="2" /></>,
  book: <><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" /><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" /></>,
  file: <><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z" /><path d="M14 2v4a2 2 0 0 0 2 2h4M16 13H8M16 17H8M10 9H8" /></>,
  calendar: <><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></>,
  check: <path d="M20 6 9 17l-5-5" />,
  checkCircle: <><circle cx="12" cy="12" r="10" /><path d="m9 12 2 2 4-4" /></>,
  lock: <><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></>,
  landmark: <path d="M3 22h18M6 18v-7M10 18v-7M14 18v-7M18 18v-7M12 2l9 5H3z" />,
  briefcase: <><rect x="2" y="7" width="20" height="14" rx="2" /><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" /></>,
  bank: <><path d="M3 21h18M3 10h18M5 6l7-3 7 3" /><path d="M4 10v11M20 10v11M8 14v3M12 14v3M16 14v3" /></>,
  building: <><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01" /></>,
  presentation: <path d="M2 3h20M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3M7 21l5-5 5 5" />,
  shield: <><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" /><path d="m9 12 2 2 4-4" /></>,
  train: <><rect x="4" y="3" width="16" height="16" rx="2" /><path d="M4 11h16M12 3v8M8 19l-2 3M18 22l-2-3M8 15h.01M16 15h.01" /></>,
  badge: <><circle cx="12" cy="8" r="6" /><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11" /></>,
  grid: <><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /></>,
  trophy: <path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22M18 2H6v7a6 6 0 0 0 12 0V2Z" />,
  headset: <path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3" />,
  newspaper: <><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2" /><path d="M18 14h-8M15 18h-5M10 6h8v4h-8V6Z" /></>,
  globe: <><circle cx="12" cy="12" r="10" /><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20M2 12h20" /></>,
  help: <><circle cx="12" cy="12" r="10" /><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01" /></>,
  target: <><circle cx="12" cy="12" r="10" /><circle cx="12" cy="12" r="6" /><circle cx="12" cy="12" r="2" /></>,
  bell: <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0" />,
  sliders: <path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6" />,
  layers: <><path d="m12 2 10 5-10 5L2 7Z" /><path d="m2 17 10 5 10-5M2 12l10 5 10-5" /></>,
  infinity: <path d="M12 12c-2-2.67-4-4-6-4a4 4 0 1 0 0 8c2 0 4-1.33 6-4Zm0 0c2 2.67 4 4 6 4a4 4 0 0 0 0-8c-2 0-4 1.33-6 4Z" />,
  globeLang: <><circle cx="12" cy="12" r="10" /><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" /></>,
  mic: <><rect x="9" y="2" width="6" height="12" rx="3" /><path d="M19 10v1a7 7 0 0 1-14 0v-1M12 18v4M8 22h8" /></>,
  share: <><circle cx="18" cy="5" r="3" /><circle cx="6" cy="12" r="3" /><circle cx="18" cy="19" r="3" /><path d="m8.59 13.51 6.83 3.98M15.41 6.51l-6.82 3.98" /></>,
  arrowUp: <path d="M12 19V5M5 12l7-7 7 7" />,
  refresh: <path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5" />,
  send: <><path d="m22 2-7 20-4-9-9-4Z" /><path d="M22 2 11 13" /></>,
  link: <><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" /></>,
  externalLink: <path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />,
  upload: <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12" />,
  tag: <><path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z" /><circle cx="7.5" cy="7.5" r="1.5" /></>,
  megaphone: <><path d="m3 11 18-5v12L3 14v-3z" /><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6" /></>,
  heart: <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />,
  settings: <><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z" /><circle cx="12" cy="12" r="3" /></>,
  logout: <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />,
  edit: <path d="M12 20h9M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4Z" />,
  camera: <><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z" /><circle cx="12" cy="13" r="3" /></>,
  receipt: <><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z" /><path d="M16 8h-6M16 12h-6M13 16h-3" /></>,
  creditCard: <><rect x="2" y="5" width="20" height="14" rx="2" /><path d="M2 10h20" /></>,
  monitor: <><rect x="2" y="3" width="20" height="14" rx="2" /><path d="M8 21h8M12 17v4" /></>,
  trash: <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />,
  messages: <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" />,
  ticket: <><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" /><path d="M13 5v2M13 17v2M13 11v2" /></>,
  calculator: <><rect x="4" y="2" width="16" height="20" rx="2" /><path d="M8 6h8M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01" /></>,
  puzzle: <path d="M19.44 7.85c-.05.32.06.65.29.88l1.57 1.57c.47.47.7 1.09.7 1.7s-.23 1.24-.7 1.71l-1.61 1.61a.98.98 0 0 1-.84.28c-.47-.07-.8-.48-.97-.93a2.5 2.5 0 1 0-3.21 3.21c.45.17.86.5.93.97a.98.98 0 0 1-.28.84l-1.61 1.61a2.4 2.4 0 0 1-3.41 0l-1.57-1.57a1.03 1.03 0 0 0-.88-.29c-.49.07-.84.5-1.02.97a2.5 2.5 0 1 1-3.24-3.24c.46-.18.9-.53.97-1.02a1.03 1.03 0 0 0-.29-.88l-1.57-1.57a2.4 2.4 0 0 1 0-3.41l1.53-1.52c.24-.24.58-.36.92-.31.51.08.88.53 1.07 1.01a2.5 2.5 0 1 0 3.26-3.26c-.48-.19-.93-.56-1.01-1.07-.05-.34.06-.68.3-.92l1.53-1.52a2.4 2.4 0 0 1 3.4 0l1.57 1.57c.23.23.56.34.88.29.49-.07.84-.5 1.02-.97a2.5 2.5 0 1 1 3.24 3.24c-.46.18-.9.53-.97 1.02Z" />,
  atom: <><circle cx="12" cy="12" r="1" /><path d="M20.2 20.2c2.04-2.03.02-7.36-4.5-11.9-4.54-4.52-9.87-6.54-11.9-4.5-2.04 2.03-.02 7.36 4.5 11.9 4.54 4.52 9.87 6.54 11.9 4.5Z" /><path d="M15.7 15.7c4.52-4.54 6.54-9.87 4.5-11.9-2.03-2.04-7.36-.02-11.9 4.5-4.52 4.54-6.54 9.87-4.5 11.9 2.03 2.04 7.36.02 11.9-4.5Z" /></>,
  languages: <path d="m5 8 6 6M4 14l6-6 2-3M2 5h12M7 2h1M22 22l-5-10-5 10M14 18h6" />,
  home: <><path d="M3 10.5 12 3l9 7.5" /><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5" /></>,
  sparkles: <><path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.13-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.13a.5.5 0 0 1 .96 0l1.58 6.13a2 2 0 0 0 1.44 1.44l6.13 1.58a.5.5 0 0 1 0 .96l-6.13 1.58a2 2 0 0 0-1.44 1.44l-1.58 6.13a.5.5 0 0 1-.96 0z" /><path d="M20 3v4M22 5h-4M4 17v2M5 18H3" /></>,
  graduationCap: <><path d="M21.42 10.92a1 1 0 0 0-.02-1.84L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.83l8.57 3.91a2 2 0 0 0 1.66 0z" /><path d="M22 10v6M6 12.5V16a6 3 0 0 0 12 0v-3.5" /></>,
  barChart: <path d="M3 3v18h18M18 17V9M13 17V5M8 17v-3" />,
  copy: <><rect x="8" y="8" width="14" height="14" rx="2" /><path d="M4 16a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2" /></>,
  moreHorizontal: <><circle cx="12" cy="12" r="1" /><circle cx="19" cy="12" r="1" /><circle cx="5" cy="12" r="1" /></>,
  trendingUp: <path d="M22 7 13.5 15.5l-5-5L2 17M16 7h6v6" />,
  history: <><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8" /><path d="M3 3v5h5M12 7v5l4 2" /></>,
  flame: <path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z" />,
  gift: <><rect x="3" y="8" width="18" height="4" rx="1" /><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7M7.5 8a2.5 2.5 0 0 1 0-5C10 3 12 8 12 8s2-5 4.5-5a2.5 2.5 0 0 1 0 5" /></>,
  info: <><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></>,
  alert: <path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3M12 9v4M12 17h.01" />,
  chevronUp: <path d="m18 15-6-6-6 6" />,
  eye: <><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0" /><circle cx="12" cy="12" r="3" /></>,
  inbox: <><path d="M22 12h-6l-2 3h-4l-2-3H2" /><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" /></>,
  phoneCall: <><path d="M13 2a9 9 0 0 1 9 9M13 6a5 5 0 0 1 5 5" /><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z" /></>,
  layoutDashboard: <><rect x="3" y="3" width="7" height="9" rx="1" /><rect x="14" y="3" width="7" height="5" rx="1" /><rect x="14" y="12" width="7" height="9" rx="1" /><rect x="3" y="16" width="7" height="5" rx="1" /></>,
};

const FILLED = {
  star: <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z" />,
  play: <path d="M7 4.5v15l13-7.5z" />,
  quote: <path d="M9.5 6C6.5 7.2 4 9.9 4 14v4h6v-6H7c0-2.2 1.4-3.9 3.3-4.6zM19.5 6c-3 1.2-5.5 3.9-5.5 8v4h6v-6h-3c0-2.2 1.4-3.9 3.3-4.6z" />,
  whatsapp: <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />,
  youtube: <path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />,
  facebook: <path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z" />,
  linkedin: <path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9.5h4V21H3zM9.5 9.5h3.8v1.6h.1c.5-1 1.8-2 3.8-2 4 0 4.8 2.6 4.8 6V21h-4v-5.1c0-1.2 0-2.8-1.7-2.8s-2 1.3-2 2.7V21h-4z" />,
  telegram: <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z" />,
  instagram: <path d="M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077" />,
  threads: <path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.472 12.01v-.017c.03-3.579.879-6.43 2.525-8.482C5.845 1.205 8.6.024 12.18 0h.014c2.746.02 5.043.725 6.826 2.098 1.677 1.29 2.858 3.13 3.509 5.467l-2.04.569c-1.104-3.96-3.898-5.984-8.304-6.015-2.91.022-5.11.936-6.54 2.717C4.307 6.504 3.616 8.914 3.589 12c.027 3.086.718 5.496 2.057 7.164 1.43 1.783 3.631 2.698 6.54 2.717 2.623-.02 4.358-.631 5.8-2.045 1.647-1.613 1.618-3.593 1.09-4.798-.31-.71-.873-1.3-1.634-1.75-.192 1.352-.622 2.446-1.284 3.272-.886 1.102-2.14 1.704-3.73 1.79-1.202.065-2.361-.218-3.259-.801-1.063-.689-1.685-1.74-1.752-2.964-.065-1.19.408-2.285 1.33-3.082.88-.76 2.119-1.207 3.583-1.291a13.853 13.853 0 0 1 3.02.142c-.126-.742-.375-1.332-.75-1.757-.513-.586-1.308-.883-2.359-.89h-.029c-.844 0-1.992.232-2.721 1.32L7.734 7.847c.98-1.454 2.568-2.256 4.478-2.256h.044c3.194.02 5.097 1.975 5.287 5.388.108.046.216.094.321.142 1.49.7 2.58 1.761 3.154 3.07.797 1.82.871 4.79-1.548 7.158-1.85 1.81-4.094 2.628-7.277 2.65Zm1.003-11.69c-.242 0-.487.007-.739.021-1.836.103-2.98.946-2.916 2.143.067 1.256 1.452 1.839 2.784 1.767 1.224-.065 2.818-.543 3.086-3.71a10.5 10.5 0 0 0-2.215-.221z" />,
  pinterest: <path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.663.967-2.911 2.168-2.911 1.024 0 1.518.769 1.518 1.688 0 1.029-.653 2.567-.992 3.992-.285 1.193.6 2.165 1.775 2.165 2.128 0 3.768-2.245 3.768-5.487 0-2.861-2.063-4.869-5.008-4.869-3.41 0-5.409 2.562-5.409 5.199 0 1.033.394 2.143.889 2.741.099.12.112.225.085.345-.09.375-.293 1.199-.334 1.363-.053.225-.172.271-.401.165-1.495-.69-2.433-2.878-2.433-4.646 0-3.776 2.748-7.252 7.92-7.252 4.158 0 7.392 2.967 7.392 6.923 0 4.135-2.607 7.462-6.233 7.462-1.214 0-2.354-.629-2.758-1.379l-.749 2.848c-.269 1.045-1.004 2.352-1.498 3.146 1.123.345 2.306.535 3.55.535 6.607 0 11.985-5.365 11.985-11.987C23.97 5.39 18.592.026 11.985.026L12.017 0z" />,
  heartFilled: <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />,
  xBrand: <path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z" />,
  googlePlay: <path d="M22.018 13.298l-3.919 2.218-3.515-3.493 3.543-3.521 3.891 2.202a1.49 1.49 0 0 1 0 2.594zM1.337.924a1.486 1.486 0 0 0-.112.568v21.017c0 .217.045.419.124.6l11.155-11.087L1.337.924zm12.207 10.065l3.258-3.238L3.45.195a1.466 1.466 0 0 0-.946-.179l11.04 10.973zm0 2.067l-11 10.933c.298.036.612-.016.906-.183l13.324-7.54-3.23-3.21z" />,
  apple: <path d="M12.152 6.896c-.948 0-2.415-1.078-3.96-1.04-2.04.027-3.91 1.183-4.961 3.014-2.117 3.675-.546 9.103 1.519 12.09 1.013 1.454 2.208 3.09 3.792 3.039 1.52-.065 2.09-.987 3.935-.987 1.831 0 2.35.987 3.96.948 1.637-.026 2.676-1.48 3.676-2.948 1.156-1.688 1.636-3.325 1.662-3.415-.039-.013-3.182-1.221-3.22-4.857-.026-3.04 2.48-4.494 2.597-4.559-1.429-2.09-3.623-2.324-4.39-2.376-2-.156-3.675 1.09-4.61 1.09zM15.53 3.83c.843-1.012 1.4-2.427 1.245-3.83-1.207.052-2.662.805-3.532 1.818-.78.896-1.454 2.338-1.273 3.714 1.338.104 2.715-.688 3.559-1.701" />,
};

export function Icon({ name, className = 'size-5', strokeWidth = 2, title, style }) {
  const filled = FILLED[name];
  const shape = filled || STROKE[name] || STROKE.grid;
  return (
    <svg
      viewBox="0 0 24 24"
      className={className}
      style={style}
      fill={filled ? 'currentColor' : 'none'}
      stroke={filled ? 'none' : 'currentColor'}
      strokeWidth={strokeWidth}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden={title ? undefined : true}
      role={title ? 'img' : undefined}
    >
      {title && <title>{title}</title>}
      {shape}
    </svg>
  );
}

// ------------------------------------------------------------------
// Logo: uses /assets/logo.png when present, otherwise an SVG fallback
// ------------------------------------------------------------------
export function LogoMark({ className = 'h-12 w-auto' }) {
  return (
    <svg viewBox="0 0 64 56" className={className} aria-hidden="true">
      <defs>
        <linearGradient id="lx-arc" x1="0" y1="1" x2="1" y2="0">
          <stop offset="0" stopColor="#FFC928" />
          <stop offset="1" stopColor="#F04E23" />
        </linearGradient>
      </defs>
      <path d="M5 33 A27 27 0 0 1 50 9" fill="none" stroke="url(#lx-arc)" strokeWidth="4" strokeLinecap="round" />
      <path d="M53 2.5l1.6 3.3 3.6.5-2.6 2.5.6 3.6-3.2-1.7-3.2 1.7.6-3.6-2.6-2.5 3.6-.5z" fill="#FFC928" />
      <path d="M2 34 Q16 30 32 38 Q48 30 62 34 L62 52 Q48 48 32 56 Q16 48 2 52 Z" fill="#0B3A8C" />
      <path d="M6 34.5 Q18 31.5 30.5 38.5 L30.5 52 Q18 46 6 49 Z" fill="#fff" />
      <path d="M58 34.5 Q46 31.5 33.5 38.5 L33.5 52 Q46 46 58 49 Z" fill="#fff" />
      <path d="M9 41 Q19 39 29 43.5 M35 43.5 Q45 39 55 41" stroke="#0B63F6" strokeWidth="2" fill="none" />
      <path d="M17 14 L30 29 L30 40 L34 40 L34 29 L47 14 L43 13 L32 25 L21 13 Z" fill="#0B3A8C" />
      <circle cx="32" cy="17" r="4.5" fill="#0B63F6" />
      <path d="M24.5 10.5 L32 7 L39.5 10.5 L32 14 Z" fill="#08264D" />
      <path d="M38 11 v5" stroke="#FFC928" strokeWidth="1.2" />
    </svg>
  );
}

/**
 * Brand logo from /assets/logo.webp (PNG fallback). The logo artwork is navy,
 * so on dark backgrounds ("light" variant) it sits on a white card.
 */
const LOGO_HEIGHT = { sm: 'h-9 sm:h-10 xl:h-11', md: 'h-11 sm:h-[52px]', lg: 'h-14' };

/** size: 'sm' (site header), 'md' (default) or 'lg'; the light variant defaults to lg. */
export function Logo({ variant = 'dark', className = '', size }) {
  const [imgOk, setImgOk] = useState(true);
  const { data } = useSite();
  const light = variant === 'light';
  const height = LOGO_HEIGHT[size || (light ? 'lg' : 'md')] + ' ';
  if (imgOk && data?.site?.custom_logo && data.site.logo_url) {
    // Admin → Appearance → Logo upload
    const img = <img src={data.site.logo_url} alt="Laxyam Academy - Learn, Prepare, Achieve" fetchPriority={light ? undefined : 'high'} className={height + 'w-auto ' + className} onError={() => setImgOk(false)} />;
    return light ? <span className="inline-block rounded-xl bg-white px-4 py-2.5 shadow-sm">{img}</span> : img;
  }
  if (imgOk) {
    const img = (
      <picture>
        <source srcSet={asset('assets/logo.webp')} type="image/webp" />
        <img
          src={asset('assets/logo.png')}
          alt="Laxyam Academy - Learn, Prepare, Achieve"
          width="439"
          height="128"
          fetchPriority={light ? undefined : 'high'}
          className={height + 'w-auto ' + className}
          onError={() => setImgOk(false)}
        />
      </picture>
    );
    return light ? <span className="inline-block rounded-xl bg-white px-4 py-2.5 shadow-sm">{img}</span> : img;
  }
  return (
    <span className={'flex items-center gap-2 ' + className} aria-label="Laxyam Academy">
      <LogoMark className="h-11 w-auto sm:h-12" />
      <span className="flex flex-col leading-none">
        <span className={'text-xl font-extrabold tracking-wide sm:text-2xl ' + (light ? 'text-white' : 'text-navy')}>LAXYAM</span>
        <span className={'mt-0.5 rounded-sm px-1.5 py-0.5 text-center text-[10px] font-bold tracking-[0.3em] ' + (light ? 'bg-white text-navy' : 'bg-navy text-white')}>ACADEMY</span>
        <span className={'mt-1 text-[9px] font-medium tracking-wide ' + (light ? 'text-white/80' : 'text-navy')}>Learn • Prepare • Achieve</span>
      </span>
    </span>
  );
}

// ------------------------------------------------------------------
// Data hooks
// ------------------------------------------------------------------

/** GET an API path and track loading/error state. Re-runs when params change. */
export function useApi(path, params) {
  const key = JSON.stringify(params || {});
  const [state, setState] = useState({ data: null, loading: true, error: null });
  const [nonce, setNonce] = useState(0);

  useEffect(() => {
    if (!path) return undefined;
    const ctrl = new AbortController();
    setState((s) => ({ ...s, loading: true, error: null }));
    api
      .get(path, JSON.parse(key), { signal: ctrl.signal })
      .then((data) => setState({ data, loading: false, error: null }))
      .catch((error) => {
        if (error.name !== 'AbortError') setState((s) => ({ ...s, loading: false, error }));
      });
    return () => ctrl.abort();
  }, [path, key, nonce]);

  return { ...state, reload: () => setNonce((n) => n + 1) };
}

const SiteContext = createContext({ data: null, loading: true });

/** Site settings, announcement bar, footer and nav categories - loaded once. */
export function SiteProvider({ children }) {
  const layout = useApi('cms.php', { action: 'layout' });
  return <SiteContext.Provider value={layout}>{children}</SiteContext.Provider>;
}

export const useSite = () => useContext(SiteContext);

// ------------------------------------------------------------------
// SEO: title, description, canonical, robots, Open Graph
// ------------------------------------------------------------------
function setMeta(attr, key, content) {
  let el = document.head.querySelector(`meta[${attr}="${key}"]`);
  if (!content) {
    el?.remove();
    return;
  }
  if (!el) {
    el = document.createElement('meta');
    el.setAttribute(attr, key);
    document.head.appendChild(el);
  }
  el.setAttribute('content', content);
}

export function useSeo({ title, description, canonical, robots = 'index,follow', image, ogTitle, ogDescription } = {}) {
  const location = useLocation();
  useEffect(() => {
    if (title) document.title = title;
    setMeta('name', 'description', description);
    setMeta('name', 'robots', robots);
    setMeta('property', 'og:title', ogTitle || title);
    setMeta('property', 'og:description', ogDescription || description);
    setMeta('property', 'og:image', image);
    const url = canonical || window.location.origin + APP_BASE.replace(/\/$/, '') + location.pathname;
    setMeta('property', 'og:url', url);
    let link = document.head.querySelector('link[rel="canonical"]');
    if (!link) {
      link = document.createElement('link');
      link.rel = 'canonical';
      document.head.appendChild(link);
    }
    link.href = url;
  }, [title, description, canonical, robots, image, ogTitle, ogDescription, location.pathname]);
}

/** SEO for static pages, managed in admin/seo.php (falls back to the given defaults). */
export function useCmsSeo(path, fallback = {}) {
  const { data } = useApi('cms.php', { action: 'seo', path });
  useSeo({
    title: data?.title || fallback.title,
    description: data?.meta_description || data?.description || fallback.description,
    canonical: data?.canonical,
    robots: data?.robots,
    image: data?.og_image,
    ogTitle: data?.og_title,
    ogDescription: data?.og_description,
  });
}

// ------------------------------------------------------------------
// Links: CMS URLs may be internal ("/courses") or external ("https://...")
// ------------------------------------------------------------------
export function SmartLink({ to, children, ...rest }) {
  if (!to) return <span {...rest}>{children}</span>;
  if (/^(https?:|mailto:|tel:)/i.test(to)) {
    const external = /^https?:/i.test(to);
    return (
      <a href={to} {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})} {...rest}>
        {children}
      </a>
    );
  }
  return <Link to={to} {...rest}>{children}</Link>;
}

// ------------------------------------------------------------------
// Scroll reveal: <Reveal as="section" delay={120}>…</Reveal>
// Adds .reveal-ready to <html> once, then .is-visible when the element scrolls into view
// (css/style.css animates [data-reveal]). Without IntersectionObserver content shows at once.
// ------------------------------------------------------------------
let revealObserver = null;
function observeReveal(el) {
  if (!el) return () => {};
  if (typeof IntersectionObserver === 'undefined') {
    el.classList.add('is-visible');
    return () => {};
  }
  revealObserver ||= new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        revealObserver.unobserve(entry.target);
      }
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0 });
  revealObserver.observe(el);
  return () => revealObserver.unobserve(el);
}

export function Reveal({ as: Tag = 'div', delay = 0, className, style, children, ...rest }) {
  const ref = useRef(null);
  useLayoutEffect(() => {
    document.documentElement.classList.add('reveal-ready');
    return observeReveal(ref.current);
  }, []);
  return (
    <Tag ref={ref} data-reveal="" className={className} style={delay ? { ...style, '--reveal-delay': `${delay}ms` } : style} {...rest}>
      {children}
    </Tag>
  );
}

// ------------------------------------------------------------------
// Generic UI pieces
// ------------------------------------------------------------------
const HEADING_SIZE = {
  md: 'font-display text-2xl leading-tight font-extrabold tracking-tight sm:text-[28px]',
  lg: 'heading-lg',
  xl: 'heading-xl',
};

/** Gradient highlight word with the gold hand-drawn underline. */
export function Highlight({ children }) {
  if (!children) return null;
  return <span className="underline-swash isolate"><span className="text-gradient">{children}</span></span>;
}

/**
 * Section title. eyebrow: small pill above; highlight: gradient word with swash underline;
 * align: 'left' (action on the right) | 'center'; size: 'md' | 'lg' (default) | 'xl'.
 */
export function SectionHeading({ title, highlight, subtitle, action, eyebrow, align = 'left', size = 'lg', as: Tag = 'h2', className = '' }) {
  const center = align === 'center';
  return (
    <div className={(center ? 'mb-8 flex flex-col items-center gap-4 text-center ' : 'mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-3 sm:mb-8 ') + className}>
      <div className="min-w-0 max-w-3xl">
        {eyebrow && <span className="eyebrow mb-3">{eyebrow}</span>}
        <Tag className={HEADING_SIZE[size] || HEADING_SIZE.lg}>
          {title}{title && highlight ? ' ' : ''}<Highlight>{highlight}</Highlight>
        </Tag>
        {subtitle && <p className={'mt-3 text-[15px] leading-relaxed text-muted sm:text-lg ' + (center ? 'mx-auto max-w-2xl' : 'max-w-2xl')}>{subtitle}</p>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  );
}

export function ViewAll({ to, children = 'View All' }) {
  return (
    <Link to={to} className="inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline">
      {children} <Icon name="arrowRight" className="size-4" />
    </Link>
  );
}

export function DemoBadge({ className = '' }) {
  return (
    <span title="Demo content - replace before launch" className={'inline-flex items-center rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-amber-800 uppercase ' + className}>
      Demo
    </span>
  );
}

/** Five stars with quarter-step partial fill (decorative; pair with a text rating). */
export function Stars({ value = 0, className = 'size-4' }) {
  return (
    <span className="flex items-center gap-px" aria-hidden="true">
      {[1, 2, 3, 4, 5].map((i) => {
        const fill = Math.round(Math.max(0, Math.min(1, (Number(value) || 0) - i + 1)) * 4) / 4;
        return (
          <span key={i} className="relative inline-flex">
            <Icon name="star" className={className + ' text-line'} />
            {fill > 0 && (
              <span className="absolute inset-y-0 left-0 overflow-hidden text-gold" style={{ width: `${fill * 100}%` }}>
                <Icon name="star" className={className + ' max-w-none drop-shadow-[0_1px_1px_rgb(242_178_0/0.35)]'} />
              </span>
            )}
          </span>
        );
      })}
    </span>
  );
}

export function Rating({ rating, small }) {
  if (!rating) {
    return <span className={'font-medium text-muted ' + (small ? 'text-xs' : 'text-sm')}>New course</span>;
  }
  const count = rating.count >= 1000 ? (rating.count / 1000).toFixed(1) + 'K' : rating.count;
  return (
    <span className={'flex items-center gap-1.5 ' + (small ? 'text-xs' : 'text-sm')}>
      <Stars value={rating.average} className={small ? 'size-3.5' : 'size-4'} />
      <span className="font-semibold text-ink">{rating.average.toFixed(1)}</span>
      <span className="text-muted">({count})</span>
      <span className="sr-only">{rating.average} out of 5 from {rating.count} reviews</span>
    </span>
  );
}

const BADGE_STYLE = {
  bestseller: 'bg-gradient-to-r from-red-600 to-coral',
  popular: 'bg-gradient-to-r from-emerald-600 to-teal',
  free: 'bg-gradient-to-r from-amber-500 to-orange-500',
  new: 'bg-gradient-to-r from-violet to-primary',
};
export const badgeClass = (badge) => BADGE_STYLE[String(badge || '').toLowerCase()] || 'bg-gradient-to-r from-primary to-violet';

/** Course image, or a branded category banner when no thumbnail is uploaded. imgClassName zooms on card hover. */
export function CourseThumb({ course, className = '', showBadge = true, imgClassName = '' }) {
  const color = course.category?.color || '#0B63F6';
  const badge = showBadge && course.badge;
  return (
    <div className={'relative aspect-[16/9] overflow-hidden ' + className}>
      {course.thumbnail_url ? (
        <img src={course.thumbnail_url} alt="" loading="lazy" width="640" height="360" className={'size-full object-cover ' + imgClassName} />
      ) : (
        <div
          className={'relative size-full ' + imgClassName}
          style={{ background: `linear-gradient(135deg, ${color} 0%, color-mix(in srgb, ${color} 45%, #08264D) 100%)` }}
        >
          <div className="dot-grid absolute inset-0 opacity-60" />
          <div className="absolute -top-10 -right-10 size-40 rounded-full bg-white/10" />
          <div className="absolute -bottom-16 left-1/3 size-40 rounded-full bg-white/5" />
          <Icon name={course.category?.icon} className="absolute right-5 bottom-4 size-20 text-white/25 sm:size-24" strokeWidth={1.4} />
          <div className="absolute bottom-4 left-4 max-w-[65%]">
            <p className="text-2xl leading-none font-extrabold tracking-tight text-white drop-shadow-sm sm:text-[28px]">{course.category?.name}</p>
            <p className="mt-1 line-clamp-1 text-xs font-medium text-white/80">{course.subtitle || course.title}</p>
          </div>
        </div>
      )}
      {badge && (
        <span className={'absolute top-3 left-3 rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wide text-white uppercase shadow-md ' + badgeClass(badge)}>
          {badge}
        </span>
      )}
    </div>
  );
}

/** Whole-number discount vs the original price (0 when none). */
export const discountPercent = (course) => (
  !course.is_free && course.original_price > course.price ? Math.round((1 - course.price / course.original_price) * 100) : 0
);

/** Price with strike-through original; showOff adds the "% OFF" pill (always on for size lg). */
export function Price({ course, size = 'md', showOff }) {
  const big = size === 'lg';
  if (course.is_free) {
    return <span className={'font-display font-extrabold text-emerald-600 ' + (big ? 'text-3xl' : 'text-lg')}>Free</span>;
  }
  const off = discountPercent(course);
  return (
    <span className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
      <span className={'font-display font-extrabold text-navy ' + (big ? 'text-3xl' : 'text-lg')}>{formatPrice(course.price)}</span>
      {course.original_price > course.price && (
        <>
          <s className={'text-muted ' + (big ? 'text-lg' : 'text-sm')}>{formatPrice(course.original_price)}</s>
          {(showOff ?? big) && off > 0 && <span className={'rounded-full bg-red-50 font-bold text-red-600 ring-1 ring-red-100 ' + (big ? 'px-2.5 py-0.5 text-sm' : 'px-2 py-px text-[11px]')}>{off}% OFF</span>}
        </>
      )}
    </span>
  );
}

const TYPE_LABEL = { free: 'Free', paid: 'Paid', recorded: 'Recorded', live: 'Live', hybrid: 'Live + Recorded', offline: 'Offline Classes' };
const LANG_LABEL = { hindi: 'Hindi', english: 'English', bilingual: 'Hindi & English' };
export const courseTypeLabel = (t) => TYPE_LABEL[t] || t;
/** "20 Nov 2026" for offer end dates (the API sends ISO date-times). */
export const formatOfferDate = (iso) => new Date(iso).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
export const languageLabel = (l) => LANG_LABEL[l] || l;

const LEVEL_LABEL = { beginner: 'Beginner', intermediate: 'Intermediate', advanced: 'Advanced', all: 'All levels' };
export const levelLabel = (l) => LEVEL_LABEL[l] || l;

function PreviewChip({ className = 'bg-white/95 py-1 shadow-sm' }) {
  return (
    <span className={'flex shrink-0 items-center gap-1 rounded-full px-2.5 text-[11px] font-bold text-navy ' + className}>
      <Icon name="playCircle" className="size-3.5 text-primary" />Free preview
    </span>
  );
}

/** Public URL of a course (absolute share_url from the API when present). */
const courseShareUrl = (course) => course.share_url || `/courses/${course.slug}`;

/** Share icon on a course card: native share sheet where available, otherwise a small network menu. */
function CardShare({ course }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  useEffect(() => {
    if (!open) return undefined;
    const onDown = (e) => { if (!ref.current?.contains(e.target)) setOpen(false); };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('pointerdown', onDown);
    document.addEventListener('keydown', onKey);
    return () => { document.removeEventListener('pointerdown', onDown); document.removeEventListener('keydown', onKey); };
  }, [open]);
  const click = async () => {
    if (typeof navigator !== 'undefined' && navigator.share && window.matchMedia?.('(pointer: coarse)').matches) {
      try { await navigator.share({ title: course.title, text: course.short_description || course.subtitle || '', url: withShareUtm(courseShareUrl(course), 'native') }); } catch { /* cancelled */ }
      return;
    }
    setOpen((o) => !o);
  };
  return (
    <div ref={ref} className="relative">
      <button type="button" onClick={click} aria-expanded={open} aria-label={`Share ${course.title}`} title="Share"
        className="grid size-9 place-items-center rounded-full bg-white/95 text-muted shadow transition-colors hover:text-primary">
        <Icon name="share" className="size-4" />
      </button>
      {open && (
        <div className="absolute top-full right-0 z-20 mt-2 w-max animate-scale-in rounded-2xl border border-line bg-white p-2.5 shadow-card-hover">
          <ShareButtons url={courseShareUrl(course)} title={course.title} text={course.short_description || course.subtitle || ''} label={null} size="sm" className="grid grid-cols-4 gap-1.5" />
        </div>
      )}
    </div>
  );
}

/**
 * Course card for grids and carousels. compact = carousel variant (no description, single CTA).
 * Uses: thumbnail_url, badge, category, preview_lessons, title, subtitle, short_description,
 * duration, lessons_count, level, language, rating, price/original_price/is_free, share_url.
 */
export function CourseCard({ course, compact = false }) {
  const url = `/courses/${course.slug}`;
  const color = course.category?.color || '#0B63F6';
  // Admission through the enquiry form (offline batches): "Enquire Now" opens the course page form instead of checkout.
  const enquiry = course.admission === 'enquiry';
  const meta = [
    course.course_type === 'offline' && ['presentation', 'Offline classes'],
    course.duration && ['clock', course.duration],
    course.lessons_count > 0 && ['playCircle', `${course.lessons_count} lessons`],
    !compact && course.level && ['barChart', levelLabel(course.level)],
    !compact && course.language && ['languages', languageLabel(course.language)],
  ].filter(Boolean);
  return (
    <article className="group card card-hover relative flex h-full flex-col">
      <div className="relative overflow-hidden rounded-t-2xl">
        <Link to={url} tabIndex={-1} aria-hidden="true" className="block">
          <CourseThumb course={course} showBadge={false} imgClassName="transition-transform duration-700 ease-[cubic-bezier(0.22,1,0.36,1)] group-hover:scale-[1.06]" />
          <span className="pointer-events-none absolute inset-0 bg-gradient-to-t from-navy-deep/55 via-navy-deep/5 to-transparent opacity-70 transition-opacity duration-300 group-hover:opacity-100" />
        </Link>
        {course.badge && (
          <span className={'pointer-events-none absolute top-3 left-3 rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wide text-white uppercase shadow-md ' + badgeClass(course.badge)}>{course.badge}</span>
        )}
        {/* On real thumbnails the category + preview chips sit on the image; the branded fallback already shows the category */}
        {course.thumbnail_url && (
          <div className="pointer-events-none absolute inset-x-3 bottom-3 flex items-end justify-between gap-2">
            {course.category?.name ? (
              <span className="truncate rounded-full px-2.5 py-1 text-[11px] font-bold text-white shadow-sm backdrop-blur" style={{ backgroundColor: `color-mix(in srgb, ${color} 88%, transparent)` }}>{course.category.name}</span>
            ) : <span />}
            {course.preview_lessons > 0 && <PreviewChip />}
          </div>
        )}
      </div>
      <div className="absolute top-3 right-3 z-10 flex items-center gap-2">
        <CardShare course={course} />
        <WishlistButton course={course} />
      </div>

      <div className="flex flex-1 flex-col p-4 sm:p-5">
        {(!course.thumbnail_url || course.is_demo) && (
          <div className="mb-2 flex flex-wrap items-center gap-2">
            {!course.thumbnail_url && course.category?.name && <CategoryPill category={course.category} />}
            {!course.thumbnail_url && course.preview_lessons > 0 && <PreviewChip className="bg-primary-soft py-0.5 shadow-none" />}
            {course.is_demo && <DemoBadge />}
          </div>
        )}
        <h3 className="line-clamp-2 text-[17px] leading-snug font-bold">
          <Link to={url} className="transition-colors hover:text-primary">{course.title}</Link>
        </h3>
        {course.subtitle && <p className="mt-1 line-clamp-1 text-sm font-medium text-muted">{course.subtitle}</p>}
        {!compact && course.short_description && <p className="mt-2 line-clamp-2 text-sm leading-relaxed text-muted">{course.short_description}</p>}

        {meta.length > 0 && (
          <ul className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs font-medium text-ink/75">
            {meta.map(([icon, text]) => (
              <li key={icon} className="flex items-center gap-1.5"><Icon name={icon} className="size-3.5 text-primary" />{text}</li>
            ))}
          </ul>
        )}

        <div className="mt-3"><Rating rating={course.rating} small /></div>

        <div className="mt-auto pt-4">
          <div className="flex items-end justify-between gap-2 border-t border-line pt-3">
            <div className="min-w-0">
              <Price course={course} showOff />
              {course.offer_ends_at && <p className="mt-0.5 text-[11px] font-semibold text-coral">Offer till {formatOfferDate(course.offer_ends_at)}</p>}
            </div>
            {compact && <Link to={url} className="btn-light btn-sm shrink-0">View <Icon name="arrowRight" className="size-3.5" /></Link>}
          </div>
          {!compact && (
            <div className="mt-3 grid grid-cols-2 gap-2">
              <Link to={url} className="btn-light btn-sm py-2.5 text-[13px]">View Details</Link>
              {enquiry
                ? <Link to={`${url}#enquire`} className="btn-primary btn-sm py-2.5 text-[13px]">Enquire Now</Link>
                : <Link to={`/checkout/${course.slug}`} className="btn-primary btn-sm py-2.5 text-[13px]">{course.is_free ? 'Enroll Free' : 'Enroll Now'}</Link>}
            </div>
          )}
        </div>
      </div>
    </article>
  );
}

/** Shimmering placeholder block. */
export function Skeleton({ className = '', rounded = 'rounded-lg' }) {
  return <div aria-hidden="true" className={`skeleton ${rounded} ${className}`} />;
}

export function CourseCardSkeleton({ compact = false }) {
  return (
    <div className="card overflow-hidden" aria-hidden="true">
      <Skeleton className="aspect-[16/9]" rounded="rounded-none" />
      <div className="space-y-3 p-4 sm:p-5">
        <Skeleton className="h-4 w-4/5" />
        <Skeleton className="h-3 w-1/2" />
        {!compact && <Skeleton className="h-3 w-full" />}
        <div className="flex gap-3"><Skeleton className="h-3 w-16" /><Skeleton className="h-3 w-20" /></div>
        <Skeleton className="h-3 w-24" />
        <div className="border-t border-line pt-3"><Skeleton className="h-5 w-28" /></div>
        {!compact && <div className="grid grid-cols-2 gap-2"><Skeleton className="h-9" rounded="rounded-lg" /><Skeleton className="h-9" rounded="rounded-lg" /></div>}
      </div>
    </div>
  );
}

export function Spinner({ label = 'Loading…', className = '' }) {
  return (
    <div role="status" className={'flex flex-col items-center justify-center gap-3 py-16 text-muted ' + className}>
      <span className="relative block size-10" aria-hidden="true">
        <span className="absolute inset-0 rounded-full border-[3px] border-primary/15" />
        <span className="absolute inset-0 animate-spin rounded-full border-[3px] border-transparent border-t-primary border-r-violet" />
      </span>
      <span className="text-sm font-medium">{label}</span>
    </div>
  );
}

export function ErrorState({ error, onRetry, title = 'We couldn’t load this content.' }) {
  return (
    <div role="alert" className="card mx-auto my-10 max-w-lg p-8 text-center">
      <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-red-50 text-danger ring-1 ring-red-100"><Icon name="alert" className="size-7" /></span>
      <p className="mt-4 font-display text-lg font-bold text-navy">{title}</p>
      {error?.message && <p className="mt-1 text-sm text-muted">{error.message}</p>}
      {onRetry && <button type="button" onClick={onRetry} className="btn-primary mt-6"><Icon name="refresh" className="size-4" />Try again</button>}
    </div>
  );
}

export function EmptyState({ title, text, action, icon = 'search', className = '' }) {
  return (
    <div className={'card relative overflow-hidden p-10 text-center ' + className}>
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 [mask-image:radial-gradient(circle_at_center,black,transparent_70%)]" />
      <span className="relative mx-auto grid size-16 place-items-center rounded-2xl bg-gradient-to-br from-primary-soft to-white text-primary shadow-card ring-1 ring-primary/10">
        <Icon name={icon} className="size-7" />
      </span>
      <p className="relative mt-4 font-display text-lg font-bold text-navy">{title}</p>
      {text && <p className="relative mx-auto mt-1 max-w-md text-sm text-muted">{text}</p>}
      {action && <div className="relative mt-6">{action}</div>}
    </div>
  );
}

export function Breadcrumbs({ items, className = '' }) {
  return (
    <nav aria-label="Breadcrumb" className="text-sm text-muted">
      <ol className={'flex flex-wrap items-center gap-1.5 ' + className}>
        {items.map((item, i) => (
          <li key={i} className="flex min-w-0 items-center gap-1.5">
            {i > 0 && <Icon name="chevronRight" className="size-3.5 shrink-0 text-muted/70" />}
            {item.to ? (
              <Link to={item.to} className="inline-flex items-center gap-1 font-medium transition-colors hover:text-primary">
                {i === 0 && item.to === '/' && <Icon name="home" className="size-3.5" />}{item.label}
              </Link>
            ) : <span aria-current="page" className="truncate font-semibold text-navy">{item.label}</span>}
          </li>
        ))}
      </ol>
    </nav>
  );
}

export function Pagination({ page, totalPages, onChange }) {
  if (totalPages <= 1) return null;
  const pages = [];
  for (let p = Math.max(1, page - 2); p <= Math.min(totalPages, page + 2); p++) pages.push(p);
  const btn = 'grid size-10 place-items-center rounded-lg border text-sm font-medium transition-colors';
  return (
    <nav aria-label="Pagination" className="mt-10 flex items-center justify-center gap-2">
      <button type="button" disabled={page <= 1} onClick={() => onChange(page - 1)} aria-label="Previous page" className={btn + ' border-line bg-white disabled:opacity-40'}>
        <Icon name="chevronLeft" className="size-4" />
      </button>
      {pages.map((p) => (
        <button
          key={p}
          type="button"
          onClick={() => onChange(p)}
          aria-current={p === page ? 'page' : undefined}
          className={btn + (p === page ? ' border-primary bg-primary text-white' : ' border-line bg-white hover:border-primary')}
        >
          {p}
        </button>
      ))}
      <button type="button" disabled={page >= totalPages} onClick={() => onChange(page + 1)} aria-label="Next page" className={btn + ' border-line bg-white disabled:opacity-40'}>
        <Icon name="chevronRight" className="size-4" />
      </button>
    </nav>
  );
}

// ------------------------------------------------------------------
// Forms
// ------------------------------------------------------------------

/** Labelled input with server-side error message support. */
export function Field({ id, label, error, hint, as = 'input', children, className = '', ...props }) {
  const Tag = as;
  const describedBy = [error && `${id}-err`, hint && `${id}-hint`].filter(Boolean).join(' ') || undefined;
  return (
    <div className={className}>
      {label && <label htmlFor={id} className="label">{label}</label>}
      {children || <Tag id={id} name={id} aria-invalid={!!error} aria-describedby={describedBy} className="input" {...props} />}
      {hint && !error && <p id={`${id}-hint`} className="mt-1 text-xs text-muted">{hint}</p>}
      {error && <p id={`${id}-err`} className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}

export function Alert({ type = 'error', children, className = '' }) {
  if (!children) return null;
  const styles = {
    error: 'bg-red-50 text-red-700 border-red-200',
    success: 'bg-green-50 text-green-800 border-green-200',
    info: 'bg-primary-soft text-navy border-primary/20',
    warning: 'bg-amber-50 text-amber-900 border-amber-200',
  };
  return (
    <div role={type === 'error' ? 'alert' : 'status'} className={`rounded-lg border p-3 text-sm ${styles[type]} ${className}`}>
      {children}
    </div>
  );
}

/** Minimal form state + submit handling with API field errors. */
export function useForm(initial) {
  const [values, setValues] = useState(initial);
  const [errors, setErrors] = useState({});
  const [message, setMessage] = useState(null);
  const [busy, setBusy] = useState(false);
  const bind = (name) => ({
    id: name,
    value: values[name] ?? '',
    error: errors[name],
    onChange: (e) => setValues((v) => ({ ...v, [name]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })),
  });
  const submit = (fn) => async (e) => {
    e?.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);
    try {
      await fn(values);
    } catch (err) {
      setErrors(err.fields || {});
      setMessage({ type: 'error', text: err.message, code: err.code });
    } finally {
      setBusy(false);
    }
  };
  return { values, setValues, errors, message, setMessage, busy, bind, submit };
}

/**
 * Page banner: soft mesh gradient, breadcrumbs, optional eyebrow, gradient highlight,
 * subtitle and `actions` (buttons on the right). children render below (search forms, chips…).
 */
export function PageHeader({ title, highlight, subtitle, crumbs, children, eyebrow, actions, align = 'left', className = '' }) {
  const center = align === 'center';
  return (
    <section className={'relative isolate overflow-hidden border-b border-line/60 bg-mesh ' + className}>
      <div aria-hidden="true" className="dot-grid-dark absolute inset-0 -z-10 [mask-image:linear-gradient(to_bottom,black,transparent_85%)]" />
      <div aria-hidden="true" className="absolute -top-28 -right-24 -z-10 size-80 rounded-full bg-primary/15 blur-3xl" />
      <div aria-hidden="true" className="absolute -bottom-32 -left-20 -z-10 size-72 rounded-full bg-gold/20 blur-3xl" />
      <div className={'container-x py-8 sm:py-12 lg:py-14 ' + (center ? 'text-center' : '')}>
        {crumbs && <Breadcrumbs items={crumbs} className={center ? 'justify-center' : ''} />}
        <div className={'mt-4 flex flex-wrap gap-6 ' + (center ? 'flex-col items-center' : 'items-end justify-between')}>
          <div className="min-w-0 max-w-3xl">
            {eyebrow && <span className="eyebrow mb-3">{eyebrow}</span>}
            <h1 className="heading-lg animate-fade-up">
              {title}{title && highlight ? ' ' : ''}<Highlight>{highlight}</Highlight>
            </h1>
            {subtitle && <p className={'mt-3 max-w-2xl animate-fade-up text-base leading-relaxed text-muted [animation-delay:80ms] sm:text-lg ' + (center ? 'mx-auto' : '')}>{subtitle}</p>}
          </div>
          {actions && <div className="flex shrink-0 flex-wrap gap-3">{actions}</div>}
        </div>
        {children}
      </div>
    </section>
  );
}

export function formatBytes(bytes) {
  if (!bytes) return '';
  const units = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  let n = bytes;
  while (n >= 1024 && i < units.length - 1) {
    n /= 1024;
    i++;
  }
  return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
}

export function CategoryPill({ category }) {
  if (!category) return null;
  const color = category.color || '#0B63F6';
  return (
    <span className="rounded px-2 py-0.5 text-[11px] font-semibold" style={{ backgroundColor: color + '1A', color }}>
      {category.name}
    </span>
  );
}

// ------------------------------------------------------------------
// Voice input (Web Speech API - Chrome, Edge, Safari; hidden where unsupported)
// ------------------------------------------------------------------
const SpeechRecognitionApi = typeof window !== 'undefined' ? window.SpeechRecognition || window.webkitSpeechRecognition : null;

export function useVoiceInput(onText) {
  const [listening, setListening] = useState(false);
  const [error, setError] = useState(null);
  const recRef = useRef(null);
  const cbRef = useRef(onText);
  cbRef.current = onText;

  const start = (lang = 'en-IN') => {
    if (!SpeechRecognitionApi) return;
    recRef.current?.abort();
    const rec = new SpeechRecognitionApi();
    rec.lang = lang;
    rec.interimResults = true;
    rec.maxAlternatives = 1;
    rec.onresult = (e) => {
      const text = Array.from(e.results).map((r) => r[0].transcript).join(' ').trim();
      cbRef.current(text, e.results[e.results.length - 1].isFinal);
    };
    rec.onerror = (e) => setError(e.error === 'not-allowed' ? 'Microphone permission was denied.' : e.error === 'no-speech' ? 'No speech detected. Try again.' : null);
    rec.onend = () => setListening(false);
    setError(null);
    setListening(true);
    rec.start();
    recRef.current = rec;
  };
  const stop = () => recRef.current?.stop();
  useEffect(() => () => recRef.current?.abort(), []);
  return { supported: !!SpeechRecognitionApi, listening, error, start, stop };
}

/** Mic button with an English/Hindi toggle. Calls onText(text, isFinal). */
export function MicButton({ onText, className = '', compact = false }) {
  const [lang, setLang] = useState(() => {
    try { return localStorage.getItem('lx-voice-lang') || 'en-IN'; } catch { return 'en-IN'; }
  });
  const voice = useVoiceInput(onText);
  if (!voice.supported) return null;
  const switchLang = () => {
    const next = lang === 'en-IN' ? 'hi-IN' : 'en-IN';
    setLang(next);
    try { localStorage.setItem('lx-voice-lang', next); } catch { /* ignore */ }
  };
  return (
    <span className={'relative flex shrink-0 items-center gap-1 ' + className}>
      {!compact && (
        <button type="button" onClick={switchLang} title="Voice language" className="rounded-md px-1.5 py-1 text-[11px] font-bold text-muted hover:bg-surface hover:text-primary">
          {lang === 'en-IN' ? 'EN' : 'हिं'}
        </button>
      )}
      <button
        type="button"
        onClick={() => (voice.listening ? voice.stop() : voice.start(lang))}
        aria-label={voice.listening ? 'Stop voice input' : `Speak to search (${lang === 'en-IN' ? 'English' : 'Hindi'})`}
        aria-pressed={voice.listening}
        className={'grid size-9 place-items-center rounded-full transition-colors ' + (voice.listening ? 'animate-pulse bg-red-600 text-white' : 'text-primary hover:bg-primary-soft')}
      >
        <Icon name="mic" className="size-[18px]" />
      </button>
      {voice.error && <span role="alert" className="absolute top-full right-0 z-10 mt-1 w-48 rounded bg-navy px-2 py-1 text-xs text-white">{voice.error}</span>}
    </span>
  );
}

// ------------------------------------------------------------------
// Sharing
// ------------------------------------------------------------------

/** Absolute URL for a site path ("/courses/x") or the URL itself when already absolute. */
export function absoluteUrl(url) {
  if (!url) return typeof window !== 'undefined' ? window.location.href : '';
  if (/^https?:\/\//i.test(url)) return url;
  return window.location.origin + APP_BASE.replace(/\/$/, '') + (url.startsWith('/') ? url : '/' + url);
}

/** Adds utm_source=share&utm_medium=<network> so shared visits show up in analytics. */
export function withShareUtm(url, medium) {
  const abs = absoluteUrl(url);
  try {
    const u = new URL(abs);
    u.searchParams.set('utm_source', 'share');
    u.searchParams.set('utm_medium', medium);
    return u.toString();
  } catch {
    return abs;
  }
}

/** Current page URL without tracking parameters (default share target). */
function currentUrl() {
  if (typeof window === 'undefined') return '';
  try {
    const u = new URL(window.location.href);
    [...u.searchParams.keys()].filter((k) => k.startsWith('utm_')).forEach((k) => u.searchParams.delete(k));
    u.hash = '';
    return u.toString();
  } catch {
    return window.location.href;
  }
}

const enc = encodeURIComponent;
const SHARE_NETWORKS = [
  ['whatsapp', 'WhatsApp', 'whatsapp', '#25D366', (u, t) => `https://wa.me/?text=${enc(t ? `${t} ${u}` : u)}`],
  ['facebook', 'Facebook', 'facebook', '#1877F2', (u) => `https://www.facebook.com/sharer/sharer.php?u=${enc(u)}`],
  ['linkedin', 'LinkedIn', 'linkedin', '#0A66C2', (u) => `https://www.linkedin.com/sharing/share-offsite/?url=${enc(u)}`],
  ['x', 'X', 'xBrand', '#000000', (u, t) => `https://twitter.com/intent/tweet?url=${enc(u)}${t ? `&text=${enc(t)}` : ''}`],
  ['telegram', 'Telegram', 'telegram', '#26A5E4', (u, t) => `https://t.me/share/url?url=${enc(u)}${t ? `&text=${enc(t)}` : ''}`],
  ['email', 'Email', 'mail', '#EA4335', (u, t, body) => `mailto:?subject=${enc(t || 'Laxyam Academy')}&body=${enc(`${body ? body + '\n\n' : ''}${u}`)}`],
];

/**
 * Brand-coloured share buttons. url: absolute or site path (defaults to this page);
 * title/text: message; label: leading text (null hides it); size: 'sm' | 'md'.
 * Each network link carries utm_source=share&utm_medium=<network>.
 */
export function ShareButtons({ url, title = '', text = '', label = 'Share:', size = 'md', className = 'flex flex-wrap items-center gap-2' }) {
  const [copied, setCopied] = useState(false);
  const timer = useRef(null);
  useEffect(() => () => clearTimeout(timer.current), []);
  const target = url || currentUrl();
  const canNative = typeof navigator !== 'undefined' && !!navigator.share;
  const box = size === 'sm' ? 'size-9' : 'size-10';
  const glyph = size === 'sm' ? 'size-4' : 'size-[18px]';
  const native = async () => {
    try { await navigator.share({ title, text, url: withShareUtm(target, 'native') }); } catch { /* cancelled */ }
  };
  const copy = async () => {
    const link = withShareUtm(target, 'copy_link');
    try {
      await navigator.clipboard.writeText(link);
    } catch {
      // Older browsers / insecure origins: fall back to a temporary textarea
      const ta = document.createElement('textarea');
      ta.value = link;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch { /* ignore */ }
      ta.remove();
    }
    setCopied(true);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setCopied(false), 2000);
  };
  const btn = `relative grid ${box} place-items-center rounded-full text-white shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:shadow-md`;
  return (
    <div className={className}>
      {label && <span className="mr-1 text-sm font-semibold text-muted">{label}</span>}
      {SHARE_NETWORKS.map(([key, name, icon, color, href]) => (
        <a key={key} href={href(withShareUtm(target, key), title, text)} target={key === 'email' ? undefined : '_blank'} rel="noopener noreferrer"
          aria-label={`Share on ${name}`} title={name} className={btn} style={{ backgroundColor: color }}>
          <Icon name={icon} className={glyph} />
        </a>
      ))}
      <button type="button" onClick={copy} aria-label={copied ? 'Link copied' : 'Copy link'} title="Copy link"
        className={btn + ' ' + (copied ? 'bg-emerald-600' : 'bg-navy')}>
        <Icon name={copied ? 'check' : 'link'} className={glyph} />
      </button>
      {canNative && (
        <button type="button" onClick={native} aria-label="More sharing options" title="More options" className={btn + ' bg-gradient-to-br from-primary to-violet'}>
          <Icon name="share" className={glyph} />
        </button>
      )}
      <span role="status" aria-live="polite" className={copied ? 'col-span-full text-xs font-semibold text-emerald-700' : 'sr-only'}>{copied ? 'Copied!' : ''}</span>
    </div>
  );
}

/** Heart toggle for the wishlist (students only; guests are sent to login). */
export function WishlistButton({ course, className = '' }) {
  const { user, summary, toggleWishlist } = useAuth();
  const location = useLocation();
  const saved = summary.wishlist.includes(course.id);
  const label = saved ? 'Remove from wishlist' : 'Save to wishlist';
  if (!user) {
    return (
      <Link to={`/login?next=${encodeURIComponent(location.pathname)}`} aria-label={label} title={label} className={'grid size-9 place-items-center rounded-full bg-white/95 text-muted shadow hover:text-red-500 ' + className}>
        <Icon name="heart" className="size-[18px]" />
      </Link>
    );
  }
  if (user.type !== 'student') return null;
  return (
    <button type="button" onClick={(e) => { e.preventDefault(); toggleWishlist(course, !saved); }} aria-pressed={saved} aria-label={label} title={label}
      className={'grid size-9 place-items-center rounded-full bg-white/95 shadow transition-colors ' + (saved ? 'text-red-500' : 'text-muted hover:text-red-500') + ' ' + className}>
      <Icon name={saved ? 'heartFilled' : 'heart'} className="size-[18px]" />
    </button>
  );
}

/** Two-column card used by login, register and password pages. */
export function AuthCard({ title, subtitle, children, footer }) {
  return (
    <section className="bg-gradient-to-br from-[#EAF2FF] via-[#F5F9FF] to-white">
      <div className="container-x grid items-center gap-10 py-10 sm:py-14 lg:grid-cols-2">
        <div className="hidden lg:block">
          <h2 className="text-4xl leading-tight font-bold">Learn • Prepare • <span className="text-primary">Achieve</span></h2>
          <p className="mt-4 max-w-md text-muted">One account for your courses, live classes, mock tests and study material.</p>
          <ul className="mt-8 space-y-4">
            {[['video', 'Live & recorded classes'], ['clipboard', 'Timed mock tests with instant analysis'], ['book', 'Notes, PDFs & free resources']].map(([icon, text]) => (
              <li key={text} className="flex items-center gap-3 font-medium text-navy">
                <span className="grid size-10 place-items-center rounded-lg bg-white text-primary shadow-card"><Icon name={icon} /></span>
                {text}
              </li>
            ))}
          </ul>
        </div>
        <div className="card mx-auto w-full max-w-md p-6 sm:p-8">
          <h1 className="text-2xl font-bold">{title}</h1>
          {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
          <div className="mt-6">{children}</div>
          {footer && <div className="mt-6 border-t border-line pt-5 text-center text-sm text-muted">{footer}</div>}
        </div>
      </div>
    </section>
  );
}

export function DevLink({ data }) {
  if (!data?.dev_link) return null;
  return (
    <Alert type="warning" className="mt-4 break-all">
      <strong>Development only:</strong> {data.dev_note} <a href={data.dev_link} className="font-semibold underline">Open link</a>
    </Alert>
  );
}

// ------------------------------------------------------------------
// Lead / enquiry form (popups, course pages, CTAs) → POST enquiries.php
// ------------------------------------------------------------------
export const DEFAULT_CONSENT_TEXT = 'I agree to be contacted by Laxyam Academy by phone call, WhatsApp, SMS and email about courses, admissions and offers. I can opt out at any time.';
const MOBILE_RE = /^(?:\+?91|0)?[6-9]\d{9}$/;
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
// Server field names → form field names
const FIELD_ALIAS = { course_slug: 'interest', course: 'interest', subject: 'interest' };
/** Education choices of the admission form (api/enquiries.php ENQUIRY_QUALIFICATIONS). */
const QUALIFICATIONS = ['10th pass', '12th pass', 'Pursuing graduation', 'Graduate', 'Post graduate', 'Other'];

/**
 * <EnquiryForm source="popup" | "course_page" | "home_cta" … course="<slug>" compact onDone />
 * Name, mobile, email, course interest (hidden when `course` is given), optional message,
 * consent checkbox (required for popup / course_page) and a honeypot. Validates inline,
 * shows the server's field errors and a success state. onDone(response) runs after success.
 * Optional: popupId, consentText, successMessage, submitLabel, idPrefix, dark (light text on dark bg).
 */
/**
 * Lead / callback form. `admission` adds the admission details (WhatsApp number, qualification and a
 * message) and shows the chosen course; `course` is a slug or a course object ({slug, title}).
 */
export function EnquiryForm({ source = 'contact_form', course = null, compact = false, admission = false, onDone, popupId, consentText, successMessage, submitLabel, idPrefix = 'enq', dark = false }) {
  const { data } = useSite();
  const location = useLocation();
  const courseSlug = typeof course === 'string' ? course : course?.slug || null;
  const courseTitle = typeof course === 'object' ? course?.title : null;
  const consentRequired = source === 'popup' || source === 'course_page';
  const [v, setV] = useState({ name: '', phone: '', whatsapp: '', sameWhatsapp: true, email: '', qualification: '', interest: '', message: '', consent: false, website: '' });
  const [errors, setErrors] = useState({});
  const [state, setState] = useState({ status: 'idle', message: '' });

  // Course interest: every category (any course) plus its top courses from the mega menu data
  const groups = useMemo(() => {
    const mega = data?.mega || [];
    if (mega.length) return mega.map((c) => ({ slug: c.slug, name: c.name, courses: c.courses || [] }));
    return (data?.categories || []).map((c) => ({ slug: c.slug, name: c.name, courses: [] }));
  }, [data]);

  const id = (f) => `${idPrefix}-${f}`;
  const set = (f) => (e) => {
    const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
    setV((s) => ({ ...s, [f]: value }));
    if (errors[f]) setErrors((x) => ({ ...x, [f]: undefined }));
  };

  const check = () => {
    const e = {};
    if (v.name.trim().length < 2) e.name = 'Please enter your full name.';
    const mobile = v.phone.replace(/[\s()-]/g, '');
    if (!mobile) e.phone = 'Please enter your mobile number.';
    else if (!MOBILE_RE.test(mobile)) e.phone = 'Enter a valid 10-digit mobile number.';
    if (admission && !v.sameWhatsapp) {
      const wa = v.whatsapp.replace(/[\s()-]/g, '');
      if (!wa) e.whatsapp = 'Please enter your WhatsApp number (or tick “Same as mobile”).';
      else if (!MOBILE_RE.test(wa)) e.whatsapp = 'Enter a valid 10-digit WhatsApp number.';
    }
    if (v.email.trim() && !EMAIL_RE.test(v.email.trim())) e.email = 'Enter a valid email address.';
    if (admission && !v.qualification) e.qualification = 'Please choose your education qualification.';
    if (consentRequired && !v.consent) e.consent = 'Please tick the box so we can contact you.';
    return e;
  };

  const submit = async (e) => {
    e.preventDefault();
    const found = check();
    setErrors(found);
    if (Object.keys(found).length) {
      document.getElementById(id(Object.keys(found)[0]))?.focus();
      return;
    }
    setState({ status: 'busy', message: '' });
    const national = (n) => n.replace(/[\s()-]/g, '').replace(/^(?:\+?91|0)(?=[6-9]\d{9}$)/, '');
    const mobile = national(v.phone);
    const whatsapp = admission ? (v.sameWhatsapp ? mobile : national(v.whatsapp)) : '';
    const [kind, slug] = v.interest.split(':');
    const group = groups.find((g) => (kind === 'cat' ? g.slug === slug : g.courses.some((c) => c.slug === slug)));
    const picked = kind === 'course' ? group?.courses.find((c) => c.slug === slug)?.title : group ? `${group.name} exams` : '';
    const chosenCourse = courseSlug || (kind === 'course' ? slug : null);
    try {
      const res = await api.post('enquiries.php', {
        name: v.name.trim(),
        phone: '+91' + mobile,
        whatsapp: whatsapp ? '+91' + whatsapp : undefined,
        qualification: admission ? v.qualification : undefined,
        email: v.email.trim() || undefined,
        subject: picked ? `Interested in ${picked}`.slice(0, 160) : undefined,
        message: v.message.trim() || undefined,
        course: chosenCourse || undefined,
        course_slug: chosenCourse || undefined,
        source,
        consent: !!v.consent,
        popup_id: popupId || undefined,
        page_url: (location.pathname + location.search).slice(0, 250),
        website: v.website,
      });
      setState({ status: 'ok', message: successMessage || res?.message || 'Thank you! Our admission counsellor will call you shortly.' });
      onDone?.(res);
    } catch (err) {
      const fields = {};
      Object.entries(err.fields || {}).forEach(([k, msg]) => { fields[FIELD_ALIAS[k] || k] = msg; });
      setErrors(fields);
      setState({ status: 'error', message: err.message });
      const first = Object.keys(fields)[0];
      if (first) document.getElementById(id(first))?.focus();
    }
  };

  const site = data?.site;
  if (state.status === 'ok') {
    return (
      <div role="status" className="py-4 text-center">
        <span className="mx-auto grid size-16 animate-pop place-items-center rounded-full bg-emerald-50 text-emerald-600 ring-8 ring-emerald-50/60">
          <Icon name="checkCircle" className="size-9" />
        </span>
        <p className={'mt-4 font-display text-lg font-bold ' + (dark ? 'text-white' : 'text-navy')}>Request received</p>
        <p className={'mx-auto mt-1 max-w-sm text-sm ' + (dark ? 'text-white/80' : 'text-muted')}>{state.message}</p>
        {site?.whatsapp && (
          <a href={`https://wa.me/${site.whatsapp}?text=${encodeURIComponent(`Hello Laxyam Academy, I just sent an enquiry. My name is ${v.name.trim()}.`)}`} target="_blank" rel="noopener noreferrer" className="btn-whatsapp mt-5">
            <Icon name="whatsapp" className="size-4" />Chat on WhatsApp now
          </a>
        )}
      </div>
    );
  }

  const label = 'mb-1 block text-[13px] font-semibold ' + (dark ? 'text-white' : 'text-ink');
  const err = (f) => errors[f] && <p id={id(f) + '-err'} className={'mt-1 text-xs font-medium ' + (dark ? 'text-red-200' : 'text-danger')}>{errors[f]}</p>;
  const aria = (f) => ({ id: id(f), name: f, 'aria-invalid': !!errors[f], 'aria-describedby': errors[f] ? id(f) + '-err' : undefined });
  const busy = state.status === 'busy';

  return (
    <form onSubmit={submit} noValidate className="space-y-3.5">
      <div className={compact ? 'space-y-3.5' : 'grid gap-3.5 sm:grid-cols-2'}>
        <div>
          <label htmlFor={id('name')} className={label}>Full name <span className="text-danger">*</span></label>
          <input {...aria('name')} type="text" autoComplete="name" maxLength={120} value={v.name} onChange={set('name')} placeholder="Your name" className="input" />
          {err('name')}
        </div>
        <div>
          <label htmlFor={id('phone')} className={label}>Mobile number <span className="text-danger">*</span></label>
          <div className="flex">
            <span className="flex items-center rounded-l-xl border border-r-0 border-line bg-surface px-3 text-sm font-semibold text-muted">+91</span>
            <input {...aria('phone')} type="tel" inputMode="numeric" autoComplete="tel-national" maxLength={16} value={v.phone} onChange={set('phone')} placeholder="10-digit mobile" className="input min-w-0 rounded-l-none!" />
          </div>
          {err('phone')}
        </div>
      </div>
      {admission && (
        <div>
          <label htmlFor={id('sameWhatsapp')} className={'flex cursor-pointer items-center gap-2 text-[13px] font-semibold ' + (dark ? 'text-white' : 'text-ink')}>
            <input {...aria('sameWhatsapp')} type="checkbox" checked={v.sameWhatsapp} onChange={set('sameWhatsapp')} className="size-4 shrink-0 rounded border-line accent-primary" />
            <Icon name="whatsapp" className="size-4 text-[#128C7E]" />WhatsApp number is the same as mobile
          </label>
          {!v.sameWhatsapp && (
            <div className="mt-2">
              <label htmlFor={id('whatsapp')} className={label}>WhatsApp number <span className="text-danger">*</span></label>
              <div className="flex">
                <span className="flex items-center rounded-l-xl border border-r-0 border-line bg-surface px-3 text-sm font-semibold text-muted">+91</span>
                <input {...aria('whatsapp')} type="tel" inputMode="numeric" autoComplete="off" maxLength={16} value={v.whatsapp} onChange={set('whatsapp')} placeholder="10-digit WhatsApp number" className="input min-w-0 rounded-l-none!" />
              </div>
              {err('whatsapp')}
            </div>
          )}
        </div>
      )}
      <div className={admission && !compact ? 'grid gap-3.5 sm:grid-cols-2' : 'space-y-3.5'}>
        <div>
          <label htmlFor={id('email')} className={label}>Email <span className={'font-normal ' + (dark ? 'text-white/60' : 'text-muted')}>(optional)</span></label>
          <input {...aria('email')} type="email" inputMode="email" autoComplete="email" maxLength={160} value={v.email} onChange={set('email')} placeholder="you@example.com" className="input" />
          {err('email')}
        </div>
        {admission && (
          <div>
            <label htmlFor={id('qualification')} className={label}>Education qualification <span className="text-danger">*</span></label>
            <select {...aria('qualification')} value={v.qualification} onChange={set('qualification')} className="input">
              <option value="">Select your qualification</option>
              {QUALIFICATIONS.map((q) => <option key={q} value={q}>{q}</option>)}
            </select>
            {err('qualification')}
          </div>
        )}
      </div>
      {admission && courseSlug && courseTitle && (
        <p className={'flex items-center gap-2 rounded-xl px-3 py-2 text-[13px] ' + (dark ? 'bg-white/10 text-white' : 'bg-primary-soft/60 text-navy')}>
          <Icon name="book" className="size-4 shrink-0 text-primary" /><span className="min-w-0">Interested course: <strong>{courseTitle}</strong></span>
        </p>
      )}
      {!courseSlug && groups.length > 0 && (
        <div>
          <label htmlFor={id('interest')} className={label}>Course / exam you are interested in</label>
          <select {...aria('interest')} value={v.interest} onChange={set('interest')} className="input">
            <option value="">Select a course or exam</option>
            {groups.map((g) => (
              <optgroup key={g.slug} label={g.name}>
                <option value={`cat:${g.slug}`}>{g.name} - any course</option>
                {g.courses.map((c) => <option key={c.slug} value={`course:${c.slug}`}>{c.title}</option>)}
              </optgroup>
            ))}
          </select>
          {err('interest')}
        </div>
      )}
      {(!compact || admission) && (
        <div>
          <label htmlFor={id('message')} className={label}>Message <span className={'font-normal ' + (dark ? 'text-white/60' : 'text-muted')}>(optional)</span></label>
          <textarea {...aria('message')} rows={3} maxLength={3000} value={v.message} onChange={set('message')} placeholder="Tell us about your target exam, preferred batch or any question." className="input resize-y" />
          {err('message')}
        </div>
      )}
      {/* Honeypot: hidden from people, bots fill it in */}
      <input type="text" name="website" value={v.website} onChange={set('website')} tabIndex={-1} autoComplete="off" aria-hidden="true" className="hidden" />
      <div>
        <label htmlFor={id('consent')} className={'flex cursor-pointer items-start gap-2.5 text-xs leading-relaxed ' + (dark ? 'text-white/80' : 'text-muted')}>
          <input {...aria('consent')} type="checkbox" checked={v.consent} onChange={set('consent')} className="mt-0.5 size-4 shrink-0 rounded border-line accent-primary" />
          <span>{consentText || DEFAULT_CONSENT_TEXT} <Link to="/privacy-policy" className="font-semibold text-primary underline-offset-2 hover:underline">Privacy Policy</Link></span>
        </label>
        {err('consent')}
      </div>
      {state.status === 'error' && !Object.keys(errors).length && <Alert type="error">{state.message}</Alert>}
      <button type="submit" disabled={busy} className="btn-primary btn-lg w-full">
        {busy ? <><span className="size-4 animate-spin rounded-full border-2 border-white border-t-transparent" aria-hidden="true" />Sending…</> : <><Icon name="phoneCall" className="size-[18px]" />{submitLabel || 'Request a Free Callback'}</>}
      </button>
    </form>
  );
}
