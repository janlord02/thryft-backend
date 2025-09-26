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
        Schema::create('claimed_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // User who claimed
            $table->foreignId('coupon_id')->constrained()->onDelete('cascade'); // Coupon claimed
            $table->foreignId('business_id')->constrained('users')->onDelete('cascade'); // Business owner
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('set null'); // Product if applicable
            $table->string('coupon_code'); // Store the code at time of claim
            $table->string('coupon_title'); // Store the title at time of claim
            $table->text('coupon_description')->nullable(); // Store description at time of claim
            $table->enum('discount_type', ['fixed', 'percentage']);
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->decimal('discount_percentage', 5, 2)->nullable();
            $table->decimal('minimum_amount', 10, 2)->nullable();
            $table->datetime('expires_at')->nullable(); // Store expiry at time of claim
            $table->enum('status', ['claimed', 'used', 'expired', 'cancelled'])->default('claimed');
            $table->datetime('used_at')->nullable(); // When actually used
            $table->text('usage_notes')->nullable(); // Notes about usage
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['coupon_id', 'status']);
            $table->index(['business_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claimed_coupons');
    }
};
