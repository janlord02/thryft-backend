<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side gate on business features.
 *
 * Before this, access was granted purely by users.role === 'business'. Nothing
 * ever reverted that role — not cancel(), not a Stripe cancellation — so a
 * business that stopped paying kept full API access permanently. The only check
 * was in the Vue router, which any direct API call bypasses.
 *
 * Super admins pass through: they need to administer businesses without holding
 * a subscription themselves.
 */
class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->role === 'super-admin') {
            return $next($request);
        }

        // Covers active, and past_due still inside its grace window.
        if ($user->hasActiveSubscription()) {
            return $next($request);
        }

        // 402 rather than 403: the request is well-formed and the caller is who
        // they claim to be — what is missing is payment. This lets the client
        // route to an upgrade prompt instead of a generic "access denied".
        return response()->json([
            'status' => 'error',
            'message' => 'An active subscription is required to use business features.',
            'code' => 'subscription_required',
        ], 402);
    }
}
