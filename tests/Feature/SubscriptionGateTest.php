<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The paywall. Before this middleware existed, access came purely from
 * users.role === 'business' and nothing ever revoked that role — so a business
 * that cancelled, or whose card died, kept full API access forever. The only
 * check lived in the Vue router, which any direct API call bypasses.
 */
class SubscriptionGateTest extends TestCase
{
    use RefreshDatabase;

    private function businessUser(): User
    {
        return User::factory()->create(['role' => 'business']);
    }

    private function listCoupons(User $user)
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/coupons');
    }

    public function test_business_with_active_subscription_is_allowed()
    {
        $user = $this->businessUser();
        UserSubscription::factory()->create(['user_id' => $user->id]);

        $this->listCoupons($user)->assertStatus(200);
    }

    public function test_business_with_no_subscription_is_blocked()
    {
        // The regression case: role alone used to be enough.
        $user = $this->businessUser();

        $this->listCoupons($user)
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required');
    }

    public function test_business_with_cancelled_subscription_is_blocked()
    {
        $user = $this->businessUser();
        UserSubscription::factory()->cancelled()->create(['user_id' => $user->id]);

        $this->listCoupons($user)->assertStatus(402);
    }

    public function test_business_whose_period_lapsed_is_blocked()
    {
        $user = $this->businessUser();
        UserSubscription::factory()->expired()->create(['user_id' => $user->id]);

        $this->listCoupons($user)->assertStatus(402);
    }

    /**
     * Card declines are routine and Stripe retries for days. Revoking on the
     * first failed invoice would lock out paying customers over a temporary
     * bank decline.
     */
    public function test_past_due_business_keeps_access_during_grace()
    {
        $user = $this->businessUser();
        UserSubscription::factory()->pastDueInGrace()->create(['user_id' => $user->id]);

        $this->listCoupons($user)->assertStatus(200);
    }

    public function test_past_due_business_is_blocked_once_grace_lapses()
    {
        $user = $this->businessUser();
        UserSubscription::factory()->pastDueGraceLapsed()->create(['user_id' => $user->id]);

        $this->listCoupons($user)->assertStatus(402);
    }

    public function test_future_dated_subscription_does_not_grant_access_yet()
    {
        $user = $this->businessUser();
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'starts_at' => now()->addWeek(),
        ]);

        $this->listCoupons($user)->assertStatus(402);
    }

    public function test_super_admin_passes_without_a_subscription()
    {
        // Admins administer businesses; they must not need to buy a plan.
        $admin = User::factory()->create(['role' => 'super-admin']);

        // Still 403 from the role middleware, but crucially NOT 402 — the
        // subscription gate is not what stops them.
        $this->listCoupons($admin)->assertStatus(403);
    }

    public function test_plan_product_limit_is_enforced()
    {
        $plan = Subscription::factory()->withLimits(maxProducts: 1)->create();
        $user = $this->businessUser();
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $plan->id,
        ]);

        $payload = fn (string $name) => ['name' => $name, 'description' => 'x'];

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', $payload('First'))
            ->assertStatus(201);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/products', $payload('Second'))
            ->assertStatus(402)
            ->assertJsonPath('code', 'plan_limit_reached');
    }

    public function test_absent_plan_limit_means_unlimited()
    {
        // Metadata with no max_products must not be read as zero, or every
        // business on a plan whose metadata was never filled in is locked out.
        $plan = Subscription::factory()->create(['metadata' => []]);
        $user = $this->businessUser();
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $plan->id,
        ]);

        foreach (['A', 'B', 'C'] as $name) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/products', ['name' => $name, 'description' => 'x'])
                ->assertStatus(201);
        }
    }
}
