<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per free month a business earned through referrals. The row is
 * written before the credit is applied, so its id doubles as the Stripe
 * idempotency key and a failed apply is retried rather than lost or doubled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->enum('reason', ['business', 'shoppers']);
            $table->enum('method', ['stripe_credit', 'extension'])->nullable();
            $table->unsignedInteger('amount_cents')->nullable();
            $table->string('stripe_balance_transaction_id')->nullable();
            $table->timestamp('extended_to')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'applied_at']);
        });

        Schema::table('referrals', function (Blueprint $table) {
            $table->foreignId('reward_id')->nullable()->after('status')->constrained('referral_rewards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reward_id');
        });
        Schema::dropIfExists('referral_rewards');
    }
};
