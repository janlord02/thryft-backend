<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\ShopperAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two nudges that bring shoppers back: a saved business publishes a new
 * offer, and a claimed coupon is about to expire. Both must fire exactly once.
 */
class RetentionAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business', 'business_name' => 'Fernwood Coffee']);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create(['name' => 'Fernwood Coffee']);
        BusinessMember::factory()->owner()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
        ]);
    }

    private function createCoupon(array $overrides = [])
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/coupons', array_merge([
            'title' => 'Free pastry with any latte',
            'discount_type' => 'percentage',
            'discount_percentage' => 15,
        ], $overrides));
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

    public function test_publishing_a_coupon_alerts_shoppers_who_saved_the_business()
    {
        $savedBusiness = User::factory()->create();
        $savedBusiness->favoriteBusinesses()->attach($this->owner->id);

        $product = Product::factory()->create(['user_id' => $this->owner->id, 'business_id' => $this->business->id]);
        $savedProduct = User::factory()->create();
        $savedProduct->favoriteProducts()->attach($product->id);

        $stranger = User::factory()->create();

        $response = $this->createCoupon()->assertStatus(201);
        $couponId = $response->json('data.id');

        foreach ([$savedBusiness, $savedProduct] as $shopper) {
            $alerts = $this->notificationsFor($shopper);
            $this->assertCount(1, $alerts, "shopper {$shopper->id} should be told once");
            $this->assertSame('new_offer', $alerts[0]['kind']);
            $this->assertSame($couponId, $alerts[0]['coupon_id']);
            $this->assertSame("/user/business/{$this->owner->id}", $alerts[0]['action_url']);
            $this->assertSame('New offer at Fernwood Coffee', $alerts[0]['title']);
        }

        $this->assertCount(0, $this->notificationsFor($stranger));
        $this->assertCount(0, $this->notificationsFor($this->owner));
        $this->assertNotNull(Coupon::find($couponId)->followers_notified_at);

        // Editing the offer later must not announce it again.
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/coupons/{$couponId}", [
                'title' => 'Free pastry with any latte (updated)',
                'discount_type' => 'percentage',
                'discount_percentage' => 20,
            ])
            ->assertStatus(200);

        $this->assertCount(1, $this->notificationsFor($savedBusiness));
    }

    public function test_a_draft_coupon_is_announced_when_it_is_switched_on()
    {
        $shopper = User::factory()->create();
        $shopper->favoriteBusinesses()->attach($this->owner->id);

        $couponId = $this->createCoupon(['is_active' => false])->assertStatus(201)->json('data.id');

        $this->assertCount(0, $this->notificationsFor($shopper));
        $this->assertNull(Coupon::find($couponId)->followers_notified_at);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/coupons/{$couponId}", [
                'title' => 'Free pastry with any latte',
                'discount_type' => 'percentage',
                'discount_percentage' => 15,
                'is_active' => true,
            ])
            ->assertStatus(200);

        $this->assertCount(1, $this->notificationsFor($shopper));
    }

    public function test_an_offer_with_no_followers_is_still_marked_announced()
    {
        $couponId = $this->createCoupon()->assertStatus(201)->json('data.id');

        $this->assertNotNull(Coupon::find($couponId)->followers_notified_at);
    }

    public function test_shoppers_are_reminded_once_about_coupons_expiring_soon()
    {
        $coupon = Coupon::factory()->create([
            'user_id' => $this->owner->id,
            'business_id' => $this->business->id,
            'title' => 'Free pastry with any latte',
        ]);

        $soon = User::factory()->create();
        $tomorrowClaim = ClaimedCoupon::factory()->forCoupon($coupon, $soon)->create([
            'expires_at' => now()->addDay()->setTime(18, 0),
        ]);

        $later = User::factory()->create();
        ClaimedCoupon::factory()->forCoupon($coupon, $later)->create([
            'expires_at' => now()->addDays(10),
        ]);

        $alreadyUsed = User::factory()->create();
        ClaimedCoupon::factory()->forCoupon($coupon, $alreadyUsed)->used()->create([
            'expires_at' => now()->addDay(),
        ]);

        $lapsed = User::factory()->create();
        ClaimedCoupon::factory()->forCoupon($coupon, $lapsed)->create([
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('coupons:remind-expiring')
            ->expectsOutputToContain('Sent 1 expiring-coupon reminder(s).')
            ->assertSuccessful();

        $reminders = $this->notificationsFor($soon);
        $this->assertCount(1, $reminders);
        $this->assertSame('expiring_coupon', $reminders[0]['kind']);
        $this->assertSame($tomorrowClaim->id, $reminders[0]['coupon_id']);
        $this->assertSame('/user/coupons', $reminders[0]['action_url']);
        $this->assertSame('Your coupon expires tomorrow', $reminders[0]['title']);
        $this->assertNotNull($tomorrowClaim->fresh()->expiry_reminded_at);

        foreach ([$later, $alreadyUsed, $lapsed] as $shopper) {
            $this->assertCount(0, $this->notificationsFor($shopper));
        }

        // The next day's run finds nothing new for the same claim.
        $this->assertSame(0, app(ShopperAlerts::class)->remindExpiringClaims());
        $this->assertCount(1, $this->notificationsFor($soon));
    }
}
