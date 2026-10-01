<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business page editor: an ordered list of blocks, validated against
 * config/blocks.php on save and rendered by both the app and the crawlable
 * page from the same JSON. One page per business, so it lives on the row.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('page_blocks')->nullable()->after('cover_path');
            $table->timestamp('page_updated_at')->nullable()->after('page_blocks');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['page_blocks', 'page_updated_at']);
        });
    }
};
