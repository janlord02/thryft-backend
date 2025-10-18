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
        if (!Schema::hasTable('product_favorites')) {
            Schema::create('product_favorites', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('product_id')->constrained()->onDelete('cascade');
                $table->timestamps();

                // Ensure a user can only favorite a product once
                $table->unique(['user_id', 'product_id'], 'product_favorites_user_product_unique');

                // Add indexes for better performance
                $table->index(['user_id', 'created_at'], 'product_favorites_user_created_index');
                $table->index(['product_id', 'created_at'], 'product_favorites_product_created_index');
            });
        } else {
            // Table exists, check and add missing constraints/indexes
            $this->ensureTableStructure();
        }
    }

    /**
     * Ensure the table has the correct structure
     */
    private function ensureTableStructure(): void
    {
        $connection = DB::connection();
        $tableName = 'product_favorites';

        // Check if unique constraint exists
        $uniqueExists = $this->constraintExists($connection, $tableName, 'product_favorites_user_product_unique');

        if (!$uniqueExists) {
            Schema::table('product_favorites', function (Blueprint $table) {
                $table->unique(['user_id', 'product_id'], 'product_favorites_user_product_unique');
            });
        }

        // Check if indexes exist
        $userIndexExists = $this->indexExists($connection, $tableName, 'product_favorites_user_created_index');
        if (!$userIndexExists) {
            Schema::table('product_favorites', function (Blueprint $table) {
                $table->index(['user_id', 'created_at'], 'product_favorites_user_created_index');
            });
        }

        $productIndexExists = $this->indexExists($connection, $tableName, 'product_favorites_product_created_index');
        if (!$productIndexExists) {
            Schema::table('product_favorites', function (Blueprint $table) {
                $table->index(['product_id', 'created_at'], 'product_favorites_product_created_index');
            });
        }
    }

    /**
     * Check if a constraint exists
     */
    private function constraintExists($connection, $tableName, $constraintName): bool
    {
        try {
            $constraints = $connection->select("
                SELECT CONSTRAINT_NAME
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND CONSTRAINT_NAME = ?
            ", [$connection->getDatabaseName(), $tableName, $constraintName]);

            return count($constraints) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if an index exists
     */
    private function indexExists($connection, $tableName, $indexName): bool
    {
        try {
            $indexes = $connection->select("
                SELECT INDEX_NAME
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = ?
                AND TABLE_NAME = ?
                AND INDEX_NAME = ?
            ", [$connection->getDatabaseName(), $tableName, $indexName]);

            return count($indexes) > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_favorites');
    }
};
