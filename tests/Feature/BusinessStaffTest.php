<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Managing the team from the app: owners and admins add people by the email
 * of their Thryft account, change their role, and remove them. The member
 * then sees the merchant tools without holding a plan of their own.
 */
class BusinessStaffTest extends TestCase
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

    private function add(User $actor, string $email, string $role = 'staff')
    {
        return $this->actingAs($actor, 'sanctum')->postJson('/api/business/staff', [
            'email' => $email,
            'role' => $role,
        ]);
    }

    public function test_owner_sees_the_team_with_themselves_first()
    {
        $barista = User::factory()->create(['name' => 'Bea Barista']);
        BusinessMember::factory()->staff()->create([
            'business_id' => $this->business->id,
            'user_id' => $barista->id,
        ]);

        $data = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/business/staff')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame('Fernwood Coffee', $data['business']['name']);
        $this->assertCount(2, $data['members']);
        $this->assertSame('owner', $data['members'][0]['role']);
        $this->assertTrue($data['members'][0]['is_you']);
        $this->assertSame('Bea Barista', $data['members'][1]['name']);
        $this->assertSame('staff', $data['members'][1]['role']);
        $this->assertSame(['admin', 'manager', 'staff'], array_column($data['roles'], 'value'));
    }

    public function test_owner_adds_a_member_by_email_who_then_has_access()
    {
        $newHire = User::factory()->create(['email' => 'Sam@Example.com']);

        // Before: a plain shopper, no merchant tools, sent to checkout.
        $this->actingAs($newHire, 'sanctum')->getJson('/api/user')
            ->assertStatus(200)
            ->assertJsonPath('business_access', []);
        $this->actingAs($newHire, 'sanctum')->getJson('/api/subscriptions/check')
            ->assertJsonPath('data.hasActiveSubscription', false);

        $this->add($this->owner, 'sam@example.com', 'manager')
            ->assertStatus(201)
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.email', 'Sam@Example.com');

        // After: the app learns what they may do, without a plan of their own.
        $access = $this->actingAs($newHire, 'sanctum')->getJson('/api/user')
            ->assertStatus(200)
            ->json('business_access');
        $this->assertCount(1, $access);
        $this->assertSame($this->business->id, $access[0]['business_id']);
        $this->assertSame('manager', $access[0]['role']);
        $this->assertContains('business.manage_offers', $access[0]['abilities']);
        $this->assertNotContains('business.manage_staff', $access[0]['abilities']);

        $this->actingAs($newHire, 'sanctum')->getJson('/api/subscriptions/check')
            ->assertJsonPath('data.hasActiveSubscription', true)
            ->assertJsonPath('data.covered_by_business', true);

        $this->actingAs($newHire, 'sanctum')->getJson('/api/coupons')->assertStatus(200);
        $this->actingAs($newHire, 'sanctum')->getJson('/api/business/staff')->assertStatus(403);

        // And they were told.
        $this->assertDatabaseHas('notification_user', ['user_id' => $newHire->id]);
    }

    public function test_adding_requires_an_existing_account_and_no_duplicates()
    {
        $this->add($this->owner, 'nobody@example.com')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->add($this->owner, $this->owner->email)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $hire = User::factory()->create();
        $this->add($this->owner, $hire->email)->assertStatus(201);
        $this->add($this->owner, $hire->email)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        $this->add($this->owner, $hire->email, 'owner')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_admin_can_manage_the_team_but_manager_and_staff_cannot()
    {
        $admin = User::factory()->create();
        BusinessMember::factory()->admin()->create(['business_id' => $this->business->id, 'user_id' => $admin->id]);
        $manager = User::factory()->create();
        BusinessMember::factory()->manager()->create(['business_id' => $this->business->id, 'user_id' => $manager->id]);
        $staff = User::factory()->create();
        BusinessMember::factory()->staff()->create(['business_id' => $this->business->id, 'user_id' => $staff->id]);

        $hire = User::factory()->create();
        $this->add($admin, $hire->email)->assertStatus(201);
        $this->add($manager, User::factory()->create()->email)->assertStatus(403);
        $this->add($staff, User::factory()->create()->email)->assertStatus(403);
    }

    public function test_role_change_and_removal_take_effect_immediately()
    {
        $hire = User::factory()->create();
        $memberId = $this->add($this->owner, $hire->email, 'staff')->json('data.id');

        // Staff cannot see offers...
        $this->actingAs($hire, 'sanctum')->getJson('/api/coupons')->assertStatus(403);

        // ...until promoted.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/business/staff/{$memberId}", ['role' => 'manager'])
            ->assertStatus(200)
            ->assertJsonPath('data.role', 'manager');
        $this->actingAs($hire, 'sanctum')->getJson('/api/coupons')->assertStatus(200);

        // Removed: gone from the list and locked out on the next request.
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/business/staff/{$memberId}")
            ->assertStatus(200);
        $this->assertDatabaseHas('business_members', ['id' => $memberId, 'status' => 'revoked']);
        $this->actingAs($hire, 'sanctum')->getJson('/api/coupons')->assertStatus(403);

        $members = $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/staff')->json('data.members');
        $this->assertCount(1, $members);

        // Re-adding reuses the row instead of tripping the unique index.
        $this->add($this->owner, $hire->email, 'admin')
            ->assertStatus(201)
            ->assertJsonPath('data.id', $memberId)
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_the_owner_and_yourself_are_protected()
    {
        $ownerRow = BusinessMember::where('business_id', $this->business->id)->where('role', 'owner')->first();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/business/staff/{$ownerRow->id}", ['role' => 'staff'])
            ->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/business/staff/{$ownerRow->id}")
            ->assertStatus(422);

        $admin = User::factory()->create();
        $adminRow = BusinessMember::factory()->admin()->create(['business_id' => $this->business->id, 'user_id' => $admin->id]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/business/staff/{$adminRow->id}")
            ->assertStatus(422);
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/business/staff/{$adminRow->id}", ['role' => 'staff'])
            ->assertStatus(422);

        // A member of another business is not reachable through this one.
        $otherOwner = User::factory()->create(['role' => 'business']);
        $other = Business::factory()->forOwner($otherOwner)->create();
        $otherRow = BusinessMember::factory()->staff()->create(['business_id' => $other->id, 'user_id' => User::factory()->create()->id]);
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/business/staff/{$otherRow->id}")
            ->assertStatus(404);
    }
}
