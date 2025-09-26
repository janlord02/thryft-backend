<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\Carbon;

class ActivityLogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();

        if ($users->isEmpty()) {
            $this->command->info('No users found. Please run UserSeeder first.');
            return;
        }

        $activities = [
            [
                'title' => 'New user registered',
                'description' => 'John Doe joined the platform',
                'icon' => 'person_add',
                'type' => 'user_registration',
                'created_at' => Carbon::now()->subMinutes(5),
            ],
            [
                'title' => 'Profile updated',
                'description' => 'Jane Smith updated their profile',
                'icon' => 'edit',
                'type' => 'profile_update',
                'created_at' => Carbon::now()->subMinutes(15),
            ],
            [
                'title' => '2FA enabled',
                'description' => 'Mike Johnson enabled two-factor authentication',
                'icon' => 'security',
                'type' => 'two_factor_enable',
                'created_at' => Carbon::now()->subMinutes(30),
            ],
            [
                'title' => 'Password changed',
                'description' => 'Sarah Wilson changed their password',
                'icon' => 'lock',
                'type' => 'password_change',
                'created_at' => Carbon::now()->subMinutes(45),
            ],
            [
                'title' => 'User logged in',
                'description' => 'Admin user logged in from new device',
                'icon' => 'login',
                'type' => 'user_login',
                'created_at' => Carbon::now()->subHour(),
            ],
            [
                'title' => 'Email verified',
                'description' => 'New user verified their email address',
                'icon' => 'verified',
                'type' => 'email_verification',
                'created_at' => Carbon::now()->subHours(2),
            ],
            [
                'title' => 'Profile updated',
                'description' => 'User updated their profile information',
                'icon' => 'edit',
                'type' => 'profile_update',
                'created_at' => Carbon::now()->subHours(3),
            ],
            [
                'title' => '2FA disabled',
                'description' => 'User disabled two-factor authentication',
                'icon' => 'security',
                'type' => 'two_factor_disable',
                'created_at' => Carbon::now()->subHours(4),
            ],
            [
                'title' => 'User logged in',
                'description' => 'User logged in from mobile device',
                'icon' => 'login',
                'type' => 'user_login',
                'created_at' => Carbon::now()->subHours(5),
            ],
            [
                'title' => 'New user registered',
                'description' => 'Another user joined the platform',
                'icon' => 'person_add',
                'type' => 'user_registration',
                'created_at' => Carbon::now()->subHours(6),
            ],
        ];

        foreach ($activities as $activity) {
            // Randomly assign to a user or leave as system event
            $userId = rand(0, 1) ? $users->random()->id : null;

            ActivityLog::create([
                'user_id' => $userId,
                'title' => $activity['title'],
                'description' => $activity['description'],
                'icon' => $activity['icon'],
                'type' => $activity['type'],
                'created_at' => $activity['created_at'],
                'updated_at' => $activity['created_at'],
            ]);
        }

        $this->command->info('Activity logs seeded successfully!');
    }
}
