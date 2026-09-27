<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;
use App\Support\BusinessResolver;

/**
 * Authorization for coupons.
 *
 * Replaces the ownership check that was copy-pasted into four CouponController
 * methods. Ownership itself still routes through BusinessResolver so the
 * business-entity rollout flag governs it in one place.
 *
 * Super admins are allowed by the Gate::before hook in AppServiceProvider and
 * are deliberately not mentioned here.
 */
class CouponPolicy
{
    public function view(User $user, Coupon $coupon): bool
    {
        return $this->manages($user, $coupon);
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $this->manages($user, $coupon);
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $this->manages($user, $coupon);
    }

    public function redeem(User $user, Coupon $coupon): bool
    {
        // Front-of-house staff may honour a coupon without being able to
        // change what is on offer.
        return BusinessResolver::owns($user, $coupon)
            && $user->hasBusinessAbility('business.redeem', $coupon->business_id ?? $coupon->user_id);
    }

    private function manages(User $user, Coupon $coupon): bool
    {
        return BusinessResolver::owns($user, $coupon)
            && $user->hasBusinessAbility('business.manage_offers', $coupon->business_id ?? $coupon->user_id);
    }
}
