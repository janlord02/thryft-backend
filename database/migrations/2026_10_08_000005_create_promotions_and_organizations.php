<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Businesses working together.
 *
 * An organization (chamber, downtown association, market…) is a business
 * row with kind = organization: it keeps the page, events, team and
 * announcements every business has, and adds member businesses.
 *
 * A promotion is one offer several businesses take part in, each with one
 * of its own coupons:
 *   partner  — using one business's offer unlocks another's
 *   bundle   — a packaged night out, every business shown in the package
 *   campaign — an organization or group runs it across many businesses
 *
 * Businesses say whether they are open to partnerships, and what kind, so
 * others can find them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->enum('kind', ['business', 'organization'])->default('business')->after('slug');
            $table->boolean('open_to_partnerships')->default(false)->after('sells_gift_certificates');
            $table->json('partnership_interests')->nullable()->after('open_to_partnerships');
            $table->string('partnership_pitch', 300)->nullable()->after('partnership_interests');
            $table->index(['open_to_partnerships', 'status']);
        });

        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->enum('status', ['invited', 'active'])->default('invited');
            $table->timestamps();

            $table->unique(['organization_id', 'business_id']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_business_id')->constrained('businesses')->cascadeOnDelete();
            $table->string('slug', 120)->unique();
            $table->enum('type', ['partner', 'bundle', 'campaign']);
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->enum('status', ['draft', 'live', 'ended'])->default('draft');
            $table->timestamps();

            $table->index(['status', 'ends_at']);
        });

        Schema::create('promotion_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->enum('status', ['invited', 'accepted', 'declined'])->default('invited');
            $table->string('role', 60)->nullable();
            $table->foreignId('unlocked_by_participant_id')->nullable()->constrained('promotion_participants')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['promotion_id', 'business_id']);
            $table->index(['business_id', 'status']);
            $table->index('coupon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_participants');
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('organization_members');
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['open_to_partnerships', 'status']);
            $table->dropColumn(['kind', 'open_to_partnerships', 'partnership_interests', 'partnership_pitch']);
        });
    }
};
