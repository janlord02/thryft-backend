<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::updateOrCreate([
            'email' => 'janlord.luga@gmail.com',
        ], [
            'firstname' => 'Janlord',
            'lastname' => 'Luga',
            'password' => Hash::make('Password123!'),
            'role' => 'super-admin',
            'email_verified_at' => now(),
        ]);

        User::updateOrCreate([
            'email' => 'janlord.vetting0001@gmail.com',
        ], [
            'firstname' => 'test',
            'lastname' => 'user',
            'password' => Hash::make('password'),
            'role' => 'user',
            'email_verified_at' => now(),
        ]);

        User::updateOrCreate([
            'email' => 'janlord.luga1991@gmail.com',
        ], [
            'firstname' => 'test',
            'lastname' => 'business',
            'password' => Hash::make('password'),
            'role' => 'business',
            'email_verified_at' => now(),
        ]);
    }
}
