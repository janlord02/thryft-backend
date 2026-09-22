<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce "one claim per user per coupon" in the database.
 *
 * This was previously a PHP-only check in UserDashboardController::claimCoupon,
 * which read then wrote with no transaction and no lock — two concurrent
 * requests could both pass the check and both insert.
 *
 * The unique index is the enforcement mechanism (rather than lockForUpdate)
 * because it behaves identically on MySQL and SQLite. SELECT ... FOR UPDATE is
 * silently accepted and does nothing on SQLite, so a lock-based design would
 * pass the whole test suite while its correctness rested on behaviour the tests
 * cannot exercise. Do not "improve" this into a lock.
 *
 * Note this matches the rule the app already enforced: claimCoupon rejected any
 * second claim outright, so the per_user_limit column has never actually
 * governed anything. If multiple claims per user are ever needed (loyalty,
 * membership offers), add a claim_index column defaulting to 0 and widen this
 * index to (user_id, coupon_id, claim_index) — a non-breaking change.
 */
return new class extends Migration {
    public function up(): void
    {
        $loserIds = $this->findDuplicateLoserIds();

        // Preserve anything we are about to hard-delete. Marking the rows
        // 'cancelled' instead would not help: they would still violate the index.
        $this->backupRows($loserIds);

        if (!empty($loserIds)) {
            foreach (array_chunk($loserIds, 500) as $chunk) {
                DB::table('claimed_coupons')->whereIn('id', $chunk)->delete();
            }
        }

        Schema::table('claimed_coupons', function (Blueprint $table) {
            $table->unique(['user_id', 'coupon_id'], 'claimed_coupons_user_coupon_unique');
        });
    }

    public function down(): void
    {
        Schema::table('claimed_coupons', function (Blueprint $table) {
            $table->dropUnique('claimed_coupons_user_coupon_unique');
        });

        // Restore the deduped rows only after the index is gone, or they would
        // immediately violate it again.
        if (Schema::hasTable('claimed_coupons_dedupe_backup')) {
            DB::statement('INSERT INTO claimed_coupons SELECT * FROM claimed_coupons_dedupe_backup');
            Schema::drop('claimed_coupons_dedupe_backup');
        }
    }

    /**
     * For each (user_id, coupon_id) group with more than one row, keep a 'used'
     * row if any exists (a real redemption is the most meaningful record),
     * otherwise the earliest claim. Everything else is a loser.
     *
     * @return array<int>
     */
    private function findDuplicateLoserIds(): array
    {
        $groups = DB::table('claimed_coupons')
            ->select('user_id', 'coupon_id')
            ->groupBy('user_id', 'coupon_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $loserIds = [];

        foreach ($groups as $group) {
            $rows = DB::table('claimed_coupons')
                ->where('user_id', $group->user_id)
                ->where('coupon_id', $group->coupon_id)
                ->orderByRaw("CASE WHEN status = 'used' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->pluck('id');

            // Skip the first (the keeper), collect the rest.
            $loserIds = array_merge($loserIds, $rows->slice(1)->all());
        }

        return $loserIds;
    }

    /**
     * CREATE TABLE ... AS SELECT is supported by both MySQL and SQLite, and
     * copies the column layout without us having to restate it.
     */
    private function backupRows(array $loserIds): void
    {
        if (Schema::hasTable('claimed_coupons_dedupe_backup')) {
            Schema::drop('claimed_coupons_dedupe_backup');
        }

        if (empty($loserIds)) {
            // Still create the table so down() has a consistent shape to work with.
            DB::statement('CREATE TABLE claimed_coupons_dedupe_backup AS SELECT * FROM claimed_coupons WHERE 1 = 0');

            return;
        }

        $ids = implode(',', array_map('intval', $loserIds));
        DB::statement("CREATE TABLE claimed_coupons_dedupe_backup AS SELECT * FROM claimed_coupons WHERE id IN ({$ids})");
    }
};
