<?php

namespace App\Http\Controllers;

use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Flash deals near a shopper: live, flagged coupons that still have claims
 * left, soonest to end first. Optional auth, so a guest sees the rail and a
 * signed-in shopper also sees which ones they already hold.
 *
 * Distance uses the business owner's stored position, the same source the
 * nearby-businesses list uses, so the two agree on what "near" means.
 */
class FlashDealController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $now = now();

        $query = Coupon::query()
            ->select('coupons.*')
            ->join('users as owners', 'owners.id', '=', 'coupons.user_id')
            ->where('coupons.is_flash', true)
            ->where('coupons.is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('coupons.starts_at')->orWhere('coupons.starts_at', '<=', $now);
            })
            ->where('coupons.expires_at', '>', $now)
            ->where(function ($q) {
                $q->whereNull('coupons.claim_limit')->orWhereColumn('coupons.claimed_count', '<', 'coupons.claim_limit');
            })
            ->with(['products:id,name']);

        $lat = $validated['latitude'] ?? null;
        $lng = $validated['longitude'] ?? null;

        if ($lat !== null && $lng !== null) {
            // Integer on purpose; see EventBrowseController for why.
            $radius = (int) ceil((float) ($validated['radius'] ?? 25));
            $distance = '(3959 * acos(least(1.0, cos(radians(?)) * cos(radians(owners.latitude)) * cos(radians(owners.longitude) - radians(?)) + sin(radians(?)) * sin(radians(owners.latitude)))))';
            if (DB::getDriverName() === 'sqlite') {
                $distance = str_replace('least(1.0, ', 'min(1.0, ', $distance);
            }
            $query->whereNotNull('owners.latitude')->whereNotNull('owners.longitude')
                ->selectRaw("{$distance} as distance", [$lat, $lng, $lat])
                ->whereRaw("{$distance} <= ?", [$lat, $lng, $lat, $radius]);
        }

        $deals = $query
            ->addSelect('owners.business_name as owner_business_name', 'owners.name as owner_name', 'owners.profile_image as owner_profile_image')
            ->orderBy('coupons.expires_at')
            ->limit((int) ($validated['limit'] ?? 20))
            ->get();

        $user = $request->user();
        $held = $user
            ? ClaimedCoupon::query()
                ->where('user_id', $user->id)
                ->whereIn('coupon_id', $deals->pluck('id'))
                ->whereIn('status', ['claimed', 'used'])
                ->pluck('coupon_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        $rows = $deals->map(function (Coupon $c) use ($held) {
            $product = $c->products->first();

            return [
                'id' => $c->id,
                'title' => $c->title,
                'description' => $c->description,
                'discount_type' => $c->discount_type,
                'discount_amount' => $c->discount_amount,
                'discount_percentage' => $c->discount_percentage,
                'formatted_discount' => $c->formatted_discount,
                'minimum_amount' => $c->minimum_amount,
                'banner_image_url' => $c->banner_image_url,
                'starts_at' => $c->starts_at,
                'expires_at' => $c->expires_at,
                'remaining' => $c->claim_limit ? max(0, (int) $c->claim_limit - (int) $c->claimed_count) : null,
                'is_flash' => true,
                'business_id' => $c->user_id,
                'business_name' => $c->owner_business_name ?: $c->owner_name,
                'product_id' => $product?->id,
                'product_name' => $product?->name,
                'distance' => isset($c->distance) ? round((float) $c->distance, 1) : null,
                'is_claimed_by_user' => in_array((int) $c->id, $held, true),
            ];
        });

        return response()->json(['status' => 'success', 'data' => $rows->values()]);
    }
}
