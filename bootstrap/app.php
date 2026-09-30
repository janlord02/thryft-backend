<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Applies throttle:api to the "api" middleware group. Routes in
        // routes/api.php are registered by RouteServiceProvider via
        // Route::middleware('api'), so they pick this up. Named per-route
        // limiters (login, two-factor, claim, redeem, search) are defined in
        // RouteServiceProvider::boot(). Verify with `php artisan route:list`.
        $middleware->throttleApi();

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'maintenance' => \App\Http\Middleware\MaintenanceMode::class,
            'api.session' => \App\Http\Middleware\EnsureApiSession::class,
            // Supersedes ['role:business', 'subscribed'] — resolves the business,
            // checks the subscription, then checks the ability.
            'business' => \App\Http\Middleware\EnsureBusinessAbility::class,
            'auth.optional' => \App\Http\Middleware\OptionalSanctumAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
