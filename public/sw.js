// BetterDoc DMS Offline-First Air-Gap Service Worker
// Strictly precaches static UI shell assets; never caches classified PDF streams or API payloads.
const CACHE_NAME = 'betterdoc-dms-shell-v2';
const STATIC_ASSETS = [
    '/manifest.webmanifest',
    '/betterdoc-icon.svg',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    // Never cache classified document streams, downloads, watermarked spools, or API endpoints
    if (
        url.pathname.includes('/stream') ||
        url.pathname.includes('/download') ||
        url.pathname.includes('/preview') ||
        url.pathname.startsWith('/wopi/') ||
        url.pathname.startsWith('/api/')
    ) {
        return;
    }

    if (url.pathname.startsWith('/build/') || STATIC_ASSETS.includes(url.pathname)) {
        event.respondWith(
            caches.match(req).then((cached) => {
                if (cached) {
                    return cached;
                }
                return fetch(req).then((netResp) => {
                    if (netResp.ok) {
                        const clone = netResp.clone();
                        caches.open(CACHE_NAME).then((c) => c.put(req, clone));
                    }
                    return netResp;
                });
            })
        );
    }
});
