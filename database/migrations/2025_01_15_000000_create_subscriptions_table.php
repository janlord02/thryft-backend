<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('billing_cycle')->default('monthly'); // monthly, yearly, lifetime
            $table->json('features')->nullable(); // List of features included
            $table->boolean('is_popular')->default(false);
            $table->boolean('status')->default(true); // active/inactive
            $table->boolean('on_show')->default(true); // visible/hidden to users
            $table->integer('max_users')->nullable(); // Maximum users allowed
            $table->integer('max_storage_gb')->nullable(); // Storage limit in GB
            $table->integer('sort_order')->default(0); // For ordering display
            $table->string('stripe_price_id')->nullable(); // Stripe price ID for integration
            $table->json('metadata')->nullable(); // Additional metadata
            $table->timestamps();
        });

        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('active'); // active, cancelled, expired, suspended
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('amount_paid', 10, 2);
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();
            $table->json('subscription_data')->nullable(); // Store subscription details at time of purchase
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
        Schema::dropIfExists('subscriptions');
    }
};
