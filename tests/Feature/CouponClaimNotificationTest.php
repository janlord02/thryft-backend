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
 * The merchant's bell depends on this: a claim must land a notification on
 * the business OWNER, resolved through the business, not on whichever user
 * happens to share the business's id.
 */
class CouponClaimNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_notifies_the_business_owner()
    {
        $owner = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $owner->id]);
        $business = Business::factory()->forOwner($owner)->create();
        BusinessMember::factory()->owner()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
        ]);
        $coupon = Coupon::factory()->create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
        ]);
        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/coupons/claim', ['coupon_id' => $coupon->id])
            ->assertStatus(200);

        $this->assertDatabaseHas('notification_user', ['user_id' => $owner->id]);
        $this->assertDatabaseMissing('notification_user', ['user_id' => $customer->id]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/notifications/user/stats')
            ->assertStatus(200)
            ->assertJsonPath('data.unread', 1);

        $list = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/notifications?limit=8')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $list);
        $this->assertSame($coupon->id, $list[0]['data']['coupon_id']);
        $this->assertSame(0, (int) $list[0]['pivot']['read']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/notifications/{$list[0]['id']}/read")
            ->assertStatus(200);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/notifications/user/stats')
            ->assertJsonPath('data.unread', 0);
    }
}
