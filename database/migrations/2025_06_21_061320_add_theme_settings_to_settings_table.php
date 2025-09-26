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
        // Add theme settings to the settings table
        $themeSettings = [
            ['key' => 'primary', 'value' => '#1976D2', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'secondary', 'value' => '#26A69A', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'accent', 'value' => '#9C27B0', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'dark', 'value' => '#1D1D1D', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'dark_page', 'value' => '#121212', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'positive', 'value' => '#21BA45', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'negative', 'value' => '#C10015', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'info', 'value' => '#31CCEC', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'warning', 'value' => '#F2C037', 'type' => 'string', 'group' => 'theme'],
        ];

        foreach ($themeSettings as $setting) {
            DB::table('settings')->insert([
                'key' => $setting['key'],
                'value' => $setting['value'],
                'type' => $setting['type'],
                'group' => $setting['group'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove theme settings
        DB::table('settings')->where('group', 'theme')->delete();
    }
};
