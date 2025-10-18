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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('user_subscription_id')->nullable();
            $table->string('provider')->default('stripe'); // stripe|free|manual
            $table->string('provider_payment_id')->nullable(); // stripe payment_intent or subscription/invoice id
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 10)->default('usd');
            $table->string('status')->default('pending'); // pending|succeeded|failed|refunded
            $table->json('raw_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id']);
            $table->index(['user_subscription_id']);
            $table->index(['provider', 'provider_payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};


