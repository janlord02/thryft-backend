<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\BusinessAnalytics;
use App\Services\MerchantOnboarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The merchant's own dashboard.
 *
 * Distinct from Admin\DashboardController (platform-wide user counts) and from
 * UserDashboardController (which, despite the name, is the consumer side).
 * This is the first endpoint that answers the question a business owner
 * actually has: is Thryft bringing people in?
 */
class BusinessDashboardController extends Controller
{
    /**
     * Analytics for the acting business.
     *
     * The business is resolved by EnsureBusinessAbility and handed over on the
     * request, so this never re-derives it — one resolution per request, and
     * the ability check and the data can never disagree about which business
     * is meant.
     */
    public function analytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $business = $this->business($request);

        return response()->json([
            'status' => 'success',
            'data' => BusinessAnalytics::for(
                $business,
                $validated['from'] ?? null,
                $validated['to'] ?? null,
            )->toArray(),
        ]);
    }

    public function onboarding(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => MerchantOnboarding::for($this->business($request))->toArray(),
        ]);
    }

    /**
     * Legacy accounts (role === 'business', no business record yet) reach the
     * middleware's fallback and arrive here with no business attached. There
     * is nothing to report on, so say so plainly rather than 500-ing on a null.
     */
    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');

        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
