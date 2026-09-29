<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\UserSubscription>
 */
class UserSubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $periodEnd = now()->addMonth();

        return [
            'user_id' => User::factory()->state(['role' => 'business']),
            'subscription_id' => Subscription::factory(),
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => $periodEnd,
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => false,
            'grace_ends_at' => null,
            'cancelled_at' => null,
            'amount_paid' => 29.00,
            'payment_method' => 'stripe_subscription',
            'transaction_id' => 'sub_' . Str::random(14),
            'stripe_subscription_id' => 'sub_' . Str::random(14),
            'stripe_price_id' => 'price_' . Str::random(14),
            'subscription_data' => [],
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'ends_at' => now()->subDay(),
            'current_period_end' => now()->subDay(),
        ]);
    }

    /**
     * Failed payment, still inside the grace window — must still grant access.
     */
    public function pastDueInGrace(): static
    {
        return $this->state(fn () => [
            'status' => 'past_due',
            'grace_ends_at' => now()->addDays(3),
        ]);
    }

    public function pastDueGraceLapsed(): static
    {
        return $this->state(fn () => [
            'status' => 'past_due',
            'grace_ends_at' => now()->subDay(),
        ]);
    }
}
