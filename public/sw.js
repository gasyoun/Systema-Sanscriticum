/* H1488 — cabinet PWA shell service worker
 * Network-first for navigations; offline.html fallback when the network fails.
 * Precaches only the static shell — never HTML authenticated pages.
 *
 * H2634 / issue #1630 — CACHE OWNERSHIP.
 * This worker owns exactly the caches whose name starts with OWNED_PREFIX, and on
 * activation it reaps only the obsolete versions of those. Every other cache on the
 * origin belongs to someone else — notably the offline cabinet's encrypted chunk store
 * (`systema-offline-spike-encrypted-v1`, resources/js/offline/spike.ts) — and is never
 * touched. The previous deny-list (`keys.filter(k => k !== CACHE)`) deleted every
 * sibling cache, so any byte change to this file (install → skipWaiting, activate →
 * clients.claim, i.e. immediately on an ordinary deploy) silently destroyed downloaded
 * encrypted lesson content while IndexedDB still reported it as downloaded.
 *
 * Adding a cache here: bump OWNED_VERSION so the old one is reaped, or add the new name
 * to KEEP if this worker is meant to own more than one live cache. Do NOT move the
 * offline content cache into this namespace — that re-creates the same ownership
 * confusion one release later.
 */
const OWNED_PREFIX = 'ors-cabinet-shell-';
const OWNED_VERSION = 'v1';
const CACHE = OWNED_PREFIX + OWNED_VERSION;

/** Versioned allow-list: caches this worker owns AND still wants after activation. */
const KEEP = [CACHE];

const SHELL = [
  '/offline.html',
  '/manifest.webmanifest',
  '/favicon.ico',
  '/images/logo.png',
];

/** True only for caches this worker is responsible for. */
function isOwned(key) {
  return key.indexOf(OWNED_PREFIX) === 0;
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((k) => isOwned(k) && KEEP.indexOf(k) === -1)
          .map((k) => caches.delete(k))
      )
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
