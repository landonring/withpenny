const CACHE_NAME = 'penny-shell-v16';
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

self.addEventListener('push', (event) => {
    let payload = {};
    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = {};
    }

    const title = payload.title || 'Penny';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/icons/penny-192.png',
        badge: payload.badge || '/icons/penny-192.png',
        tag: payload.tag || 'penny-notification',
        renotify: Boolean(payload.renotify),
        data: {
            click_url: payload.click_url || '/app',
            track_url: payload.track_url || null,
            notification_id: payload.notification_id || null,
            type: payload.type || null,
            payload: payload.data || {},
        },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const clickUrl = event.notification?.data?.click_url || '/app';
    const trackUrl = event.notification?.data?.track_url || null;

    event.waitUntil((async () => {
        if (trackUrl) {
            try {
                await fetch(trackUrl, {
                    method: 'GET',
                    credentials: 'include',
                    cache: 'no-store',
                    keepalive: true,
                });
            } catch {
                // Ignore tracking failures.
            }
        }

        const targetUrl = new URL(clickUrl, self.location.origin).href;
        const windowClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windowClients) {
            if ('focus' in client) {
                try {
                    await client.navigate(targetUrl);
                    await client.focus();
                    return;
                } catch {
                    // Continue to open a new window.
                }
            }
        }

        if (clients.openWindow) {
            await clients.openWindow(targetUrl);
        }
    })());
});
