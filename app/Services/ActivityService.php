<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;

class ActivityService
{
    /**
     * Log user registration
     */
    public static function logUserRegistration(User $user)
    {
        ActivityLog::log(
            $user->id,
            'New user registered',
            "{$user->name} joined the platform",
            'person_add',
            'user_registration'
        );
    }

    /**
     * Log user login
     */
    public static function logUserLogin(User $user, $ipAddress = null)
    {
        ActivityLog::log(
            $user->id,
            'User logged in',
            "{$user->name} logged in" . ($ipAddress ? " from {$ipAddress}" : ''),
            'login',
            'user_login',
            ['ip_address' => $ipAddress]
        );
    }

    /**
     * Log profile update
     */
    public static function logProfileUpdate(User $user)
    {
        ActivityLog::log(
            $user->id,
            'Profile updated',
            "{$user->name} updated their profile",
            'edit',
            'profile_update'
        );
    }

    /**
     * Log password change
     */
    public static function logPasswordChange(User $user)
    {
        ActivityLog::log(
            $user->id,
            'Password changed',
            "{$user->name} changed their password",
            'lock',
            'password_change'
        );
    }

    /**
     * Log 2FA enable
     */
    public static function logTwoFactorEnable(User $user)
    {
        ActivityLog::log(
            $user->id,
            '2FA enabled',
            "{$user->name} enabled two-factor authentication",
            'security',
            'two_factor_enable'
        );
    }

    /**
     * Log 2FA disable
     */
    public static function logTwoFactorDisable(User $user)
    {
        ActivityLog::log(
            $user->id,
            '2FA disabled',
            "{$user->name} disabled two-factor authentication",
            'security',
            'two_factor_disable'
        );
    }

    /**
     * Log email verification
     */
    public static function logEmailVerification(User $user)
    {
        ActivityLog::log(
            $user->id,
            'Email verified',
            "{$user->name} verified their email address",
            'verified',
            'email_verification'
        );
    }

    /**
     * Log admin action
     */
    public static function logAdminAction(User $admin, $action, $description, $data = [])
    {
        ActivityLog::log(
            $admin->id,
            $action,
            $description,
            'admin_panel_settings',
            'admin_action',
            $data
        );
    }

    /**
     * Log system event
     */
    public static function logSystemEvent($title, $description, $icon = 'info', $data = [])
    {
        ActivityLog::log(
            null, // No user for system events
            $title,
            $description,
            $icon,
            'system_event',
            $data
        );
    }

    /**
     * Log user export
     */
    public static function logUserExport(User $admin, $filters = [], $exportCount = 0)
    {
        ActivityLog::log(
            $admin->id,
            'User Export',
            "Admin exported {$exportCount} users",
            'download',
            'user_export',
            [
                'export_count' => $exportCount,
                'filters' => $filters,
                'exported_by' => $admin->email,
            ]
        );
    }
}
