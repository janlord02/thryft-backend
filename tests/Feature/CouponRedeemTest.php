<?php

namespace Tests\Feature;

use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CouponRedeemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // CouponStatusChanged implements ShouldBroadcast and would otherwise try
        // to reach Pusher.
        Event::fake();
    }

    private function redeem(User $actor, $claimedCouponId)
    {
        return $this->actingAs($actor, 'sanctum')
            ->postJson('/api/coupons/mark-as-used', ['claimedCouponId' => $claimedCouponId]);
    }

    /**
     * @return array{0: User, 1: Coupon, 2: ClaimedCoupon}
     */
    private function scenario(array $couponState = [], array $claimState = []): array
    {
        $coupon = Coupon::factory()->create($couponState);
        $business = User::find($coupon->user_id);
        $customer = User::factory()->create();

        $claim = ClaimedCoupon::factory()
            ->forCoupon($coupon, $customer)
            ->create($claimState);

        return [$business, $coupon, $claim];
    }

    public function test_business_can_redeem_a_claimed_coupon()
    {
        [$business, $coupon, $claim] = $this->scenario();

        $response = $this->redeem($business, $claim->id);

        $response->assertStatus(200)->assertJsonPath('already_redeemed_by_you', false);

        $claim->refresh();
        $this->assertSame('used', $claim->status);
        $this->assertNotNull($claim->used_at);
        $this->assertSame($business->id, (int) $claim->redeemed_by_user_id);

        $this->assertSame(1, $coupon->fresh()->redeemed_count);
    }

    public function test_expired_claim_cannot_be_redeemed()
    {
        // The snapshot on the claim is what governs, so expire that.
        [$business, $coupon, $claim] = $this->scenario([], ['expires_at' => now()->subDay()]);

        $response = $this->redeem($business, $claim->id);

        $response->assertStatus(409)->assertJsonPath('code', 'coupon_expired');

        $this->assertSame('claimed', $claim->fresh()->status);
        $this->assertSame(0, $coupon->fresh()->redeemed_count);
    }

    public function test_withdrawn_offer_cannot_be_redeemed_and_is_distinguishable_from_expiry()
    {
        [$business, $coupon, $claim] = $this->scenario(['is_active' => false]);

        $response = $this->redeem($business, $claim->id);

        $response->assertStatus(409)->assertJsonPath('code', 'offer_withdrawn');
        $this->assertSame('claimed', $claim->fresh()->status);
    }

    public function test_immediate_retry_by_the_same_operator_is_idempotent()
    {
        [$business, $coupon, $claim] = $this->scenario();

        $this->redeem($business, $claim->id)->assertStatus(200);

        // A dropped response makes the till retry; that must not read as fraud.
        $retry = $this->redeem($business, $claim->id);

        $retry->assertStatus(200)->assertJsonPath('already_redeemed_by_you', true);

        // Critically, the counter must not move twice.
        $this->assertSame(1, $coupon->fresh()->redeemed_count);
    }

    public function test_retry_after_the_idempotency_window_is_a_conflict()
    {
        [$business, $coupon, $claim] = $this->scenario();

        $this->redeem($business, $claim->id)->assertStatus(200);

        $this->travel(61)->seconds();

        $this->redeem($business, $claim->id)
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_redeemed');

        $this->assertSame(1, $coupon->fresh()->redeemed_count);
    }

    public function test_another_business_cannot_redeem_someone_elses_claim()
    {
        [$business, $coupon, $claim] = $this->scenario();
        $otherBusiness = User::factory()->create(['role' => 'business']);

        $this->redeem($otherBusiness, $claim->id)
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_redeemable');

        $this->assertSame('claimed', $claim->fresh()->status);
    }

    /**
     * The transaction test that matters: if the redemption cap is exhausted
     * after the status has already flipped, the flip must be rolled back so the
     * claim stays redeemable rather than being burned for nothing.
     */
    public function test_status_change_is_rolled_back_when_the_redemption_cap_is_full()
    {
        [$business, $coupon, $claim] = $this->scenario([
            'usage_limit' => 1,
            'redeemed_count' => 1,
        ]);

        $response = $this->redeem($business, $claim->id);

        $response->assertStatus(409)->assertJsonPath('code', 'redemption_limit_reached');

        $claim->refresh();
        $this->assertSame('claimed', $claim->status, 'The claim must remain redeemable after a rolled-back redemption.');
        $this->assertNull($claim->used_at);
        $this->assertNull($claim->redeemed_by_user_id);
    }

    public function test_cancelled_claim_cannot_be_redeemed()
    {
        [$business, $coupon, $claim] = $this->scenario([], ['status' => 'cancelled']);

        $this->redeem($business, $claim->id)
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_redeemable');

        $this->assertSame(0, $coupon->fresh()->redeemed_count);
    }

    public function test_claimed_coupon_id_is_validated()
    {
        $business = User::factory()->create(['role' => 'business']);

        $this->redeem($business, null)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['claimedCouponId']);
    }
}
