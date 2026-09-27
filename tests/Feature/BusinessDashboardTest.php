<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The merchant dashboard and the onboarding checklist.
 *
 * Until now the only "dashboard" was platform-wide user counts, which tells a
 * business owner nothing about whether Thryft brings them customers.
 */
class BusinessDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Business, 1: User}
     */
    private function paidBusiness(): array
    {
        $owner = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $owner->id]);
        $business = Business::factory()->forOwner($owner)->create();
        BusinessMember::factory()->owner()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
        ]);

        return [$business, $owner];
    }

    private function claim(Business $business, Coupon $coupon, array $state = []): ClaimedCoupon
    {
        return ClaimedCoupon::factory()->create(array_merge([
            'user_id' => User::factory(),
            'coupon_id' => $coupon->id,
            'business_id' => $business->id,
            'coupon_code' => $coupon->code,
            'coupon_title' => $coupon->title,
        ], $state));
    }

    // -----------------------------------------------------------------
    // Analytics
    // -----------------------------------------------------------------

    public function test_reports_claims_redemptions_and_conversion()
    {
        [$business, $owner] = $this->paidBusiness();
        $coupon = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);

        $this->claim($business, $coupon);
        $this->claim($business, $coupon);
        $this->claim($business, $coupon, ['status' => 'used', 'used_at' => now()]);

        $totals = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/analytics')
            ->assertStatus(200)
            ->json('data.totals');

        $this->assertSame(3, $totals['claims']);
        $this->assertSame(1, $totals['redemptions']);
        $this->assertSame(33.3, $totals['redemption_rate']);
    }

    public function test_redemption_rate_is_null_rather_than_zero_with_no_claims()
    {
        [, $owner] = $this->paidBusiness();

        $totals = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/analytics')
            ->json('data.totals');

        // "No data" and "nobody redeemed" are different; a 0% badge on a brand
        // new business is just discouraging.
        $this->assertSame(0, $totals['claims']);
        $this->assertNull($totals['redemption_rate']);
    }

    public function test_another_businesss_activity_is_never_counted()
    {
        [$mine, $owner] = $this->paidBusiness();
        [$theirs, $otherOwner] = $this->paidBusiness();

        $mineCoupon = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $mine->id]);
        $theirCoupon = Coupon::factory()->create(['user_id' => $otherOwner->id, 'business_id' => $theirs->id]);

        $this->claim($mine, $mineCoupon);
        $this->claim($theirs, $theirCoupon);
        $this->claim($theirs, $theirCoupon);

        $totals = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/analytics')
            ->json('data.totals');

        $this->assertSame(1, $totals['claims']);
    }

    public function test_date_range_filters_results()
    {
        [$business, $owner] = $this->paidBusiness();
        $coupon = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);

        $this->claim($business, $coupon, ['created_at' => now()->subMonths(6)]);
        $this->claim($business, $coupon, ['created_at' => now()->subDay()]);

        // Default window is the trailing 30 days.
        $this->assertSame(
            1,
            $this->actingAs($owner, 'sanctum')->getJson('/api/business/dashboard/analytics')->json('data.totals.claims')
        );

        $wide = $this->actingAs($owner, 'sanctum')->getJson(
            '/api/business/dashboard/analytics?from=' . now()->subYear()->toDateString() . '&to=' . now()->toDateString()
        );

        $this->assertSame(2, $wide->json('data.totals.claims'));
    }

    /**
     * A coupon claimed in one period and redeemed in another belongs to the
     * period it was REDEEMED in. Counting both by claim date would understate
     * the current period, whose newest claims have not had time to convert.
     */
    public function test_redemptions_are_counted_by_when_they_happened()
    {
        [$business, $owner] = $this->paidBusiness();
        $coupon = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);

        $this->claim($business, $coupon, [
            'created_at' => now()->subMonths(3),   // claimed long ago
            'status' => 'used',
            'used_at' => now()->subDay(),          // redeemed recently
        ]);

        $totals = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/analytics')
            ->json('data.totals');

        $this->assertSame(0, $totals['claims'], 'the claim itself is outside the window');
        $this->assertSame(1, $totals['redemptions'], 'but the redemption is inside it');
    }

    public function test_timeseries_is_zero_filled_across_the_whole_range()
    {
        [$business, $owner] = $this->paidBusiness();
        $coupon = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);
        $this->claim($business, $coupon, ['created_at' => now()->subDays(2)]);

        $series = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/analytics?from=' . now()->subDays(6)->toDateString() . '&to=' . now()->toDateString())
            ->json('data.timeseries');

        // A chart with holes in it is worse than one with zeroes.
        $this->assertCount(7, $series);
        $this->assertSame(1, collect($series)->sum('claims'));
    }

    public function test_top_offers_are_ranked_with_their_own_conversion()
    {
        [$business, $owner] = $this->paidBusiness();
        $popular = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id, 'title' => 'Popular']);
        $quiet = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id, 'title' => 'Quiet']);

        $this->claim($business, $popular, ['coupon_title' => 'Popular', 'status' => 'used', 'used_at' => now()]);
        $this->claim($business, $popular, ['coupon_title' => 'Popular']);
        $this->claim($business, $quiet, ['coupon_title' => 'Quiet']);

        $top = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/analytics')
            ->json('data.top_offers');

        $this->assertSame('Popular', $top[0]['title']);
        $this->assertSame(2, $top[0]['claims']);
        $this->assertSame(1, $top[0]['redemptions']);
        // assertEquals, not assertSame: a whole-number rate round-trips through
        // JSON as an int, so 50.0 comes back as 50.
        $this->assertEquals(50, $top[0]['redemption_rate']);
    }

    public function test_repeat_customers_are_measured_on_redemptions_not_claims()
    {
        [$business, $owner] = $this->paidBusiness();

        // Two offers, because Phase 1's unique index means one customer cannot
        // claim the same coupon twice — a repeat visit is necessarily a
        // different offer.
        $first = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);
        $second = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);

        $returning = User::factory()->create();
        $once = User::factory()->create();
        $claimerOnly = User::factory()->create();

        $this->claim($business, $first, ['user_id' => $returning->id, 'status' => 'used', 'used_at' => now()->subWeek()]);
        $this->claim($business, $second, ['user_id' => $returning->id, 'status' => 'used', 'used_at' => now()]);
        $this->claim($business, $first, ['user_id' => $once->id, 'status' => 'used', 'used_at' => now()]);
        // Claiming is free and proves nothing about a visit.
        $this->claim($business, $first, ['user_id' => $claimerOnly->id]);

        $repeat = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/analytics')
            ->json('data.repeat_customers');

        $this->assertSame(2, $repeat['total_redeeming_customers']);
        $this->assertSame(1, $repeat['repeat_customers']);
        $this->assertEquals(50, $repeat['repeat_rate']);
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_manager_can_view_analytics_but_staff_cannot()
    {
        [$business] = $this->paidBusiness();

        $manager = User::factory()->create();
        BusinessMember::factory()->manager()->create(['business_id' => $business->id, 'user_id' => $manager->id]);

        $staff = User::factory()->create();
        BusinessMember::factory()->staff()->create(['business_id' => $business->id, 'user_id' => $staff->id]);

        $this->actingAs($manager, 'sanctum')->getJson('/api/business/dashboard/analytics')->assertStatus(200);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/business/dashboard/analytics')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ability_required');
    }

    public function test_unsubscribed_business_cannot_view_analytics()
    {
        [, $owner] = $this->paidBusiness();
        UserSubscription::where('user_id', $owner->id)->update(['status' => 'cancelled']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/analytics')
            ->assertStatus(402);
    }

    // -----------------------------------------------------------------
    // Onboarding
    // -----------------------------------------------------------------

    public function test_onboarding_starts_empty_and_points_at_the_first_step()
    {
        [, $owner] = $this->paidBusiness();

        $data = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/onboarding')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(0, $data['completed']);
        $this->assertSame(4, $data['total']);
        $this->assertFalse($data['complete']);
        $this->assertSame('business_details', $data['next']);
    }

    public function test_onboarding_completes_as_real_state_appears()
    {
        [$business, $owner] = $this->paidBusiness();

        $business->update(['description' => 'Independent coffee roaster.']);
        $business->locations()->create(['label' => 'Main', 'address' => '1 Main St', 'is_primary' => true]);
        Product::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);
        $coupon = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);
        $this->claim($business, $coupon, ['status' => 'used', 'used_at' => now()]);

        $data = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/onboarding')
            ->json('data');

        // Derived from state, so nothing had to be marked done.
        $this->assertTrue($data['complete']);
        $this->assertSame(4, $data['completed']);
        $this->assertNull($data['next']);
    }

    public function test_onboarding_reverts_when_the_underlying_state_goes_away()
    {
        [$business, $owner] = $this->paidBusiness();
        $product = Product::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);

        $stepFor = fn (array $data, string $key) => collect($data['steps'])->firstWhere('key', $key);

        $before = $this->actingAs($owner, 'sanctum')->getJson('/api/business/dashboard/onboarding')->json('data');
        $this->assertTrue($stepFor($before, 'add_product')['complete']);

        $product->delete();

        // A stored checklist would have drifted here.
        $after = $this->actingAs($owner, 'sanctum')->getJson('/api/business/dashboard/onboarding')->json('data');
        $this->assertFalse($stepFor($after, 'add_product')['complete']);
    }
}
