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
        // Add logo setting to the settings table
        DB::table('settings')->insert([
            'key' => 'app_logo',
            'value' => null,
            'type' => 'string',
            'group' => 'general',
            'description' => 'Application logo URL',
            'is_public' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove logo setting
        DB::table('settings')->where('key', 'app_logo')->delete();
    }
};
