/* Laxyam Academy service worker.
 * - Hashed build assets (dist/static) and /assets: cache-first (they never change once built).
 * - Page navigations: network-first, falling back to the cached app shell when offline.
 * - API calls, uploads and anything else: never cached (always fresh, always authorised).
 */
const VERSION = 'lx-v2'; // bump on every deploy that changes files under /assets (old caches are deleted on activate)
const SHELL = VERSION + '-shell';
const STATIC = VERSION + '-static';
const scopePath = new URL(self.registration.scope).pathname; // e.g. "/laxyam/" or "/"

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(SHELL).then((c) => c.add(scopePath)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  const path = url.pathname;

  if (path.startsWith(scopePath + 'api/') || path.startsWith(scopePath + 'uploads/') || path.startsWith(scopePath + 'admin/')) return;

  if (path.startsWith(scopePath + 'dist/static/') || path.startsWith(scopePath + 'assets/')) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(STATIC).then((c) => c.put(req, copy));
        }
        return res;
      }))
    );
    return;
  }

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(SHELL).then((c) => c.put(scopePath, copy));
          return res;
        })
        .catch(() => caches.match(scopePath))
    );
  }
});
