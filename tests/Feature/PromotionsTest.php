<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Businesses working together: partnership profiles and the directory,
 * organizations and their members, and joint promotions with offers that
 * unlock one another.
 */
class PromotionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Business, 2: Coupon} */
    private function shop(string $name, string $kind = 'business'): array
    {
        $owner = User::factory()->create(['role' => 'business', 'business_name' => $name]);
        UserSubscription::factory()->create(['user_id' => $owner->id]);
        $business = Business::factory()->forOwner($owner)->create(['name' => $name, 'kind' => $kind]);
        BusinessMember::factory()->owner()->create(['business_id' => $business->id, 'user_id' => $owner->id]);
        $coupon = Coupon::factory()->create(['user_id' => $owner->id, 'business_id' => $business->id, 'title' => "{$name} deal", 'slug' => \Str::slug($name) . '-deal']);

        return [$owner, $business, $coupon];
    }

    public function test_a_partner_promotion_unlocks_the_second_offer_after_the_first_is_used()
    {
        [$bistroOwner, $bistro, $dinner] = $this->shop('Lumen Bistro');
        [$cinemaOwner, $cinema, $movie] = $this->shop('Riverside Cinema');
        $shopper = User::factory()->create();

        $p = $this->actingAs($bistroOwner, 'sanctum')->postJson('/api/business/promotions', [
            'type' => 'partner', 'title' => 'Dinner and a movie', 'ends_at' => now()->addMonth()->toIso8601String(),
        ])->assertStatus(201)->json('data');

        // Live needs two businesses with offers.
        $this->actingAs($bistroOwner, 'sanctum')->postJson("/api/business/promotions/{$p['id']}/participate", ['accept' => true, 'coupon_id' => $dinner->id, 'role' => 'Dinner']);
        $this->actingAs($bistroOwner, 'sanctum')->putJson("/api/business/promotions/{$p['id']}/status", ['status' => 'live'])->assertStatus(422);

        $view = $this->actingAs($bistroOwner, 'sanctum')->postJson("/api/business/promotions/{$p['id']}/invite", ['business_ids' => [$cinema->id]])->json('data');
        $cinemaRow = collect($view['participants'])->firstWhere('business.id', $cinema->id);
        $bistroRow = collect($view['participants'])->firstWhere('business.id', $bistro->id);

        // The cinema can only offer its own coupons.
        $this->actingAs($cinemaOwner, 'sanctum')->postJson("/api/business/promotions/{$p['id']}/participate", ['accept' => true, 'coupon_id' => $dinner->id])->assertStatus(422);
        $this->actingAs($cinemaOwner, 'sanctum')->postJson("/api/business/promotions/{$p['id']}/participate", ['accept' => true, 'coupon_id' => $movie->id, 'role' => 'The movie'])->assertStatus(200);

        $this->actingAs($bistroOwner, 'sanctum')->putJson("/api/business/promotions/{$p['id']}/participants/{$cinemaRow['id']}", [
            'role' => 'The movie', 'unlocked_by_participant_id' => $bistroRow['id'],
        ])->assertStatus(200);
        $this->actingAs($bistroOwner, 'sanctum')->putJson("/api/business/promotions/{$p['id']}/status", ['status' => 'live'])->assertStatus(200);

        // Shoppers see it; the movie offer is locked until dinner is redeemed.
        $detail = $this->actingAs($shopper, 'sanctum')->getJson("/api/promotions/{$p['slug']}")->json('data');
        $this->assertSame(['Lumen Bistro', 'Riverside Cinema'], $detail['business_names']);
        $this->assertTrue(collect($detail['offers'])->firstWhere('business.name', 'Riverside Cinema')['locked']);

        $this->actingAs($shopper, 'sanctum')->postJson('/api/coupons/claim', ['coupon_id' => $movie->id])
            ->assertStatus(403)->assertJsonPath('code', 'promotion_locked');

        $this->actingAs($shopper, 'sanctum')->postJson('/api/coupons/claim', ['coupon_id' => $dinner->id])->assertSuccessful();
        ClaimedCoupon::query()->where('coupon_id', $dinner->id)->update(['status' => 'used', 'used_at' => now()]);

        $this->actingAs($shopper, 'sanctum')->postJson('/api/coupons/claim', ['coupon_id' => $movie->id])->assertSuccessful();

        // And it has a crawlable page.
        $html = $this->get("/p/{$p['slug']}")->assertStatus(200)->getContent();
        $this->assertStringContainsString('Dinner and a movie', $html);
        $this->assertStringContainsString('Unlocks after you use the offer at Lumen Bistro', $html);
        $this->assertStringContainsString("/p/{$p['slug']}", $this->get('/sitemap.xml')->getContent());
    }

    public function test_drafts_are_not_public()
    {
        [$owner] = $this->shop('Lumen Bistro');
        $p = $this->actingAs($owner, 'sanctum')->postJson('/api/business/promotions', ['type' => 'bundle', 'title' => 'Date Night'])->json('data');

        $this->get("/p/{$p['slug']}")->assertStatus(404);
        $this->getJson("/api/promotions/{$p['slug']}")->assertStatus(404);
        $this->getJson('/api/promotions')->assertJsonCount(0, 'data');
    }

    public function test_an_organization_gathers_members_and_runs_a_campaign_across_them()
    {
        [$chamberOwner, $chamber] = $this->shop('Downtown Association', 'organization');
        [$aOwner, $a, $aDeal] = $this->shop('Paper Moon Books');
        [$bOwner, $b, $bDeal] = $this->shop('Sweet Fern Bakery');

        foreach ([$a, $b] as $member) {
            $this->actingAs($chamberOwner, 'sanctum')->postJson('/api/business/organization/members', ['business_id' => $member->id])->assertStatus(201);
        }
        $this->actingAs($aOwner, 'sanctum')->getJson('/api/business/organizations')->assertJsonPath('data.0.status', 'invited');
        $this->actingAs($aOwner, 'sanctum')->postJson("/api/business/organizations/{$chamber->id}/respond", ['accept' => true])->assertStatus(200);
        $this->actingAs($bOwner, 'sanctum')->postJson("/api/business/organizations/{$chamber->id}/respond", ['accept' => true])->assertStatus(200);

        $p = $this->actingAs($chamberOwner, 'sanctum')->postJson('/api/business/promotions', ['type' => 'campaign', 'title' => 'Small Business Saturday'])->json('data');
        $this->assertSame([], $p['participants']); // the organization runs it without taking part
        $view = $this->actingAs($chamberOwner, 'sanctum')->postJson("/api/business/promotions/{$p['id']}/invite", ['all_members' => true])->json('data');
        $this->assertCount(2, $view['participants']);

        $this->actingAs($aOwner, 'sanctum')->postJson("/api/business/promotions/{$p['id']}/participate", ['accept' => true, 'coupon_id' => $aDeal->id]);
        $this->actingAs($bOwner, 'sanctum')->postJson("/api/business/promotions/{$p['id']}/participate", ['accept' => true, 'coupon_id' => $bDeal->id]);
        $this->actingAs($chamberOwner, 'sanctum')->putJson("/api/business/promotions/{$p['id']}/status", ['status' => 'live'])->assertStatus(200);

        // The organization's page lists its members and the campaign.
        $html = $this->get("/b/{$chamber->slug}")->assertStatus(200)->getContent();
        $this->assertStringContainsString('Member businesses', $html);
        $this->assertStringContainsString('Paper Moon Books', $html);
        $this->assertStringContainsString('Small Business Saturday', $html);

        // A member's page shows it too.
        $this->getJson("/api/public/businesses/{$a->slug}")->assertJsonPath('community.promotions.0.title', 'Small Business Saturday');
    }

    public function test_only_organizations_have_members_and_the_directory_lists_open_businesses()
    {
        [$owner, $shop] = $this->shop('Groom and Brew');
        [, $other] = $this->shop('Lumen Bistro');
        [, $closed] = $this->shop('Hidden Cafe');

        $this->actingAs($owner, 'sanctum')->postJson('/api/business/organization/members', ['business_id' => $other->id])->assertStatus(422);

        $other->update(['open_to_partnerships' => true, 'partnership_interests' => ['bundles'], 'partnership_pitch' => 'Dinner partners wanted']);
        $rows = $this->actingAs($owner, 'sanctum')->getJson('/api/business/partners/directory?interest=bundles')->json('data');
        $this->assertSame(['Lumen Bistro'], array_column($rows, 'name'));
        $this->assertSame([], $this->actingAs($owner, 'sanctum')->getJson('/api/business/partners/directory?interest=events')->json('data'));

        $this->actingAs($owner, 'sanctum')->putJson('/api/business/partners/profile', [
            'open_to_partnerships' => true, 'partnership_interests' => ['promotions', 'nonsense'],
        ])->assertStatus(422);
    }
}
