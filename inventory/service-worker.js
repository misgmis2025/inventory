'use strict';
const CACHE_NAME = 'inv-pwa-v3';
const STATIC_ASSETS = [
  'manifest.json',
  'css/style.css',
  'images/logo-removebg.png'
];
self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    for (const url of STATIC_ASSETS) {
      try {
        const resp = await fetch(url, { cache: 'no-cache' });
        if (resp && resp.ok) {
          await cache.put(url, resp.clone());
        }
      } catch (_) { /* skip missing */ }
    }
    await self.skipWaiting();
  })());
});
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))).then(() => self.clients.claim())
  );
});
function isStatic(req) {
  try {
    const url = new URL(req.url);
    return (/\.(css|js|png|jpg|jpeg|gif|svg|webp|ico)$/i).test(url.pathname);
  } catch (_) {
    return false;
  }
}
self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  if (isStatic(req)) {
    event.respondWith(
      caches.match(req).then((res) => res || fetch(req).then((resp) => {
        const clone = resp.clone();
        caches.open(CACHE_NAME).then((c) => c.put(req, clone));
        return resp;
      }).catch(() => caches.match('images/logo-removebg.png')))
    );
    return;
  }
  event.respondWith(
    fetch(req).catch(() => caches.match(req))
  );
});
