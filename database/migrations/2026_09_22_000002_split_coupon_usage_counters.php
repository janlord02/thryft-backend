<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split the overloaded used_count column into two explicit counters.
 *
 * Before this migration, used_count was incremented at CLAIM time and never at
 * redemption, so usage_limit effectively meant "maximum claims" despite its
 * name. Both semantics are legitimately needed — flash deals want a claim cap,
 * while merchant liability is governed by a redemption cap — so rather than
 * repurposing one column we introduce both:
 *
 *   claim_limit / claimed_count   -> how many people may take the offer
 *   usage_limit / redeemed_count  -> how many may actually redeem it
 *
 * used_count is deliberately KEPT and dual-written by the application for one
 * release so this migration stays reversible. It is dropped in Phase 2.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->integer('claim_limit')->nullable()->after('usage_limit');
            $table->integer('claimed_count')->default(0)->after('used_count');
            $table->integer('redeemed_count')->default(0)->after('claimed_count');
        });

        // Preserve current behaviour: usage_limit was capping claims, so it
        // becomes the claim limit. usage_limit then takes on its named meaning
        // (a redemption cap) going forward.
        DB::table('coupons')->update(['claim_limit' => DB::raw('usage_limit')]);

        // Recompute both counters from claimed_coupons rather than from
        // used_count. claimed_coupons is the real ledger; used_count is a
        // derived cache that was never maintained correctly (never decremented
        // on cancel, double-incremented by the unreachable redeem route), so
        // trusting it would launder existing drift into the new columns.
        DB::statement("
            UPDATE coupons SET
                claimed_count = (
                    SELECT COUNT(*) FROM claimed_coupons
                    WHERE claimed_coupons.coupon_id = coupons.id
                      AND claimed_coupons.status IN ('claimed', 'used')
                ),
                redeemed_count = (
                    SELECT COUNT(*) FROM claimed_coupons
                    WHERE claimed_coupons.coupon_id = coupons.id
                      AND claimed_coupons.status = 'used'
                )
        ");
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['claim_limit', 'claimed_count', 'redeemed_count']);
        });
    }
};
