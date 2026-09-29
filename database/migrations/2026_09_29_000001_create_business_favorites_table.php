<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shopper saving a business.
 *
 * Until now "favorite businesses" was derived from favorited products, and the
 * add path on POST /businesses/favorite returned 400. The redesigned consumer
 * screens put a heart on every business row and a Save tile on the business
 * page, so the relation has to exist in its own right.
 *
 * business_id is the business's users.id, which is also its businesses.id by
 * construction of the extraction backfill, so it stays correct either side of
 * the entity flag.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_favorites');
    }
};
