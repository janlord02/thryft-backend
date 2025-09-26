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
            $table->string('profile_image')->nullable()->after('email');
            $table->string('phone')->nullable()->after('profile_image');
            $table->text('bio')->nullable()->after('phone');
            $table->string('two_factor_secret')->nullable()->after('bio');
            $table->boolean('two_factor_enabled')->default(false)->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'profile_image',
                'phone',
                'bio',
                'two_factor_secret',
                'two_factor_enabled',
                'two_factor_confirmed_at'
            ]);
        });
    }
};
