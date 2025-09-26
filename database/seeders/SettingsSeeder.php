<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultSettings = [
            // General Settings
            [
                'key' => 'app_name',
                'value' => 'Boilerplate',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Application name displayed throughout the app',
                'is_public' => true,
            ],
            [
                'key' => 'app_logo',
                'value' => null,
                'type' => 'string',
                'group' => 'general',
                'description' => 'Application logo URL',
                'is_public' => true,
            ],
            [
                'key' => 'app_url',
                'value' => 'http://localhost:3000',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Base URL of the application',
                'is_public' => true,
            ],
            [
                'key' => 'timezone',
                'value' => 'UTC',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Default timezone for the application',
                'is_public' => false,
            ],
            [
                'key' => 'language',
                'value' => 'en',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Default language for new users',
                'is_public' => false,
            ],
            [
                'key' => 'maintenance_mode',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'general',
                'description' => 'Enable maintenance mode to restrict access',
                'is_public' => true,
            ],
            [
                'key' => 'registration_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'general',
                'description' => 'Allow new users to register',
                'is_public' => true,
            ],
            [
                'key' => 'email_verification',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'general',
                'description' => 'Require users to verify their email',
                'is_public' => false,
            ],

            // Security Settings
            [
                'key' => 'min_password_length',
                'value' => '8',
                'type' => 'integer',
                'group' => 'security',
                'description' => 'Minimum characters required for passwords',
                'is_public' => false,
            ],
            [
                'key' => 'require_uppercase',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'security',
                'description' => 'Require uppercase letters in passwords',
                'is_public' => false,
            ],
            [
                'key' => 'require_lowercase',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'security',
                'description' => 'Require lowercase letters in passwords',
                'is_public' => false,
            ],
            [
                'key' => 'require_numbers',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'security',
                'description' => 'Require numbers in passwords',
                'is_public' => false,
            ],
            [
                'key' => 'require_symbols',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'security',
                'description' => 'Require special characters in passwords',
                'is_public' => false,
            ],
            [
                'key' => 'session_timeout',
                'value' => '120',
                'type' => 'integer',
                'group' => 'security',
                'description' => 'Session timeout in minutes',
                'is_public' => false,
            ],
            [
                'key' => 'force_two_factor',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'security',
                'description' => 'Force 2FA for all users',
                'is_public' => false,
            ],
            [
                'key' => 'rate_limit_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'security',
                'description' => 'Enable rate limiting',
                'is_public' => false,
            ],
            [
                'key' => 'max_login_attempts',
                'value' => '5',
                'type' => 'integer',
                'group' => 'security',
                'description' => 'Maximum failed login attempts before lockout',
                'is_public' => false,
            ],

            // Email Settings
            [
                'key' => 'smtp_host',
                'value' => '',
                'type' => 'string',
                'group' => 'email',
                'description' => 'SMTP server hostname',
                'is_public' => false,
            ],
            [
                'key' => 'smtp_port',
                'value' => '587',
                'type' => 'integer',
                'group' => 'email',
                'description' => 'SMTP server port',
                'is_public' => false,
            ],
            [
                'key' => 'smtp_username',
                'value' => '',
                'type' => 'string',
                'group' => 'email',
                'description' => 'SMTP authentication username',
                'is_public' => false,
            ],
            [
                'key' => 'smtp_password',
                'value' => '',
                'type' => 'string',
                'group' => 'email',
                'description' => 'SMTP authentication password',
                'is_public' => false,
            ],
            [
                'key' => 'smtp_encryption',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'email',
                'description' => 'Use SSL/TLS for SMTP',
                'is_public' => false,
            ],
            [
                'key' => 'from_email',
                'value' => 'noreply@example.com',
                'type' => 'string',
                'group' => 'email',
                'description' => 'Default sender email address',
                'is_public' => false,
            ],
            [
                'key' => 'from_name',
                'value' => 'Boilerplate',
                'type' => 'string',
                'group' => 'email',
                'description' => 'Default sender name',
                'is_public' => false,
            ],
            [
                'key' => 'email_notifications',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'email',
                'description' => 'Enable email notifications',
                'is_public' => false,
            ],

            // Notification Settings
            [
                'key' => 'notify_new_users',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Notify on new user registration',
                'is_public' => false,
            ],
            [
                'key' => 'notify_failed_logins',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Notify on failed login attempts',
                'is_public' => false,
            ],
            [
                'key' => 'notify_system_errors',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Notify on system errors',
                'is_public' => false,
            ],
            [
                'key' => 'notify_security_events',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'notifications',
                'description' => 'Notify on security events',
                'is_public' => false,
            ],

            // Advanced Settings
            [
                'key' => 'debug_mode',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'advanced',
                'description' => 'Enable debug mode',
                'is_public' => false,
            ],
            [
                'key' => 'cache_enabled',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'advanced',
                'description' => 'Enable application caching',
                'is_public' => false,
            ],
            [
                'key' => 'cache_timeout',
                'value' => '60',
                'type' => 'integer',
                'group' => 'advanced',
                'description' => 'Cache timeout in minutes',
                'is_public' => false,
            ],
            [
                'key' => 'auto_backup',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'advanced',
                'description' => 'Enable automatic backups',
                'is_public' => false,
            ],
            [
                'key' => 'backup_frequency',
                'value' => 'daily',
                'type' => 'string',
                'group' => 'advanced',
                'description' => 'Backup frequency',
                'is_public' => false,
            ],
            [
                'key' => 'log_retention',
                'value' => '1',
                'type' => 'boolean',
                'group' => 'advanced',
                'description' => 'Enable log retention',
                'is_public' => false,
            ],
            [
                'key' => 'log_retention_days',
                'value' => '30',
                'type' => 'integer',
                'group' => 'advanced',
                'description' => 'Log retention period in days',
                'is_public' => false,
            ],

            // Theme Settings
            [
                'key' => 'primary',
                'value' => '#1976D2',
                'type' => 'string',
                'group' => 'theme',
                'description' => 'Primary color for the application',
                'is_public' => true,
            ],
            [
                'key' => 'primary_light',
                'value' => '#42A5F5',
                'type' => 'string',
                'group' => 'theme',
                'description' => 'Light variant of primary color',
                'is_public' => true,
            ],
            [
                'key' => 'primary_dark',
                'value' => '#1565C0',
                'type' => 'string',
                'group' => 'theme',
                'description' => 'Dark variant of primary color',
                'is_public' => true,
            ],
            [
                'key' => 'secondary',
                'value' => '#26A69A',
                'type' => 'string',
                'group' => 'theme',
                'description' => 'Secondary color for the application',
                'is_public' => true,
            ],
            [
                'key' => 'secondary_light',
                'value' => '#4DB6AC',
                'type' => 'string',
                'group' => 'theme',
                'description' => 'Light variant of secondary color',
                'is_public' => true,
            ],
            [
                'key' => 'secondary_dark',
                'value' => '#00897B',
                'type' => 'string',
                'group' => 'theme',
                'description' => 'Dark variant of secondary color',
                'is_public' => true,
            ],
            [
                'key' => 'accent',
                'value' => '#9C27B0',
                'type' => 'string',
                'group' => 'theme',
                'description' => 'Accent color for highlights',
                'is_public' => true,
            ],
            [
                'key' => 'accent_light',
                'value' => '#BA68C8',
                'type' => 'string',
                'group' => 'theme',
                'description' => 'Light variant of accent color',
                'is_public' => true,
            ],
            [
                'key' => 'accent_dark',
                'value' => '#7B1FA2',
                'type' => 'string',
                'group' => 'theme',
                'description' => 'Dark variant of accent color',
                'is_public' => true,
            ],
        ];

        foreach ($defaultSettings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
