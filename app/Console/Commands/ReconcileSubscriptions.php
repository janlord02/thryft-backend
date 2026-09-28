<?php

namespace App\Console\Commands;

use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\Subscription as StripeSubscription;

/**
 * Safety net for missed or failed webhooks.
 *
 * Webhooks are the primary path, but they can be missed entirely — an outage, a
 * misconfigured endpoint secret, a handler that errored. Without a reconciler
 * those failures are invisible AND silently generous: a cancelled subscription
 * that never received its webhook keeps granting access forever.
 *
 * Run daily. Idempotent, so running it more often is harmless.
 */
class ReconcileSubscriptions extends Command
{
    protected $signature = 'subscriptions:reconcile
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Re-sync local subscription state with Stripe and expire stale records';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->expireStaleLocalRecords($dryRun);
        $this->resyncFromStripe($dryRun);

        return self::SUCCESS;
    }

    /**
     * Records whose period has lapsed and which no renewal webhook revived.
     * Handled locally because it needs no Stripe call — and because free and
     * manually-assigned plans have no Stripe counterpart at all.
     */
    private function expireStaleLocalRecords(bool $dryRun): void
    {
        $stale = UserSubscription::whereIn('status', ['active', 'past_due'])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->where(function ($query) {
                // Leave anything still inside its grace window alone.
                $query->whereNull('grace_ends_at')->orWhere('grace_ends_at', '<=', now());
            })
            ->get();

        foreach ($stale as $subscription) {
            $this->line("expire  user={$subscription->user_id} subscription={$subscription->id} ended={$subscription->ends_at}");

            if (!$dryRun) {
                $subscription->update(['status' => 'expired']);
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Expired {$stale->count()} stale subscription(s).");
    }

    /**
     * Pull the authoritative state for anything backed by a real Stripe
     * subscription whose local period looks finished.
     */
    private function resyncFromStripe(bool $dryRun): void
    {
        $secret = config('services.stripe.secret');

        if (!$secret) {
            $this->warn('No Stripe secret configured; skipping remote re-sync.');

            return;
        }

        Stripe::setApiKey($secret);

        $candidates = UserSubscription::whereNotNull('stripe_subscription_id')
            ->whereIn('status', ['active', 'past_due', 'unpaid', 'incomplete', 'expired'])
            ->where(function ($query) {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '<=', now());
            })
            ->get();

        $synced = 0;

        foreach ($candidates as $subscription) {
            try {
                $remote = StripeSubscription::retrieve($subscription->stripe_subscription_id);
            } catch (ApiErrorException $e) {
                Log::warning('Could not retrieve Stripe subscription during reconcile', [
                    'user_subscription_id' => $subscription->id,
                    'stripe_subscription_id' => $subscription->stripe_subscription_id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $status = $this->mapStatus($remote->status ?? null);
            $periodEnd = $this->resolvePeriodEnd($remote);

            $attributes = ['status' => $status];

            if ($periodEnd) {
                $attributes['current_period_end'] = $periodEnd;
                $attributes['ends_at'] = $periodEnd;
            }

            if ($status === 'active') {
                $attributes['grace_ends_at'] = null;
            }

            // Mirror StripeWebhookService::onInvoicePaymentFailed. Without
            // this, reconciling a past_due subscription left grace_ends_at
            // null, grantsAccess() denies past_due with no grace, and the
            // customer was locked out on the first failed invoice — in exactly
            // the missed-webhook scenario this command exists to cover.
            if ($status === 'past_due') {
                $attributes['grace_ends_at'] = $subscription->grace_ends_at ?? now()->addDays(7);
            }

            if ($status === 'cancelled') {
                $attributes['cancelled_at'] = $subscription->cancelled_at ?? now();
            }

            $this->line("sync    user={$subscription->user_id} {$subscription->status} -> {$status}");

            if (!$dryRun) {
                $subscription->update($attributes);
            }

            $synced++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Re-synced {$synced} subscription(s) from Stripe.");
    }

    private function mapStatus(?string $stripeStatus): string
    {
        return match ($stripeStatus) {
            'active', 'trialing' => 'active',
            'past_due' => 'past_due',
            'unpaid' => 'unpaid',
            'canceled' => 'cancelled',
            'incomplete', 'incomplete_expired' => 'incomplete',
            default => 'expired',
        };
    }

    /**
     * current_period_end moved to the subscription item in API 2025-04-30.
     */
    private function resolvePeriodEnd($subscription): ?Carbon
    {
        $timestamp = $subscription->current_period_end
            ?? $subscription->items->data[0]->current_period_end
            ?? null;

        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
