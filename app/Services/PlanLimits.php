<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Support\BusinessResolver;

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
        return $this->remaining($user, 'max_products', $this->countFor($user, Product::query()));
    }

    public function remainingCoupons(User $user): ?int
    {
        return $this->remaining($user, 'max_coupons', $this->countFor($user, Coupon::query()));
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
        // Quotas belong to the BUSINESS, like every other entitlement in this
        // codebase (Business::hasActiveSubscription, EnsureBusinessAbility).
        // Reading the acting user's own subscription gave any staff member a
        // null plan — and therefore an unlimited, private quota.
        $business = BusinessResolver::forUser($user);
        $plan = $business?->owner?->activeSubscription()?->subscription
            ?? $user->activeSubscription()?->subscription;

        if (!$plan) {
            return null;
        }

        $limit = data_get($plan->metadata, $key);

        // Treat an explicit null, empty string or non-numeric value as
        // "unlimited" rather than as zero — a zero would lock out every
        // business on a plan whose metadata was simply never filled in.
        return is_numeric($limit) ? (int) $limit : null;
    }

    /**
     * Count the business's records, not just the acting user's. Counting by
     * user_id missed anything a colleague created, so each member had their
     * own private allowance against a single shared quota.
     */
    private function countFor(User $user, $query): int
    {
        $businessId = BusinessResolver::businessIdFor($user);

        if ($businessId === null) {
            return (int) $query->where('user_id', $user->id)->count();
        }

        return (int) $query->where(function ($q) use ($businessId, $user) {
            $q->where('business_id', $businessId)
                // Rows predating the backfill carry no business_id.
                ->orWhere(fn ($legacy) => $legacy->whereNull('business_id')->where('user_id', $user->id));
        })->count();
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
