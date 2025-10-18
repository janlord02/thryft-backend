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
        // Remove duplicate indexes and constraints
        Schema::table('product_favorites', function (Blueprint $table) {
            // Drop duplicate unique constraint (keep the one with explicit name)
            try {
                $table->dropUnique('product_favorites_user_id_product_id_unique');
            } catch (Exception $e) {
                // Constraint doesn't exist, ignore
            }

            // Drop duplicate indexes (keep the ones with explicit names)
            try {
                $table->dropIndex('product_favorites_user_id_created_at_index');
            } catch (Exception $e) {
                // Index doesn't exist, ignore
            }

            try {
                $table->dropIndex('product_favorites_product_id_created_at_index');
            } catch (Exception $e) {
                // Index doesn't exist, ignore
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add the indexes if needed (though this shouldn't be necessary)
        Schema::table('product_favorites', function (Blueprint $table) {
            try {
                $table->unique(['user_id', 'product_id'], 'product_favorites_user_id_product_id_unique');
            } catch (Exception $e) {
                // Ignore if already exists
            }

            try {
                $table->index(['user_id', 'created_at'], 'product_favorites_user_id_created_at_index');
            } catch (Exception $e) {
                // Ignore if already exists
            }

            try {
                $table->index(['product_id', 'created_at'], 'product_favorites_product_id_created_at_index');
            } catch (Exception $e) {
                // Ignore if already exists
            }
        });
    }
};
