<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step 1 of the business extraction: create the tables, touch nothing else.
 *
 * Today a business IS a users row — role is a string column, the address and
 * business_name live on users, and products/coupons FK straight to user_id.
 * That blocks staff accounts (a person who acts as a business), multi-location
 * (many addresses per business), business transfer (the business is the login),
 * and every roadmap feature that joins businesses together.
 *
 * location_id is added to products and coupons here, ahead of any multi-location
 * UI, purely so that feature is later a UI change rather than a second
 * migration wave over the same tables.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            // NOTE: no auto-increment gap handling here — step 2 seeds ids
            // explicitly to match users.id and then bumps AUTO_INCREMENT.
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->enum('status', ['draft', 'active', 'suspended'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'name']);
            $table->index('owner_user_id');
        });

        Schema::create('business_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('country')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->json('hours')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['business_id', 'is_primary']);
            // Nearby-business search filters on coordinates.
            $table->index(['latitude', 'longitude']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('user_id')
                ->constrained('business_locations')->nullOnDelete();
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('user_id')
                ->constrained('business_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::dropIfExists('business_locations');
        Schema::dropIfExists('businesses');
    }
};
