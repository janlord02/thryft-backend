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
        Schema::table('users', function (Blueprint $table) {
            // Business address fields
            $table->string('address')->nullable()->after('lastname');
            $table->string('city')->nullable()->after('address');
            $table->string('state')->nullable()->after('city');
            $table->string('zipcode')->nullable()->after('state');
            $table->string('country')->nullable()->after('zipcode');

            // Location coordinates
            $table->decimal('latitude', 10, 8)->nullable()->after('country');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');

            // Business specific fields
            $table->string('business_name')->nullable()->after('longitude');
            $table->text('business_description')->nullable()->after('business_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'address',
                'city',
                'state',
                'zipcode',
                'country',
                'latitude',
                'longitude',
                'business_name',
                'business_description'
            ]);
        });
    }
};
