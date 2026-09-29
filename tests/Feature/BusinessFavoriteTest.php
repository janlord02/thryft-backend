<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessFavoriteTest extends TestCase
{
    use RefreshDatabase;

    private function shopper(): User
    {
        return User::factory()->create(['role' => 'user']);
    }

    private function shop(): User
    {
        return User::factory()->create([
            'role' => 'business',
            'business_name' => 'Fernwood Coffee',
            'latitude' => 7.07,
            'longitude' => 125.56,
        ]);
    }

    public function test_shopper_can_save_a_business()
    {
        $shopper = $this->shopper();
        $shop = $this->shop();

        $this->actingAs($shopper)
            ->postJson('/api/businesses/favorite', ['business_id' => $shop->id, 'action' => 'add'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_favorite', true);

        $this->assertDatabaseHas('business_favorites', [
            'user_id' => $shopper->id,
            'business_id' => $shop->id,
        ]);

        // Saving twice is a no-op, not a unique-violation 500.
        $this->actingAs($shopper)
            ->postJson('/api/businesses/favorite', ['business_id' => $shop->id, 'action' => 'add'])
            ->assertStatus(200);

        $this->assertDatabaseCount('business_favorites', 1);
    }

    public function test_saved_business_is_flagged_on_its_page_and_nearby()
    {
        $shopper = $this->shopper();
        $shop = $this->shop();
        $shopper->favoriteBusinesses()->attach($shop->id);

        $this->actingAs($shopper)
            ->getJson("/api/business/{$shop->id}/products")
            ->assertStatus(200)
            ->assertJsonPath('data.business.is_favorite', true);

        $this->actingAs($shopper)
            ->getJson('/api/nearby-businesses?latitude=7.07&longitude=125.56&radius=10')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $shop->id)
            ->assertJsonPath('data.0.is_favorite', true);
    }

    public function test_saved_business_appears_in_favorites_without_a_favorited_product()
    {
        $shopper = $this->shopper();
        $shop = $this->shop();
        Product::factory()->create(['user_id' => $shop->id]);
        $shopper->favoriteBusinesses()->attach($shop->id);

        $this->actingAs($shopper)
            ->getJson('/api/businesses/favorites')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $shop->id);
    }

    public function test_removing_clears_the_save_and_the_products_from_that_shop()
    {
        $shopper = $this->shopper();
        $shop = $this->shop();
        $product = Product::factory()->create(['user_id' => $shop->id]);
        $shopper->favoriteBusinesses()->attach($shop->id);
        $shopper->favoriteProducts()->attach($product->id);

        $this->actingAs($shopper)
            ->postJson('/api/businesses/favorite', ['business_id' => $shop->id, 'action' => 'remove'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_favorite', false);

        $this->assertDatabaseMissing('business_favorites', ['user_id' => $shopper->id]);
        $this->assertDatabaseMissing('product_favorites', ['user_id' => $shopper->id]);
    }
}
