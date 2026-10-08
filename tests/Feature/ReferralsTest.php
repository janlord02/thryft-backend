<?php

namespace Tests\Feature;

use App\Events\CouponStatusChanged;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Services\Referrals;
use App\Services\StripeCredits;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Referrals: a signup with a business's code is attributed automatically,
 * qualifies on a real event (first redemption, first paid plan), and the
 * merchant sees it happen.
 */
class ReferralsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business', 'business_name' => 'Fernwood Coffee']);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create(['name' => 'Fernwood Coffee']);
        BusinessMember::factory()->owner()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_every_business_gets_a_permanent_code()
    {
        $this->assertMatchesRegularExpression('/^FERNWO[A-Z0-9]{4}$/', $this->business->referral_code);

        $panel = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/business/referrals')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame($this->business->referral_code, $panel['code']);
        $this->assertStringEndsWith('/auth/register?ref=' . $this->business->referral_code, $panel['link']);
        $this->assertSame(0, $panel['counts']['total']);
    }

    public function test_a_shopper_signup_with_the_code_is_attributed_and_qualifies_on_first_redemption()
    {
        $response = $this->postJson('/api/register', [
            'firstname' => 'Sam',
            'lastname' => 'Shopper',
            'email' => 'sam@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => strtolower($this->business->referral_code),
        ])->assertStatus(201);

        $shopper = User::find($response->json('data.user.id'));
        $referral = Referral::where('referred_user_id', $shopper->id)->first();
        $this->assertNotNull($referral);
        $this->assertSame($this->business->id, $referral->business_id);
        $this->assertSame('shopper', $referral->kind);
        $this->assertSame('pending', $referral->status);

        $panel = $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/referrals')->json('data');
        $this->assertSame(1, $panel['counts']['pending']);
        $this->assertSame('Sam S.', $panel['referrals'][0]['name']);

        // Redeeming at any business qualifies them; the merchant sees it move.
        // (The redemption broadcast would otherwise try to reach Pusher.)
        Event::fake([CouponStatusChanged::class]);
        $coupon = Coupon::factory()->create(['user_id' => $this->owner->id, 'business_id' => $this->business->id]);
        $claim = ClaimedCoupon::factory()->forCoupon($coupon, $shopper)->create();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/coupons/mark-as-used', ['claimedCouponId' => $claim->id])
            ->assertStatus(200);

        $this->assertSame('qualified', $referral->fresh()->status);
        $this->assertNotNull($referral->fresh()->qualified_at);

        $panel = $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/referrals')->json('data');
        $this->assertSame(1, $panel['counts']['qualified']);
        $this->assertSame(0, $panel['counts']['pending']);
    }

    public function test_a_business_signup_qualifies_on_its_first_paid_subscription()
    {
        $response = $this->postJson('/api/register-business', [
            'firstname' => 'Bea',
            'lastname' => 'Baker',
            'email' => 'bea@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'business_name' => 'Bea Bakes',
            'address' => '9 Oak St',
            'city' => 'Davao',
            'state' => 'DS',
            'zipcode' => '8000',
            'country' => 'PH',
            'latitude' => 7.06,
            'longitude' => 125.55,
            'referral_code' => $this->business->referral_code,
        ])->assertStatus(201);

        $newOwner = User::find($response->json('data.user.id'));
        $referral = Referral::where('referred_user_id', $newOwner->id)->firstOrFail();
        $this->assertSame('business', $referral->kind);
        $this->assertSame('pending', $referral->status);

        // A free plan does not qualify...
        UserSubscription::factory()->create(['user_id' => $newOwner->id, 'amount_paid' => 0]);
        $this->assertSame('pending', $referral->fresh()->status);

        // ...a paid one does.
        UserSubscription::factory()->create(['user_id' => $newOwner->id, 'amount_paid' => 29]);
        $this->assertSame('qualified', $referral->fresh()->status);

        $panel = $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/referrals')->json('data');
        $this->assertSame('Bea Bakes', $panel['referrals'][0]['name']);
        $this->assertSame('business', $panel['referrals'][0]['kind']);
    }

    public function test_bad_or_missing_codes_never_break_a_signup()
    {
        $this->postJson('/api/register', [
            'email' => 'nocode@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertStatus(201);

        $this->postJson('/api/register', [
            'email' => 'badcode@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'referral_code' => 'NOPE-NOT-A-CODE',
        ])->assertStatus(201);

        $this->assertSame(0, Referral::count());
    }

    public function test_admin_records_the_reward_once_and_can_void()
    {
        $shopper = User::factory()->create();
        $referral = Referral::create([
            'business_id' => $this->business->id,
            'referred_user_id' => $shopper->id,
            'kind' => 'shopper',
            'status' => 'qualified',
            'qualified_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => 'super-admin']);

        // A merchant cannot touch the admin endpoints.
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/admin/referrals/{$referral->id}", ['status' => 'rewarded'])->assertStatus(403);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/referrals?status=qualified')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/referrals/{$referral->id}", ['status' => 'rewarded', 'reward_note' => '$10 credit applied'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rewarded');

        // Rewarding twice is refused; the first note stands.
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/referrals/{$referral->id}", ['status' => 'rewarded'])
            ->assertStatus(409);
        $this->assertSame('$10 credit applied', $referral->fresh()->reward_note);

        $panel = $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/referrals')->json('data');
        $this->assertSame(1, $panel['counts']['rewarded']);
        $this->assertSame('$10 credit applied', $panel['referrals'][0]['reward_note']);

        $pending = Referral::create([
            'business_id' => $this->business->id,
            'referred_user_id' => User::factory()->create()->id,
            'kind' => 'shopper',
        ]);
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/referrals/{$pending->id}", ['status' => 'void', 'reward_note' => 'duplicate account'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');
    }

    // -----------------------------------------------------------------
    // Automatic rewards
    // -----------------------------------------------------------------

    private function qualifiedReferral(string $kind): Referral
    {
        $user = User::factory()->create(['role' => $kind === 'business' ? 'business' : 'user']);

        return Referral::create([
            'business_id' => $this->business->id,
            'referred_user_id' => $user->id,
            'kind' => $kind,
            'status' => 'qualified',
            'qualified_at' => now(),
        ]);
    }

    /** A fake Stripe that records what it was asked to credit. */
    private function fakeStripe(): object
    {
        $fake = new class extends StripeCredits {
            public array $calls = [];

            public function credit(string $customerId, int $cents, string $description, string $idempotencyKey): string
            {
                $this->calls[] = compact('customerId', 'cents', 'idempotencyKey');

                return 'cbtxn_' . count($this->calls);
            }
        };
        $this->app->instance(StripeCredits::class, $fake);

        return $fake;
    }

    public function test_a_qualified_business_referral_extends_a_plan_paid_outside_stripe()
    {
        $plan = $this->owner->userSubscriptions()->first();
        $plan->update(['stripe_subscription_id' => null, 'ends_at' => now()->addDays(10)->startOfSecond()]);
        $referral = $this->qualifiedReferral('business');

        app(Referrals::class)->rewardBusiness($this->business->id);

        $this->assertTrue($plan->fresh()->ends_at->equalTo(now()->addDays(10)->startOfSecond()->addMonthNoOverflow()));
        $referral->refresh();
        $this->assertSame('rewarded', $referral->status);
        $this->assertStringStartsWith('1 free month', $referral->reward_note);

        $panel = $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/referrals')->json('data');
        $this->assertSame('extension', $panel['rewards'][0]['method']);
        $this->assertSame('business', $panel['rewards'][0]['reason']);
    }

    public function test_a_stripe_customer_gets_one_months_credit_exactly_once()
    {
        $stripe = $this->fakeStripe();
        $this->owner->forceFill(['stripe_customer_id' => 'cus_123'])->save();
        $plan = $this->owner->userSubscriptions()->with('subscription')->first();
        $plan->subscription->update(['price' => 348, 'billing_cycle' => 'yearly']);
        $this->qualifiedReferral('business');

        app(Referrals::class)->rewardBusiness($this->business->id);
        app(Referrals::class)->rewardBusiness($this->business->id);
        $this->artisan('referrals:apply-rewards')->assertSuccessful();

        $this->assertCount(1, $stripe->calls);
        $this->assertSame('cus_123', $stripe->calls[0]['customerId']);
        $this->assertSame(2900, $stripe->calls[0]['cents']); // $348 a year → $29 a month
        $reward = ReferralReward::sole();
        $this->assertSame('thryft-referral-reward-' . $reward->id, $stripe->calls[0]['idempotencyKey']);
        $this->assertSame('stripe_credit', $reward->method);
        $this->assertSame('cbtxn_1', $reward->stripe_balance_transaction_id);
    }

    public function test_ten_qualified_shoppers_earn_one_free_month()
    {
        $this->fakeStripe();
        foreach (range(1, 9) as $i) {
            $this->qualifiedReferral('shopper');
        }
        app(Referrals::class)->rewardBusiness($this->business->id);

        $this->assertSame(0, ReferralReward::count());
        $panel = $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/referrals')->json('data');
        $this->assertSame(9, $panel['shoppers_toward_next']);

        $this->qualifiedReferral('shopper');
        $this->qualifiedReferral('shopper');
        app(Referrals::class)->rewardBusiness($this->business->id);

        $this->assertSame(1, ReferralReward::count());
        $this->assertSame(10, Referral::where('status', 'rewarded')->count());
        $this->assertSame(1, Referral::where('status', 'qualified')->count());
        $panel = $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/referrals')->json('data');
        $this->assertSame(1, $panel['shoppers_toward_next']);
        $this->assertSame('shoppers', $panel['rewards'][0]['reason']);
    }

    public function test_a_reward_waits_for_an_active_plan_then_the_daily_run_applies_it()
    {
        $this->owner->userSubscriptions()->update(['status' => 'expired', 'ends_at' => now()->subDay(), 'current_period_end' => now()->subDay()]);
        $referral = $this->qualifiedReferral('business');

        app(Referrals::class)->rewardBusiness($this->business->id);
        $this->assertNull(ReferralReward::sole()->applied_at);
        $this->assertSame('qualified', $referral->fresh()->status);

        UserSubscription::factory()->create(['user_id' => $this->owner->id, 'stripe_subscription_id' => null, 'ends_at' => now()->addMonth()]);
        $this->artisan('referrals:apply-rewards')->assertSuccessful();

        $this->assertNotNull(ReferralReward::sole()->applied_at);
        $this->assertSame('rewarded', $referral->fresh()->status);
    }
}
