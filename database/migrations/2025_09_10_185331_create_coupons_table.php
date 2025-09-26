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
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Business owner
            $table->string('title');
            $table->string('code')->unique(); // Unique coupon code
            $table->text('description')->nullable();
            $table->string('qr_code')->nullable(); // QR code image path
            $table->decimal('discount_amount', 10, 2)->nullable(); // Fixed discount amount
            $table->decimal('discount_percentage', 5, 2)->nullable(); // Percentage discount
            $table->enum('discount_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('minimum_amount', 10, 2)->nullable(); // Minimum order amount
            $table->integer('usage_limit')->nullable(); // Max number of uses
            $table->integer('used_count')->default(0); // Number of times used
            $table->integer('per_user_limit')->default(1); // Max uses per user
            $table->datetime('starts_at')->nullable(); // Start date
            $table->datetime('expires_at')->nullable(); // Expiry date
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->json('terms_conditions')->nullable(); // Terms and conditions
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index(['code', 'is_active']);
            $table->index(['expires_at', 'is_active']);
            $table->index(['is_featured', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
