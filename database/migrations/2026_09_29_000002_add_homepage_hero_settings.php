<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The shopper home page's hero (eyebrow, headline, subtitle, button and
 * background) becomes editable from Admin > Settings > Home page.
 *
 * Settings rows are created by the seeder on a fresh install and by
 * SettingsController::reset(), neither of which runs on an existing
 * deployment, so the rows are added here. Existing rows are left alone.
 */
return new class extends Migration {
    public function up(): void
    {
        $now = now();

        foreach (self::rows() as $row) {
            if (DB::table('settings')->where('key', $row['key'])->exists()) {
                continue;
            }

            DB::table('settings')->insert($row + [
                'type' => 'string',
                'group' => 'homepage',
                'is_public' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('group', 'homepage')->delete();
    }

    public static function rows(): array
    {
        return [
            [
                'key' => 'hero_eyebrow',
                'value' => 'SHOP LOCAL THIS HOLIDAY SEASON',
                'description' => 'Small line above the home page headline',
            ],
            [
                'key' => 'hero_title',
                'value' => 'Discover local businesses this holiday season',
                'description' => 'Home page headline',
            ],
            [
                'key' => 'hero_subtitle',
                'value' => 'Unique gifts, cozy cafés and trusted services — the people and places that make your community brighter.',
                'description' => 'Home page subtitle',
            ],
            [
                'key' => 'hero_button_label',
                'value' => 'Explore nearby',
                'description' => 'Home page hero button label',
            ],
            [
                'key' => 'hero_button_link',
                'value' => '',
                'description' => 'Where the hero button goes. Empty scrolls to nearby stores; a path or full URL navigates there.',
            ],
            [
                'key' => 'hero_background',
                'value' => '',
                'description' => 'Hero background colour (hex). Empty uses the primary colour.',
            ],
        ];
    }
};
