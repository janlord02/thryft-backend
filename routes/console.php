<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| REQUIRES a cron entry on the server — nothing below runs without it:
|
|   * * * * * cd /var/www/thryft-api && php artisan schedule:run >> /dev/null 2>&1
|
| Queued work likewise requires a running worker (supervisor):
|
|   php artisan queue:work --sleep=3 --tries=3 --max-time=3600
|
| Neither exists in production today; they appear only in the "composer dev"
| script. Until they are set up, the reconcile below silently does nothing.
|
*/

// Safety net for missed or failed Stripe webhooks. Without it, a webhook
// outage is invisible and silently generous — cancelled subscriptions keep
// granting access indefinitely.
Schedule::command('subscriptions:reconcile')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();

// Shoppers with a claimed coupon expiring in the next few days get one
// reminder. 14:00 UTC is morning across the US, so the push arrives at a
// civil hour rather than overnight.
Schedule::command('coupons:remind-expiring')
    ->dailyAt('14:00')
    ->withoutOverlapping()
    ->onOneServer();

// Referral free months that could not be applied when earned (no active plan
// yet, or Stripe unreachable) are retried here.
Schedule::command('referrals:apply-rewards')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->onOneServer();
