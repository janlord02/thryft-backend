<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * claim-free used to end every free subscription on a hard-coded 2025-12-27.
 * After that date each claim was born expired, and the business was locked
 * out with no way back: clicking Free again either said "already on the free
 * plan" (row still 'active') or "can only be claimed once" (row 'expired').
 */
class ClaimFreePlanTest extends TestCase
{
    use RefreshDatabase;

    private function freePlan(array $metadata = []): Subscription
    {
        return Subscription::factory()->free()->create(['metadata' => $metadata]);
    }

    private function strandedFreeSubscription(User $user, Subscription $plan, string $status)
    {
        return UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $plan->id,
            'status' => $status,
            'starts_at' => now()->subMonths(2),
            'ends_at' => '2025-12-27 23:59:59',
        ]);
    }

    private function hasAccess(User $user): bool
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/subscriptions/check')
            ->json('data.hasActiveSubscription');
    }

    public function test_new_free_claim_grants_access()
    {
        $this->freePlan();
        $user = User::factory()->create(['role' => 'business']);

        $this->actingAs($user, 'sanctum')->postJson('/api/subscriptions/claim-free')->assertOk();

        $this->assertTrue($this->hasAccess($user));
    }

    public function test_free_plan_end_date_comes_from_the_plan()
    {
        $this->freePlan(['expires_at' => now()->addMonths(4)->toDateString()]);
        $user = User::factory()->create(['role' => 'business']);

        $this->actingAs($user, 'sanctum')->postJson('/api/subscriptions/claim-free')->assertOk();

        $this->assertTrue(
            $user->activeSubscription()->ends_at->isSameDay(now()->addMonths(4))
        );
    }

    public function test_stranded_active_free_subscription_is_revived()
    {
        $plan = $this->freePlan();
        $user = User::factory()->create(['role' => 'business']);
        $this->strandedFreeSubscription($user, $plan, 'active');
        $this->assertFalse($this->hasAccess($user));

        $this->actingAs($user, 'sanctum')->postJson('/api/subscriptions/claim-free')->assertOk();

        $this->assertTrue($this->hasAccess($user));
    }

    public function test_free_claim_that_never_ran_does_not_count_as_claimed()
    {
        $plan = $this->freePlan();
        $user = User::factory()->create(['role' => 'business']);
        $this->strandedFreeSubscription($user, $plan, 'expired');

        $this->actingAs($user, 'sanctum')->postJson('/api/subscriptions/claim-free')->assertOk();

        $this->assertTrue($this->hasAccess($user));
    }

    public function test_free_plan_still_claimable_only_once()
    {
        $plan = $this->freePlan();
        $user = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create([
            'user_id' => $user->id,
            'subscription_id' => $plan->id,
            'status' => 'expired',
            'starts_at' => now()->subMonths(3),
            'ends_at' => now()->subMonth(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/subscriptions/claim-free')->assertStatus(400);
    }
}
