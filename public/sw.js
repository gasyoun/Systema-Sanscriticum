/* H1488 — cabinet PWA shell service worker
 * Network-first for navigations; offline.html fallback when the network fails.
 * Precaches only the static shell — never HTML authenticated pages.
 */
const CACHE = 'ors-cabinet-shell-v1';
const SHELL = [
  '/offline.html',
  '/manifest.webmanifest',
  '/favicon.ico',
  '/images/logo.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') {
    return;
  }

  // Navigations: network first, offline shell on failure.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/offline.html'))
    );
    return;
  }

  // Shell assets: cache first, then network.
  const url = new URL(req.url);
  if (SHELL.includes(url.pathname)) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy));
        return res;
      }))
    );
  }
});
