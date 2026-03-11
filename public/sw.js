try {
    importScripts('/pwa-version.js');
} catch {
    // Keep fallback version below if version script is unavailable.
}

const APP_VERSION = self.__PENNY_APP_VERSION__ || '2026.03.11.1';
const CACHE_PREFIX = 'penny-shell-';
const CACHE_NAME = `${CACHE_PREFIX}${APP_VERSION}`;
const IS_DEV = self.location.hostname === 'localhost' || self.location.hostname === '127.0.0.1';
const CORE_ASSETS = [
    '/',
    '/manifest.webmanifest',
    '/icons/penny-192.png',
    '/icons/penny-512.png',
    '/icons/penny-maskable-512.png',
    '/pwa-version.js',
];

const notifyClients = async (message) => {
    const windowClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    await Promise.all(windowClients.map((client) => client.postMessage(message)));
};

self.addEventListener('install', (event) => {
    if (IS_DEV) {
        return;
    }

    event.waitUntil(
        caches.open(CACHE_NAME).then(async (cache) => {
            await Promise.allSettled(CORE_ASSETS.map((asset) => cache.add(asset)));
        })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(
                keys
                    .filter((key) => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            );

            await self.clients.claim();
            await notifyClients({ type: 'PENNY_SW_ACTIVATED', version: APP_VERSION });
        })()
    );
});

self.addEventListener('message', (event) => {
    const data = event.data || {};
    const type = String(data.type || '');

    if (type === 'PENNY_SKIP_WAITING') {
        event.waitUntil(self.skipWaiting());
        return;
    }

    if (type === 'PENNY_GET_VERSION') {
        if (event.ports?.[0]) {
            event.ports[0].postMessage({ version: APP_VERSION });
        }
    }
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
        const targetPath = new URL(targetUrl).pathname;
        const windowClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });

        const exactClient = windowClients.find((client) => {
            try {
                return new URL(client.url).pathname === targetPath;
            } catch {
                return false;
            }
        });

        const reusableClient = exactClient || windowClients[0];
        if (reusableClient && 'focus' in reusableClient) {
            try {
                if (reusableClient.url !== targetUrl && 'navigate' in reusableClient) {
                    await reusableClient.navigate(targetUrl);
                }
                await reusableClient.focus();
                return;
            } catch {
                // Fall through to openWindow as a fallback.
            }
        }

        if (clients.openWindow) {
            await clients.openWindow(targetUrl);
        }
    })());
});
