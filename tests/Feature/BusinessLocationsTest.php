<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Coupon;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-location: one business, several addresses, exactly one main one,
 * and offers and events that can be pinned to a location.
 */
class BusinessLocationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business', 'business_name' => 'Fernwood Coffee']);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create(['name' => 'Fernwood Coffee', 'slug' => 'fernwood-coffee']);
        BusinessMember::factory()->owner()->create(['business_id' => $this->business->id, 'user_id' => $this->owner->id]);
        $this->business->locations()->create(['label' => 'Main', 'address' => '12 Elm St', 'city' => 'Davao', 'is_primary' => true]);
    }

    private function add(array $data)
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/locations', $data + ['label' => 'Downtown']);
    }

    public function test_owner_adds_locations_and_switches_the_main_one()
    {
        $second = $this->add(['address' => '5 Bay Rd', 'city' => 'Cebu', 'latitude' => 10.3, 'longitude' => 123.9, 'hours' => [['label' => 'Daily', 'value' => '8–5']]])
            ->assertStatus(201)->json('data');
        $this->assertFalse($second['is_primary']);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/business/locations/{$second['id']}", ['label' => 'Downtown', 'address' => '5 Bay Rd', 'city' => 'Cebu', 'latitude' => 10.3, 'longitude' => 123.9, 'is_primary' => true])
            ->assertStatus(200);

        $rows = $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/locations')->json('data');
        $this->assertSame(['Downtown', 'Main'], array_column($rows, 'label'));
        $this->assertSame([true, false], array_column($rows, 'is_primary'));
        // Nearby search reads the owner's row, so the main address is mirrored there.
        $this->assertSame('Cebu', $this->owner->fresh()->city);

        // Both show on the public page and in the guest API.
        $html = $this->get('/b/fernwood-coffee')->assertStatus(200)->getContent();
        $this->assertStringContainsString('<h2>Locations</h2>', $html);
        $this->assertStringContainsString('5 Bay Rd', $html);
        $this->getJson('/api/public/businesses/fernwood-coffee')->assertJsonCount(2, 'data.locations');
    }

    public function test_the_main_location_cannot_be_removed()
    {
        $main = $this->business->primaryLocation;
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/business/locations/{$main->id}")->assertStatus(422);

        $other = $this->add([])->json('data');
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/business/locations/{$other['id']}")->assertStatus(200);
    }

    public function test_offers_and_events_can_be_pinned_to_their_own_location_only()
    {
        $downtown = $this->add(['address' => '5 Bay Rd', 'city' => 'Cebu'])->json('data');

        $event = $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/events', [
            'title' => 'Cupping', 'starts_at' => now()->addWeek()->toIso8601String(), 'location_id' => $downtown['id'],
        ])->assertStatus(201)->json('data');
        $this->assertSame('5 Bay Rd', $event['address']);

        $coupon = $this->actingAs($this->owner, 'sanctum')->postJson('/api/coupons', [
            'title' => 'Downtown only', 'discount_type' => 'percentage', 'discount_percentage' => 10, 'location_id' => $downtown['id'],
        ])->assertStatus(201)->json('data');
        $this->assertSame($downtown['id'], Coupon::find($coupon['id'])->location_id);

        // Someone else's location is refused.
        $other = Business::factory()->create();
        $foreign = $other->locations()->create(['label' => 'Theirs', 'is_primary' => true]);
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/coupons', [
            'title' => 'Nope', 'discount_type' => 'percentage', 'discount_percentage' => 10, 'location_id' => $foreign->id,
        ])->assertStatus(422);
    }

    public function test_managers_read_locations_but_cannot_change_them()
    {
        $staff = User::factory()->create();
        BusinessMember::factory()->create(['business_id' => $this->business->id, 'user_id' => $staff->id, 'role' => 'manager']);

        // A manager can see them, to pin an offer to one, but not change them.
        $this->actingAs($staff, 'sanctum')->getJson('/api/business/locations')->assertStatus(200)->assertJsonCount(1, 'data');
        $this->actingAs($staff, 'sanctum')->postJson('/api/business/locations', ['label' => 'Mine'])->assertStatus(403);
    }

    public function test_the_app_business_payload_never_carries_the_owners_login_email()
    {
        $this->business->update(['email' => 'hello@fernwood.test']);

        $business = $this->getJson("/api/business/{$this->owner->id}/products")->assertStatus(200)->json('data.business');
        $this->assertSame('hello@fernwood.test', $business['email']);
        $this->assertNotSame($this->owner->email, $business['email']);
        $this->assertCount(1, $business['locations']);
    }
}
