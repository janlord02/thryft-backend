<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
                $table->unique(['user_id', 'product_id']);

                // Add indexes for better performance
                $table->index(['user_id', 'created_at']);
                $table->index(['product_id', 'created_at']);
            });
        } else {
            // Table exists, but let's ensure it has the correct structure
            Schema::table('product_favorites', function (Blueprint $table) {
                // Check if foreign key constraints exist, if not add them
                if (!Schema::hasColumn('product_favorites', 'user_id')) {
                    $table->foreignId('user_id')->constrained()->onDelete('cascade');
                }
                if (!Schema::hasColumn('product_favorites', 'product_id')) {
                    $table->foreignId('product_id')->constrained()->onDelete('cascade');
                }

                // Add unique constraint if it doesn't exist
                try {
                    $table->unique(['user_id', 'product_id']);
                } catch (Exception $e) {
                    // Unique constraint already exists, ignore
                }

                // Add indexes if they don't exist
                try {
                    $table->index(['user_id', 'created_at']);
                } catch (Exception $e) {
                    // Index already exists, ignore
                }

                try {
                    $table->index(['product_id', 'created_at']);
                } catch (Exception $e) {
                    // Index already exists, ignore
                }
            });
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
