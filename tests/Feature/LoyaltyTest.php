<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\LoyaltyCard;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Loyalty: a merchant sets up a punch card, a shopper joins, staff stamp it
 * at the till, and a full card banks a reward that is given out once.
 */
class LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $staff;
    private User $shopper;
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business', 'business_name' => 'Fernwood Coffee']);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create(['name' => 'Fernwood Coffee']);
        BusinessMember::factory()->owner()->create(['business_id' => $this->business->id, 'user_id' => $this->owner->id]);

        $this->staff = User::factory()->create();
        BusinessMember::factory()->create(['business_id' => $this->business->id, 'user_id' => $this->staff->id, 'role' => 'staff']);

        $this->shopper = User::factory()->create(['firstname' => 'Sam', 'lastname' => 'Shopper']);
    }

    private function program(int $stamps = 3): array
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/loyalty', [
            'title' => 'Coffee card',
            'reward' => 'A free drink',
            'stamps_required' => $stamps,
        ])->assertStatus(201)->json('data');
    }

    private function joined(array $program): string
    {
        return $this->actingAs($this->shopper, 'sanctum')
            ->postJson("/api/loyalty/{$program['id']}/join")
            ->assertStatus(201)
            ->json('data.code');
    }

    public function test_a_full_card_banks_one_reward_that_staff_give_out_once()
    {
        $program = $this->program(3);
        $code = $this->joined($program);
        $this->assertMatchesRegularExpression('/^LOY-[A-Z2-9]{8}$/', $code);

        // Guests see the program; the shopper sees their card on it.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/business/{$this->owner->id}/loyalty")->assertJsonPath('data.0.card', null);
        $this->actingAs($this->shopper, 'sanctum')->getJson("/api/business/{$this->owner->id}/loyalty")
            ->assertJsonPath('data.0.card.code', $code);

        foreach (range(1, 3) as $i) {
            $this->travel(11)->minutes();
            $this->actingAs($this->staff, 'sanctum')->postJson('/api/business/till/' . strtolower($code), ['action' => 'stamp'])->assertStatus(200);
        }

        $view = $this->actingAs($this->staff, 'sanctum')->getJson("/api/business/till/{$code}")->assertStatus(200)->json('data');
        $this->assertSame(0, $view['stamps']);
        $this->assertSame(1, $view['rewards_available']);
        $this->assertSame('Sam Shopper', $view['customer']);
        $this->assertContains('redeem', array_column($view['actions'], 'action'));

        $this->actingAs($this->staff, 'sanctum')->postJson("/api/business/till/{$code}", ['action' => 'redeem'])->assertStatus(200);
        $this->actingAs($this->staff, 'sanctum')->postJson("/api/business/till/{$code}", ['action' => 'redeem'])->assertStatus(422);

        $wallet = $this->actingAs($this->shopper, 'sanctum')->getJson('/api/my/wallet')->json('data.loyalty');
        $this->assertSame(0, $wallet[0]['rewards_available']);
        $this->assertSame('Fernwood Coffee', $wallet[0]['business']['name']);
    }

    public function test_a_double_scan_does_not_stamp_twice()
    {
        $code = $this->joined($this->program());

        $this->actingAs($this->staff, 'sanctum')->postJson("/api/business/till/{$code}", ['action' => 'stamp'])->assertStatus(200);
        $this->actingAs($this->staff, 'sanctum')->postJson("/api/business/till/{$code}", ['action' => 'stamp'])->assertStatus(429);
        $this->assertSame(1, LoyaltyCard::sole()->stamps);
    }

    public function test_another_business_cannot_see_or_stamp_the_card()
    {
        $code = $this->joined($this->program());

        $rival = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $rival->id]);
        $rivalBusiness = Business::factory()->forOwner($rival)->create();
        BusinessMember::factory()->owner()->create(['business_id' => $rivalBusiness->id, 'user_id' => $rival->id]);

        $this->actingAs($rival, 'sanctum')->getJson("/api/business/till/{$code}")->assertStatus(404);
        $this->actingAs($rival, 'sanctum')->postJson("/api/business/till/{$code}", ['action' => 'stamp'])->assertStatus(404);
    }

    public function test_joining_twice_keeps_one_card_and_ending_a_program_keeps_the_stamps()
    {
        $program = $this->program();
        $first = $this->joined($program);
        $this->assertSame($first, $this->joined($program));

        $this->actingAs($this->staff, 'sanctum')->postJson("/api/business/till/{$first}", ['action' => 'stamp']);
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/business/loyalty/{$program['id']}")->assertStatus(200);

        $this->assertSame(1, LoyaltyCard::sole()->stamps);
        $this->travel(11)->minutes();
        $this->actingAs($this->staff, 'sanctum')->postJson("/api/business/till/{$first}", ['action' => 'stamp'])->assertStatus(422);
        $this->getJson("/api/business/{$this->owner->id}/loyalty")->assertJsonCount(0, 'data');
    }

    public function test_staff_cannot_set_up_programs()
    {
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/business/loyalty', [
            'title' => 'Mine', 'reward' => 'x', 'stamps_required' => 5,
        ])->assertStatus(403);
    }

    public function test_the_app_id_is_the_owners_even_when_it_matches_another_business_id()
    {
        // A newer owner whose business id differs from their user id, while
        // some other business happens to have the id equal to that user id.
        $owner = User::factory()->create(['role' => 'business']);
        Business::factory()->create(['id' => $owner->id]);
        $theirs = Business::factory()->create(['id' => $owner->id + 500, 'owner_user_id' => $owner->id]);
        \App\Models\LoyaltyProgram::create(['business_id' => $theirs->id, 'title' => 'Theirs', 'reward' => 'x', 'stamps_required' => 3]);

        $this->getJson("/api/business/{$owner->id}/loyalty")->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Theirs');
    }
}
