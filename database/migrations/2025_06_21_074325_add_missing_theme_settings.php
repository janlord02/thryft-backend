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
        // Add missing theme settings to the settings table
        $missingThemeSettings = [
            ['key' => 'primary_light', 'value' => '#42A5F5', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'primary_dark', 'value' => '#1565C0', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'secondary_light', 'value' => '#4DB6AC', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'secondary_dark', 'value' => '#00897B', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'accent_light', 'value' => '#BA68C8', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'accent_dark', 'value' => '#7B1FA2', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'dark_contrast', 'value' => '#FFFFFF', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'background', 'value' => '#FFFFFF', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'surface', 'value' => '#FFFFFF', 'type' => 'string', 'group' => 'theme'],
            ['key' => 'border', 'value' => '#E0E0E0', 'type' => 'string', 'group' => 'theme'],
        ];

        foreach ($missingThemeSettings as $setting) {
            // Check if the setting already exists
            $exists = DB::table('settings')->where('key', $setting['key'])->exists();

            if (!$exists) {
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove the missing theme settings
        $keysToRemove = [
            'primary_light',
            'primary_dark',
            'secondary_light',
            'secondary_dark',
            'accent_light',
            'accent_dark',
            'dark_contrast',
            'background',
            'surface',
            'border'
        ];

        DB::table('settings')->whereIn('key', $keysToRemove)->delete();
    }
};
