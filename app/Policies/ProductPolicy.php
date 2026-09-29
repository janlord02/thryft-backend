<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\BusinessResolver;

/**
 * Authorization for products. See CouponPolicy for the shape and for why
 * super admins are absent.
 */
class ProductPolicy
{
    public function view(User $user, Product $product): bool
    {
        return $this->manages($user, $product);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->manages($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->manages($user, $product);
    }

    private function manages(User $user, Product $product): bool
    {
        return BusinessResolver::owns($user, $product)
            && $user->hasBusinessAbility('business.manage_offers', $product->business_id ?? $product->user_id);
    }
}
