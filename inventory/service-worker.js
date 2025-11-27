'use strict';
const CACHE_NAME = 'inv-pwa-v8';
const STATIC_ASSETS = [
  'manifest.json',
  'css/style.css',
  'images/logo-removebg.png',
  'images/ECA.png'
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
  // Always fetch latest for the logo to avoid stale cache
  try {
    const url = new URL(req.url);
    if (false && url.pathname.endsWith('/images/logo-removebg.png')) {
      event.respondWith(
        fetch(req, { cache: 'reload' })
          .then((resp) => resp)
          .catch(() => caches.match('images/logo-removebg.png', { ignoreSearch: true }))
      );
      return;
    }
  } catch (_) {}
  if (isStatic(req)) {
    // For CSS/JS, honor query-string versions (e.g., ?v=hash) with network-first
    try {
      const u = new URL(req.url);
      const isCssJs = /\.(css|js)$/i.test(u.pathname);
      if (isCssJs) {
        event.respondWith((async () => {
          const cache = await caches.open(CACHE_NAME);
          try {
            const resp = await fetch(req, { cache: 'no-store' });
            try { await cache.put(req, resp.clone()); } catch(_) {}
            return resp;
          } catch(_) {
            const exact = await caches.match(req);
            if (exact) return exact;
            // As a last resort (offline), try a pathname match
            try { const fb = await caches.match(u.pathname, { ignoreSearch: true }); if (fb) return fb; } catch(_) {}
            // Final fallback
            return fetch(req);
          }
        })());
        return;
      }
    } catch(_) {}
    event.respondWith((async () => {
      const exact = await caches.match(req);
      if (exact) return exact;
      let fallback = null;
      try {
        const u = new URL(req.url);
        fallback = await caches.match(u.pathname, { ignoreSearch: true });
      } catch(_) {}
      if (fallback) {
        event.waitUntil(fetch(req).then((resp)=>caches.open(CACHE_NAME).then((c)=>c.put(req, resp.clone()))).catch(()=>{}));
        return fallback;
      }
      try {
        const resp = await fetch(req);
        caches.open(CACHE_NAME).then((c)=>c.put(req, resp.clone()));
        return resp;
      } catch(_) {
        return caches.match('images/logo-removebg.png', { ignoreSearch: true });
      }
    })());
    return;
  }
  event.respondWith(
    fetch(req).catch(() => caches.match(req))
  );
});
