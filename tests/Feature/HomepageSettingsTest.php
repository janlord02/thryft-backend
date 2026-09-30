<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hero_settings_are_public_and_editable_by_an_admin()
    {
        // The migration seeds the rows; a fresh test database has them.
        $public = $this->getJson('/api/settings/public')->assertStatus(200)->json('data');
        $this->assertSame('Explore nearby', $public['hero_button_label']);
        $this->assertSame('', $public['hero_background']);

        $admin = User::factory()->create(['role' => 'super-admin']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/settings', [
                'settings' => [
                    ['key' => 'hero_title', 'value' => 'Shop small this winter', 'type' => 'string', 'group' => 'homepage'],
                    ['key' => 'hero_background', 'value' => '#7B1F2A', 'type' => 'string', 'group' => 'homepage'],
                    ['key' => 'hero_button_link', 'value' => '/user/search?type=coupons', 'type' => 'string', 'group' => 'homepage'],
                ],
            ])
            ->assertStatus(200);

        $public = $this->getJson('/api/settings/public')->json('data');
        $this->assertSame('Shop small this winter', $public['hero_title']);
        $this->assertSame('#7B1F2A', $public['hero_background']);
        $this->assertSame('/user/search?type=coupons', $public['hero_button_link']);
    }

    public function test_shoppers_cannot_edit_the_hero()
    {
        $shopper = User::factory()->create(['role' => 'user']);

        $this->actingAs($shopper, 'sanctum')
            ->putJson('/api/admin/settings', [
                'settings' => [['key' => 'hero_title', 'value' => 'x', 'type' => 'string', 'group' => 'homepage']],
            ])
            ->assertStatus(403);
    }
}
