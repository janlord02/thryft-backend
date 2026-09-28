<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Auth endpoints are unauthenticated, so key on submitted email + source IP.
        // Keying on IP alone lets one NAT'd office lock out its own staff; keying on
        // email alone lets an attacker lock a known account out from anywhere.
        $byEmailAndIp = fn (Request $request) => Str::lower((string) $request->input('email')) . '|' . $request->ip();

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($byEmailAndIp($request)));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($byEmailAndIp($request)));

        // Claim is a write that creates rows; redeem is a counter-hit merchants
        // fire repeatedly at a busy till, so it gets more headroom.
        RateLimiter::for('claim', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('redeem', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));

        // Guest browsing is unauthenticated, so it can only be keyed on IP and
        // is the obvious scraping target. Generous enough for real browsing,
        // tight enough that enumerating the whole catalogue is slow.
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));

            // Crawlable public pages, served from the apex domain. Kept in
            // their own file because they are the only unauthenticated,
            // server-rendered surface and their routing rules differ from both
            // the API and the app's web routes.
            Route::middleware('web')
                ->group(base_path('routes/public.php'));
        });
    }
}
