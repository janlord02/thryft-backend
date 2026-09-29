<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the Stripe customer id instead of rediscovering it by email.
 *
 * getOrCreateStripeCustomer() currently calls Customer::all(['email' => ...])
 * on every payment flow. That is a network round trip per call, it breaks the
 * moment a user changes their email, and — because test and live environments
 * frequently share an email — it can match a customer belonging to a different
 * environment or create silent duplicates.
 *
 * Backfilled lazily: the next time a user touches a payment flow, the resolved
 * id is written here. No data migration is needed or attempted.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropColumn('stripe_customer_id');
        });
    }
};
