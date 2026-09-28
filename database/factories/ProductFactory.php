<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'user_id' => User::factory()->state(['role' => 'business']),
            'category_id' => null,
            'name' => $name,
            // products.slug is unique and the model only slugifies the name, which
            // collides across fake() words; set it explicitly.
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'description' => fake()->sentence(),
            'is_active' => true,
            'is_featured' => false,
            'sort_order' => 0,
        ];
    }
}
