<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Once-only markers for the two retention notifications.
 *
 * coupons.followers_notified_at — set when the shoppers who saved the business
 * were told about the offer. Null means "not yet announced", which is what
 * lets a coupon created inactive be announced when it is later switched on.
 * Existing coupons are stamped with their created_at so an old offer is not
 * announced as new the next time a merchant edits it.
 *
 * claimed_coupons.expiry_reminded_at — set when the shopper was reminded that
 * a claim is about to expire, so the daily run never reminds twice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->timestamp('followers_notified_at')->nullable()->after('is_featured');
        });

        DB::table('coupons')->whereNull('followers_notified_at')->update([
            'followers_notified_at' => DB::raw('created_at'),
        ]);

        Schema::table('claimed_coupons', function (Blueprint $table) {
            $table->timestamp('expiry_reminded_at')->nullable()->after('used_at');
            $table->index(['status', 'expires_at'], 'claimed_coupons_status_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::table('claimed_coupons', function (Blueprint $table) {
            $table->dropIndex('claimed_coupons_status_expires_idx');
            $table->dropColumn('expiry_reminded_at');
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('followers_notified_at');
        });
    }
};
