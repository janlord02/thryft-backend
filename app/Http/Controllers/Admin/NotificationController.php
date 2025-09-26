<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Display a listing of notifications.
     */
    public function index(Request $request)
    {
        $query = Notification::with('users');

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
            $query->where('notifications.type', $request->type);
        }

        // Status filter
        if ($request->filled('status')) {
            if ($request->status === 'unread') {
                $query->unread();
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
     * Store a newly created notification.
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|in:info,warning,error,success,urgent',
            'user_ids' => 'array',
            'user_ids.*' => 'exists:users,id',
            'target' => 'in:all,admins,users',
            'channel' => 'in:database,email,push,all',
            'urgent' => 'boolean',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        // Determine user IDs based on target or user_ids
        $userIds = [];

        if ($request->filled('target')) {
            switch ($request->target) {
                case 'all':
                    $userIds = User::pluck('id')->toArray();
                    break;
                case 'admins':
                    $userIds = User::where('role', 'super-admin')->pluck('id')->toArray();
                    break;
                case 'users':
                    $userIds = User::where('role', 'user')->pluck('id')->toArray();
                    break;
            }
        } elseif ($request->filled('user_ids')) {
            $userIds = $request->user_ids;
        } else {
            return response()->json([
                'message' => 'Either target or user_ids must be provided',
                'errors' => ['recipients' => ['Please select recipients for the notification']]
            ], 422);
        }

        if (empty($userIds)) {
            return response()->json([
                'message' => 'No valid recipients found',
                'errors' => ['recipients' => ['No users match the selected criteria']]
            ], 422);
        }

        $notification = $this->notificationService->send(
            title: $request->title,
            message: $request->message,
            type: $request->type,
            userIds: $userIds,
            data: $request->data ?? [],
            channel: $request->channel ?? 'all',
            urgent: $request->urgent ?? false,
            scheduledAt: $request->scheduled_at,
        );

        return response()->json([
            'message' => 'Notification sent successfully',
            'data' => $notification->load('users'),
        ], 201);
    }

    /**
     * Display the specified notification.
     */
    public function show(Notification $notification)
    {
        $notification->load('users');

        return response()->json([
            'data' => $notification,
        ]);
    }

    /**
     * Update the specified notification.
     */
    public function update(Request $request, Notification $notification)
    {
        $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'message' => 'sometimes|required|string',
            'type' => 'sometimes|required|in:info,warning,error,success,urgent',
            'urgent' => 'sometimes|boolean',
        ]);

        $notification->update($request->only(['title', 'message', 'type', 'urgent']));

        return response()->json([
            'message' => 'Notification updated successfully',
            'data' => $notification->load('users'),
        ]);
    }

    /**
     * Remove the specified notification.
     */
    public function destroy(Notification $notification)
    {
        $this->notificationService->delete($notification);

        return response()->json([
            'message' => 'Notification deleted successfully',
        ]);
    }

    /**
     * Get notification statistics.
     */
    public function stats()
    {
        $stats = $this->notificationService->getStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Mark notification as read for current user.
     */
    public function markAsRead(Notification $notification)
    {
        $this->notificationService->markAsRead($notification, Auth::user());

        return response()->json([
            'message' => 'Notification marked as read',
        ]);
    }

    /**
     * Mark all notifications as read for current user.
     */
    public function markAllAsRead()
    {
        $count = $this->notificationService->markAllAsRead(Auth::user());

        return response()->json([
            'message' => "{$count} notifications marked as read",
            'count' => $count,
        ]);
    }

    /**
     * Get user's notifications.
     */
    public function userNotifications(Request $request)
    {
        $user = Auth::user();
        $query = $user->notifications();

        // Type filter
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Read status filter
        if ($request->filled('read')) {
            if ($request->read === 'true') {
                $query->wherePivot('read', true);
            } else {
                $query->wherePivot('read', false);
            }
        }

        // Sorting
        $query->orderBy('notifications.created_at', 'desc');

        // Pagination
        $perPage = $request->get('per_page', 10);
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
     * Save push subscription.
     */
    public function savePushSubscription(Request $request)
    {
        $request->validate([
            'subscription' => 'required|array',
            'subscription.endpoint' => 'required|string',
            'subscription.keys' => 'required|array',
            'subscription.keys.p256dh' => 'required|string',
            'subscription.keys.auth' => 'required|string',
        ]);

        $user = Auth::user();

        // Delete existing subscription for this user
        $user->pushSubscriptions()->delete();

        // Create new subscription
        $subscription = $user->pushSubscriptions()->create([
            'endpoint' => $request->subscription['endpoint'],
            'p256dh_key' => $request->subscription['keys']['p256dh'],
            'auth_token' => $request->subscription['keys']['auth'],
        ]);

        return response()->json([
            'message' => 'Push subscription saved successfully',
            'data' => $subscription,
        ]);
    }

    /**
     * Delete push subscription.
     */
    public function deletePushSubscription()
    {
        $user = Auth::user();

        // Delete all subscriptions for this user
        $deleted = $user->pushSubscriptions()->delete();

        return response()->json([
            'message' => 'Push subscription deleted successfully',
            'deleted_count' => $deleted,
        ]);
    }

    /**
     * Get push subscription status.
     */
    public function getPushSubscription()
    {
        $user = Auth::user();

        $subscriptions = $user->pushSubscriptions()->get();

        return response()->json([
            'data' => [
                'has_subscriptions' => $subscriptions->count() > 0,
                'subscriptions' => $subscriptions,
                'subscription_count' => $subscriptions->count(),
            ],
        ]);
    }

    /**
     * Test push notification for current user.
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
     * Send test notification for current user.
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
                    $userIds = User::pluck('id')->toArray();
                    break;
                case 'admins':
                    $userIds = User::where('role', 'super-admin')->pluck('id')->toArray();
                    break;
                case 'users':
                    $userIds = User::where('role', 'user')->pluck('id')->toArray();
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
     * Get users for notification targeting.
     */
    public function users()
    {
        $users = User::select('id', 'name', 'email', 'role')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                return [
                    'label' => "{$user->name} ({$user->email}) - {$user->role}",
                    'value' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ];
            });

        return response()->json([
            'data' => $users,
        ]);
    }
}
