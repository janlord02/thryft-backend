<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get user's notifications.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = $user->notifications();

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        // Type filter
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Status filter
        if ($request->filled('status')) {
            if ($request->status === 'unread') {
                $query->wherePivot('read', false);
            } elseif ($request->status === 'read') {
                $query->wherePivot('read', true);
            }
        }

        // Sorting
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 25);
        $notifications = $query->paginate($perPage);

        return response()->json([
            'data' => $notifications->items(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'from' => $notifications->firstItem(),
                'to' => $notifications->lastItem(),
            ],
        ]);
    }

    /**
     * Mark notification as read.
     */
    public function markAsRead(Notification $notification)
    {
        $user = Auth::user();

        $this->notificationService->markAsRead($notification, $user);

        return response()->json([
            'message' => 'Notification marked as read',
        ]);
    }

    /**
     * Get a single notification.
     */
    public function show(Notification $notification)
    {
        $user = Auth::user();

        // Get the notification with pivot data for this specific user
        $userNotification = $user->notifications()
            ->where('notification_id', $notification->id)
            ->withPivot('read', 'read_at')
            ->first();

        if (!$userNotification) {
            return response()->json([
                'message' => 'Notification not found or access denied'
            ], 404);
        }

        // Create a response that includes the pivot data
        $notificationData = $notification->toArray();
        $notificationData['pivot'] = [
            'read' => $userNotification->pivot->read,
            'read_at' => $userNotification->pivot->read_at,
        ];

        return response()->json([
            'data' => $notificationData,
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead()
    {
        $user = Auth::user();

        $this->notificationService->markAllAsRead($user);

        return response()->json([
            'message' => 'All notifications marked as read',
        ]);
    }

    /**
     * Store push subscription.
     */
    public function storePushSubscription(Request $request)
    {
        $request->validate([
            'subscription' => 'required|array',
            'subscription.endpoint' => 'required|string',
            'subscription.keys' => 'required|array',
            'subscription.keys.auth' => 'required|string',
            'subscription.keys.p256dh' => 'required|string',
        ]);

        $user = Auth::user();

        // Delete existing subscriptions for this user
        $user->pushSubscriptions()->delete();

        // Create new subscription
        $user->pushSubscriptions()->create([
            'endpoint' => $request->subscription['endpoint'],
            'p256dh_key' => $request->subscription['keys']['p256dh'],
            'auth_token' => $request->subscription['keys']['auth'],
            'active' => true,
        ]);

        return response()->json([
            'message' => 'Push subscription saved successfully',
        ]);
    }

    /**
     * Get push subscription.
     */
    public function getPushSubscription()
    {
        $user = Auth::user();

        $subscription = $user->pushSubscriptions()->first();

        return response()->json([
            'data' => $subscription,
        ]);
    }

    /**
     * Delete push subscription.
     */
    public function deletePushSubscription()
    {
        $user = Auth::user();

        // Delete all push subscriptions for this user
        $user->pushSubscriptions()->delete();

        return response()->json([
            'message' => 'Push subscription deleted successfully',
        ]);
    }

    /**
     * Test push notification.
     */
    public function testPushNotification()
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Check if user has push subscription
            $subscription = $user->pushSubscriptions()->first();

            if (!$subscription) {
                return response()->json([
                    'message' => 'No push subscription found. Please enable push notifications first.'
                ], 400);
            }

            // Send test push notification
            $this->notificationService->send(
                title: 'Test Push Notification',
                message: 'This is a test push notification from ' . config('app.name'),
                type: 'info',
                userIds: [$user->id],
                data: [
                    'action_url' => config('app.url') . '/notifications',
                    'icon' => '/icons/favicon-128x128.png',
                ],
                channel: 'push',
                urgent: false,
            );

            return response()->json([
                'message' => 'Test push notification sent successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Test push notification failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to send test push notification: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send test notification.
     */
    public function sendTestNotification(Request $request)
    {
        try {
            $request->validate([
                'title' => 'sometimes|string|max:255',
                'message' => 'sometimes|string',
                'type' => 'sometimes|in:info,warning,error,success,urgent',
                'target' => 'sometimes|in:all,admins,users',
            ]);

            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'message' => 'User not authenticated'
                ], 401);
            }

            $title = $request->title ?? 'Test Notification';
            $message = $request->message ?? 'This is a test notification from ' . config('app.name');
            $type = $request->type ?? 'info';
            $target = $request->target ?? 'all';

            // Determine user IDs based on target
            $userIds = [];
            switch ($target) {
                case 'all':
                    $userIds = \App\Models\User::pluck('id')->toArray();
                    break;
                case 'admins':
                    $userIds = \App\Models\User::where('role', 'super-admin')->pluck('id')->toArray();
                    break;
                case 'users':
                    $userIds = \App\Models\User::where('role', 'user')->pluck('id')->toArray();
                    break;
            }

            if (empty($userIds)) {
                return response()->json([
                    'message' => 'No users found for the specified target'
                ], 400);
            }

            // Send test notification
            $notification = $this->notificationService->send(
                title: $title,
                message: $message,
                type: $type,
                userIds: $userIds,
                data: [
                    'action_url' => config('app.url') . '/notifications',
                    'icon' => '/icons/favicon-128x128.png',
                ],
                channel: 'all',
                urgent: false,
            );

            return response()->json([
                'message' => 'Test notification sent successfully',
                'data' => $notification->load('users'),
            ]);

        } catch (\Exception $e) {
            Log::error('Test notification failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to send test notification: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user notification statistics.
     */
    public function userStats()
    {
        $user = Auth::user();

        $stats = [
            'total' => $user->notifications()->count(),
            'unread' => $user->unreadNotifications()->count(),
            'this_week' => $user->notifications()
                ->where('notifications.created_at', '>=', now()->startOfWeek())
                ->count(),
        ];

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get available notification types.
     */
    public function types()
    {
        $types = [
            ['label' => 'Info', 'value' => 'info'],
            ['label' => 'Warning', 'value' => 'warning'],
            ['label' => 'Error', 'value' => 'error'],
            ['label' => 'Success', 'value' => 'success'],
            ['label' => 'Urgent', 'value' => 'urgent'],
        ];

        return response()->json([
            'data' => $types,
        ]);
    }

    /**
     * Get user notification preferences.
     */
    public function getPreferences()
    {
        $user = Auth::user();

        // Get user preferences
        $preferences = $user->notificationPreferences()->get();

        // Default preferences
        $defaults = [
            'push_enabled' => false,
            'push_urgent' => false,
            'email_enabled' => true,
            'email_daily' => false,
            'types' => [
                'info' => true,
                'warning' => true,
                'error' => true,
                'success' => true,
                'urgent' => true,
            ],
        ];

        // Build settings from user preferences
        $settings = $defaults;

        foreach ($preferences as $preference) {
            if ($preference->type === 'push_enabled') {
                $settings['push_enabled'] = $preference->push_enabled;
            } elseif ($preference->type === 'push_urgent') {
                $settings['push_urgent'] = $preference->push_enabled;
            } elseif ($preference->type === 'email_enabled') {
                $settings['email_enabled'] = $preference->email_enabled;
            } elseif ($preference->type === 'email_daily') {
                $settings['email_daily'] = $preference->email_enabled;
            } else {
                // For notification types (info, warning, etc.)
                $settings['types'][$preference->type] = $preference->database_enabled;
            }
        }

        return response()->json([
            'data' => $settings,
        ]);
    }

    /**
     * Update user notification preferences.
     */
    public function updatePreferences(Request $request)
    {
        $request->validate([
            'push_enabled' => 'boolean',
            'push_urgent' => 'boolean',
            'email_enabled' => 'boolean',
            'email_daily' => 'boolean',
            'types' => 'array',
            'types.*' => 'boolean',
        ]);

        $user = Auth::user();

        // Update or create preferences
        foreach ($request->all() as $key => $value) {
            if ($key === 'types' && is_array($value)) {
                foreach ($value as $type => $enabled) {
                    $user->notificationPreferences()->updateOrCreate(
                        ['type' => $type],
                        [
                            'database_enabled' => $enabled,
                            'email_enabled' => $enabled,
                            'push_enabled' => $enabled,
                        ]
                    );
                }
            } else {
                // Handle boolean settings
                $user->notificationPreferences()->updateOrCreate(
                    ['type' => $key],
                    [
                        'database_enabled' => $value,
                        'email_enabled' => $value,
                        'push_enabled' => $value,
                    ]
                );
            }
        }

        return response()->json([
            'message' => 'Notification preferences updated successfully',
        ]);
    }
}
