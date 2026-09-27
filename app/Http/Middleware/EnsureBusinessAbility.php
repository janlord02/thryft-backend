<?php

namespace App\Http\Middleware;

use App\Support\BusinessResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single gate on business endpoints. Replaces ['role:business',
 * 'subscribed'] and does three jobs in a fixed order:
 *
 *   1. resolve which business this request acts for
 *   2. confirm that business is paid up
 *   3. confirm the caller holds the required ability
 *
 * One middleware rather than three because the order matters and each step
 * needs the previous step's result. It is also the only place that knows how a
 * request names its business, so a future multi-business UI changes one file.
 *
 * Usage: ->middleware('business:business.manage_offers')
 * The ability argument may be omitted to require business context alone.
 */
class EnsureBusinessAbility
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $business = BusinessResolver::forRequest($request, $user);

        // Transitional: an account whose role is still 'business' but which has
        // no business row yet — promoted by createSubscription/claimFree after
        // the backfill ran. Let it through rather than locking out a paying
        // customer, but make the gap visible.
        $legacyBusinessUser = $business === null && $user->role === 'business';

        if (!$business && !$legacyBusinessUser) {
            // Same 403 that role:business used to return, including for super
            // admins: the Gate::before hook grants them abilities, but acting
            // AS a business still requires a business to act as.
            return response()->json([
                'status' => 'error',
                'message' => 'Access denied. Insufficient permissions.',
                'code' => 'no_business_context',
            ], 403);
        }

        if ($legacyBusinessUser) {
            Log::warning('Business request with no business record', [
                'user_id' => $user->id,
                'path' => $request->path(),
            ]);
        }

        // Payment before permission: a member of an unpaid business should be
        // told the business is unpaid, not that they lack a permission.
        if ($user->role !== 'super-admin' && !$user->hasActiveSubscription()) {
            return response()->json([
                'status' => 'error',
                'message' => 'An active subscription is required to use business features.',
                'code' => 'subscription_required',
            ], 402);
        }

        if ($ability && $business && !$this->allows($user, $ability, $business)) {
            return response()->json([
                'status' => 'error',
                'message' => 'You do not have permission to perform this action for this business.',
                'code' => 'ability_required',
            ], 403);
        }

        // Downstream code reads this instead of re-resolving.
        $request->attributes->set('business', $business);

        return $next($request);
    }

    private function allows($user, string $ability, $business): bool
    {
        if ($user->role === 'super-admin') {
            return true;
        }

        return $user->hasBusinessAbility($ability, $business);
    }
}
