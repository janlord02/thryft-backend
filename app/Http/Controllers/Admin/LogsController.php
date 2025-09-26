<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LogsController extends Controller
{
    /**
     * Display a listing of logs with filtering and pagination.
     */
    public function index(Request $request)
    {
        $query = ActivityLog::with('user');

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Type filter
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // User filter
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sorting
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 25);
        $logs = $query->paginate($perPage);

        return response()->json([
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
        ]);
    }

    /**
     * Display the specified log.
     */
    public function show(ActivityLog $log)
    {
        $log->load('user');

        return response()->json([
            'data' => $log,
        ]);
    }

    /**
     * Get log statistics.
     */
    public function stats()
    {
        $stats = [
            'total_logs' => ActivityLog::count(),
            'logs_today' => ActivityLog::whereDate('created_at', today())->count(),
            'logs_this_week' => ActivityLog::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'logs_this_month' => ActivityLog::whereMonth('created_at', now()->month)->count(),
            'types' => ActivityLog::select('type', DB::raw('count(*) as count'))
                ->groupBy('type')
                ->orderBy('count', 'desc')
                ->get(),
            'top_users' => ActivityLog::select('user_id', DB::raw('count(*) as count'))
                ->with('user:id,name,email')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get(),
        ];

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Export logs to CSV.
     */
    public function export(Request $request)
    {
        $query = ActivityLog::with('user');

        // Apply same filters as index method
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($userQuery) use ($search) {
                        $userQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $logs = $query->orderBy('created_at', 'desc')->get();
        $exportCount = $logs->count();

        $filename = 'activity_logs_export_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');

            // CSV headers
            fputcsv($file, [
                'ID',
                'Title',
                'Description',
                'Type',
                'Icon',
                'User',
                'User Email',
                'Created At',
                'Data',
            ]);

            // CSV data
            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->title,
                    $log->description,
                    $log->type,
                    $log->icon,
                    $log->user ? $log->user->name : 'System',
                    $log->user ? $log->user->email : 'N/A',
                    $log->created_at,
                    json_encode($log->data),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Clear all logs.
     */
    public function clear()
    {
        $deletedCount = ActivityLog::count();
        ActivityLog::truncate();

        return response()->json([
            'message' => "Successfully cleared {$deletedCount} logs",
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Get available log types.
     */
    public function types()
    {
        $types = ActivityLog::select('type')
            ->distinct()
            ->whereNotNull('type')
            ->pluck('type')
            ->map(function ($type) {
                return [
                    'label' => ucfirst(str_replace('_', ' ', $type)),
                    'value' => $type,
                ];
            })
            ->sortBy('label')
            ->values();

        return response()->json([
            'data' => $types,
        ]);
    }

    /**
     * Get users for filtering.
     */
    public function users()
    {
        $users = User::select('id', 'name', 'email')
            ->whereHas('activityLogs')
            ->withCount('activityLogs')
            ->orderBy('activity_logs_count', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($user) {
                return [
                    'label' => "{$user->name} ({$user->email})",
                    'value' => $user->id,
                    'count' => $user->activity_logs_count,
                ];
            });

        return response()->json([
            'data' => $users,
        ]);
    }
}
