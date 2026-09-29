<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Staff working the till.
 *
 * The till endpoints used to query where('business_id', $user->id), which only
 * worked because the Phase 3a backfill seeded businesses.id from users.id — so
 * for an OWNER the two coincide. They do not coincide for a staff member, nor
 * for any business created after the backfill (those get ids from 1,000,000
 * up). The result was that staff accounts, the entire point of Phase 3b, got
 * a 404 on every scan.
 */
class StaffTillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Business, 1: User, 2: Coupon}
     */
    private function shop(): array
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

        return [$business, $owner, $coupon];
    }

    private function staffOf(Business $business): User
    {
        $staff = User::factory()->create();

        BusinessMember::factory()->staff()->create([
            'business_id' => $business->id,
            'user_id' => $staff->id,
        ]);

        return $staff;
    }

    public function test_staff_can_redeem_for_the_business_they_work_for()
    {
        Event::fake();

        [$business, , $coupon] = $this->shop();
        $staff = $this->staffOf($business);
        $claim = ClaimedCoupon::factory()->forCoupon($coupon)->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/coupons/mark-as-used', ['claimedCouponId' => $claim->id])
            ->assertStatus(200);

        $claim->refresh();
        $this->assertSame('used', $claim->status);
        // Attribution is the person, even though authority is the business.
        $this->assertSame($staff->id, (int) $claim->redeemed_by_user_id);
    }

    public function test_staff_can_look_a_customer_up_by_coupon_code()
    {
        [$business, , $coupon] = $this->shop();
        $staff = $this->staffOf($business);
        ClaimedCoupon::factory()->forCoupon($coupon)->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/coupons/validate-manual', ['couponCode' => $coupon->code])
            ->assertStatus(200);
    }

    public function test_staff_cannot_redeem_for_a_business_they_do_not_work_for()
    {
        Event::fake();

        [$mine] = $this->shop();
        [, , $theirCoupon] = $this->shop();

        $staff = $this->staffOf($mine);
        $theirClaim = ClaimedCoupon::factory()->forCoupon($theirCoupon)->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/coupons/mark-as-used', ['claimedCouponId' => $theirClaim->id])
            ->assertStatus(404);

        $this->assertSame('claimed', $theirClaim->fresh()->status);
    }

    public function test_owner_redemption_still_works()
    {
        // The path that worked before, which must not regress.
        Event::fake();

        [, $owner, $coupon] = $this->shop();
        $claim = ClaimedCoupon::factory()->forCoupon($coupon)->create();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/coupons/mark-as-used', ['claimedCouponId' => $claim->id])
            ->assertStatus(200);
    }

    public function test_scan_ownership_check_compares_against_the_business_not_the_user()
    {
        [$business, , $coupon] = $this->shop();
        $staff = $this->staffOf($business);
        $claim = ClaimedCoupon::factory()->forCoupon($coupon)->create();

        // The QR carries the BUSINESS id. Comparing it to the staff member's
        // own user id would always fail.
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/coupons/validate-scan', [
                'claimedCouponId' => $claim->id,
                'couponCode' => $claim->coupon_code,
                'userId' => $claim->user_id,
                'businessId' => $business->id,
            ])
            ->assertStatus(200);
    }
}
