<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guest mode and the crawlable public pages.
 *
 * The assertions that matter most are the negative ones: this is the first
 * surface where an anonymous caller receives business data, and the codebase's
 * hand-built arrays had already leaked account emails through /api/search.
 */
class PublicSurfaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Business, 1: User, 2: Coupon}
     */
    private function publishedBusiness(array $couponState = []): array
    {
        $owner = User::factory()->create([
            'role' => 'business',
            'email' => 'owner-login@example.com',
        ]);

        $business = Business::factory()->forOwner($owner)->create([
            'name' => 'Corner Cafe',
            'slug' => 'corner-cafe',
            'email' => 'hello@cornercafe.test',
        ]);

        $business->locations()->create([
            'label' => 'Main',
            'address' => '1 Main St',
            'city' => 'Davao',
            'latitude' => 7.06,
            'longitude' => 125.55,
            'is_primary' => true,
        ]);

        $coupon = Coupon::factory()->create(array_merge([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'title' => 'Ten percent off',
            'slug' => 'ten-percent-off-1',
            'code' => 'SECRET99',
        ], $couponState));

        return [$business, $owner, $coupon];
    }

    // -----------------------------------------------------------------
    // PII containment
    // -----------------------------------------------------------------

    public function test_guest_business_payload_never_exposes_the_owner_login_email()
    {
        [$business, $owner] = $this->publishedBusiness();

        $response = $this->getJson("/api/public/businesses/{$business->id}")->assertStatus(200);

        // The published business contact address is fine; the account login is not.
        $this->assertStringNotContainsString($owner->email, $response->getContent());
        $this->assertSame('hello@cornercafe.test', $response->json('data.email'));
    }

    public function test_unauthenticated_search_no_longer_returns_account_emails()
    {
        // The live leak this phase fixes: /api/search is unauthenticated and
        // was returning users.email for every matching business.
        $owner = User::factory()->create([
            'role' => 'business',
            'business_name' => 'Searchable Cafe',
            'email' => 'leaky-login@example.com',
        ]);

        $response = $this->getJson('/api/search?q=Searchable')->assertStatus(200);

        $this->assertStringNotContainsString($owner->email, $response->getContent());
    }

    public function test_guest_deal_payload_never_exposes_the_redemption_code()
    {
        [$business, , $coupon] = $this->publishedBusiness();

        $response = $this->getJson("/api/public/businesses/{$business->id}/deals/{$coupon->slug}")
            ->assertStatus(200);

        // Publishing the code would let anyone present a coupon they never claimed.
        $this->assertStringNotContainsString('SECRET99', $response->getContent());
    }

    public function test_guest_deal_payload_hides_commercial_counters()
    {
        [$business, , $coupon] = $this->publishedBusiness();
        $coupon->update(['claim_limit' => 100, 'claimed_count' => 37]);

        $json = $this->getJson("/api/public/businesses/{$business->id}/deals/{$coupon->slug}")->json('data');

        // Scarcity is useful to a shopper; take-up volume is a competitor's business.
        $this->assertSame(63, $json['remaining']);
        $this->assertArrayNotHasKey('claimed_count', $json);
        $this->assertArrayNotHasKey('redeemed_count', $json);
        $this->assertArrayNotHasKey('usage_limit', $json);
    }

    public function test_uncapped_offer_reports_null_remaining_rather_than_a_number()
    {
        [$business, , $coupon] = $this->publishedBusiness();
        $coupon->update(['claim_limit' => null]);

        $json = $this->getJson("/api/public/businesses/{$business->id}/deals/{$coupon->slug}")->json('data');

        $this->assertNull($json['remaining']);
    }

    // -----------------------------------------------------------------
    // Guest browsing
    // -----------------------------------------------------------------

    public function test_guest_can_list_businesses_and_deals_without_an_account()
    {
        $this->publishedBusiness();

        $this->getJson('/api/public/businesses')->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson('/api/public/deals')->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_suspended_business_is_not_browsable()
    {
        [$business] = $this->publishedBusiness();
        $business->update(['status' => 'suspended']);

        $this->getJson('/api/public/businesses')->assertJsonCount(0, 'data');
        $this->getJson("/api/public/businesses/{$business->slug}")->assertStatus(404);
    }

    public function test_business_can_be_fetched_by_id_or_slug()
    {
        // The app holds ids; a shared link carries a slug. Both must work.
        [$business] = $this->publishedBusiness();

        $byId = $this->getJson("/api/public/businesses/{$business->id}")->assertStatus(200);
        $bySlug = $this->getJson("/api/public/businesses/{$business->slug}")->assertStatus(200);

        $this->assertSame($byId->json('data.id'), $bySlug->json('data.id'));
    }

    public function test_guest_browsing_is_read_only()
    {
        [$business, , $coupon] = $this->publishedBusiness();

        // Identity-bound actions must still require an account.
        $this->postJson('/api/coupons/claim', ['coupon_id' => $coupon->id])->assertStatus(401);
        $this->postJson('/api/products/favorite', ['product_id' => 1])->assertStatus(401);
    }

    public function test_search_wildcards_are_escaped()
    {
        $this->publishedBusiness();

        // A bare % would otherwise match every row regardless of the term.
        $this->getJson('/api/public/businesses?q=%25')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    // -----------------------------------------------------------------
    // Crawlable pages
    // -----------------------------------------------------------------

    public function test_business_page_renders_with_metadata_and_structured_data()
    {
        [$business] = $this->publishedBusiness();

        $response = $this->get("/b/{$business->slug}")->assertStatus(200);

        $response->assertSee('Corner Cafe', false);
        $response->assertSee('og:title', false);
        $response->assertSee('application/ld+json', false);
        $response->assertSee('LocalBusiness', false);
        $response->assertSee('rel="canonical"', false);
    }

    public function test_deal_page_renders_offer_structured_data()
    {
        [$business, , $coupon] = $this->publishedBusiness();

        $response = $this->get("/b/{$business->slug}/deals/{$coupon->slug}")->assertStatus(200);

        $response->assertSee('Ten percent off', false);
        $response->assertSee('"@type":"Offer"', false);
        // Never render the redemption code on a public page.
        $response->assertDontSee('SECRET99', false);
    }

    public function test_deal_page_is_scoped_to_its_business()
    {
        [, , $coupon] = $this->publishedBusiness();
        $other = Business::factory()->create(['slug' => 'other-shop']);

        // A coupon slug must not render under an unrelated business's branding.
        $this->get("/b/{$other->slug}/deals/{$coupon->slug}")->assertStatus(404);
    }

    public function test_suspended_business_page_is_not_served()
    {
        [$business] = $this->publishedBusiness();
        $business->update(['status' => 'suspended']);

        $this->get("/b/{$business->slug}")->assertStatus(404);
    }

    public function test_sitemap_lists_businesses_and_active_deals()
    {
        [$business, , $coupon] = $this->publishedBusiness();

        $response = $this->get('/sitemap.xml')->assertStatus(200);

        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSee("/b/{$business->slug}", false);
        $response->assertSee("/deals/{$coupon->slug}", false);
    }

    public function test_sitemap_omits_suspended_businesses()
    {
        [$business] = $this->publishedBusiness();
        $business->update(['status' => 'suspended']);

        // A sitemap entry that 404s is worse than an absent one.
        $this->get('/sitemap.xml')->assertDontSee("/b/{$business->slug}", false);
    }

    public function test_expired_deal_is_not_published()
    {
        [$business, , $coupon] = $this->publishedBusiness();
        $coupon->update(['expires_at' => now()->subDay()]);

        $this->getJson('/api/public/deals')->assertJsonCount(0, 'data');
        $this->get('/sitemap.xml')->assertDontSee("/deals/{$coupon->slug}", false);
    }
}
