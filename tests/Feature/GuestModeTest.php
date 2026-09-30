<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guest mode in the app: the browse endpoints answer without a token, and
 * with one they carry the shopper's own favorites and claims. Anything tied
 * to an identity still needs an account.
 */
class GuestModeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;
    private Product $product;
    private Coupon $coupon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'business',
            'business_name' => 'Fernwood Coffee',
            'latitude' => 40.7128,
            'longitude' => -74.0060,
        ]);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create(['name' => 'Fernwood Coffee']);
        BusinessMember::factory()->owner()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
        ]);
        $this->product = Product::factory()->create([
            'user_id' => $this->owner->id,
            'business_id' => $this->business->id,
        ]);
        $this->coupon = Coupon::factory()->create([
            'user_id' => $this->owner->id,
            'business_id' => $this->business->id,
        ]);
    }

    public function test_a_guest_can_browse_nearby_businesses_and_a_business_page()
    {
        $nearby = $this->getJson('/api/nearby-businesses?latitude=40.7128&longitude=-74.0060&radius=10')
            ->assertStatus(200)
            ->json('data');

        $this->assertNotEmpty($nearby);
        $row = collect($nearby)->firstWhere('id', $this->owner->id);
        $this->assertNotNull($row, 'the seeded business should be in the nearby list');
        $this->assertFalse($row['is_favorite']);

        $this->getJson("/api/business/{$this->owner->id}/products")
            ->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    public function test_the_same_endpoints_personalise_for_a_signed_in_shopper()
    {
        $shopper = User::factory()->create();
        $shopper->favoriteBusinesses()->attach($this->owner->id);

        $nearby = $this->actingAs($shopper, 'sanctum')
            ->getJson('/api/nearby-businesses?latitude=40.7128&longitude=-74.0060&radius=10')
            ->assertStatus(200)
            ->json('data');

        $row = collect($nearby)->firstWhere('id', $this->owner->id);
        $this->assertTrue($row['is_favorite']);
    }

    public function test_identity_bound_actions_still_require_an_account()
    {
        $this->postJson('/api/coupons/claim', ['coupon_id' => $this->coupon->id])->assertStatus(401);
        $this->postJson('/api/businesses/favorite', ['business_id' => $this->owner->id, 'action' => 'add'])->assertStatus(401);
        $this->postJson('/api/products/favorite', ['product_id' => $this->product->id, 'action' => 'add'])->assertStatus(401);
        $this->getJson('/api/coupons/claimed')->assertStatus(401);
        $this->getJson('/api/profile')->assertStatus(401);
    }
}
