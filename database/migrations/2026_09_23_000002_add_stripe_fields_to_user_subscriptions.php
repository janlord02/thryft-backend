<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give user_subscriptions first-class Stripe fields.
 *
 * Today the Stripe subscription id is stored in transaction_id — a column that
 * also holds PaymentIntent ids, "free_<uniqid>" and "manual_<uniqid>" — and
 * duplicated inside the subscription_data JSON blob. Webhooks have to guess
 * which meaning applies, and there is nowhere to record the billing period, so
 * renewals cannot be reflected locally at all.
 *
 * grace_ends_at is what lets a failed payment degrade instead of revoking
 * instantly: card declines are routine and Stripe retries them for days.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->after('transaction_id');
            $table->string('stripe_price_id')->nullable()->after('stripe_subscription_id');
            $table->timestamp('current_period_end')->nullable()->after('ends_at');
            $table->boolean('cancel_at_period_end')->default(false)->after('current_period_end');
            $table->timestamp('grace_ends_at')->nullable()->after('cancel_at_period_end');

            $table->index('stripe_subscription_id', 'user_subscriptions_stripe_sub_index');
        });

        // Backfill in PHP rather than with JSON_EXTRACT: the JSON functions
        // differ enough between MySQL, MariaDB and SQLite to be a liability,
        // and this table is small.
        DB::table('user_subscriptions')
            ->select('id', 'transaction_id', 'payment_method', 'subscription_data', 'ends_at')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $data = json_decode($row->subscription_data ?? '{}', true) ?: [];

                    $stripeSubscriptionId = $data['stripe_subscription_id'] ?? null;

                    // Fall back to transaction_id only when it genuinely holds a
                    // Stripe subscription id (those are prefixed "sub_").
                    if (!$stripeSubscriptionId && is_string($row->transaction_id) && str_starts_with($row->transaction_id, 'sub_')) {
                        $stripeSubscriptionId = $row->transaction_id;
                    }

                    DB::table('user_subscriptions')->where('id', $row->id)->update([
                        'stripe_subscription_id' => $stripeSubscriptionId,
                        'stripe_price_id' => $data['price_id'] ?? null,
                        // Seed the billing period from the end date we already
                        // have, so the reconcile command has something to compare
                        // against on its first run.
                        'current_period_end' => $row->ends_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropIndex('user_subscriptions_stripe_sub_index');
            $table->dropColumn([
                'stripe_subscription_id',
                'stripe_price_id',
                'current_period_end',
                'cancel_at_period_end',
                'grace_ends_at',
            ]);
        });
    }
};
