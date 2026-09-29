<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'description' => fake()->sentence(),
            'price' => 29.00,
            'billing_cycle' => 'monthly',
            'features' => ['Feature A', 'Feature B'],
            'is_popular' => false,
            // NB: on this table `status` is a boolean (active/inactive), not an
            // enum — unlike user_subscriptions.status, which is a string.
            'status' => true,
            'on_show' => true,
            'sort_order' => 0,
            'stripe_price_id' => 'price_' . Str::random(14),
            'metadata' => [],
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => ['name' => 'Free', 'price' => 0]);
    }

    /**
     * Plan quotas live in metadata; null or absent means unlimited.
     */
    public function withLimits(?int $maxProducts = null, ?int $maxCoupons = null): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => array_merge($attributes['metadata'] ?? [], array_filter([
                'max_products' => $maxProducts,
                'max_coupons' => $maxCoupons,
            ], fn ($v) => $v !== null)),
        ]);
    }
}
