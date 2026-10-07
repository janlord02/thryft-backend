<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Coupon;
use App\Models\GiftCertificate;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gift certificates and memberships, both paid for at the business and
 * both shown at the till by code.
 */
class GiftsAndMembershipsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $staff;
    private User $shopper;
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business', 'business_name' => 'Fernwood Coffee']);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create(['name' => 'Fernwood Coffee']);
        BusinessMember::factory()->owner()->create(['business_id' => $this->business->id, 'user_id' => $this->owner->id]);

        $this->staff = User::factory()->create();
        BusinessMember::factory()->create(['business_id' => $this->business->id, 'user_id' => $this->staff->id, 'role' => 'staff']);

        $this->shopper = User::factory()->create(['email' => 'sam@example.com', 'email_verified_at' => now()]);
    }

    private function till(string $code, array $body = [])
    {
        return $body
            ? $this->actingAs($this->staff, 'sanctum')->postJson("/api/business/till/{$code}", $body)
            : $this->actingAs($this->staff, 'sanctum')->getJson("/api/business/till/{$code}");
    }

    // -----------------------------------------------------------------
    // Gift certificates
    // -----------------------------------------------------------------

    public function test_an_issued_certificate_reaches_the_recipient_and_is_spent_down_at_the_till()
    {
        $gift = $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/gift-certificates', [
            'amount' => 50, 'recipient_email' => 'SAM@example.com', 'recipient_name' => 'Sam', 'from_name' => 'Mum',
        ])->assertStatus(201)->json('data');
        $this->assertMatchesRegularExpression('/^GIFT-/', $gift['code']);
        $this->assertSame($this->shopper->id, $gift['recipient_user_id']);

        $wallet = $this->actingAs($this->shopper, 'sanctum')->getJson('/api/my/wallet')->json('data.gifts');
        $this->assertSame(5000, $wallet[0]['balance_cents']);
        $this->assertSame('Mum', $wallet[0]['from_name']);

        $this->till($gift['code'], ['action' => 'use', 'amount_cents' => 1250])->assertStatus(200)->assertJsonPath('data.balance_cents', 3750);
        $this->till($gift['code'], ['action' => 'use', 'amount_cents' => 5000])->assertStatus(422);
        $this->till($gift['code'], ['action' => 'use', 'amount_cents' => 3750])->assertStatus(200);
        $this->till($gift['code'])->assertJsonPath('data.actions', []);

        // Fully used: it drops out of the wallet.
        $this->assertCount(0, $this->actingAs($this->shopper, 'sanctum')->getJson('/api/my/wallet')->json('data.gifts'));
    }

    public function test_a_shopper_request_waits_for_payment_then_goes_to_the_person_it_is_for()
    {
        $this->actingAs($this->shopper, 'sanctum')
            ->postJson("/api/business/{$this->owner->id}/gift-certificates", ['amount' => 25])
            ->assertStatus(404); // not selling yet

        $this->actingAs($this->owner, 'sanctum')->putJson('/api/business/gift-certificates/settings', ['sells_gift_certificates' => true])->assertStatus(200);

        $request = $this->actingAs($this->shopper, 'sanctum')->postJson("/api/business/{$this->owner->id}/gift-certificates", [
            'amount' => 25, 'recipient_email' => 'friend@example.com', 'recipient_name' => 'Fran', 'message' => 'Happy birthday',
        ])->assertStatus(201)->json('data');
        $this->assertSame('pending_payment', $request['status']);

        // It sits in the buyer's wallet to show at the counter, and cannot be spent yet.
        $this->assertSame($request['code'], $this->actingAs($this->shopper, 'sanctum')->getJson('/api/my/wallet')->json('data.gifts.0.code'));
        $this->till($request['code'], ['action' => 'use', 'amount_cents' => 100])->assertStatus(422);

        $this->till($request['code'], ['action' => 'paid'])->assertStatus(200);
        $this->till($request['code'], ['action' => 'paid'])->assertStatus(422);
        $this->assertCount(0, $this->actingAs($this->shopper, 'sanctum')->getJson('/api/my/wallet')->json('data.gifts'));

        // Fran signs up later with that address and finds it waiting.
        $fran = User::factory()->create(['email' => 'friend@example.com', 'email_verified_at' => now()]);
        $gifts = $this->actingAs($fran, 'sanctum')->getJson('/api/my/wallet')->json('data.gifts');
        $this->assertSame(2500, $gifts[0]['balance_cents']);
        $this->assertSame('Happy birthday', $gifts[0]['message']);
    }

    public function test_an_unverified_account_cannot_pick_up_someone_elses_certificate()
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/gift-certificates', ['amount' => 20, 'recipient_email' => 'later@example.com'])->assertStatus(201);
        $squatter = User::factory()->create(['email' => 'later@example.com', 'email_verified_at' => null]);

        $this->assertCount(0, $this->actingAs($squatter, 'sanctum')->getJson('/api/my/wallet')->json('data.gifts'));
        $this->assertNull(GiftCertificate::sole()->recipient_user_id);
    }

    public function test_a_cancelled_certificate_cannot_be_spent()
    {
        $gift = $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/gift-certificates', ['amount' => 20])->json('data');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/business/gift-certificates/{$gift['id']}/void")->assertStatus(200);

        $this->till($gift['code'], ['action' => 'use', 'amount_cents' => 100])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Memberships
    // -----------------------------------------------------------------

    private function plan(): array
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/memberships/plans', [
            'name' => 'Coffee Club', 'benefits' => '10% off every drink', 'price_label' => '$15 a month', 'duration_days' => 30,
        ])->assertStatus(201)->json('data');
    }

    public function test_members_only_offers_need_a_current_membership()
    {
        $plan = $this->plan();
        $coupon = Coupon::factory()->create(['user_id' => $this->owner->id, 'business_id' => $this->business->id, 'members_only' => true]);

        $this->actingAs($this->shopper, 'sanctum')->postJson('/api/coupons/claim', ['coupon_id' => $coupon->id])
            ->assertStatus(403)->assertJsonPath('code', 'members_only');

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/business/memberships/plans/{$plan['id']}/members", ['email' => 'sam@example.com'])->assertStatus(201);

        $this->actingAs($this->shopper, 'sanctum')->postJson('/api/coupons/claim', ['coupon_id' => $coupon->id])->assertSuccessful();
    }

    public function test_renewing_extends_from_the_current_end_and_the_till_shows_status()
    {
        $plan = $this->plan();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/business/memberships/plans/{$plan['id']}/members", ['email' => 'sam@example.com']);
        $first = Membership::sole()->ends_at;

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/business/memberships/plans/{$plan['id']}/members", ['email' => 'sam@example.com']);
        $this->assertTrue(Membership::sole()->ends_at->equalTo($first->copy()->addDays(30)));

        $code = Membership::sole()->code;
        $this->till($code)->assertJsonPath('data.is_active', true)->assertJsonPath('data.benefits', '10% off every drink');

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/memberships/' . Membership::sole()->id . '/cancel');
        $this->till($code)->assertJsonPath('data.is_active', false);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/business/{$this->owner->id}/memberships")->assertJsonPath('data.plans.0.price_label', '$15 a month');
    }

    public function test_enrolling_needs_an_existing_account()
    {
        $plan = $this->plan();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/business/memberships/plans/{$plan['id']}/members", ['email' => 'nobody@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
