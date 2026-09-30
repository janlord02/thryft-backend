<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events: something a business hosts at a time and place, with optional
 * capacity and registration.
 *
 * Capacity is enforced the way coupon claims are: registered_count is bumped
 * with a conditional UPDATE (WHERE registered_count < capacity), never read-
 * then-written, so two people cannot take the last seat. The unique index on
 * (event_id, user_id) makes a registration idempotent; cancelling flips the
 * row's status rather than deleting it so re-registering reuses it.
 *
 * Every event belongs to a business (never a user); location_id is optional
 * so a multi-location business can later pin an event to one venue.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('business_locations')->nullOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('venue_name', 160)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('registered_count')->default(0);
            $table->boolean('registration_enabled')->default(true);
            $table->enum('status', ['draft', 'published', 'cancelled'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('followers_notified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'status']);
            $table->index(['status', 'starts_at']);
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['registered', 'cancelled'])->default('registered');
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
