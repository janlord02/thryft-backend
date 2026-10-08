<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Event;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Events: a merchant publishes one, followers hear about it, shoppers and
 * guests can find it, registration respects capacity, and the public page
 * is crawlable.
 */
class EventsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business', 'business_name' => 'Fernwood Coffee']);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create(['name' => 'Fernwood Coffee', 'slug' => 'fernwood-coffee']);
        BusinessMember::factory()->owner()->create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
        ]);
        $this->business->locations()->create([
            'label' => 'Main',
            'address' => '12 Elm St',
            'city' => 'Davao',
            'latitude' => 7.06,
            'longitude' => 125.55,
            'is_primary' => true,
        ]);
    }

    private function notificationsFor(User $user): array
    {
        return DB::table('notification_user')
            ->join('notifications', 'notifications.id', '=', 'notification_user.notification_id')
            ->where('notification_user.user_id', $user->id)
            ->get()
            ->map(fn ($row) => json_decode($row->data, true) + ['title' => $row->title])
            ->all();
    }

    // -----------------------------------------------------------------
    // Merchant side
    // -----------------------------------------------------------------

    public function test_owner_publishes_an_event_at_the_business_and_followers_are_told()
    {
        $follower = User::factory()->create();
        $follower->favoriteBusinesses()->attach($this->owner->id);

        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/events', [
            'title' => 'Latte Art Night',
            'description' => 'Learn to pour a rosetta.',
            'starts_at' => now()->addDays(5)->setTime(18, 0)->toIso8601String(),
            'ends_at' => now()->addDays(5)->setTime(20, 0)->toIso8601String(),
            'capacity' => 12,
            'status' => 'published',
        ])->assertStatus(201);

        $event = Event::find($response->json('data.id'));
        $this->assertSame('published', $event->status);
        $this->assertNotNull($event->published_at);
        $this->assertNotEmpty($event->slug);
        // No venue given, so the event is at the business.
        $this->assertSame('12 Elm St', $event->address);
        $this->assertSame('Davao', $event->city);
        $this->assertEqualsWithDelta(7.06, (float) $event->latitude, 0.0001);

        $alerts = $this->notificationsFor($follower);
        $this->assertCount(1, $alerts);
        $this->assertSame('new_event', $alerts[0]['kind']);
        $this->assertSame("/user/events/{$event->slug}", $alerts[0]['action_url']);
        $this->assertSame('New event at Fernwood Coffee', $alerts[0]['title']);

        // Editing later must not announce again.
        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/business/events/{$event->id}", [
                'title' => 'Latte Art Night (moved)',
                'starts_at' => now()->addDays(6)->setTime(18, 0)->toIso8601String(),
                'status' => 'published',
            ])
            ->assertStatus(200);
        $this->assertCount(1, $this->notificationsFor($follower));
    }

    public function test_a_draft_is_private_until_published()
    {
        $follower = User::factory()->create();
        $follower->favoriteBusinesses()->attach($this->owner->id);

        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/events', [
            'title' => 'Secret tasting',
            'starts_at' => now()->addDays(3)->toIso8601String(),
        ])->assertStatus(201)->json('data.id');

        $event = Event::find($id);
        $this->assertSame('draft', $event->status);
        $this->assertCount(0, $this->notificationsFor($follower));

        $this->getJson('/api/events')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson("/api/events/{$event->slug}")->assertStatus(404);
        $this->get("/e/{$event->slug}")->assertStatus(404);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/business/events/{$id}", [
                'title' => 'Secret tasting',
                'starts_at' => now()->addDays(3)->toIso8601String(),
                'status' => 'published',
            ])
            ->assertStatus(200);

        $this->assertCount(1, $this->notificationsFor($follower));
        $this->getJson('/api/events')->assertJsonCount(1, 'data');
    }

    public function test_manager_can_manage_events_but_staff_cannot()
    {
        $manager = User::factory()->create();
        BusinessMember::factory()->manager()->create(['business_id' => $this->business->id, 'user_id' => $manager->id]);
        $staff = User::factory()->create();
        BusinessMember::factory()->staff()->create(['business_id' => $this->business->id, 'user_id' => $staff->id]);

        $payload = ['title' => 'Open mic', 'starts_at' => now()->addDays(2)->toIso8601String()];
        $this->actingAs($manager, 'sanctum')->postJson('/api/business/events', $payload)->assertStatus(201);
        $this->actingAs($staff, 'sanctum')->postJson('/api/business/events', $payload)->assertStatus(403);
        $this->actingAs($staff, 'sanctum')->getJson('/api/business/events')->assertStatus(403);
    }

    public function test_another_business_cannot_touch_my_event()
    {
        $event = Event::factory()->create(['business_id' => $this->business->id]);

        $otherOwner = User::factory()->create(['role' => 'business']);
        UserSubscription::factory()->create(['user_id' => $otherOwner->id]);
        $other = Business::factory()->forOwner($otherOwner)->create();
        BusinessMember::factory()->owner()->create(['business_id' => $other->id, 'user_id' => $otherOwner->id]);

        $this->actingAs($otherOwner, 'sanctum')->getJson("/api/business/events/{$event->id}")->assertStatus(404);
        $this->actingAs($otherOwner, 'sanctum')->deleteJson("/api/business/events/{$event->id}")->assertStatus(404);
        $this->assertDatabaseHas('events', ['id' => $event->id, 'deleted_at' => null]);
    }

    // -----------------------------------------------------------------
    // Shopper side
    // -----------------------------------------------------------------

    public function test_guests_see_upcoming_published_events_nearby()
    {
        Event::factory()->create(['business_id' => $this->business->id, 'title' => 'Near and soon', 'latitude' => 7.061, 'longitude' => 125.551]);
        Event::factory()->past()->create(['business_id' => $this->business->id, 'title' => 'Already happened']);
        Event::factory()->draft()->create(['business_id' => $this->business->id, 'title' => 'Still a draft']);
        Event::factory()->cancelled()->create(['business_id' => $this->business->id, 'title' => 'Called off']);
        Event::factory()->create(['business_id' => $this->business->id, 'title' => 'Far away', 'latitude' => 40.7, 'longitude' => -74.0]);

        $titles = collect($this->getJson('/api/events?latitude=7.06&longitude=125.55&radius=10')
            ->assertStatus(200)
            ->json('data'))->pluck('title')->all();

        $this->assertSame(['Near and soon'], $titles);

        $all = collect($this->getJson('/api/events')->assertStatus(200)->json('data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Near and soon', 'Far away'], $all);

        $one = $this->getJson('/api/events?business_id=' . $this->owner->id)->json('data');
        $this->assertCount(2, $one);
        $this->assertSame('Fernwood Coffee', $one[0]['business']['name']);
        $this->assertFalse($one[0]['is_registered']);
        $this->assertArrayNotHasKey('registered_count', $one[0]);
    }

    public function test_registration_respects_capacity_and_is_idempotent()
    {
        $event = Event::factory()->withCapacity(2, 1)->create(['business_id' => $this->business->id]);
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->actingAs($alice, 'sanctum')->postJson("/api/events/{$event->id}/register")
            ->assertStatus(200)
            ->assertJsonPath('data.already_registered', false)
            ->assertJsonPath('data.event.is_registered', true)
            ->assertJsonPath('data.event.spots_left', 0);

        // A retry finds the existing seat instead of taking a second one.
        $this->actingAs($alice, 'sanctum')->postJson("/api/events/{$event->id}/register")
            ->assertStatus(200)
            ->assertJsonPath('data.already_registered', true);
        $this->assertSame(2, $event->fresh()->registered_count);

        // Full: the conditional update refuses, nothing is written.
        $this->actingAs($bob, 'sanctum')->postJson("/api/events/{$event->id}/register")
            ->assertStatus(409)
            ->assertJsonPath('code', 'event_full');
        $this->assertDatabaseMissing('event_registrations', ['event_id' => $event->id, 'user_id' => $bob->id]);

        // Alice gives her seat back; Bob gets it. A second cancel changes nothing.
        $this->actingAs($alice, 'sanctum')->deleteJson("/api/events/{$event->id}/register")->assertStatus(200);
        $this->assertSame(1, $event->fresh()->registered_count);
        $this->actingAs($alice, 'sanctum')->deleteJson("/api/events/{$event->id}/register")->assertStatus(200);
        $this->assertSame(1, $event->fresh()->registered_count);

        $this->actingAs($bob, 'sanctum')->postJson("/api/events/{$event->id}/register")->assertStatus(200);
        $this->assertSame(2, $event->fresh()->registered_count);

        $mine = $this->actingAs($bob, 'sanctum')->getJson('/api/my/events')->assertStatus(200)->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame($event->id, $mine[0]['id']);

        // The host sees who is coming.
        $list = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/business/events/{$event->id}/registrations")
            ->assertStatus(200)
            ->json('data.registrations');
        $this->assertSame([$bob->name], array_column($list, 'name'));
    }

    public function test_registration_needs_an_account_and_an_open_event()
    {
        $event = Event::factory()->create(['business_id' => $this->business->id]);
        $this->postJson("/api/events/{$event->id}/register")->assertStatus(401);

        $shopper = User::factory()->create();
        $closed = Event::factory()->create(['business_id' => $this->business->id, 'registration_enabled' => false]);
        $this->actingAs($shopper, 'sanctum')->postJson("/api/events/{$closed->id}/register")
            ->assertStatus(409)->assertJsonPath('code', 'registration_closed');

        $past = Event::factory()->past()->create(['business_id' => $this->business->id]);
        $this->actingAs($shopper, 'sanctum')->postJson("/api/events/{$past->id}/register")
            ->assertStatus(409)->assertJsonPath('code', 'event_over');

        $draft = Event::factory()->draft()->create(['business_id' => $this->business->id]);
        $this->actingAs($shopper, 'sanctum')->postJson("/api/events/{$draft->id}/register")->assertStatus(404);
    }

    public function test_cancelling_a_published_event_with_attendees_tells_them()
    {
        $event = Event::factory()->create(['business_id' => $this->business->id, 'title' => 'Cupping session']);
        $shopper = User::factory()->create();
        $this->actingAs($shopper, 'sanctum')->postJson("/api/events/{$event->id}/register")->assertStatus(200);

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/business/events/{$event->id}")->assertStatus(200);

        $this->assertSame('cancelled', $event->fresh()->status);
        $notes = $this->notificationsFor($shopper);
        $this->assertCount(1, $notes);
        $this->assertSame('event_cancelled', $notes[0]['kind']);
        $this->assertSame('Cancelled: Cupping session', $notes[0]['title']);

        // Gone from listings, but the merchant still has the record.
        $this->getJson('/api/events')->assertJsonCount(0, 'data');
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/business/events')->assertJsonCount(1, 'data');
    }

    // -----------------------------------------------------------------
    // Public page
    // -----------------------------------------------------------------

    public function test_public_event_page_is_crawlable_and_in_the_sitemap()
    {
        $event = Event::factory()->create([
            'business_id' => $this->business->id,
            'title' => 'Latte Art Night',
            'description' => 'Learn to pour a rosetta.',
            'venue_name' => 'Fernwood Coffee',
            'address' => '12 Elm St',
            'city' => 'Davao',
        ]);

        $html = $this->get("/e/{$event->slug}")->assertStatus(200)->getContent();

        $this->assertStringContainsString('<title>Latte Art Night — Fernwood Coffee — Thryft</title>', $html);
        $this->assertStringContainsString('"@type":"Event"', $html);
        $this->assertStringContainsString('"startDate":"' . $event->starts_at->toIso8601String() . '"', $html);
        $this->assertStringContainsString('12 Elm St', $html);
        $this->assertStringContainsString('/user/events/' . $event->slug, $html);
        $this->assertStringNotContainsString($this->owner->email, $html);

        $xml = $this->get('/sitemap.xml')->assertStatus(200)->getContent();
        $this->assertStringContainsString("/e/{$event->slug}", $xml);
    }

    public function test_times_render_in_the_hosts_zone_not_utc()
    {
        // 6:30 PM in Chicago (CDT, UTC-5) is 23:30 UTC.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/events', [
            'title' => 'Latte Art Night',
            'starts_at' => '2030-10-11T23:30:00Z',
            'ends_at' => '2030-10-12T01:30:00Z',
            'timezone' => 'America/Chicago',
            'status' => 'published',
        ])->assertStatus(201);

        $event = Event::query()->where('title', 'Latte Art Night')->firstOrFail();
        $this->assertSame('America/Chicago', $event->timezone);

        $html = $this->get("/e/{$event->slug}")->assertStatus(200)->getContent();
        $this->assertStringContainsString('Fri, Oct 11 at 6:30 PM', $html);
        $this->assertStringContainsString('8:30 PM', $html);
        $this->assertStringContainsString('2030-10-11T18:30:00-05:00', $html);

        $this->getJson("/api/events/{$event->slug}")->assertJsonPath('data.timezone', 'America/Chicago');
    }

    public function test_an_unknown_zone_is_refused()
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/business/events', [
            'title' => 'Somewhere',
            'starts_at' => '2030-10-11T23:30:00Z',
            'timezone' => 'Mars/Olympus',
        ])->assertStatus(422)->assertJsonValidationErrors('timezone');
    }
}
