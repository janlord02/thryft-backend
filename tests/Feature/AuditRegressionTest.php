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
 * Regressions found by auditing the hardening work. Each of these shipped
 * green because the tests written alongside the feature happened to avoid the
 * failing case.
 */
class AuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Business, 1: User}
     */
    private function paidShop(array $businessState = []): array
    {
        $owner = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $owner->id]);
        $business = Business::factory()->forOwner($owner)->create($businessState);
        BusinessMember::factory()->owner()->create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
        ]);

        return [$business, $owner];
    }

    // -----------------------------------------------------------------
    // Stored XSS on the public pages
    // -----------------------------------------------------------------

    public function test_business_name_is_escaped_in_page_metadata()
    {
        // business_name is validated only as string|max:255, and @yield emits
        // its argument unescaped — so this broke out of <title> and og:title.
        [$business] = $this->paidShop([
            'name' => '"><script>alert(1)</script>',
            'slug' => 'xss-probe',
        ]);

        $html = $this->get("/b/{$business->slug}")->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_coupon_title_is_escaped_in_deal_metadata()
    {
        [$business, $owner] = $this->paidShop(['slug' => 'xss-deal-shop']);
        $coupon = Coupon::factory()->create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'title' => '"><img src=x onerror=alert(1)>',
        ]);

        $html = $this->get("/b/{$business->slug}/deals/{$coupon->slug}")
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('<img src=x onerror=', $html);
    }

    // -----------------------------------------------------------------
    // Owner login email must never reach a public payload
    // -----------------------------------------------------------------

    /**
     * businesses.email is a PUBLISHED contact field, so the resource is right
     * to expose it — the bug was the backfill seeding it from users.email,
     * which republished every existing owner's login. The guarantee is
     * therefore about what the migration writes, not about the resource.
     */
    public function test_backfill_does_not_seed_the_business_email_from_the_login()
    {
        $owner = User::factory()->create([
            'role' => 'business',
            'email' => 'login@secret.test',
            'business_name' => 'Backfilled Shop',
        ]);

        // Re-run the backfill against this user, exactly as the migration does.
        $migration = require database_path('migrations/2026_09_24_000002_backfill_businesses_from_users.php');
        $migration->up();

        $business = Business::where('owner_user_id', $owner->id)->firstOrFail();

        $this->assertNull($business->email, 'The account login must not become the public contact address.');

        $body = $this->getJson("/api/public/businesses/{$business->id}")->getContent();
        $this->assertStringNotContainsString('login@secret.test', $body);
    }

    // -----------------------------------------------------------------
    // Coupon slugs
    // -----------------------------------------------------------------

    public function test_new_coupons_get_a_slug()
    {
        $coupon = Coupon::factory()->create(['title' => 'Half Price Tuesdays']);

        $this->assertNotNull($coupon->fresh()->slug);
        $this->assertStringStartsWith('half-price-tuesdays-', $coupon->fresh()->slug);
    }

    public function test_business_page_survives_a_coupon_with_no_slug()
    {
        [$business, $owner] = $this->paidShop(['slug' => 'slugless-shop']);
        $coupon = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);

        // Rows predating the slug migration. route() throws on a null
        // parameter, which used to 500 the entire page.
        Coupon::whereKey($coupon->id)->update(['slug' => null]);

        $this->get("/b/{$business->slug}")->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // Claims must be filed against the business, not the owner's user id
    // -----------------------------------------------------------------

    public function test_claim_is_filed_against_the_business_not_the_creator()
    {
        [$business] = $this->paidShop();

        // A coupon created by a MANAGER: ownershipAttributes() writes that
        // member's user_id, so coupon.user_id != business.id. The old claim
        // path filed the claim under coupon.user_id, which no till lookup
        // would ever match.
        //
        // NOTE: the more dramatic case — a business whose id is outside the
        // users range, as every business created after the backfill will be —
        // cannot be expressed here. Migration 2026_09_24_000003 repoints
        // claimed_coupons.business_id at `businesses` on MySQL only (SQLite
        // cannot ALTER a foreign key), so under SQLite the column still
        // references users and any id above the users range is rejected.
        $manager = User::factory()->create();
        BusinessMember::factory()->manager()->create([
            'business_id' => $business->id,
            'user_id' => $manager->id,
        ]);

        $coupon = Coupon::factory()->create([
            'user_id' => $manager->id,
            'business_id' => $business->id,
        ]);

        $customer = User::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/coupons/claim', ['coupon_id' => $coupon->id])
            ->assertStatus(200);

        $claim = ClaimedCoupon::firstOrFail();

        $this->assertSame(
            (int) $business->id,
            (int) $claim->business_id,
            "business_id must be the coupon's business, not its creator's user id."
        );
        $this->assertNotSame((int) $manager->id, (int) $claim->business_id);
    }

    // -----------------------------------------------------------------
    // The till is behind the paywall
    // -----------------------------------------------------------------

    public function test_lapsed_business_cannot_keep_redeeming()
    {
        [$business, $owner] = $this->paidShop();
        $coupon = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);
        $claim = ClaimedCoupon::factory()->forCoupon($coupon)->create();

        UserSubscription::where('user_id', $owner->id)->update([
            'status' => 'cancelled',
            'ends_at' => now()->subDay(),
        ]);

        // These endpoints sat outside the gate, so a business blocked from
        // managing offers could still redeem indefinitely.
        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/coupons/mark-as-used', ['claimedCouponId' => $claim->id])
            ->assertStatus(402);

        $this->assertSame('claimed', $claim->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Suspended businesses disappear from every public surface
    // -----------------------------------------------------------------

    public function test_suspended_business_deals_leave_the_guest_feed()
    {
        [$business, $owner] = $this->paidShop();
        Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);

        $this->getJson('/api/public/deals')->assertJsonCount(1, 'data');

        $business->update(['status' => 'suspended']);

        // Every other public surface hid it; this feed did not.
        $this->getJson('/api/public/deals')->assertJsonCount(0, 'data');
    }

    // -----------------------------------------------------------------
    // Quotas belong to the business
    // -----------------------------------------------------------------

    public function test_plan_quota_is_shared_across_the_team()
    {
        $plan = \App\Models\Subscription::factory()->withLimits(maxProducts: 1)->create();
        $owner = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $owner->id, 'subscription_id' => $plan->id]);
        $business = Business::factory()->forOwner($owner)->create();
        BusinessMember::factory()->owner()->create(['business_id' => $business->id, 'user_id' => $owner->id]);

        $manager = User::factory()->create();
        BusinessMember::factory()->manager()->create(['business_id' => $business->id, 'user_id' => $manager->id]);

        Product::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id]);

        // The manager used to read their own (absent) subscription — giving
        // them a null limit, i.e. an unlimited private quota.
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/products', ['name' => 'Over quota', 'description' => 'x'])
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit_reached');
    }

    // -----------------------------------------------------------------
    // Misc
    // -----------------------------------------------------------------

    public function test_like_wildcards_do_not_match_everything()
    {
        $this->paidShop(['name' => 'Corner Cafe', 'slug' => 'corner-cafe-x']);

        // Escaping without an ESCAPE clause is inert; a bare % used to be a
        // wildcard matching every row.
        $this->getJson('/api/public/businesses?q=%25')->assertJsonCount(0, 'data');
        $this->getJson('/api/public/businesses?q=Corner')->assertJsonCount(1, 'data');
    }

    public function test_analytics_range_is_clamped()
    {
        [, $owner] = $this->paidShop();

        // An unbounded range built one array entry per day — ~730k of them.
        $data = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/business/dashboard/analytics?from=1000-01-01&to=2999-12-31')
            ->assertStatus(200)
            ->json('data');

        $this->assertLessThanOrEqual(366, count($data['timeseries']));
    }
}
