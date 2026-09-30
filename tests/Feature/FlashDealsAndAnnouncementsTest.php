<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Flash deals ride on coupons (an end time and a claim cap); announcements
 * are plain updates on the business page. Both alert followers once.
 */
class FlashDealsAndAnnouncementsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'business',
            'business_name' => 'Fernwood Coffee',
            'latitude' => 7.06,
            'longitude' => 125.55,
        ]);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create(['name' => 'Fernwood Coffee', 'slug' => 'fernwood-coffee']);
        BusinessMember::factory()->owner()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
        ]);
    }

    private function notificationsFor(User $user): array
    {
        return DB::table('notification_user')
            ->join('notifications', 'notifications.id', '=', 'notification_user.notification_id')
            ->where('notification_user.user_id', $user->id)
            ->get()
            ->map(fn ($row) => json_decode($row->data, true) + ['title' => $row->title])
            ->all();
    }

    // -----------------------------------------------------------------
    // Flash deals
    // -----------------------------------------------------------------

    public function test_a_flash_deal_needs_an_end_time_and_keeps_its_claim_cap()
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/coupons', [
            'title' => 'Happy hour lattes',
            'discount_type' => 'percentage',
            'discount_percentage' => 30,
            'is_flash' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['expires_at']);

        $follower = User::factory()->create();
        $follower->favoriteBusinesses()->attach($this->owner->id);

        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/coupons', [
            'title' => 'Happy hour lattes',
            'discount_type' => 'percentage',
            'discount_percentage' => 30,
            'is_flash' => true,
            'claim_limit' => 5,
            'expires_at' => now()->addHours(3)->toIso8601String(),
        ])->assertStatus(201)->json('data.id');

        $coupon = Coupon::find($id);
        $this->assertTrue($coupon->is_flash);
        $this->assertSame(5, $coupon->claim_limit);

        $alerts = $this->notificationsFor($follower);
        $this->assertCount(1, $alerts);
        $this->assertSame('Flash deal at Fernwood Coffee', $alerts[0]['title']);
    }

    public function test_nearby_flash_deals_list_only_live_claimable_ones()
    {
        $live = Coupon::factory()->create([
            'user_id' => $this->owner->id, 'business_id' => $this->business->id,
            'title' => 'Live flash', 'is_flash' => true, 'claim_limit' => 10, 'claimed_count' => 3,
            'expires_at' => now()->addHours(2),
        ]);
        Coupon::factory()->create([
            'user_id' => $this->owner->id, 'business_id' => $this->business->id,
            'title' => 'Sold out', 'is_flash' => true, 'claim_limit' => 3, 'claimed_count' => 3,
            'expires_at' => now()->addHours(2),
        ]);
        Coupon::factory()->create([
            'user_id' => $this->owner->id, 'business_id' => $this->business->id,
            'title' => 'Already over', 'is_flash' => true, 'expires_at' => now()->subMinute(),
        ]);
        Coupon::factory()->create([
            'user_id' => $this->owner->id, 'business_id' => $this->business->id,
            'title' => 'Regular coupon', 'is_flash' => false,
        ]);

        $farOwner = User::factory()->create(['role' => 'business', 'latitude' => 40.7, 'longitude' => -74.0]);
        $far = Business::factory()->forOwner($farOwner)->create();
        Coupon::factory()->create([
            'user_id' => $farOwner->id, 'business_id' => $far->id,
            'title' => 'Far flash', 'is_flash' => true, 'expires_at' => now()->addHours(2),
        ]);

        $rows = $this->getJson('/api/flash-deals?latitude=7.06&longitude=125.55&radius=10')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(['Live flash'], array_column($rows, 'title'));
        $this->assertSame(7, $rows[0]['remaining']);
        $this->assertSame('Fernwood Coffee', $rows[0]['business_name']);
        $this->assertFalse($rows[0]['is_claimed_by_user']);
        $this->assertArrayNotHasKey('code', $rows[0]);

        $shopper = User::factory()->create();
        ClaimedCoupon::factory()->forCoupon($live, $shopper)->create();
        $mine = $this->actingAs($shopper, 'sanctum')->getJson('/api/flash-deals')->json('data');
        $this->assertTrue(collect($mine)->firstWhere('id', $live->id)['is_claimed_by_user']);

        $all = $this->getJson('/api/flash-deals')->json('data');
        $this->assertEqualsCanonicalizing(['Live flash', 'Far flash'], array_column($all, 'title'));
    }

    // -----------------------------------------------------------------
    // Announcements
    // -----------------------------------------------------------------

    public function test_posting_an_announcement_shows_it_on_the_business_and_alerts_followers_once()
    {
        $follower = User::factory()->create();
        $follower->favoriteBusinesses()->attach($this->owner->id);

        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/announcements', [
            'title' => 'New winter menu',
            'body' => 'Cardamom buns are back, and the peppermint latte returns Friday.',
        ])->assertStatus(201)->assertJsonPath('data.status', 'published')->json('data.id');

        $alerts = $this->notificationsFor($follower);
        $this->assertCount(1, $alerts);
        $this->assertSame('News from Fernwood Coffee', $alerts[0]['title']);
        $this->assertSame("/user/business/{$this->owner->id}?tab=updates", $alerts[0]['action_url']);

        // Guests read it on the business, by owner id or business id.
        $this->getJson("/api/business/{$this->owner->id}/announcements")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'New winter menu');

        // And on the crawlable page.
        $html = $this->get('/b/fernwood-coffee')->assertStatus(200)->getContent();
        $this->assertStringContainsString('New winter menu', $html);

        // Editing does not alert again; deleting removes it.
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/business/announcements/{$id}", ['title' => 'New winter menu (updated)'])
            ->assertStatus(200);
        $this->assertCount(1, $this->notificationsFor($follower));

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/business/announcements/{$id}")->assertStatus(200);
        $this->getJson("/api/business/{$this->owner->id}/announcements")->assertJsonCount(0, 'data');
    }

    public function test_drafts_stay_private_and_staff_cannot_post()
    {
        $follower = User::factory()->create();
        $follower->favoriteBusinesses()->attach($this->owner->id);

        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/announcements', [
            'title' => 'Not yet',
            'status' => 'draft',
        ])->assertStatus(201)->json('data.id');

        $this->assertCount(0, $this->notificationsFor($follower));
        $this->getJson("/api/business/{$this->owner->id}/announcements")->assertJsonCount(0, 'data');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/business/announcements/{$id}", ['title' => 'Now yes', 'status' => 'published'])
            ->assertStatus(200);
        $this->assertCount(1, $this->notificationsFor($follower));
        $this->getJson("/api/business/{$this->owner->id}/announcements")->assertJsonCount(1, 'data');

        $staff = User::factory()->create();
        BusinessMember::factory()->staff()->create(['business_id' => $this->business->id, 'user_id' => $staff->id]);
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/business/announcements', ['title' => 'Nope'])
            ->assertStatus(403);

        $otherOwner = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $otherOwner->id]);
        $other = Business::factory()->forOwner($otherOwner)->create();
        BusinessMember::factory()->owner()->create(['business_id' => $other->id, 'user_id' => $otherOwner->id]);
        $this->actingAs($otherOwner, 'sanctum')->deleteJson("/api/business/announcements/{$id}")->assertStatus(404);
        $this->assertNotNull(Announcement::find($id));
    }
}
