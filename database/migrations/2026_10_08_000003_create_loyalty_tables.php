<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital punch cards. A business runs programs ("5 coffees, the 6th is
 * free"); a shopper who joins gets a card with a code staff stamp at the
 * till. Reaching the target banks a reward and starts the card over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('reward', 160);
            $table->unsignedSmallInteger('stamps_required');
            $table->string('terms', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
        });

        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->unsignedSmallInteger('stamps')->default(0);
            $table->unsignedInteger('rewards_available')->default(0);
            $table->unsignedInteger('rewards_redeemed')->default(0);
            $table->timestamp('last_stamp_at')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'user_id']);
        });

        Schema::create('loyalty_card_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('loyalty_cards')->cascadeOnDelete();
            $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type', ['stamp', 'redeem']);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_card_events');
        Schema::dropIfExists('loyalty_cards');
        Schema::dropIfExists('loyalty_programs');
    }
};
