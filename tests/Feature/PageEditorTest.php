<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Coupon;
use App\Models\Event;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The page editor: blocks are validated against the closed set on save, the
 * data blocks resolve against live records, and the app and the crawlable
 * page both render the result.
 */
class PageEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => 'business', 'business_name' => 'Fernwood Coffee', 'phone' => '555-0100']);
        UserSubscription::factory()->create(['user_id' => $this->owner->id]);
        $this->business = Business::factory()->forOwner($this->owner)->create([
            'name' => 'Fernwood Coffee',
            'slug' => 'fernwood-coffee',
            'phone' => '555-0100',
        ]);
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

    public function test_editor_offers_templates_and_the_block_catalog()
    {
        $data = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/business/page')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame([], $data['blocks']);
        $this->assertSame(['classic', 'showcase', 'simple'], array_column($data['templates'], 'key'));
        $this->assertContains('hero', array_column($data['catalog'], 'type'));
        $this->assertStringEndsWith('/b/fernwood-coffee', $data['public_url']);
        // Template blocks already carry ids so the editor can key them.
        $this->assertNotEmpty($data['templates'][0]['blocks'][0]['id']);
    }

    public function test_saving_validates_against_the_closed_set()
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/business/page', ['blocks' => [['type' => 'iframe', 'src' => 'https://evil.test']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks.0.type']);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/business/page', ['blocks' => [['type' => 'cta', 'label' => 'Order', 'url' => 'javascript:alert(1)']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks.0.url']);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/business/page', ['blocks' => [['type' => 'hero', 'image' => 'https://elsewhere.test/x.png']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blocks.0.image']);

        $tooMany = array_fill(0, 21, ['type' => 'text', 'body' => 'x']);
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/business/page', ['blocks' => $tooMany])
            ->assertStatus(422);

        // Unknown fields are dropped, tags stripped, and the page is saved.
        $saved = $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/business/page', ['blocks' => [
                ['type' => 'about', 'title' => 'About <b>us</b>', 'text' => 'Roasting since 2019.', 'onclick' => 'x'],
            ]])
            ->assertStatus(200)
            ->json('data.blocks');

        $this->assertSame('About us', $saved[0]['title']);
        $this->assertArrayNotHasKey('onclick', $saved[0]);
        $this->assertNotEmpty($saved[0]['id']);
    }

    public function test_only_the_owner_and_admins_can_edit_the_page()
    {
        $manager = User::factory()->create();
        BusinessMember::factory()->manager()->create(['business_id' => $this->business->id, 'user_id' => $manager->id]);
        $admin = User::factory()->create();
        BusinessMember::factory()->admin()->create(['business_id' => $this->business->id, 'user_id' => $admin->id]);

        $this->actingAs($manager, 'sanctum')->getJson('/api/business/page')->assertStatus(403);
        $this->actingAs($admin, 'sanctum')->getJson('/api/business/page')->assertStatus(200);
    }

    public function test_data_blocks_resolve_and_both_renderers_show_the_page()
    {
        Storage::fake('public');

        $upload = $this->actingAs($this->owner, 'sanctum')
            ->post('/api/business/page/media', ['image' => UploadedFile::fake()->image('front.jpg', 800, 500)])
            ->assertStatus(201)
            ->json('data');
        $this->assertMatchesRegularExpression('/^page-media\//', $upload['path']);

        Coupon::factory()->create(['user_id' => $this->owner->id, 'business_id' => $this->business->id, 'title' => 'Free pastry', 'slug' => 'free-pastry']);
        Coupon::factory()->inactive()->create(['user_id' => $this->owner->id, 'business_id' => $this->business->id, 'title' => 'Hidden deal']);
        Event::factory()->create(['business_id' => $this->business->id, 'title' => 'Latte Art Night']);

        $this->actingAs($this->owner, 'sanctum')->putJson('/api/business/page', ['blocks' => [
            ['type' => 'hero', 'image' => $upload['path'], 'headline' => 'Fernwood Coffee', 'subheadline' => 'Small batch, big welcome.'],
            ['type' => 'about', 'title' => 'About us', 'text' => "Roasting since 2019.\nOpen every day."],
            ['type' => 'deals', 'title' => 'This week', 'limit' => 6],
            ['type' => 'events', 'title' => 'Coming up', 'limit' => 4],
            ['type' => 'hours', 'title' => 'Hours', 'rows' => [['label' => 'Mon–Fri', 'value' => '7am–6pm'], ['label' => '', 'value' => '']]],
            ['type' => 'contact', 'title' => 'Find us', 'show_map' => true],
            ['type' => 'cta', 'label' => 'Order online', 'url' => 'https://order.example.com'],
        ]])->assertStatus(200);

        // The app's business page gets the resolved blocks.
        $blocks = $this->getJson("/api/business/{$this->owner->id}/products")
            ->assertStatus(200)
            ->json('data.business.page_blocks');

        $this->assertCount(7, $blocks);
        $this->assertStringContainsString($upload['path'], $blocks[0]['image_url']);
        $this->assertSame(['Free pastry'], array_column($blocks[2]['items'], 'title'));
        $this->assertSame(['Latte Art Night'], array_column($blocks[3]['items'], 'title'));
        $this->assertCount(1, $blocks[4]['rows']);
        $this->assertSame('12 Elm St, Davao', $blocks[5]['address']);
        $this->assertSame('555-0100', $blocks[5]['phone']);
        $this->assertStringContainsString('7.06', $blocks[5]['map_url']);

        // So does the guest API.
        $this->getJson("/api/public/businesses/{$this->business->id}")
            ->assertStatus(200)
            ->assertJsonPath('page_blocks.0.type', 'hero');

        // And the crawlable page renders them, with the stock sections replaced.
        $html = $this->get('/b/fernwood-coffee')->assertStatus(200)->getContent();
        $this->assertStringContainsString('Small batch, big welcome.', $html);
        $this->assertStringContainsString('Roasting since 2019.', $html);
        $this->assertStringContainsString('This week', $html);
        $this->assertStringContainsString('Free pastry', $html);
        $this->assertStringNotContainsString('Hidden deal', $html);
        $this->assertStringContainsString('Latte Art Night', $html);
        $this->assertStringContainsString('7am–6pm', $html);
        $this->assertStringContainsString('Order online', $html);
        $this->assertStringContainsString('https://order.example.com', $html);
        $this->assertStringNotContainsString('<h2>Current deals</h2>', $html);
    }
}
