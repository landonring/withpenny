# Penny

Penny is a calm, modern personal finance PWA. It is intentionally simple, mobile-first, and non-judgmental.

## Tech Stack

- Laravel (monolith)
- Vue + Vite
- MySQL
- PWA (manifest + service worker)

## Local Setup

1. Create your `.env` file:

```bash
cp .env.example .env
php artisan key:generate
```

2. Configure database credentials in `.env`:

```bash
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=penny
DB_USERNAME=root
DB_PASSWORD=
```

3. Install dependencies:

```bash
composer install
npm install
```

4. Run migrations:

```bash
php artisan migrate
```

5. Start the dev servers:

```bash
php artisan serve
npm run dev
```

## ngrok (Mobile Testing)

1. Start ngrok:

```bash
ngrok http 8000
```

2. Update your `.env` with the HTTPS URL from ngrok:

```bash
APP_URL=https://your-ngrok-url
ASSET_URL=https://your-ngrok-url
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

3. Restart `php artisan serve` and `npm run dev`.

Penny trusts proxy headers so sessions and auth work correctly behind the ngrok tunnel.

## PWA Notes

- Manifest: `public/manifest.webmanifest`
- Service worker: `public/sw.js`
- Icons: `public/icons`

Open Penny on a phone and select “Add to Home Screen” to install.

## Phase 1 Scope

- App shell + PWA
- Email/password auth
- Bottom nav with five tabs
- Placeholder screens for Home, Scan, Insights, Chat, Savings

Future phases will add transactions, receipt scanning, savings logic, and AI summaries.
