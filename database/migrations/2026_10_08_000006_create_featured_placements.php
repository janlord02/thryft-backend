<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid placements in the shopper's Featured row: one business, deal or
 * event, shown to shoppers within radius_miles of the business (optionally
 * only in one category) for a number of days. Paid by card through Stripe;
 * it goes live when the payment succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('featured_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->enum('target_type', ['business', 'coupon', 'event']);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('business_tag_id')->nullable()->constrained('business_tags')->nullOnDelete();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->unsignedSmallInteger('radius_miles')->default(25);
            $table->unsignedSmallInteger('days');
            $table->unsignedInteger('amount_cents');
            $table->enum('status', ['pending_payment', 'active', 'cancelled'])->default('pending_payment');
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_placements');
    }
};
