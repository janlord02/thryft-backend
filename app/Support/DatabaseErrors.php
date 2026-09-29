<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Cross-engine inspection of driver errors.
 *
 * "Insert and catch the unique violation" is the idempotency primitive used in
 * both the coupon claim path and the Stripe webhook ledger, chosen because it
 * behaves identically on MySQL/MariaDB and SQLite — unlike row locking, which
 * is silently a no-op on SQLite.
 */
class DatabaseErrors
{
    /**
     * SQLSTATE 23000 covers every integrity violation (unique, foreign key,
     * not-null), so the driver message is also checked for a unique constraint.
     *
     * MySQL/MariaDB: "Duplicate entry 'x' for key 'some_unique_index'"
     * SQLite:        "UNIQUE constraint failed: table.column"
     *
     * Both contain "unique" once the index is named with a _unique suffix, which
     * every unique index in this codebase is.
     */
    public static function isUniqueViolation(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && stripos($e->getMessage(), 'unique') !== false;
    }
}
