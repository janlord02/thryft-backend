<?php

namespace Tests\Feature;

use App\Http\Controllers\FeaturedController;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Coupon;
use App\Models\FeaturedPlacement;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\StripePayments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Featured placement: a business pays to show a deal to shoppers near it;
 * it goes live only once Stripe says the payment succeeded.
 */
class FeaturedPlacementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;
    private Coupon $coupon;
    private object $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create(['name' => 'Lumen Bistro']);
        BusinessMember::factory()->owner()->create(['business_id' => $this->business->id, 'user_id' => $this->owner->id]);
        $this->business->locations()->create(['label' => 'Main', 'city' => 'Springfield', 'latitude' => 39.78, 'longitude' => -89.65, 'is_primary' => true]);
        $this->coupon = Coupon::factory()->create(['user_id' => $this->owner->id, 'business_id' => $this->business->id, 'title' => 'Half-price pizza']);

        $this->stripe = new class extends StripePayments {
            public string $status = 'requires_payment_method';
            public array $created = [];

            public function create(int $cents, string $description, array $metadata, ?string $receiptEmail = null): array
            {
                $this->created[] = compact('cents', 'metadata');

                return ['id' => 'pi_test_' . count($this->created), 'client_secret' => 'secret_' . count($this->created)];
            }

            public function status(string $paymentIntentId): string
            {
                return $this->status;
            }
        };
        $this->app->instance(StripePayments::class, $this->stripe);
    }

    private function buy(array $overrides = [])
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/featured', $overrides + [
            'target_type' => 'coupon', 'target_id' => $this->coupon->id, 'radius_miles' => 25, 'days' => 7,
        ]);
    }

    public function test_a_paid_placement_shows_to_nearby_shoppers_only()
    {
        $this->buy()->assertStatus(201)->assertJsonPath('data.amount_cents', 7 * config('featured.price_per_day_cents'));
        $this->assertSame(3500, $this->stripe->created[0]['cents']);

        // Not paid yet: nothing shows, and confirming says so.
        $this->getJson('/api/featured?latitude=39.79&longitude=-89.64')->assertJsonCount(0, 'data');
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/featured/' . FeaturedPlacement::sole()->id . '/confirm')->assertStatus(402);

        $this->stripe->status = 'succeeded';
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/featured/' . FeaturedPlacement::sole()->id . '/confirm')->assertStatus(200);
        $this->assertTrue(FeaturedPlacement::sole()->ends_at->isSameDay(now()->addDays(7)));

        $this->app['auth']->forgetGuards();
        $row = $this->getJson('/api/featured?latitude=39.79&longitude=-89.64')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('Half-price pizza', $row['title']);
        $this->assertTrue($row['sponsored']);

        // Chicago is ~180 miles away.
        $this->getJson('/api/featured?latitude=41.88&longitude=-87.63')->assertJsonCount(0, 'data');

        // And it stops when its days are up.
        $this->travel(8)->days();
        $this->getJson('/api/featured?latitude=39.79&longitude=-89.64')->assertJsonCount(0, 'data');
    }

    public function test_the_webhook_switches_it_on_once()
    {
        $this->buy();
        $p = FeaturedPlacement::sole();

        FeaturedController::onPaymentSucceeded((string) $p->id, 'pi_wrong');
        $this->assertSame('pending_payment', $p->fresh()->status);

        FeaturedController::onPaymentSucceeded((string) $p->id, 'pi_test_1');
        $ends = $p->fresh()->ends_at;
        $this->travel(1)->days();
        FeaturedController::onPaymentSucceeded((string) $p->id, 'pi_test_1');
        $this->assertTrue($p->fresh()->ends_at->equalTo($ends));
    }

    public function test_only_your_own_things_and_only_the_owner_can_buy()
    {
        $other = Coupon::factory()->create();
        $this->buy(['target_id' => $other->id])->assertStatus(422);
        $this->buy(['days' => 365])->assertStatus(422);

        $manager = User::factory()->create();
        BusinessMember::factory()->create(['business_id' => $this->business->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $this->actingAs($manager, 'sanctum')->postJson('/api/business/featured', [
            'target_type' => 'business', 'target_id' => $this->business->id, 'radius_miles' => 25, 'days' => 3,
        ])->assertStatus(403);
    }
}
