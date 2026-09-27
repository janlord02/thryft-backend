<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Coupon;
use Illuminate\Http\Request;

/**
 * Server-rendered, crawlable business and deal pages on the marketing domain.
 *
 * The Quasar app is a SPA with no SSR, every content route behind
 * meta:{auth:true}, and a catch-all that redirects to /auth/login — so none of
 * it is indexable, and a guest cannot see a business at all. Rather than
 * converting the SPA to SSR (which would mean hardening ~50 components and
 * five browser-only boot files, running Node in production, and coupling
 * mobile release risk to an SEO change), these pages are rendered from Blade
 * by the Laravel app that already serves the apex domain.
 *
 * Data comes straight from the database here — no HTTP hop to the API — and
 * every payload still goes through the public allowlist resources.
 */
class BusinessPageController extends Controller
{
    public function show(Business $business)
    {
        abort_if($business->status !== 'active', 404);

        $business->load('primaryLocation');

        $coupons = Coupon::where('business_id', $business->id)
            ->active()
            ->valid()
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->limit(24)
            ->get();

        return view('public.business', [
            'business' => $business,
            'coupons' => $coupons,
        ]);
    }

    /**
     * A single deal. Scoped to the business in the URL so a coupon slug cannot
     * be rendered under an unrelated business's branding.
     */
    public function deal(Business $business, string $couponSlug)
    {
        abort_if($business->status !== 'active', 404);

        $coupon = Coupon::where('slug', $couponSlug)
            ->where('business_id', $business->id)
            ->active()
            ->firstOrFail();

        $business->load('primaryLocation');

        return view('public.deal', [
            'business' => $business,
            'coupon' => $coupon,
        ]);
    }
}
