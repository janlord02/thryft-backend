<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step 3: give products and coupons a business_id, populated from user_id.
 *
 * Because step 2 seeded businesses.id = users.id, this is a column copy, not a
 * remap. Both columns are kept in sync by the application (dual-write) until
 * reads have been flipped and proven; user_id is not dropped here and is not
 * dropped in this phase at all.
 *
 * claimed_coupons.business_id needs no data change — the values are already
 * correct. Only its foreign key target moves, from users to businesses. That
 * also fixes a real hazard: the old ON DELETE CASCADE meant deleting a business
 * USER cascaded away their redemption history, which becomes actively dangerous
 * once staff accounts exist.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable()->after('user_id');
            $table->index(['business_id', 'is_active']);
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable()->after('user_id');
            $table->index(['business_id', 'is_active']);
        });

        // Straight copy, only where a matching business actually exists.
        foreach (['products', 'coupons'] as $table) {
            DB::statement("
                UPDATE {$table} SET business_id = user_id
                WHERE user_id IN (SELECT id FROM businesses)
            ");
        }

        $this->repointClaimedCouponsForeignKey();
    }

    public function down(): void
    {
        $this->restoreClaimedCouponsForeignKey();

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_active']);
            $table->dropColumn('business_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_active']);
            $table->dropColumn('business_id');
        });
    }

    /**
     * SQLite cannot alter foreign keys in place, and rebuilding the table there
     * buys nothing: its FK enforcement is off by default in Laravel's test
     * setup. Skip it rather than risk a destructive table rebuild.
     */
    private function repointClaimedCouponsForeignKey(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Fail with something readable. Adding a foreign key over orphaned rows
        // produces MySQL errno 150, which says nothing about which rows are at
        // fault; the previous migration should have created a business for
        // every referenced id, so an orphan here means that assumption broke.
        $orphans = DB::table('claimed_coupons')
            ->whereNotNull('business_id')
            ->whereNotIn('business_id', DB::table('businesses')->pluck('id'))
            ->pluck('business_id')
            ->unique()
            ->values();

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot repoint claimed_coupons.business_id: no business row exists for id(s) '
                . $orphans->implode(', ')
                . '. Backfill those businesses before re-running this migration.'
            );
        }

        Schema::table('claimed_coupons', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }

    private function restoreClaimedCouponsForeignKey(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('claimed_coupons', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->foreign('business_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
