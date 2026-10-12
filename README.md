# Thryft Backend

The API and server-rendered public pages behind [Thryft](https://app.thryft.net), a platform for local deals and loyalty.
Businesses publish coupons, products, events, loyalty cards, gift certificates, memberships, joint promotions and paid featured placements.
Shoppers find these nearby, claim them and save their favourites. Staff redeem them at the till by scanning a code.

The frontend and mobile apps live in [connectrader/Thryft](https://github.com/connectrader/Thryft).

## Stack

- PHP 8.2+ and Laravel 12
- Sanctum for API tokens and Google2FA for two-factor login
- Stripe for subscriptions, featured placements and webhooks
- Web Push over VAPID (`minishlink/web-push`), with optional broadcasting through Pusher
- MySQL 8 in production; SQLite works for local development and tests

## Roles

| Role | Who |
| --- | --- |
| `user` | Shoppers |
| `business` | Business owners |
| `super-admin` | Thryft administrators |

Inside a business, each team member has a role: `owner`, `admin`, `manager` or `staff`.
Each role maps to a set of abilities in `config/permissions.php`, such as `business.manage_offers`, `business.redeem` and `business.manage_billing`.
Business routes are protected by `business:<ability>`. That middleware finds the acting business, checks its subscription and then checks the ability.

## API overview

All routes live under `/api` (`routes/api.php`), and most are wrapped in the `maintenance` middleware.

| Area | Examples |
| --- | --- |
| Auth | `/login`, `/register`, `/register-business`, `/forgot-password`, `/2fa/verify` |
| Public (guest) | `/public/businesses`, `/public/deals`, `/search`, `/settings/public` |
| Optional auth | `/nearby-businesses`, `/events`, `/flash-deals`, `/promotions`, `/featured` |
| Shopper | `/profile/*`, `/coupons/claim`, `/loyalty/{program}/join`, `/notifications/*`, `/subscriptions/*` |
| Business | `/business/dashboard/analytics`, `/business/staff`, `/business/locations`, `/business/till/{code}`, `/business/page`, `/products`, `/coupons/redeem` |
| Admin | `/admin/users`, `/admin/settings`, `/admin/subscriptions`, `/admin/categories`, `/admin/promo-codes` |
| System | `POST /stripe/webhook`, `GET /health` |

The crawlable public pages are in `routes/public.php`: `/b/{business}`, `/b/{business}/deals/{slug}`, `/e/{slug}`, `/p/{slug}` and `/sitemap.xml`.

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
composer dev   # runs serve, queue:listen, pail and vite together
```

Besides the stock Laravel settings, the app reads these variables:

| Variable | Purpose |
| --- | --- |
| `FRONTEND_URL` | Where links in emails and password resets point (default `https://app.thryft.net/`) |
| `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | Stripe billing and webhooks |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | Web Push; generate the keys with `php artisan webpush:vapid` |
| `PUSHER_APP_ID`, `PUSHER_APP_KEY`, `PUSHER_APP_SECRET`, `PUSHER_APP_CLUSTER` | Optional real-time broadcasting |
| `FEATURED_PRICE_PER_DAY_CENTS` | Price of a featured placement |
| `THRYFT_USE_BUSINESS_ENTITY` | Read business ownership from `businesses` instead of the legacy `users` columns (see `config/thryft.php`) |

## Background work

Scheduled jobs are defined in `routes/console.php`:

| Command | When |
| --- | --- |
| `subscriptions:reconcile` | Daily at 03:15; re-syncs subscriptions with Stripe (`--dry-run` available) |
| `referrals:apply-rewards` | Daily at 03:45; retries referral rewards that failed earlier |
| `coupons:remind-expiring` | Daily at 14:00; reminds shoppers about claimed coupons that are about to expire |

In production these jobs need a cron entry and a queue worker:

```bash
* * * * * cd /var/www/thryft-api && php artisan schedule:run >> /dev/null 2>&1
php artisan queue:work --sleep=3 --tries=3 --max-time=3600   # under supervisor
```

## Tests

```bash
composer test
```

The test suite runs on in-memory SQLite.
CI (`.github/workflows/ci.yml`) runs it on SQLite and on MySQL 8, and again with `THRYFT_USE_BUSINESS_ENTITY=true`.
Run it against MySQL before merging anything that touches queries: some bugs only show up there.

## Deploying

After pulling new code on the server:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan route:clear && php artisan config:clear
php artisan up
curl -fsS https://app.thryft.net/api/health   # expect {"status":"ok"}
```

Take a database backup before running migrations that rewrite data.

## More docs

- [NOTIFICATION_SETUP.md](NOTIFICATION_SETUP.md) covers in-app, email and push notifications
- [CHANGELOG.md](CHANGELOG.md) lists what changed in each release
