<?php

namespace App\Services;

use App\Models\User;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class WebSocketService
{
    /**
     * Send real-time notification to user.
     */
    public function sendToUser(User $user, array $data): void
    {
        try {
            // Store notification in cache for real-time access
            $key = "user_notifications_{$user->id}";
            $notifications = Cache::get($key, []);
            $notifications[] = $data;

            // Keep only last 50 notifications
            if (count($notifications) > 50) {
                $notifications = array_slice($notifications, -50);
            }

            Cache::put($key, $notifications, 3600); // 1 hour

            // Broadcast to user's channel
            broadcast(new \App\Events\NotificationSent($user->id, $data))->toOthers();
        } catch (\Exception $e) {
            Log::error('Failed to send WebSocket notification', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send real-time notification to all users.
     */
    public function sendToAll(array $data): void
    {
        try {
            broadcast(new \App\Events\GlobalNotification($data))->toOthers();
        } catch (\Exception $e) {
            Log::error('Failed to send global WebSocket notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get user's real-time notifications.
     */
    public function getUserNotifications(User $user): array
    {
        $key = "user_notifications_{$user->id}";
        return Cache::get($key, []);
    }

    /**
     * Clear user's real-time notifications.
     */
    public function clearUserNotifications(User $user): void
    {
        $key = "user_notifications_{$user->id}";
        Cache::forget($key);
    }
}
