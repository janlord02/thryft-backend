<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BusinessMember>
 */
class BusinessMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'role' => 'staff',
            'permissions' => null,
            'status' => 'active',
            'invited_at' => now(),
            'accepted_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => 'owner']);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => 'manager']);
    }

    public function staff(): static
    {
        return $this->state(fn () => ['role' => 'staff']);
    }

    public function invited(): static
    {
        return $this->state(fn () => ['status' => 'invited', 'accepted_at' => null]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => 'revoked']);
    }
}
