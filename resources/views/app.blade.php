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
    <div id="app"></div>
</body>
</html>
