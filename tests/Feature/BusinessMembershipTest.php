<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Coupon;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Staff accounts: who may act for a business and what they may do.
 *
 * The behaviour this replaces is users.role === 'business' — a single string
 * meaning "can do everything for the one business that IS this login". The
 * only way to give an employee access was to share the owner's password.
 */
class BusinessMembershipTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A business that is paid up, so the subscription gate never masks the
     * permission behaviour under test.
     *
     * @return array{0: Business, 0: User}
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

    /**
     * A member of someone else's business. Their own account holds no
     * subscription — the business's does.
     */
    private function memberOf(Business $business, string $role): User
    {
        $user = User::factory()->create();

        BusinessMember::factory()->{$role}()->create([
            'business_id' => $business->id,
            'user_id' => $user->id,
        ]);

        // Deliberately NO subscription of their own: entitlement belongs to the
        // business they work for. Requiring staff to buy their own plan would
        // make staff accounts unusable.
        return $user;
    }

    // -----------------------------------------------------------------
    // Ability enforcement
    // -----------------------------------------------------------------

    public function test_owner_can_manage_offers()
    {
        [, $owner] = $this->paidBusiness();

        $this->actingAs($owner, 'sanctum')->getJson('/api/coupons')->assertStatus(200);
    }

    public function test_manager_can_manage_offers()
    {
        [$business] = $this->paidBusiness();
        $manager = $this->memberOf($business, 'manager');

        $this->actingAs($manager, 'sanctum')->getJson('/api/coupons')->assertStatus(200);
    }

    public function test_staff_can_redeem_but_cannot_manage_offers()
    {
        [$business] = $this->paidBusiness();
        $staff = $this->memberOf($business, 'staff');

        // The whole point of the split: front of house honours coupons without
        // being able to change what is on offer.
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/coupons/redeem', ['code' => 'NOPE1234'])
            ->assertStatus(404);   // reached the controller; no such coupon

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/coupons')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ability_required');
    }

    public function test_revoked_member_loses_access_immediately()
    {
        [$business] = $this->paidBusiness();
        $manager = $this->memberOf($business, 'manager');

        $this->actingAs($manager, 'sanctum')->getJson('/api/coupons')->assertStatus(200);

        BusinessMember::where('business_id', $business->id)
            ->where('user_id', $manager->id)
            ->update(['status' => 'revoked']);

        // Checked per request against the database, so no token juggling is
        // needed to cut someone off.
        $this->actingAs($manager, 'sanctum')->getJson('/api/coupons')->assertStatus(403);
    }

    public function test_invited_but_not_accepted_member_has_no_access()
    {
        [$business] = $this->paidBusiness();
        $pending = User::factory()->create();
        BusinessMember::factory()->manager()->invited()->create([
            'business_id' => $business->id,
            'user_id' => $pending->id,
        ]);

        $this->actingAs($pending, 'sanctum')->getJson('/api/coupons')->assertStatus(403);
    }

    public function test_staff_need_no_subscription_of_their_own()
    {
        [$business] = $this->paidBusiness();
        $manager = $this->memberOf($business, 'manager');

        $this->assertNull($manager->activeSubscription(), 'precondition: the member holds no plan');
        $this->assertTrue($business->hasActiveSubscription());

        // Entitlement belongs to the business. The gate originally checked the
        // acting user, which meant every employee had to buy their own plan.
        $this->actingAs($manager, 'sanctum')->getJson('/api/coupons')->assertStatus(200);
    }

    public function test_whole_team_is_blocked_when_the_business_stops_paying()
    {
        [$business, $owner] = $this->paidBusiness();
        $manager = $this->memberOf($business, 'manager');

        UserSubscription::where('user_id', $owner->id)->update(['status' => 'cancelled']);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/coupons')
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_required');
    }

    public function test_stranger_has_no_business_context()
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/coupons')
            ->assertStatus(403)
            ->assertJsonPath('code', 'no_business_context');
    }

    // -----------------------------------------------------------------
    // Business resolution
    // -----------------------------------------------------------------

    public function test_sole_membership_resolves_without_any_request_hint()
    {
        // This is what keeps the current frontend working unchanged: it sends
        // neither a route parameter nor a header.
        [$business] = $this->paidBusiness();
        $manager = $this->memberOf($business, 'manager');

        $this->assertSame($business->id, $manager->currentBusiness()?->id);
    }

    public function test_header_selects_among_several_businesses()
    {
        [$first] = $this->paidBusiness();
        [$second] = $this->paidBusiness();

        $user = User::factory()->create();

        foreach ([$first, $second] as $business) {
            BusinessMember::factory()->manager()->create([
                'business_id' => $business->id,
                'user_id' => $user->id,
            ]);
        }

        Coupon::factory()->create(['user_id' => $second->owner_user_id, 'business_id' => $second->id]);

        config(['thryft.use_business_entity' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Business-Id', (string) $second->id)
            ->getJson('/api/coupons');

        $response->assertStatus(200);
    }

    public function test_header_naming_a_business_you_do_not_belong_to_is_refused()
    {
        [$mine] = $this->paidBusiness();
        [$theirs] = $this->paidBusiness();

        $user = $this->memberOf($mine, 'manager');

        // Must NOT silently fall back to the caller's own business — that would
        // make a forged header operate on their records under someone else's id.
        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Business-Id', (string) $theirs->id)
            ->getJson('/api/coupons')
            ->assertStatus(403)
            ->assertJsonPath('code', 'no_business_context');
    }

    // -----------------------------------------------------------------
    // Abilities and policies
    // -----------------------------------------------------------------

    public function test_role_ability_matrix()
    {
        [$business] = $this->paidBusiness();

        $expectations = [
            'owner' => ['business.manage_billing' => true,  'business.redeem' => true],
            'admin' => ['business.manage_billing' => false, 'business.manage_staff' => true],
            'manager' => ['business.manage_staff' => false, 'business.manage_offers' => true],
            'staff' => ['business.manage_offers' => false,  'business.redeem' => true],
        ];

        foreach ($expectations as $role => $abilities) {
            $member = BusinessMember::factory()->{$role}()->create(['business_id' => $business->id]);

            foreach ($abilities as $ability => $expected) {
                $this->assertSame(
                    $expected,
                    $member->hasAbility($ability),
                    "{$role} / {$ability}"
                );
            }
        }
    }

    public function test_per_member_grant_extends_the_role()
    {
        [$business] = $this->paidBusiness();

        $member = BusinessMember::factory()->staff()->create([
            'business_id' => $business->id,
            'permissions' => ['business.view_analytics'],
        ]);

        $this->assertTrue($member->hasAbility('business.view_analytics'));
        // The grant is additive, not a replacement.
        $this->assertTrue($member->hasAbility('business.redeem'));
        $this->assertFalse($member->hasAbility('business.manage_billing'));
    }

    public function test_super_admin_gate_short_circuits_policies()
    {
        $admin = User::factory()->create(['role' => 'super-admin']);
        $coupon = Coupon::factory()->create();

        // Gate::before must return true for super admins and null — not false —
        // for everyone else, or it would deny every other check.
        $this->assertTrue(Gate::forUser($admin)->allows('update', $coupon));

        $stranger = User::factory()->create();
        $this->assertFalse(Gate::forUser($stranger)->allows('update', $coupon));
    }

    public function test_coupon_policy_follows_membership()
    {
        [$business, $owner] = $this->paidBusiness();
        $coupon = Coupon::factory()->create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
        ]);

        $manager = $this->memberOf($business, 'manager');
        $staff = $this->memberOf($business, 'staff');

        $this->assertTrue(Gate::forUser($manager)->allows('update', $coupon));
        $this->assertFalse(Gate::forUser($staff)->allows('update', $coupon));
        $this->assertTrue(Gate::forUser($staff)->allows('redeem', $coupon));
    }

    // -----------------------------------------------------------------
    // Backfill
    // -----------------------------------------------------------------

    public function test_owner_without_a_membership_row_still_has_access()
    {
        // Businesses created before business_members existed, and accounts
        // promoted to 'business' after the backfill ran.
        $owner = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $owner->id]);
        Business::factory()->forOwner($owner)->create();

        $this->actingAs($owner, 'sanctum')->getJson('/api/coupons')->assertStatus(200);
    }
}
