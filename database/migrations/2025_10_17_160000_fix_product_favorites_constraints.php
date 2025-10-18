<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if table exists first
        if (!Schema::hasTable('product_favorites')) {
            return;
        }

        $connection = DB::connection();
        $tableName = 'product_favorites';
        $databaseName = $connection->getDatabaseName();

        // Get all existing constraints for the table
        $constraints = $connection->select("
            SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ", [$databaseName, $tableName]);

        // Get all existing indexes for the table
        $indexes = $connection->select("
            SELECT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            GROUP BY INDEX_NAME
        ", [$databaseName, $tableName]);

        $constraintNames = array_column($constraints, 'CONSTRAINT_NAME');
        $indexNames = array_column($indexes, 'INDEX_NAME');

        // Drop any duplicate unique constraints (Laravel auto-generated ones)
        foreach ($constraintNames as $constraintName) {
            if (
                str_contains($constraintName, 'user_id') &&
                str_contains($constraintName, 'product_id') &&
                $constraintName !== 'product_favorites_user_product_unique'
            ) {

                try {
                    Schema::table($tableName, function (Blueprint $table) use ($constraintName) {
                        $table->dropUnique($constraintName);
                    });
                    echo "Dropped duplicate constraint: {$constraintName}\n";
                } catch (Exception $e) {
                    echo "Could not drop constraint {$constraintName}: " . $e->getMessage() . "\n";
                }
            }
        }

        // Drop any duplicate indexes (Laravel auto-generated ones)
        foreach ($indexNames as $indexName) {
            if (
                str_contains($indexName, 'user_id') &&
                str_contains($indexName, 'created_at') &&
                $indexName !== 'product_favorites_user_created_index'
            ) {

                try {
                    Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                        $table->dropIndex($indexName);
                    });
                    echo "Dropped duplicate index: {$indexName}\n";
                } catch (Exception $e) {
                    echo "Could not drop index {$indexName}: " . $e->getMessage() . "\n";
                }
            }

            if (
                str_contains($indexName, 'product_id') &&
                str_contains($indexName, 'created_at') &&
                $indexName !== 'product_favorites_product_created_index'
            ) {

                try {
                    Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                        $table->dropIndex($indexName);
                    });
                    echo "Dropped duplicate index: {$indexName}\n";
                } catch (Exception $e) {
                    echo "Could not drop index {$indexName}: " . $e->getMessage() . "\n";
                }
            }
        }

        // Ensure the correct constraints and indexes exist
        $this->ensureCorrectStructure($tableName);
    }

    /**
     * Ensure the table has the correct structure
     */
    private function ensureCorrectStructure(string $tableName): void
    {
        $connection = DB::connection();
        $databaseName = $connection->getDatabaseName();

        // Check if our named unique constraint exists
        $uniqueExists = $connection->select("
            SELECT CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?
        ", [$databaseName, $tableName, 'product_favorites_user_product_unique']);

        if (empty($uniqueExists)) {
            try {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unique(['user_id', 'product_id'], 'product_favorites_user_product_unique');
                });
                echo "Added unique constraint: product_favorites_user_product_unique\n";
            } catch (Exception $e) {
                echo "Could not add unique constraint: " . $e->getMessage() . "\n";
            }
        }

        // Check if our named indexes exist
        $userIndexExists = $connection->select("
            SELECT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
        ", [$databaseName, $tableName, 'product_favorites_user_created_index']);

        if (empty($userIndexExists)) {
            try {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->index(['user_id', 'created_at'], 'product_favorites_user_created_index');
                });
                echo "Added index: product_favorites_user_created_index\n";
            } catch (Exception $e) {
                echo "Could not add user index: " . $e->getMessage() . "\n";
            }
        }

        $productIndexExists = $connection->select("
            SELECT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
        ", [$databaseName, $tableName, 'product_favorites_product_created_index']);

        if (empty($productIndexExists)) {
            try {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->index(['product_id', 'created_at'], 'product_favorites_product_created_index');
                });
                echo "Added index: product_favorites_product_created_index\n";
            } catch (Exception $e) {
                echo "Could not add product index: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is safe to rollback - it only cleans up duplicates
        // The original constraints and indexes will remain
    }
};
