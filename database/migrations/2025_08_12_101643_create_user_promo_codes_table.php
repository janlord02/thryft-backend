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
        Schema::create('user_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('promo_code_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_id')->nullable()->constrained()->onDelete('set null'); // Which subscription it was applied to

            // Usage tracking
            $table->enum('status', ['assigned', 'used', 'expired', 'revoked'])->default('assigned');
            $table->timestamp('assigned_at')->nullable(); // When admin assigned it or user claimed it
            $table->timestamp('used_at')->nullable(); // When user actually used it
            $table->timestamp('expires_at')->nullable(); // User-specific expiry (can override promo expiry)

            // Usage details
            $table->decimal('discount_applied', 10, 2)->nullable(); // Actual discount amount applied
            $table->decimal('original_amount', 10, 2)->nullable(); // Original subscription amount
            $table->decimal('final_amount', 10, 2)->nullable(); // Final amount after discount
            $table->integer('uses_count')->default(0); // How many times this user used this promo

            // Assignment details
            $table->foreignId('assigned_by')->nullable()->constrained('users')->onDelete('set null'); // Admin who assigned it
            $table->text('assignment_notes')->nullable(); // Admin notes
            $table->json('metadata')->nullable(); // Additional tracking data

            $table->timestamps();

            // Indexes and constraints
            $table->unique(['user_id', 'promo_code_id']); // Each user can only have one assignment per promo
            $table->index(['user_id', 'status']);
            $table->index(['promo_code_id', 'status']);
            $table->index('assigned_at');
            $table->index('used_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_promo_codes');
    }
};
