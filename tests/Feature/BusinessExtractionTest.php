<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Models\UserSubscription;
use App\Support\BusinessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the expand/migrate/contract rollout of the businesses table.
 *
 * The contract being defended: with config('thryft.use_business_entity') off,
 * behaviour is byte-for-byte what it was before the extraction; with it on,
 * ownership resolves through business_id — and nothing a user could see before
 * becomes invisible or inaccessible in either position.
 */
class BusinessExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function useBusinessEntity(bool $enabled): void
    {
        config(['thryft.use_business_entity' => $enabled]);
    }

    /**
     * A business user with a subscription (so the Phase 2 gate lets them
     * through) and a matching business row, as the backfill would have made.
     *
     * @return array{0: User, 1: Business}
     */
    private function businessOwner(): array
    {
        $user = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $user->id]);
        $business = Business::factory()->forOwner($user)->create();

        return [$user, $business];
    }

    // -----------------------------------------------------------------
    // Dual-write — independent of the read flag
    // -----------------------------------------------------------------

    public function test_creating_a_product_writes_both_ownership_columns()
    {
        [$user, $business] = $this->businessOwner();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', ['name' => 'Widget', 'description' => 'x'])
            ->assertStatus(201);

        $product = Product::firstOrFail();
        $this->assertSame($user->id, (int) $product->user_id);
        $this->assertSame($business->id, (int) $product->business_id);
    }

    public function test_dual_write_happens_even_with_the_read_flag_off()
    {
        $this->useBusinessEntity(false);
        [$user, $business] = $this->businessOwner();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/coupons', [
                'title' => 'Ten off',
                'discount_type' => 'percentage',
                'discount_percentage' => 10,
            ])->assertStatus(201);

        // Writing both columns regardless is what makes the flag safe to flip
        // forward AND back without a migration.
        $this->assertSame($business->id, (int) Coupon::firstOrFail()->business_id);
    }

    public function test_owner_without_a_business_row_still_writes_user_id()
    {
        // A business user provisioned after the backfill, before their business
        // row exists. Must not fatal or write a bogus business_id.
        $user = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', ['name' => 'Orphan', 'description' => 'x'])
            ->assertStatus(201);

        $product = Product::firstOrFail();
        $this->assertSame($user->id, (int) $product->user_id);
        $this->assertNull($product->business_id);
    }

    // -----------------------------------------------------------------
    // Reads, flag off vs on
    // -----------------------------------------------------------------

    public function test_listing_returns_the_same_products_in_both_flag_positions()
    {
        [$user, $business] = $this->businessOwner();

        Product::factory()->count(3)->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
        ]);

        $this->useBusinessEntity(false);
        $off = $this->actingAs($user, 'sanctum')->getJson('/api/products')->json('data.data');

        $this->useBusinessEntity(true);
        $on = $this->actingAs($user, 'sanctum')->getJson('/api/products')->json('data.data');

        $this->assertCount(3, $off);
        $this->assertSame(
            array_column($off, 'id'),
            array_column($on, 'id'),
            'Flipping the flag must not change which records an owner sees.'
        );
    }

    public function test_legacy_rows_without_business_id_remain_visible_with_the_flag_on()
    {
        [$user, $business] = $this->businessOwner();

        // A row the backfill never reached.
        Product::factory()->create(['user_id' => $user->id, 'business_id' => null]);

        $this->useBusinessEntity(true);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/products')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data');
    }

    public function test_another_business_cannot_see_or_open_your_records_with_the_flag_on()
    {
        [$owner, $business] = $this->businessOwner();
        [$other] = $this->businessOwner();

        $product = Product::factory()->create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
        ]);

        $this->useBusinessEntity(true);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/products')
            ->assertJsonCount(0, 'data.data');

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/products/{$product->id}")
            ->assertStatus(404);
    }

    /**
     * scopeOwnedBy() and owns() must agree. If they drift, a list shows rows
     * whose detail endpoint then 404s — the confusing failure this guards.
     */
    public function test_list_scope_and_single_record_check_agree()
    {
        [$user, $business] = $this->businessOwner();

        $withBusiness = Product::factory()->create(['user_id' => $user->id, 'business_id' => $business->id]);
        $legacy = Product::factory()->create(['user_id' => $user->id, 'business_id' => null]);

        foreach ([false, true] as $flag) {
            $this->useBusinessEntity($flag);

            $listed = $this->actingAs($user, 'sanctum')->getJson('/api/products')->json('data.data');
            $listedIds = array_column($listed, 'id');

            foreach ([$withBusiness, $legacy] as $product) {
                $this->assertContains($product->id, $listedIds, "flag=" . var_export($flag, true));

                $this->actingAs($user, 'sanctum')
                    ->getJson("/api/products/{$product->id}")
                    ->assertStatus(200);
            }
        }
    }

    // -----------------------------------------------------------------
    // Model behaviour
    // -----------------------------------------------------------------

    public function test_slug_is_generated_and_kept_unique()
    {
        $first = Business::factory()->create(['name' => 'Corner Cafe', 'slug' => null]);
        $second = Business::factory()->create(['name' => 'Corner Cafe', 'slug' => null]);

        $this->assertSame('corner-cafe', $first->slug);
        $this->assertNotSame($first->slug, $second->slug);
    }

    public function test_resolver_finds_the_business_a_user_owns()
    {
        [$user, $business] = $this->businessOwner();

        $this->assertSame($business->id, BusinessResolver::forUser($user)?->id);
        $this->assertSame($business->id, $user->currentBusiness()?->id);
        $this->assertSame($business->id, BusinessResolver::businessIdFor($user));
    }

    public function test_resolver_returns_null_for_a_user_with_no_business()
    {
        $user = User::factory()->create();

        $this->assertNull(BusinessResolver::forUser($user));
        $this->assertNull(BusinessResolver::businessIdFor($user));
        $this->assertFalse(BusinessResolver::owns($user, Product::factory()->create()));
    }

    public function test_business_exposes_locations_and_a_primary()
    {
        $business = Business::factory()->create();
        $business->locations()->createMany([
            ['label' => 'Main', 'city' => 'Davao', 'is_primary' => true],
            ['label' => 'Branch', 'city' => 'Cebu', 'is_primary' => false],
        ]);

        $this->assertCount(2, $business->locations);
        $this->assertSame('Main', $business->primaryLocation->label);
    }
}
