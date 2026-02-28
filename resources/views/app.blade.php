<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <title>Penny - Because every penny counts</title>

    <meta name="theme-color" content="#c6d2c4" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <meta name="mobile-web-app-capable" content="yes" />

    <link rel="manifest" href="/manifest.webmanifest" />
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/penny-192.png" />
    <link rel="icon" type="image/png" sizes="512x512" href="/icons/penny-512.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="/icons/penny-192.png" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

<link href="/marketing.css?v={{ filemtime(public_path('marketing.css')) }}" rel="stylesheet"/>
<link href="/marketing-overrides.css?v={{ filemtime(public_path('marketing-overrides.css')) }}" rel="stylesheet"/>
    @php
        $hasHot = is_file(public_path('hot'));
    @endphp
    @if (! $hasHot)
        @php
            $manifestPath = public_path('build/manifest.json');
            $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
            $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        @endphp
        @if ($cssFile)
            <link rel="stylesheet" href="/build/{{ $cssFile }}" />
        @endif
    @endif

    @php
        $ua = strtolower(request()->userAgent() ?? '');
        $isMobile = str_contains($ua, 'mobile')
            || str_contains($ua, 'iphone')
            || str_contains($ua, 'ipad')
            || str_contains($ua, 'android');
        $isDesktop = ! $isMobile;
    @endphp
    <script>
        window.__PENNY_DESKTOP__ = {{ $isDesktop ? 'true' : 'false' }};
    </script>
</head>
<body>
    <div id="boot-fallback" class="boot-fallback">
        <div class="boot-card">
            <p class="boot-title">Loading Penny - Because every penny counts…</p>
            <p class="boot-sub">If this screen doesn’t change, the app may need a refresh.</p>
            <div class="boot-actions">
                <button type="button" class="primary-button" onclick="location.reload()">Reload</button>
                <button type="button" class="ghost-button" onclick="window.__PENNY_RESET__ && window.__PENNY_RESET__()">Reset cache</button>
            </div>
            <pre id="boot-debug" class="boot-debug hidden"></pre>
        </div>
    </div>
    <div id="app"></div>

    <script>
        window.__PENNY_RESET__ = async () => {
            if (!('serviceWorker' in navigator)) {
                location.reload();
                return;
            }

            try {
                const registrations = await navigator.serviceWorker.getRegistrations();
                await Promise.all(registrations.map((registration) => registration.unregister()));
            } catch (error) {
                console.warn('penny_sw_unregister_failed', error);
            }

            if (window.caches?.keys) {
                try {
                    const keys = await caches.keys();
                    await Promise.all(keys.map((key) => caches.delete(key)));
                } catch (error) {
                    console.warn('penny_cache_clear_failed', error);
                }
            }

            location.reload();
        };

        const params = new URLSearchParams(window.location.search);
        if (params.has('reset')) {
            params.delete('reset');
            const nextUrl = `${window.location.pathname}${params.toString() ? `?${params.toString()}` : ''}${window.location.hash}`;
            window.history.replaceState({}, '', nextUrl);
            window.__PENNY_RESET__();
        }

        (function () {
            const debugEl = document.getElementById('boot-debug');
            const pushDebug = (message) => {
                if (!debugEl || !message) return;
                debugEl.classList.remove('hidden');
                debugEl.textContent += `${message}\n`;
            };

            window.addEventListener('error', (event) => {
                if (event?.message) {
                    pushDebug(`Error: ${event.message}`);
                }
            });

            window.addEventListener('unhandledrejection', (event) => {
                const reason = event?.reason;
                const message = typeof reason === 'string' ? reason : reason?.message;
                if (message) {
                    pushDebug(`Promise: ${message}`);
                }
            });

            setTimeout(async () => {
                if (document.body.classList.contains('app-ready')) {
                    return;
                }

                pushDebug('Still loading after 2s.');
                pushDebug(`URL: ${window.location.href}`);
                pushDebug(`Online: ${navigator.onLine}`);

                try {
                    const manifestResponse = await fetch('/build/manifest.json', { cache: 'no-store' });
                    pushDebug(`manifest.json: ${manifestResponse.status}`);
                    if (manifestResponse.ok) {
                        const manifest = await manifestResponse.json();
                        const entry = manifest['resources/js/app.js'];
                        if (entry?.file) {
                            const assetPath = `/build/${entry.file}`;
                            pushDebug(`app.js: ${assetPath}`);
                            const assetResp = await fetch(assetPath, { method: 'HEAD', cache: 'no-store' });
                            pushDebug(`app.js HEAD: ${assetResp.status}`);
                            const contentType = assetResp.headers.get('content-type');
                            if (contentType) {
                                pushDebug(`app.js type: ${contentType}`);
                            }

                            try {
                                await import(assetPath);
                                pushDebug('app.js import: ok');
                            } catch (error) {
                                pushDebug(`app.js import error: ${error?.message || error}`);
                            }
                        }
                    }
                } catch (error) {
                    pushDebug('manifest.json: fetch failed');
                }
            }, 2000);
        })();
    </script>
</body>
</html>
