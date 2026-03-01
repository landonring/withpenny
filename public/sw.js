const CACHE_NAME = 'penny-shell-v15';
const IS_DEV = self.location.hostname === 'localhost' || self.location.hostname === '127.0.0.1';
const CORE_ASSETS = [
    '/',
    '/manifest.webmanifest',
    '/icons/penny-192.png',
    '/icons/penny-512.png',
    '/icons/penny-maskable-512.png',
];

self.addEventListener('install', (event) => {
    if (IS_DEV) {
        self.skipWaiting();
        return;
    }
    event.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            await Promise.allSettled(CORE_ASSETS.map((asset) => cache.add(asset)));
            await self.skipWaiting();
        })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    if (IS_DEV) {
        return;
    }

    const url = new URL(event.request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/admin/')) {
        return;
    }

    if (event.request.mode === 'navigate') {
        event.respondWith(
            (async () => {
                try {
                    return await fetch(event.request);
                } catch {
                    const shell = await caches.match('/');
                    return shell || new Response('Offline', { status: 503, headers: { 'Content-Type': 'text/plain' } });
                }
            })()
        );
        return;
    }

    event.respondWith(
        caches.match(event.request).then(async (cached) => {
            if (cached) {
                return cached;
            }

            try {
                const response = await fetch(event.request);
                if (response.ok && response.type === 'basic') {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, responseClone));
                }
                return response;
            } catch {
                return cached || new Response('', { status: 504, statusText: 'Gateway Timeout' });
            }
        })
    );
});
