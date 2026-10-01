<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBusinessResource;
use App\Http\Resources\PublicCouponResource;
use App\Models\Announcement;
use App\Models\Business;
use App\Models\Coupon;
use App\Support\PageBlocks;
use Illuminate\Support\Str;

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
            // whereNotNull('slug'): route('public.deal') throws
            // UrlGenerationException on a null parameter, which would 500 the
            // whole page for one unslugged coupon.
            ->whereNotNull('slug')
            ->active()
            ->valid()
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->limit(24)
            ->get();

        $public = (new PublicBusinessResource($business))->toArray(request());

        $announcements = Announcement::query()
            ->where('business_id', $business->id)
            ->published()
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return view('public.business', [
            'business' => $business,
            'coupons' => $coupons,
            'blocks' => PageBlocks::resolve($business),
            'announcements' => $announcements,
            'public' => $public,
            'metaTitle' => $public['name'] . ' — Thryft',
            'metaDescription' => $this->describeBusiness($public),
            'ogType' => 'business.business',
            'ogImage' => $public['cover_url'] ?? $public['logo_url'],
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

        $biz = (new PublicBusinessResource($business))->toArray(request());
        $deal = (new PublicCouponResource($coupon))->toArray(request());

        return view('public.deal', [
            'business' => $business,
            'coupon' => $coupon,
            'biz' => $biz,
            'deal' => $deal,
            'metaTitle' => $deal['title'] . ' at ' . $biz['name'] . ' — Thryft',
            'metaDescription' => $deal['description']
                ? Str::limit(strip_tags($deal['description']), 155)
                : $deal['formatted_discount'] . ' off at ' . $biz['name'] . '. Claim on Thryft.',
            'ogType' => 'product',
            'ogImage' => $deal['banner_url'] ?? $biz['logo_url'],
        ]);
    }

    private function describeBusiness(array $public): string
    {
        if ($public['description']) {
            return Str::limit(strip_tags($public['description']), 155);
        }

        $city = $public['location']['city'] ?? null;

        return trim($public['name'] . ' on Thryft' . ($city ? ' — ' . $city : '')
            . '. See current deals and offers.');
    }
}
