<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two small content types on top of what exists.
 *
 * A flash deal is a coupon flagged is_flash: it must carry an end time and
 * usually a claim cap (claim_limit, from the redemption phase), and shoppers
 * see it in its own rail with a countdown and how many are left. No new
 * table: the claim machinery is the coupon's.
 *
 * An announcement is a plain update from a business — new menu, extended
 * hours, reopening — with no time, capacity or code. It shows on the business
 * page and alerts the shoppers who saved the business, once.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('is_flash')->default(false)->after('is_featured');
            $table->index(['is_flash', 'is_active', 'expires_at'], 'coupons_flash_idx');
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('image_path')->nullable();
            $table->string('link_url')->nullable();
            $table->enum('status', ['draft', 'published'])->default('published');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('followers_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex('coupons_flash_idx');
            $table->dropColumn('is_flash');
        });
    }
};
