<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'starts_at' => now()->addDays(7)->setTime(18, 0),
            'ends_at' => now()->addDays(7)->setTime(20, 0),
            'venue_name' => fake()->company(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'latitude' => 40.7128,
            'longitude' => -74.0060,
            'capacity' => null,
            'registered_count' => 0,
            'registration_enabled' => true,
            'status' => 'published',
            'published_at' => now(),
            'followers_notified_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft', 'published_at' => null, 'followers_notified_at' => null]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(3)->addHours(2),
        ]);
    }

    public function withCapacity(int $capacity, int $taken = 0): static
    {
        return $this->state(fn () => ['capacity' => $capacity, 'registered_count' => $taken]);
    }
}
