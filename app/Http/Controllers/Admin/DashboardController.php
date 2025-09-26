<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\ActivityLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard analytics
     */
    public function analytics()
    {
        try {
            // Get current month and year
            $currentMonth = Carbon::now()->month;
            $currentYear = Carbon::now()->year;

            // Total users
            $totalUsers = User::count();

            // Verified users (active users)
            $verifiedUsers = User::whereNotNull('email_verified_at')->count();

            // New users this month
            $newUsersThisMonth = User::whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $currentMonth)
                ->count();

            // 2FA enabled users
            $twoFactorUsers = User::where('two_factor_enabled', true)->count();

            // User growth data (last 12 months)
            $userGrowth = [];
            for ($i = 11; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i);
                $count = User::whereYear('created_at', $date->year)
                    ->whereMonth('created_at', $date->month)
                    ->count();

                $userGrowth[] = [
                    'month' => $date->format('M'),
                    'count' => $count,
                ];
            }

            // User activity breakdown
            $userActivity = [
                'active' => $verifiedUsers,
                'inactive' => $totalUsers - $verifiedUsers,
                'new' => $newUsersThisMonth,
                'returning' => $verifiedUsers - $newUsersThisMonth,
            ];

            // Recent activity (last 10 activities)
            $recentActivity = ActivityLog::with('user')
                ->latest()
                ->take(10)
                ->get()
                ->map(function ($activity) {
                    return [
                        'id' => $activity->id,
                        'title' => $activity->title,
                        'description' => $activity->description,
                        'icon' => $activity->icon,
                        'timestamp' => $activity->created_at,
                        'user' => $activity->user ? [
                            'id' => $activity->user->id,
                            'name' => $activity->user->name,
                            'email' => $activity->user->email,
                        ] : null,
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'analytics' => [
                        'total_users' => $totalUsers,
                        'verified_users' => $verifiedUsers,
                        'new_users_this_month' => $newUsersThisMonth,
                        'two_factor_users' => $twoFactorUsers,
                    ],
                    'user_growth' => $userGrowth,
                    'user_activity' => $userActivity,
                    'recent_activity' => $recentActivity,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load dashboard analytics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get recent activity
     */
    public function recentActivity(Request $request)
    {
        try {
            $limit = $request->get('limit', 10);

            $activities = ActivityLog::with('user')
                ->latest()
                ->take($limit)
                ->get()
                ->map(function ($activity) {
                    return [
                        'id' => $activity->id,
                        'title' => $activity->title,
                        'description' => $activity->description,
                        'icon' => $activity->icon,
                        'timestamp' => $activity->created_at,
                        'user' => $activity->user ? [
                            'id' => $activity->user->id,
                            'name' => $activity->user->name,
                            'email' => $activity->user->email,
                        ] : null,
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => $activities,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load recent activity',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's own activity
     */
    public function userActivity(Request $request)
    {
        try {
            $user = $request->user();
            $limit = $request->get('limit', 10);

            $activities = ActivityLog::where('user_id', $user->id)
                ->latest()
                ->take($limit)
                ->get()
                ->map(function ($activity) {
                    return [
                        'id' => $activity->id,
                        'title' => $activity->title,
                        'description' => $activity->description,
                        'icon' => $activity->icon,
                        'timestamp' => $activity->created_at,
                        'user' => [
                            'id' => $activity->user->id,
                            'name' => $activity->user->name,
                            'email' => $activity->user->email,
                        ],
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => $activities,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load user activity',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user statistics for charts
     */
    public function userStats()
    {
        try {
            // Get user registration data for the last 12 months
            $monthlyStats = DB::table('users')
                ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, COUNT(*) as count')
                ->where('created_at', '>=', Carbon::now()->subMonths(12))
                ->groupBy('year', 'month')
                ->orderBy('year')
                ->orderBy('month')
                ->get();

            // Format data for charts
            $chartData = [];
            $labels = [];

            for ($i = 11; $i >= 0; $i--) {
                $date = Carbon::now()->subMonths($i);
                $monthKey = $date->format('Y-m');

                $monthData = $monthlyStats->where('year', $date->year)->where('month', $date->month)->first();
                $count = $monthData ? $monthData->count : 0;

                $chartData[] = $count;
                $labels[] = $date->format('M');
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'chart_data' => $chartData,
                    'labels' => $labels,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to load user statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
