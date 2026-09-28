<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make provider_payment_id a real idempotency key.
 *
 * payments has an index on (provider, provider_payment_id) but no unique
 * constraint, so a redelivered webhook or a repeated client call to
 * handlePaymentSuccess inserts a duplicate row. Revenue totals in
 * Admin/SubscriptionController::stats() are summed from these rows.
 *
 * Both MySQL and SQLite permit multiple NULLs in a unique index, so rows with
 * no provider id (there should be none, but the column is nullable) are
 * unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->deleteDuplicatePayments();

        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['provider', 'provider_payment_id'], 'payments_provider_payment_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_provider_payment_unique');
        });
    }

    /**
     * Keep the earliest row per (provider, provider_payment_id); back the rest
     * up before deleting, since these are financial records.
     */
    private function deleteDuplicatePayments(): void
    {
        $groups = DB::table('payments')
            ->select('provider', 'provider_payment_id')
            ->whereNotNull('provider_payment_id')
            ->groupBy('provider', 'provider_payment_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $loserIds = [];

        foreach ($groups as $group) {
            $ids = DB::table('payments')
                ->where('provider', $group->provider)
                ->where('provider_payment_id', $group->provider_payment_id)
                ->orderBy('id')
                ->pluck('id');

            $loserIds = array_merge($loserIds, $ids->slice(1)->all());
        }

        if (Schema::hasTable('payments_dedupe_backup')) {
            Schema::drop('payments_dedupe_backup');
        }

        if (empty($loserIds)) {
            DB::statement('CREATE TABLE payments_dedupe_backup AS SELECT * FROM payments WHERE 1 = 0');

            return;
        }

        $ids = implode(',', array_map('intval', $loserIds));
        DB::statement("CREATE TABLE payments_dedupe_backup AS SELECT * FROM payments WHERE id IN ({$ids})");

        foreach (array_chunk($loserIds, 500) as $chunk) {
            DB::table('payments')->whereIn('id', $chunk)->delete();
        }
    }
};
