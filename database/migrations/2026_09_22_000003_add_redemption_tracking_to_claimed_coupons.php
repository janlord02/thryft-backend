<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record WHO performed a redemption, and index the columns the merchant
 * dashboard (Phase 5) will aggregate over.
 *
 * redeemed_by_user_id is free to add now and required later: once staff
 * accounts exist (Phase 3b) the redeeming actor is no longer necessarily the
 * business owner. It is also what makes the 60-second retry-idempotency check
 * in markAsUsed safe — we can confirm the same caller is retrying rather than a
 * second member of staff double-redeeming.
 *
 * No foreign key constraint: SQLite cannot add one via ALTER TABLE, and the
 * Phase 3 business extraction reworks these relationships anyway.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('claimed_coupons', function (Blueprint $table) {
            $table->unsignedBigInteger('redeemed_by_user_id')->nullable()->after('used_at');

            // Supports the Phase 5 redemption-funnel queries
            // (claims vs redemptions per business over a date range).
            $table->index(['business_id', 'status', 'used_at'], 'claimed_coupons_business_status_used_index');
            $table->index(['business_id', 'created_at'], 'claimed_coupons_business_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('claimed_coupons', function (Blueprint $table) {
            $table->dropIndex('claimed_coupons_business_status_used_index');
            $table->dropIndex('claimed_coupons_business_created_index');
            $table->dropColumn('redeemed_by_user_id');
        });
    }
};
