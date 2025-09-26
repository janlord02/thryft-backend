<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\NotificationPreference;

class NotificationPreferenceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        $types = ['info', 'warning', 'error', 'success', 'urgent'];

        foreach ($users as $user) {
            foreach ($types as $type) {
                NotificationPreference::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'database_enabled' => true,
                    'email_enabled' => true,
                    'push_enabled' => true,
                ]);
            }
        }
    }
}
