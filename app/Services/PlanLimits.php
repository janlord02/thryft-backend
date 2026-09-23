<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;

/**
 * Enforces the per-plan quotas that the subscription seeder has always written
 * into Subscription.metadata (max_products, max_coupons) but which nothing ever
 * read — so every tier was effectively unlimited.
 *
 * A missing or null limit means unlimited, which keeps existing plans working
 * unchanged until someone deliberately sets a number.
 */
class PlanLimits
{
    public function remainingProducts(User $user): ?int
    {
        return $this->remaining($user, 'max_products', Product::where('user_id', $user->id)->count());
    }

    public function remainingCoupons(User $user): ?int
    {
        return $this->remaining($user, 'max_coupons', Coupon::where('user_id', $user->id)->count());
    }

    public function canCreateProduct(User $user): bool
    {
        $remaining = $this->remainingProducts($user);

        return $remaining === null || $remaining > 0;
    }

    public function canCreateCoupon(User $user): bool
    {
        $remaining = $this->remainingCoupons($user);

        return $remaining === null || $remaining > 0;
    }

    public function limitFor(User $user, string $key): ?int
    {
        $plan = $user->activeSubscription()?->subscription;

        if (!$plan) {
            return null;
        }

        $limit = data_get($plan->metadata, $key);

        // Treat an explicit null, empty string or non-numeric value as
        // "unlimited" rather than as zero — a zero would lock out every
        // business on a plan whose metadata was simply never filled in.
        return is_numeric($limit) ? (int) $limit : null;
    }

    private function remaining(User $user, string $key, int $used): ?int
    {
        $limit = $this->limitFor($user, $key);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $used);
    }
}
