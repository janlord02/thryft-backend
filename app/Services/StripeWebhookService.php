<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Support\DatabaseErrors;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Applies Stripe webhook events to local subscription state.
 *
 * Previously this lived inline in BusinessSubscriptionController, where three
 * of the four handled events did nothing but write to the log. The practical
 * consequences were: renewals never extended ends_at (so paying customers read
 * as expired locally), failed payments never changed anything (so a dead card
 * kept full access indefinitely), and cancellations made in the Stripe
 * dashboard never reached the database at all.
 */
class StripeWebhookService
{
    /**
     * How long a business keeps access after a failed invoice. Stripe's default
     * dunning runs to roughly three weeks; a week is long enough to cover a
     * routine decline without giving away a month.
     */
    private const GRACE_DAYS = 7;

    /**
     * @return bool True if the event was processed, false if it was a replay.
     */
    public function handle(\Stripe\Event $event): bool
    {
        // Idempotency first. Insert before doing any work: a unique violation
        // means another delivery of this same event already ran.
        try {
            $record = StripeWebhookEvent::create([
                'event_id' => $event->id,
                'type' => $event->type,
                'payload' => json_decode(json_encode($event->data->object), true),
            ]);
        } catch (QueryException $e) {
            if (DatabaseErrors::isUniqueViolation($e)) {
                Log::info('Stripe webhook replay ignored', ['event_id' => $event->id, 'type' => $event->type]);

                return false;
            }

            throw $e;
        }

        try {
            $this->dispatch($event);
            $record->markProcessed();
        } catch (\Throwable $e) {
            // Recorded rather than rethrown: returning non-2xx to Stripe would
            // trigger redelivery, which the idempotency guard above would then
            // swallow as a replay — the event would never be retried. The row
            // keeps the payload so it can be replayed deliberately.
            Log::error('Stripe webhook handler failed', [
                'event_id' => $event->id,
                'type' => $event->type,
                'error' => $e->getMessage(),
            ]);

            $record->markFailed($e->getMessage());
        }

        return true;
    }

    private function dispatch(\Stripe\Event $event): void
    {
        $object = $event->data->object;

        match ($event->type) {
            'invoice.payment_succeeded' => $this->onInvoicePaymentSucceeded($object),
            'invoice.payment_failed' => $this->onInvoicePaymentFailed($object),
            'customer.subscription.updated' => $this->onSubscriptionUpdated($object),
            'customer.subscription.deleted' => $this->onSubscriptionDeleted($object),
            'payment_intent.succeeded' => $this->onPaymentIntentSucceeded($object),
            default => Log::info('Unhandled Stripe webhook type', ['type' => $event->type]),
        };
    }

    /**
     * The renewal fix. Extends the local billing period, clears any grace, and
     * records the payment.
     */
    private function onInvoicePaymentSucceeded($invoice): void
    {
        $subscriptionId = $this->resolveSubscriptionId($invoice);

        if (!$subscriptionId) {
            Log::warning('invoice.payment_succeeded carried no resolvable subscription id', [
                'invoice_id' => $invoice->id ?? null,
            ]);

            return;
        }

        $userSubscription = $this->findLocalSubscription($subscriptionId);

        if (!$userSubscription) {
            Log::warning('No local subscription matches Stripe subscription', [
                'stripe_subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $attributes = [
            'status' => 'active',
            'grace_ends_at' => null,
        ];

        // Only advance the period when Stripe actually told us the new end. A
        // null here would otherwise wipe an existing end date and immediately
        // revoke access for a customer who just paid.
        if ($periodEnd = $this->resolvePeriodEndFromInvoice($invoice)) {
            $attributes['current_period_end'] = $periodEnd;
            // ends_at is what check()/current() and the access gate read, and is
            // what was never being advanced before.
            $attributes['ends_at'] = $periodEnd;
        } else {
            Log::warning('Could not resolve billing period from invoice; access period not advanced', [
                'invoice_id' => $invoice->id ?? null,
            ]);
        }

        $userSubscription->update($attributes);

        $this->recordPayment(
            $userSubscription,
            $this->resolvePaymentId($invoice) ?: $invoice->id,
            $invoice->amount_paid ?? null,
            $invoice->currency ?? 'usd',
            'succeeded',
            $invoice,
            isset($invoice->status_transitions->paid_at)
                ? Carbon::createFromTimestamp($invoice->status_transitions->paid_at)
                : now()
        );
    }

    /**
     * Degrade rather than revoke: move to past_due and start the grace clock.
     */
    private function onInvoicePaymentFailed($invoice): void
    {
        $subscriptionId = $this->resolveSubscriptionId($invoice);

        if (!$subscriptionId) {
            Log::warning('invoice.payment_failed carried no resolvable subscription id', [
                'invoice_id' => $invoice->id ?? null,
            ]);

            return;
        }

        $userSubscription = $this->findLocalSubscription($subscriptionId);

        if (!$userSubscription) {
            return;
        }

        $userSubscription->update([
            'status' => 'past_due',
            // Only start the clock on the first failure, so repeated retries
            // cannot keep extending access indefinitely.
            'grace_ends_at' => $userSubscription->grace_ends_at ?? now()->addDays(self::GRACE_DAYS),
        ]);

        $this->recordPayment(
            $userSubscription,
            $this->resolvePaymentId($invoice) ?: $invoice->id,
            $invoice->amount_due ?? null,
            $invoice->currency ?? 'usd',
            'failed',
            $invoice,
            null
        );

        $this->notifyPaymentFailed($userSubscription);
    }

    /**
     * Plan changes, period advances and past_due/unpaid transitions made on
     * Stripe's side. Previously not handled at all — there was no case for it.
     */
    private function onSubscriptionUpdated($subscription): void
    {
        $userSubscription = $this->findLocalSubscription($subscription->id ?? null);

        if (!$userSubscription) {
            return;
        }

        $periodEnd = $this->resolvePeriodEndFromSubscription($subscription);
        $status = $this->mapStatus($subscription->status ?? null);

        $attributes = [
            'status' => $status,
            'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
        ];

        if ($periodEnd) {
            $attributes['current_period_end'] = $periodEnd;
            $attributes['ends_at'] = $periodEnd;
        }

        if ($status === 'past_due') {
            $attributes['grace_ends_at'] = $userSubscription->grace_ends_at ?? now()->addDays(self::GRACE_DAYS);
        } elseif ($status === 'active') {
            $attributes['grace_ends_at'] = null;
        }

        // Follow a plan change if the price maps to a plan we know about.
        $priceId = $subscription->items->data[0]->price->id ?? null;

        if ($priceId && $priceId !== $userSubscription->stripe_price_id) {
            $plan = Subscription::where('stripe_price_id', $priceId)->first();

            if ($plan) {
                $attributes['subscription_id'] = $plan->id;
                $attributes['stripe_price_id'] = $priceId;
            } else {
                Log::warning('Stripe price has no matching local plan', ['price_id' => $priceId]);
            }
        }

        $userSubscription->update($attributes);
    }

    private function onSubscriptionDeleted($subscription): void
    {
        $userSubscription = $this->findLocalSubscription($subscription->id ?? null);

        if (!$userSubscription) {
            return;
        }

        $userSubscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'ends_at' => now(),
            'grace_ends_at' => null,
        ]);

        // Note: users.role is deliberately left as 'business'. Access is gated
        // by EnsureActiveSubscription, so the role no longer grants anything on
        // its own, and keeping it preserves the account's identity and data.
        Log::info('Subscription cancelled via Stripe', [
            'user_id' => $userSubscription->user_id,
            'stripe_subscription_id' => $subscription->id ?? null,
        ]);
    }

    private function onPaymentIntentSucceeded($paymentIntent): void
    {
        Log::info('Payment intent succeeded', [
            'payment_intent_id' => $paymentIntent->id ?? null,
            'customer_id' => $paymentIntent->customer ?? null,
        ]);

        // A featured placement switches on here even if the shopper's tab
        // closed before the app could confirm it.
        \App\Http\Controllers\FeaturedController::onPaymentSucceeded(
            $paymentIntent->metadata->featured_placement_id ?? null,
            (string) ($paymentIntent->id ?? ''),
        );
    }

    // ---------------------------------------------------------------------
    // Stripe API-version tolerant accessors
    //
    // stripe-php 18 targets an API version where several fields moved off the
    // top level of the invoice and subscription objects. Reading only the old
    // location (as the previous code did with $invoice->subscription) yields
    // null and silently turns the handler into a no-op, so each accessor tries
    // the known locations in turn and logs when none match.
    // ---------------------------------------------------------------------

    private function resolveSubscriptionId($invoice): ?string
    {
        return $invoice->subscription                                           // <= 2025-03-31
            ?? $invoice->parent->subscription_details->subscription             // >= 2025-04-30
            ?? $invoice->lines->data[0]->parent->subscription_item_details->subscription
            ?? $invoice->lines->data[0]->subscription
            ?? null;
    }

    private function resolvePaymentId($invoice): ?string
    {
        $paymentIntent = $invoice->payment_intent                               // older versions
            ?? $invoice->payments->data[0]->payment->payment_intent             // newer versions
            ?? null;

        return is_object($paymentIntent) ? ($paymentIntent->id ?? null) : $paymentIntent;
    }

    private function resolvePeriodEndFromInvoice($invoice): ?Carbon
    {
        $timestamp = $invoice->lines->data[0]->period->end
            ?? $invoice->period_end
            ?? null;

        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }

    private function resolvePeriodEndFromSubscription($subscription): ?Carbon
    {
        $timestamp = $subscription->current_period_end                          // <= 2025-03-31
            ?? $subscription->items->data[0]->current_period_end                // >= 2025-04-30
            ?? null;

        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }

    // ---------------------------------------------------------------------

    /**
     * Matches on the dedicated column first, falling back to transaction_id for
     * rows created before that column existed and not yet backfilled.
     */
    private function findLocalSubscription(?string $stripeSubscriptionId): ?UserSubscription
    {
        if (!$stripeSubscriptionId) {
            return null;
        }

        return UserSubscription::where('stripe_subscription_id', $stripeSubscriptionId)
            ->orWhere('transaction_id', $stripeSubscriptionId)
            ->first();
    }

    private function mapStatus(?string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active', 'trialing' => 'active',
            'past_due' => 'past_due',
            'unpaid' => 'unpaid',
            'canceled' => 'cancelled',
            'incomplete', 'incomplete_expired' => 'incomplete',
            default => 'incomplete',
        };
    }

    /**
     * Writes a Payment row, tolerating replays via the unique index on
     * (provider, provider_payment_id).
     */
    private function recordPayment(
        UserSubscription $userSubscription,
        ?string $providerPaymentId,
        $amountInMinorUnits,
        string $currency,
        string $status,
        $rawResponse,
        ?Carbon $paidAt
    ): void {
        if (!$providerPaymentId) {
            return;
        }

        $amount = $amountInMinorUnits !== null
            ? $amountInMinorUnits / 100      // Stripe reports minor units
            : ($userSubscription->subscription->price ?? 0);

        try {
            Payment::create([
                'user_id' => $userSubscription->user_id,
                'user_subscription_id' => $userSubscription->id,
                'provider' => 'stripe',
                'provider_payment_id' => $providerPaymentId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => $status,
                'raw_response' => json_decode(json_encode($rawResponse), true),
                'paid_at' => $paidAt,
            ]);
        } catch (QueryException $e) {
            if (!DatabaseErrors::isUniqueViolation($e)) {
                throw $e;
            }

            Log::info('Duplicate payment row ignored', ['provider_payment_id' => $providerPaymentId]);
        }
    }

    private function notifyPaymentFailed(UserSubscription $userSubscription): void
    {
        try {
            // NotificationService::send() fans out to EVERY user when given an
            // empty array, so never call it without an explicit recipient.
            app(NotificationService::class)->send(
                'Payment failed',
                'We could not process your subscription payment. Please update your payment method to keep your business features active.',
                'warning',
                [$userSubscription->user_id],
                ['user_subscription_id' => $userSubscription->id],
            );
        } catch (\Throwable $e) {
            // A notification failure must not fail the webhook: the state change
            // above is the important part.
            Log::error('Failed to notify user of payment failure', [
                'user_id' => $userSubscription->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
