<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One hook replaces the super-admin checks that were written inline in
        // BusinessSubscriptionController (four of them) and gives RoleMiddleware
        // the hierarchy it never had: 'role' is a strict string comparison, so
        // a super admin does not satisfy role:business.
        //
        // Returning null rather than false when the user is not a super admin
        // is important — false would short-circuit and deny every other check.
        Gate::before(function (User $user) {
            return $user->role === 'super-admin' ? true : null;
        });

        // Policies are auto-discovered by Laravel 12
        // (App\Models\X -> App\Policies\XPolicy), so none are registered here.
    }
}
