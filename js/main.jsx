import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import '../css/style.css';
import { APP_BASE } from './api.js';
import App from './App.jsx';
import { installClickTracking } from './track.js';

// GA4 / GTM events for call, WhatsApp and email links and [data-track] buttons
installClickTracking();

// Web app manifest + offline-capable service worker (production builds only).
const manifest = document.createElement('link');
manifest.rel = 'manifest';
manifest.href = APP_BASE + 'assets/manifest.webmanifest';
document.head.appendChild(manifest);

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register(APP_BASE + 'sw.js', { scope: APP_BASE }).catch(() => {});
  });
}

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <App />
  </StrictMode>
);
