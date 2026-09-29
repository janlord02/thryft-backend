<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stop user_subscriptions.starts_at rewriting itself on every update.
 *
 * MySQL and MariaDB implicitly give the FIRST non-nullable TIMESTAMP column in
 * a table `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` unless
 * explicit_defaults_for_timestamp is enabled. `starts_at` is that column here,
 * so every write to the row — a webhook syncing a period, a cancellation, the
 * nightly reconcile — silently reset the subscription's start date to now.
 *
 * Two consequences, both live:
 *   - UserSubscription::grantsAccess() rejects a subscription whose starts_at
 *     is in the future. Immediately after an update, starts_at is the write
 *     instant, so the comparison sits exactly on a second boundary and can
 *     deny access to a valid subscriber.
 *   - The recorded start of every subscription is wrong the moment anything
 *     touches it, which corrupts any reporting built on it.
 *
 * DATETIME carries no implicit auto-update behaviour and has the same
 * practical range for these values. SQLite has no such behaviour to begin
 * with, so this is a no-op there.
 *
 * Pre-existing: introduced with the original create_subscriptions_table, not
 * by the subscription work in this branch.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE user_subscriptions MODIFY starts_at DATETIME NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Restores the original column type. Note this brings the implicit
        // ON UPDATE CURRENT_TIMESTAMP back with it — that is what "down" means
        // here, not an oversight.
        DB::statement('ALTER TABLE user_subscriptions MODIFY starts_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }
};
