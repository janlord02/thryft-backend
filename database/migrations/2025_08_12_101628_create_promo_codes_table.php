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
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // The promo code itself (e.g., "SUMMER2025")
            $table->string('name'); // Display name for admin
            $table->text('description')->nullable();

            // Discount configuration
            $table->enum('discount_type', ['percentage', 'fixed_amount', 'free_access']); // Type of discount
            $table->decimal('discount_value', 10, 2)->nullable(); // Percentage (0-100) or fixed amount
            $table->decimal('max_discount_amount', 10, 2)->nullable(); // Max discount for percentage type

            // Validity and usage
            $table->timestamp('starts_at')->nullable(); // When promo becomes active
            $table->timestamp('expires_at')->nullable(); // When promo expires
            $table->integer('max_uses')->nullable(); // Max total uses (null = unlimited)
            $table->integer('max_uses_per_user')->default(1); // Max uses per user
            $table->integer('total_uses')->default(0); // Current total uses

            // Targeting
            $table->json('applicable_subscriptions')->nullable(); // Array of subscription IDs (null = all)
            $table->decimal('minimum_amount', 10, 2)->nullable(); // Minimum purchase amount

            // Admin management
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true); // Public codes can be used by anyone, private ones must be assigned
            $table->json('metadata')->nullable(); // Additional data

            $table->timestamps();

            // Indexes
            $table->index(['code', 'is_active']);
            $table->index(['starts_at', 'expires_at']);
            $table->index('is_public');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
