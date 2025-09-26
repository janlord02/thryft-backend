<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\UserSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * Display a listing of subscriptions.
     */
    public function index(Request $request)
    {
        $query = Subscription::withCount(['userSubscriptions', 'users']);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status')) {
            if ($request->status === 'active') {
                $query->where('status', true);
            } elseif ($request->status === 'inactive') {
                $query->where('status', false);
            }
        }

        // Visibility filter
        if ($request->filled('visibility')) {
            if ($request->visibility === 'visible') {
                $query->where('on_show', true);
            } elseif ($request->visibility === 'hidden') {
                $query->where('on_show', false);
            }
        }

        // Billing cycle filter
        if ($request->filled('billing_cycle')) {
            $query->where('billing_cycle', $request->billing_cycle);
        }

        // Sorting
        $sortField = $request->get('sort_by', 'sort_order');
        $sortDirection = $request->get('sort_direction', 'asc');

        if ($sortField === 'sort_order') {
            $query->orderBy('sort_order')->orderBy('name');
        } else {
            $query->orderBy($sortField, $sortDirection);
        }

        // Pagination
        $perPage = $request->get('per_page', 25);
        $subscriptions = $query->paginate($perPage);

        // Add additional computed data
        $subscriptions->getCollection()->transform(function ($subscription) {
            $subscription->active_subscribers = $subscription->userSubscriptions()
                ->where('status', 'active')
                ->count();
            $subscription->total_revenue = $subscription->userSubscriptions()
                ->whereIn('status', ['active', 'cancelled', 'expired'])
                ->sum('amount_paid');
            return $subscription;
        });

        return response()->json([
            'data' => $subscriptions->items(),
            'pagination' => [
                'current_page' => $subscriptions->currentPage(),
                'last_page' => $subscriptions->lastPage(),
                'per_page' => $subscriptions->perPage(),
                'total' => $subscriptions->total(),
                'from' => $subscriptions->firstItem(),
                'to' => $subscriptions->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created subscription.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:subscriptions,name',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'billing_cycle' => 'required|in:daily,weekly,monthly,yearly,lifetime',
            'features' => 'nullable|array',
            'features.*' => 'string',
            'is_popular' => 'boolean',
            'status' => 'boolean',
            'on_show' => 'boolean',
            'sort_order' => 'integer|min:0',
            'stripe_price_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        // Get the next sort order if not provided
        $sortOrder = $request->sort_order ?? (Subscription::max('sort_order') + 1);

        $subscription = Subscription::create([
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'billing_cycle' => $request->billing_cycle,
            'features' => $request->features,
            'is_popular' => $request->is_popular ?? false,
            'status' => $request->status ?? true,
            'on_show' => $request->on_show ?? true,
            'sort_order' => $sortOrder,
            'stripe_price_id' => $request->stripe_price_id,
            'metadata' => $request->metadata,
        ]);

        return response()->json([
            'message' => 'Subscription created successfully',
            'data' => $subscription,
        ], 201);
    }

    /**
     * Display the specified subscription.
     */
    public function show(Subscription $subscription)
    {
        $subscription->loadCount(['userSubscriptions', 'users']);

        // Add computed statistics
        $subscription->active_subscribers = $subscription->getActiveSubscriberCount();
        $subscription->total_revenue = $subscription->getTotalRevenue();

        // Get recent subscribers
        $subscription->recent_subscribers = $subscription->userSubscriptions()
            ->with('user:id,name,email')
            ->latest()
            ->limit(5)
            ->get();

        return response()->json([
            'data' => $subscription,
        ]);
    }

    /**
     * Update the specified subscription.
     */
    public function update(Request $request, Subscription $subscription)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:subscriptions,name,' . $subscription->id,
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'billing_cycle' => 'sometimes|required|in:daily,weekly,monthly,yearly,lifetime',
            'features' => 'nullable|array',
            'features.*' => 'string',
            'is_popular' => 'boolean',
            'status' => 'boolean',
            'on_show' => 'boolean',
            'sort_order' => 'integer|min:0',
            'stripe_price_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $subscription->update($request->only([
            'name',
            'description',
            'price',
            'billing_cycle',
            'features',
            'is_popular',
            'status',
            'on_show',
            'sort_order',
            'stripe_price_id',
            'metadata',
        ]));

        return response()->json([
            'message' => 'Subscription updated successfully',
            'data' => $subscription->fresh(),
        ]);
    }

    /**
     * Remove the specified subscription.
     */
    public function destroy(Subscription $subscription)
    {
        // Check if subscription has active subscribers
        $activeSubscribers = $subscription->userSubscriptions()
            ->where('status', 'active')
            ->count();

        if ($activeSubscribers > 0) {
            return response()->json([
                'message' => 'Cannot delete subscription with active subscribers',
                'errors' => ['subscription' => ['This subscription has active subscribers and cannot be deleted']]
            ], 422);
        }

        $subscription->delete();

        return response()->json([
            'message' => 'Subscription deleted successfully',
        ]);
    }

    /**
     * Get subscription statistics.
     */
    public function stats()
    {
        $totalSubscriptions = Subscription::count();
        $activeSubscriptions = Subscription::where('status', true)->count();
        $visibleSubscriptions = Subscription::where('on_show', true)->count();
        $totalSubscribers = UserSubscription::where('status', 'active')->count();
        $totalRevenue = UserSubscription::whereIn('status', ['active', 'cancelled', 'expired'])->sum('amount_paid');

        // Monthly revenue trend (last 6 months)
        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $revenue = UserSubscription::whereIn('status', ['active', 'cancelled', 'expired'])
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->sum('amount_paid');

            $monthlyRevenue[] = [
                'month' => $month->format('M Y'),
                'revenue' => (float) $revenue,
            ];
        }

        // Top subscriptions by subscribers
        $topSubscriptions = Subscription::withCount([
            'userSubscriptions' => function ($query) {
                $query->where('status', 'active');
            }
        ])
            ->orderByDesc('user_subscriptions_count')
            ->limit(5)
            ->get()
            ->map(function ($subscription) {
                return [
                    'name' => $subscription->name,
                    'subscribers' => $subscription->user_subscriptions_count,
                    'revenue' => $subscription->getTotalRevenue(),
                ];
            });

        return response()->json([
            'data' => [
                'total_subscriptions' => $totalSubscriptions,
                'active_subscriptions' => $activeSubscriptions,
                'visible_subscriptions' => $visibleSubscriptions,
                'total_subscribers' => $totalSubscribers,
                'total_revenue' => (float) $totalRevenue,
                'monthly_revenue' => $monthlyRevenue,
                'top_subscriptions' => $topSubscriptions,
            ],
        ]);
    }

    /**
     * Get subscription options for dropdowns.
     */
    public function options()
    {
        $subscriptions = Subscription::active()
            ->visible()
            ->ordered()
            ->get(['id', 'name', 'price', 'billing_cycle'])
            ->map(function ($subscription) {
                return [
                    'label' => "{$subscription->name} - {$subscription->formatted_price} {$subscription->billing_cycle_label}",
                    'value' => $subscription->id,
                    'name' => $subscription->name,
                    'price' => $subscription->price,
                    'billing_cycle' => $subscription->billing_cycle,
                ];
            });

        return response()->json([
            'data' => $subscriptions,
        ]);
    }

    /**
     * Reorder subscriptions.
     */
    public function reorder(Request $request)
    {
        $request->validate([
            'subscriptions' => 'required|array',
            'subscriptions.*.id' => 'required|exists:subscriptions,id',
            'subscriptions.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($request) {
            foreach ($request->subscriptions as $item) {
                Subscription::where('id', $item['id'])
                    ->update(['sort_order' => $item['sort_order']]);
            }
        });

        return response()->json([
            'message' => 'Subscriptions reordered successfully',
        ]);
    }

    /**
     * Toggle subscription status.
     */
    public function toggleStatus(Subscription $subscription)
    {
        $subscription->update(['status' => !$subscription->status]);

        $status = $subscription->status ? 'activated' : 'deactivated';

        return response()->json([
            'message' => "Subscription {$status} successfully",
            'data' => $subscription->fresh(),
        ]);
    }

    /**
     * Toggle subscription visibility.
     */
    public function toggleVisibility(Subscription $subscription)
    {
        $subscription->update(['on_show' => !$subscription->on_show]);

        $visibility = $subscription->on_show ? 'shown' : 'hidden';

        return response()->json([
            'message' => "Subscription {$visibility} successfully",
            'data' => $subscription->fresh(),
        ]);
    }

    /**
     * Get subscribers for a subscription.
     */
    public function subscribers(Request $request, Subscription $subscription)
    {
        $query = $subscription->userSubscriptions()->with('user');

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 25);
        $subscribers = $query->paginate($perPage);

        return response()->json([
            'data' => $subscribers->items(),
            'pagination' => [
                'current_page' => $subscribers->currentPage(),
                'last_page' => $subscribers->lastPage(),
                'per_page' => $subscribers->perPage(),
                'total' => $subscribers->total(),
                'from' => $subscribers->firstItem(),
                'to' => $subscribers->lastItem(),
            ],
        ]);
    }
}
