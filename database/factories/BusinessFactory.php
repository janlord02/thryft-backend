<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Business>
 */
class BusinessFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'owner_user_id' => User::factory()->state(['role' => 'business']),
            // Suffixed rather than probed: factories run in tight loops and
            // fake()->company() collides often enough to matter.
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'name' => $name,
            'description' => fake()->sentence(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'status' => 'active',
        ];
    }

    /**
     * Mirrors the backfill migration, which seeds businesses.id = users.id so
     * that pre-existing FK values are already valid business ids.
     */
    public function forOwner(User $owner): static
    {
        return $this->state(fn () => [
            'id' => $owner->id,
            'owner_user_id' => $owner->id,
            'name' => $owner->business_name ?: $owner->name,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
