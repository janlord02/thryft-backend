<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'link_url' => null,
            'status' => 'published',
            'published_at' => now(),
            'followers_notified_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft', 'published_at' => null, 'followers_notified_at' => null]);
    }
}
