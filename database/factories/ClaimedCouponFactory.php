<?php

namespace Database\Factories;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ClaimedCoupon>
 */
class ClaimedCouponFactory extends Factory
{
    public function definition(): array
    {
        $coupon = Coupon::factory();

        return [
            'user_id' => User::factory(),
            'coupon_id' => $coupon,
            'business_id' => User::factory()->state(['role' => 'business']),
            'product_id' => null,
            'coupon_code' => strtoupper(fake()->bothify('????####')),
            'coupon_title' => fake()->words(3, true),
            'coupon_description' => fake()->sentence(),
            'discount_type' => 'percentage',
            'discount_percentage' => 10,
            'discount_amount' => null,
            'minimum_amount' => null,
            // Snapshot of the coupon's expiry at claim time.
            'expires_at' => now()->addMonth(),
            'status' => 'claimed',
            'used_at' => null,
            'redeemed_by_user_id' => null,
        ];
    }

    /**
     * Build a claim that is genuinely consistent with a given coupon: same
     * business, same code, same snapshotted discount and expiry. Using the bare
     * factory would otherwise produce a claim whose business_id points at an
     * unrelated user, which silently breaks ownership assertions.
     */
    public function forCoupon(Coupon $coupon, ?User $customer = null): static
    {
        return $this->state(fn () => [
            'user_id' => $customer?->id ?? User::factory(),
            'coupon_id' => $coupon->id,
            'business_id' => $coupon->user_id,
            'coupon_code' => $coupon->code,
            'coupon_title' => $coupon->title,
            'coupon_description' => $coupon->description,
            'discount_type' => $coupon->discount_type,
            'discount_percentage' => $coupon->discount_percentage,
            'discount_amount' => $coupon->discount_amount,
            'minimum_amount' => $coupon->minimum_amount,
            'expires_at' => $coupon->expires_at,
        ]);
    }

    public function used(): static
    {
        return $this->state(fn () => [
            'status' => 'used',
            'used_at' => now(),
        ]);
    }

    /**
     * Expired by SNAPSHOT — the promise shown to the customer has lapsed,
     * regardless of what the live coupon now says.
     */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
