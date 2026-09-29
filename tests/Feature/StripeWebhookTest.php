<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Services\StripeWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises StripeWebhookService against constructed Stripe event objects. No
 * network calls and no signature verification — those belong to the controller
 * and to Stripe's own library; what matters here is that events actually move
 * local state, which for three of the four previously handled types they did
 * not.
 *
 * Payload shapes deliberately use the CURRENT API layout (invoice.parent
 * .subscription_details.subscription, period end on the subscription item),
 * because stripe-php 18 targets that version and the old code read only the
 * legacy locations.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StripeWebhookService
    {
        return app(StripeWebhookService::class);
    }

    private function event(string $type, array $object): \Stripe\Event
    {
        return \Stripe\Event::constructFrom([
            'id' => 'evt_' . Str::random(16),
            'type' => $type,
            'data' => ['object' => $object],
        ]);
    }

    private function subscriptionFor(array $state = []): UserSubscription
    {
        return UserSubscription::factory()->create(array_merge([
            'stripe_subscription_id' => 'sub_test123',
            'ends_at' => now()->addDays(2),
            'current_period_end' => now()->addDays(2),
        ], $state));
    }

    /** Invoice in the current API shape. */
    private function invoice(string $subscriptionId, int $periodEnd, array $extra = []): array
    {
        return array_merge([
            'id' => 'in_' . Str::random(10),
            'currency' => 'usd',
            'amount_paid' => 2900,
            'amount_due' => 2900,
            'parent' => ['subscription_details' => ['subscription' => $subscriptionId]],
            'lines' => ['data' => [['period' => ['end' => $periodEnd]]]],
            'status_transitions' => ['paid_at' => now()->timestamp],
            'payments' => ['data' => [['payment' => ['payment_intent' => 'pi_' . Str::random(10)]]]],
        ], $extra);
    }

    // -----------------------------------------------------------------
    // Idempotency
    // -----------------------------------------------------------------

    public function test_replayed_event_is_ignored()
    {
        $subscription = $this->subscriptionFor();
        $event = $this->event('invoice.payment_succeeded', $this->invoice('sub_test123', now()->addMonth()->timestamp));

        $this->assertTrue($this->service()->handle($event));
        // Stripe redelivers on any hiccup; the second delivery must be a no-op.
        $this->assertFalse($this->service()->handle($event));

        $this->assertSame(1, StripeWebhookEvent::where('event_id', $event->id)->count());
        $this->assertSame(1, Payment::count(), 'A replay must not double-count revenue.');
    }

    public function test_event_is_recorded_and_marked_processed()
    {
        $this->subscriptionFor();
        $event = $this->event('invoice.payment_succeeded', $this->invoice('sub_test123', now()->addMonth()->timestamp));

        $this->service()->handle($event);

        $record = StripeWebhookEvent::where('event_id', $event->id)->firstOrFail();
        $this->assertNotNull($record->processed_at);
        $this->assertNull($record->error);
    }

    // -----------------------------------------------------------------
    // invoice.payment_succeeded — the renewal bug
    // -----------------------------------------------------------------

    public function test_successful_invoice_extends_the_billing_period()
    {
        $subscription = $this->subscriptionFor(['status' => 'past_due', 'grace_ends_at' => now()->addDay()]);
        $newPeriodEnd = now()->addMonth()->startOfSecond();

        $this->service()->handle(
            $this->event('invoice.payment_succeeded', $this->invoice('sub_test123', $newPeriodEnd->timestamp))
        );

        $subscription->refresh();

        // This is what never happened before: ends_at stayed put on renewal, so
        // a paying customer silently read as expired.
        $this->assertSame($newPeriodEnd->timestamp, $subscription->ends_at->timestamp);
        $this->assertSame($newPeriodEnd->timestamp, $subscription->current_period_end->timestamp);
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
    }

    public function test_successful_invoice_records_a_payment_in_major_units()
    {
        $subscription = $this->subscriptionFor();

        $this->service()->handle(
            $this->event('invoice.payment_succeeded', $this->invoice('sub_test123', now()->addMonth()->timestamp))
        );

        $payment = Payment::firstOrFail();
        $this->assertSame($subscription->id, $payment->user_subscription_id);
        $this->assertSame('succeeded', $payment->status);
        // Stripe reports minor units; 2900 cents is $29.00.
        $this->assertEquals(29.00, (float) $payment->amount);
    }

    public function test_legacy_invoice_shape_is_still_understood()
    {
        // Older API versions put the subscription id at the top level. The
        // resolver must accept both, since the account's pinned version decides.
        $subscription = $this->subscriptionFor();
        $periodEnd = now()->addMonth()->startOfSecond();

        $invoice = $this->invoice('sub_test123', $periodEnd->timestamp);
        unset($invoice['parent']);
        $invoice['subscription'] = 'sub_test123';

        $this->service()->handle($this->event('invoice.payment_succeeded', $invoice));

        $this->assertSame($periodEnd->timestamp, $subscription->fresh()->ends_at->timestamp);
    }

    public function test_unresolvable_subscription_does_not_throw()
    {
        $invoice = $this->invoice('sub_test123', now()->addMonth()->timestamp);
        unset($invoice['parent'], $invoice['lines']);

        $event = $this->event('invoice.payment_succeeded', $invoice);
        $this->service()->handle($event);

        // Handled without error; nothing to apply.
        $this->assertNotNull(StripeWebhookEvent::where('event_id', $event->id)->firstOrFail()->processed_at);
        $this->assertSame(0, Payment::count());
    }

    // -----------------------------------------------------------------
    // invoice.payment_failed — degrade, don't revoke
    // -----------------------------------------------------------------

    public function test_failed_invoice_moves_to_past_due_with_grace_and_keeps_access()
    {
        $subscription = $this->subscriptionFor();

        $this->service()->handle(
            $this->event('invoice.payment_failed', $this->invoice('sub_test123', now()->addMonth()->timestamp))
        );

        $subscription->refresh();

        $this->assertSame('past_due', $subscription->status);
        $this->assertNotNull($subscription->grace_ends_at);
        $this->assertTrue($subscription->grace_ends_at->isFuture());
        // Access must survive a routine decline.
        $this->assertTrue($subscription->grantsAccess());
    }

    public function test_repeated_failures_do_not_keep_extending_grace()
    {
        $subscription = $this->subscriptionFor();

        $this->service()->handle($this->event('invoice.payment_failed', $this->invoice('sub_test123', now()->addMonth()->timestamp)));
        $firstGrace = $subscription->fresh()->grace_ends_at;

        $this->travel(2)->days();

        $this->service()->handle($this->event('invoice.payment_failed', $this->invoice('sub_test123', now()->addMonth()->timestamp)));

        // Otherwise Stripe's dunning retries would grant indefinite free access.
        $this->assertSame($firstGrace->timestamp, $subscription->fresh()->grace_ends_at->timestamp);
    }

    // -----------------------------------------------------------------
    // customer.subscription.updated — previously not handled at all
    // -----------------------------------------------------------------

    public function test_subscription_updated_syncs_status_and_period()
    {
        $subscription = $this->subscriptionFor();
        $periodEnd = now()->addMonths(2)->startOfSecond();

        $this->service()->handle($this->event('customer.subscription.updated', [
            'id' => 'sub_test123',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'items' => ['data' => [['current_period_end' => $periodEnd->timestamp, 'price' => ['id' => $subscription->stripe_price_id]]]],
        ]));

        $subscription->refresh();

        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertSame($periodEnd->timestamp, $subscription->current_period_end->timestamp);
    }

    public function test_subscription_updated_follows_a_plan_change()
    {
        $subscription = $this->subscriptionFor();
        $newPlan = Subscription::factory()->create(['stripe_price_id' => 'price_upgraded']);

        $this->service()->handle($this->event('customer.subscription.updated', [
            'id' => 'sub_test123',
            'status' => 'active',
            'items' => ['data' => [['current_period_end' => now()->addMonth()->timestamp, 'price' => ['id' => 'price_upgraded']]]],
        ]));

        $subscription->refresh();

        $this->assertSame($newPlan->id, $subscription->subscription_id);
        $this->assertSame('price_upgraded', $subscription->stripe_price_id);
    }

    // -----------------------------------------------------------------
    // customer.subscription.deleted — previously log-only
    // -----------------------------------------------------------------

    public function test_deleted_subscription_revokes_access()
    {
        $subscription = $this->subscriptionFor();

        $this->service()->handle($this->event('customer.subscription.deleted', [
            'id' => 'sub_test123',
            'status' => 'canceled',
        ]));

        $subscription->refresh();

        $this->assertSame('cancelled', $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
        $this->assertFalse($subscription->grantsAccess());
    }

    public function test_unhandled_event_type_is_recorded_without_error()
    {
        $event = $this->event('customer.created', ['id' => 'cus_123']);

        $this->assertTrue($this->service()->handle($event));
        $this->assertNotNull(StripeWebhookEvent::where('event_id', $event->id)->firstOrFail()->processed_at);
    }
}
