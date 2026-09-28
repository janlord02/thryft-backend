<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of received Stripe webhook events, and the idempotency key.
 *
 * Stripe redelivers on any non-2xx response and occasionally duplicates on
 * success, so handlers must be replay-safe. Without this, a redelivered
 * invoice.payment_succeeded inserts a second Payment row and double-counts
 * revenue in Admin/SubscriptionController::stats().
 *
 * The unique event_id is the whole mechanism: insert first, and treat a unique
 * violation as "already handled". Same primitive as the claim index in Phase 1,
 * chosen for the same reason — it behaves identically on MySQL and SQLite,
 * unlike any lock-based alternative.
 *
 * Keeping the payload also means a handler that failed can be replayed from
 * local data instead of being lost.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type')->index();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // Supports "show me unprocessed or failed events" without a scan.
            $table->index(['processed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
