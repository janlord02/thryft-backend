<?php

namespace Database\Factories;

use App\Models\User;
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
