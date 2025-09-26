<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ActivityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    /**
     * Get all settings grouped by category
     */
    public function index(): JsonResponse
    {
        $groups = ['general', 'security', 'email', 'notifications', 'advanced', 'theme'];
        $settings = [];

        foreach ($groups as $group) {
            $settings[$group] = Setting::getByGroup($group);
        }

        return response()->json([
            'status' => 'success',
            'data' => $settings,
        ]);
    }

    /**
     * Get settings by group
     */
    public function getByGroup(string $group): JsonResponse
    {
        $settings = Setting::getByGroup($group);

        return response()->json([
            'status' => 'success',
            'data' => $settings,
        ]);
    }

    /**
     * Update settings
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable',
            'settings.*.type' => 'sometimes|string|in:string,boolean,integer,json',
            'settings.*.group' => 'sometimes|string|in:general,security,email,notifications,advanced,theme',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updatedSettings = [];
        $errors = [];
        $changedSettings = [];

        foreach ($request->settings as $settingData) {
            try {
                $setting = Setting::where('key', $settingData['key'])->first();

                if (!$setting) {
                    $errors[] = "Setting '{$settingData['key']}' not found";
                    continue;
                }

                // Store old value for logging
                $oldValue = $setting->typed_value;
                $newValue = $settingData['value'];

                $setting->typed_value = $newValue;
                $setting->save();

                // Track changed settings
                if ($oldValue !== $newValue) {
                    $changedSettings[] = [
                        'key' => $setting->key,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'group' => $setting->group,
                    ];
                }

                $updatedSettings[] = [
                    'key' => $setting->key,
                    'value' => $setting->typed_value,
                    'type' => $setting->type,
                    'group' => $setting->group,
                ];
            } catch (\Exception $e) {
                $errors[] = "Failed to update setting '{$settingData['key']}': " . $e->getMessage();
            }
        }

        // Log settings changes if any were made
        if (!empty($changedSettings)) {
            // Create a more meaningful message
            $settingNames = array_map(function ($setting) {
                return ucfirst(str_replace('_', ' ', $setting['key']));
            }, $changedSettings);

            $message = count($changedSettings) === 1
                ? "Updated setting: " . $settingNames[0]
                : "Updated settings: " . implode(', ', $settingNames);

            ActivityService::logAdminAction(
                $request->user(),
                'Settings Updated',
                $message,
                [
                    'changed_settings' => $changedSettings,
                    'total_changed' => count($changedSettings),
                    'setting_names' => $settingNames,
                ]
            );
        }

        if (!empty($errors)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Some settings failed to update',
                'errors' => $errors,
                'data' => $updatedSettings,
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Settings updated successfully',
            'data' => $updatedSettings,
        ]);
    }

    /**
     * Reset settings to defaults
     */
    public function reset(): JsonResponse
    {
        try {
            // Log the reset action before clearing
            ActivityService::logAdminAction(
                request()->user(),
                'Settings Reset',
                'Admin reset all settings to defaults',
                [
                    'reset_by' => request()->user()->email,
                    'timestamp' => now()->toISOString(),
                ]
            );

            // Clear all existing settings
            Setting::truncate();

            // Insert default settings
            $this->insertDefaultSettings();

            return response()->json([
                'status' => 'success',
                'message' => 'Settings reset to defaults successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reset settings: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get public settings (accessible without authentication)
     */
    public function getPublicSettings(): JsonResponse
    {
        $settings = Setting::getPublicSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings,
        ]);
    }

    /**
     * Get theme settings (accessible to all authenticated users)
     */
    public function getThemeSettings(): JsonResponse
    {
        $themeSettings = Setting::getByGroup('theme');

        return response()->json([
            'status' => 'success',
            'data' => $themeSettings,
        ]);
    }

    /**
     * Get public theme settings (no authentication required)
     */
    public function getPublicThemeSettings()
    {
        try {
            $themeSettings = Setting::where('group', 'theme')
                ->where('is_public', true)
                ->get()
                ->keyBy('key')
                ->map(function ($setting) {
                    return $setting->value;
                });

            // Also include the app logo from general settings
            $appLogo = Setting::where('key', 'app_logo')->first();
            if ($appLogo) {
                $themeSettings['app_logo'] = $appLogo->value;
            }

            return response()->json([
                'status' => 'success',
                'data' => $themeSettings,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get public theme settings: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load theme settings',
            ], 500);
        }
    }

    /**
     * Insert default settings
     */
    private function insertDefaultSettings(): void
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
        ];

        foreach ($defaultSettings as $setting) {
            Setting::create($setting);
        }
    }

    /**
     * Upload application logo
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Get the old logo URL for logging
            $oldLogo = Setting::getValue('app_logo');

            // Upload the new logo
            $logoPath = $request->file('logo')->store('logos', 'public');
            $logoUrl = asset('storage/' . $logoPath);

            // Update the setting
            Setting::setValue('app_logo', $logoUrl, 'string', 'general', 'Application logo URL');

            // Log the logo upload
            ActivityService::logAdminAction(
                $request->user(),
                'Logo Updated',
                'Application logo was updated',
                [
                    'old_logo' => $oldLogo,
                    'new_logo' => $logoUrl,
                    'file_path' => $logoPath,
                ]
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Logo uploaded successfully',
                'data' => [
                    'logo_url' => $logoUrl,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to upload logo: ' . $e->getMessage(),
            ], 500);
        }
    }
}
