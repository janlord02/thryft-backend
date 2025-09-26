<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    protected WebPush $webPush;

    public function __construct()
    {
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);
    }

    /**
     * Send a notification to users.
     */
    public function send(
        string $title,
        string $message,
        string $type = 'info',
        array $userIds = [],
        array $data = [],
        string $channel = 'all',
        bool $urgent = false,
        ?string $scheduledAt = null
    ): Notification {
        // Create the notification
        $notification = Notification::create([
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'data' => $data,
            'channel' => $channel,
            'urgent' => $urgent,
            'scheduled_at' => $scheduledAt,
        ]);

        // If no users specified, send to all users
        if (empty($userIds)) {
            $users = User::all();
        } else {
            $users = User::whereIn('id', $userIds)->get();
        }

        // Attach users to notification
        $notification->users()->attach($users->pluck('id')->toArray());

        // Send immediately if not scheduled
        if (!$scheduledAt) {
            $this->processNotification($notification);
        }

        return $notification;
    }

    /**
     * Process a notification (send via different channels).
     */
    public function processNotification(Notification $notification): void
    {
        $users = $notification->users;

        Log::info('Processing notification for users', [
            'notification_id' => $notification->id,
            'user_count' => $users->count(),
            'notification_type' => $notification->type,
            'channel' => $notification->channel,
        ]);

        foreach ($users as $user) {
            Log::info('Processing notification for user', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'notification_id' => $notification->id,
            ]);

            $preference = $user->getNotificationPreference($notification->type);

            Log::info('User notification preference', [
                'user_id' => $user->id,
                'notification_type' => $notification->type,
                'preference_found' => $preference ? true : false,
                'database_enabled' => $preference ? $preference->database_enabled : 'default_enabled',
                'email_enabled' => $preference ? $preference->email_enabled : 'default_enabled',
                'push_enabled' => $preference ? $preference->push_enabled : 'default_enabled',
            ]);

            // Send database notification (always enabled by default)
            if (!$preference || $preference->database_enabled) {
                Log::info('Database notification enabled for user', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id,
                ]);
                // Database notification is already created
            }

            // Send email notification
            if (!$preference || $preference->email_enabled) {
                Log::info('Email notification enabled for user', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id,
                ]);
                $this->sendEmailNotification($notification, $user);
            }

            // Send push notification
            if (!$preference || $preference->push_enabled) {
                Log::info('Push notification enabled for user', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id,
                ]);
                $this->sendPushNotification($notification, $user);
            } else {
                Log::info('Push notification disabled for user', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id,
                    'preference_push_enabled' => $preference ? $preference->push_enabled : 'no_preference',
                ]);
            }
        }

        // Mark as sent
        $notification->update(['sent_at' => now()]);
    }

    /**
     * Send email notification.
     */
    protected function sendEmailNotification(Notification $notification, User $user): void
    {
        try {
            Mail::send('emails.notification', [
                'notification' => $notification,
                'user' => $user,
            ], function ($message) use ($notification, $user) {
                $message->to($user->email, $user->name)
                    ->subject($notification->title);
            });

            // Update pivot table
            $notification->users()->updateExistingPivot($user->id, [
                'email_sent' => true,
                'email_sent_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send email notification', [
                'notification_id' => $notification->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send push notification.
     */
    protected function sendPushNotification(Notification $notification, User $user): void
    {
        Log::info('Starting push notification process', [
            'user_id' => $user->id,
            'notification_id' => $notification->id,
        ]);

        $subscriptions = $user->pushSubscriptions()->active()->get();

        Log::info('Found push subscriptions', [
            'user_id' => $user->id,
            'subscription_count' => $subscriptions->count(),
        ]);

        if ($subscriptions->isEmpty()) {
            Log::warning('No active push subscriptions found for user', [
                'user_id' => $user->id,
            ]);
            return;
        }

        foreach ($subscriptions as $subscription) {
            try {
                Log::info('Attempting to send push notification', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'notification_id' => $notification->id,
                    'endpoint' => $subscription->endpoint,
                ]);

                $pushSubscription = Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $subscription->p256dh_key,
                        'auth' => $subscription->auth_token,
                    ],
                ]);

                $payload = json_encode([
                    'title' => $notification->title,
                    'body' => $notification->message,
                    'icon' => '/icons/favicon-128x128.png',
                    'badge' => '/icons/favicon-128x128.png',
                    'data' => array_merge($notification->data ?? [], [
                        'notification_id' => $notification->id,
                        'type' => $notification->type,
                        'urgent' => $notification->urgent,
                    ]),
                ]);

                Log::info('Push payload prepared', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'payload' => $payload,
                ]);

                $report = $this->webPush->sendOneNotification($pushSubscription, $payload);

                if ($report->isSuccess()) {
                    Log::info('Push notification sent successfully', [
                        'user_id' => $user->id,
                        'subscription_id' => $subscription->id,
                        'notification_id' => $notification->id,
                    ]);
                    // Update pivot table
                    $notification->users()->updateExistingPivot($user->id, [
                        'push_sent' => true,
                        'push_sent_at' => now(),
                    ]);
                } else {
                    Log::warning('Push notification failed', [
                        'user_id' => $user->id,
                        'subscription_id' => $subscription->id,
                        'notification_id' => $notification->id,
                        'reason' => $report->getReason(),
                        'response_code' => $report->getResponse()->getStatusCode(),
                    ]);
                    // Deactivate failed subscription
                    $subscription->update(['active' => false]);
                }
            } catch (\Exception $e) {
                Log::error('Failed to send push notification', [
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'notification_id' => $notification->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Mark notification as read for a user.
     */
    public function markAsRead(Notification $notification, User $user): void
    {
        $notification->users()->updateExistingPivot($user->id, [
            'read' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * Mark all notifications as read for a user.
     */
    public function markAllAsRead(User $user): int
    {
        $unreadNotifications = $user->unreadNotifications()->get();
        $count = 0;

        foreach ($unreadNotifications as $notification) {
            // Use the pivot table directly
            DB::table('notification_user')
                ->where('notification_id', $notification->id)
                ->where('user_id', $user->id)
                ->update([
                    'read' => true,
                    'read_at' => now(),
                ]);
            $count++;
        }

        return $count;
    }

    /**
     * Delete a notification.
     */
    public function delete(Notification $notification): bool
    {
        return $notification->delete();
    }

    /**
     * Get notification statistics.
     */
    public function getStats(): array
    {
        return [
            'total' => Notification::count(),
            'unread' => Notification::unread()->count(),
            'this_week' => Notification::where('created_at', '>=', now()->startOfWeek())->count(),
        ];
    }

    /**
     * Process scheduled notifications.
     */
    public function processScheduledNotifications(): void
    {
        $scheduledNotifications = Notification::scheduled()->get();

        foreach ($scheduledNotifications as $notification) {
            $this->processNotification($notification);
        }
    }
}
