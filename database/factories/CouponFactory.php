<?php

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\User;
use App\Support\BusinessResolver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Coupon>
 */
class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'business']),
            'title' => fake()->words(3, true),
            'code' => strtoupper(Str::random(8)),
            'description' => fake()->sentence(),
            'discount_type' => 'percentage',
            'discount_percentage' => 10,
            'discount_amount' => null,
            'minimum_amount' => null,
            'usage_limit' => null,   // redemption cap
            'claim_limit' => null,   // claim cap
            'used_count' => 0,
            'claimed_count' => 0,
            'redeemed_count' => 0,
            'per_user_limit' => 1,
            'starts_at' => null,
            'expires_at' => now()->addMonth(),
            'is_active' => true,
            'is_featured' => false,
        ];
    }

    /**
     * Every business account has a businesses row in production —
     * BusinessResolver::ensureFor() provisions one at registration, and the
     * backfill covered everyone earlier. Factories must match, or tests run
     * against data that cannot exist: on MySQL, claimed_coupons.business_id
     * has a foreign key to businesses, so a coupon with no business row
     * cannot be claimed at all.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Coupon $coupon) {
            if ($coupon->business_id !== null) {
                return;
            }

            $owner = User::find($coupon->user_id);

            if ($owner) {
                $coupon->forceFill([
                    'business_id' => BusinessResolver::ensureFor($owner)->id,
                ])->saveQuietly();
            }
        });
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * A coupon that has already hit its claim cap.
     */
    public function claimCapReached(int $limit = 1): static
    {
        return $this->state(fn () => [
            'claim_limit' => $limit,
            'claimed_count' => $limit,
        ]);
    }

    /**
     * A coupon that has already hit its redemption cap.
     */
    public function redeemCapReached(int $limit = 1): static
    {
        return $this->state(fn () => [
            'usage_limit' => $limit,
            'redeemed_count' => $limit,
        ]);
    }
}
