<?php

namespace Tests\Feature;

use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponClaimTest extends TestCase
{
    use RefreshDatabase;

    private function claim(User $customer, array $payload)
    {
        return $this->actingAs($customer, 'sanctum')->postJson('/api/coupons/claim', $payload);
    }

    public function test_customer_can_claim_a_coupon()
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->create();

        $response = $this->claim($customer, ['coupon_id' => $coupon->id]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('claimed_coupons', [
            'user_id' => $customer->id,
            'coupon_id' => $coupon->id,
            'business_id' => $coupon->user_id,
            'status' => 'claimed',
        ]);

        $coupon->refresh();
        $this->assertSame(1, $coupon->claimed_count);
        // Claiming must not count as a redemption — the bug this phase fixes.
        $this->assertSame(0, $coupon->redeemed_count);
    }

    public function test_second_claim_by_same_user_is_rejected_and_does_not_move_the_counter()
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->create();

        $this->claim($customer, ['coupon_id' => $coupon->id])->assertStatus(200);

        $response = $this->claim($customer, ['coupon_id' => $coupon->id]);

        $response->assertStatus(409)->assertJsonPath('code', 'already_claimed');

        $this->assertSame(1, ClaimedCoupon::where('user_id', $customer->id)->count());
        $this->assertSame(1, $coupon->fresh()->claimed_count);
    }

    public function test_claim_is_rejected_once_the_claim_cap_is_reached_and_writes_nothing()
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->claimCapReached(1)->create();

        $response = $this->claim($customer, ['coupon_id' => $coupon->id]);

        $response->assertStatus(400);

        $this->assertDatabaseCount('claimed_coupons', 0);
        $this->assertSame(1, $coupon->fresh()->claimed_count);
    }

    /**
     * Guards the transaction itself: if the cap is consumed after the advisory
     * check has passed, the inserted claim must be rolled back rather than left
     * orphaned. Simulated by letting the advisory check see room and then
     * removing it before the conditional UPDATE runs.
     */
    public function test_claim_row_is_rolled_back_when_the_cap_is_taken_mid_request()
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->create(['claim_limit' => 1, 'claimed_count' => 0]);

        // Simulate a competing claim landing in the window between this
        // request's advisory check and its conditional counter update. Hooking
        // the created event is the only way to hit that window deterministically
        // from a feature test — mutating the row beforehand would just trip the
        // advisory check and never reach the transaction.
        ClaimedCoupon::created(function () use ($coupon) {
            Coupon::whereKey($coupon->id)->update(['claimed_count' => 1]);
        });

        $response = $this->claim($customer, ['coupon_id' => $coupon->id]);

        $response->assertStatus(409)->assertJsonPath('code', 'claim_limit_reached');

        // The insert happens before the counter bump, so an empty table here is
        // proof the transaction rolled back rather than leaving an orphan claim.
        $this->assertDatabaseCount('claimed_coupons', 0);
    }

    public function test_product_from_another_business_is_rejected()
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->create();
        $foreignProduct = Product::factory()->create();

        $response = $this->claim($customer, [
            'coupon_id' => $coupon->id,
            'product_id' => $foreignProduct->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['product_id']);
        $this->assertDatabaseCount('claimed_coupons', 0);
    }

    public function test_product_belonging_to_the_business_is_accepted_for_a_store_wide_coupon()
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->create();
        $ownProduct = Product::factory()->create(['user_id' => $coupon->user_id]);

        $this->claim($customer, [
            'coupon_id' => $coupon->id,
            'product_id' => $ownProduct->id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('claimed_coupons', ['product_id' => $ownProduct->id]);
    }

    public function test_product_outside_an_explicit_coupon_product_list_is_rejected()
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->create();

        $listed = Product::factory()->create(['user_id' => $coupon->user_id]);
        $unlisted = Product::factory()->create(['user_id' => $coupon->user_id]);
        $coupon->products()->attach($listed->id);

        // Same business, but the coupon names specific products and this is not one.
        $this->claim($customer, [
            'coupon_id' => $coupon->id,
            'product_id' => $unlisted->id,
        ])->assertStatus(422);

        $this->claim($customer, [
            'coupon_id' => $coupon->id,
            'product_id' => $listed->id,
        ])->assertStatus(200);
    }

    public function test_expired_coupon_cannot_be_claimed()
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->expired()->create();

        $this->claim($customer, ['coupon_id' => $coupon->id])->assertStatus(400);
        $this->assertDatabaseCount('claimed_coupons', 0);
    }

    public function test_inactive_coupon_cannot_be_claimed()
    {
        $customer = User::factory()->create();
        $coupon = Coupon::factory()->inactive()->create();

        $this->claim($customer, ['coupon_id' => $coupon->id])->assertStatus(404);
        $this->assertDatabaseCount('claimed_coupons', 0);
    }

    public function test_coupon_id_is_validated()
    {
        $customer = User::factory()->create();

        $this->claim($customer, [])->assertStatus(422)->assertJsonValidationErrors(['coupon_id']);
        $this->claim($customer, ['coupon_id' => 999999])->assertStatus(422);
    }
}
