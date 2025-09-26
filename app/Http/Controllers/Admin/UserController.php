<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use App\Models\Setting;

class UserController extends Controller
{
    /**
     * Display a listing of users with filtering and pagination.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Role filter
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Status filter (email verification)
        if ($request->filled('status')) {
            if ($request->status === 'verified') {
                $query->whereNotNull('email_verified_at');
            } elseif ($request->status === 'unverified') {
                $query->whereNull('email_verified_at');
            }
        }

        // 2FA filter
        if ($request->filled('two_factor')) {
            $query->where('two_factor_enabled', $request->two_factor === 'enabled');
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
        $perPage = $request->get('per_page', 10);
        $users = $query->paginate($perPage);

        return response()->json([
            'data' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        // Get password requirements from settings
        $minPasswordLength = Setting::getValue('min_password_length', 8);
        $requireUppercase = Setting::getValue('require_uppercase', true);
        $requireLowercase = Setting::getValue('require_lowercase', true);
        $requireNumbers = Setting::getValue('require_numbers', true);
        $requireSymbols = Setting::getValue('require_symbols', false);

        // Build password validation rules
        $passwordRules = ['required', 'string', "min:{$minPasswordLength}"];

        if ($requireUppercase) {
            $passwordRules[] = 'regex:/[A-Z]/';
        }
        if ($requireLowercase) {
            $passwordRules[] = 'regex:/[a-z]/';
        }
        if ($requireNumbers) {
            $passwordRules[] = 'regex:/[0-9]/';
        }
        if ($requireSymbols) {
            $passwordRules[] = 'regex:/[^A-Za-z0-9]/';
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => $passwordRules,
            'role' => ['required', Rule::in(['user', 'super-admin'])],
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string|max:1000',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'phone' => $request->phone,
            'bio' => $request->bio,
        ]);

        // Log user creation
        ActivityService::logAdminAction(
            $request->user(),
            'User Created',
            "Admin created user: {$user->name} ({$user->email})",
            [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'user_role' => $user->role,
            ]
        );

        return response()->json([
            'message' => 'User created successfully',
            'data' => $user,
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        // Get password requirements from settings
        $minPasswordLength = Setting::getValue('min_password_length', 8);
        $requireUppercase = Setting::getValue('require_uppercase', true);
        $requireLowercase = Setting::getValue('require_lowercase', true);
        $requireNumbers = Setting::getValue('require_numbers', true);
        $requireSymbols = Setting::getValue('require_symbols', false);

        // Build password validation rules
        $passwordRules = ['nullable', 'string', "min:{$minPasswordLength}"];

        if ($requireUppercase) {
            $passwordRules[] = 'regex:/[A-Z]/';
        }
        if ($requireLowercase) {
            $passwordRules[] = 'regex:/[a-z]/';
        }
        if ($requireNumbers) {
            $passwordRules[] = 'regex:/[0-9]/';
        }
        if ($requireSymbols) {
            $passwordRules[] = 'regex:/[^A-Za-z0-9]/';
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'password' => $passwordRules,
            'role' => ['required', Rule::in(['user', 'super-admin'])],
            'phone' => 'nullable|string|max:20',
            'bio' => 'nullable|string|max:1000',
        ]);

        // Store old values for logging
        $oldName = $user->name;
        $oldEmail = $user->email;
        $oldRole = $user->role;

        $updateData = [
            'name' => $request->name,
            'email' => $request->email,
            'role' => $request->role,
            'phone' => $request->phone,
            'bio' => $request->bio,
        ];

        // Only update password if provided
        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $user->update($updateData);

        // Log user update
        $changes = [];
        if ($oldName !== $user->name)
            $changes[] = 'name';
        if ($oldEmail !== $user->email)
            $changes[] = 'email';
        if ($oldRole !== $user->role)
            $changes[] = 'role';
        if ($request->filled('password'))
            $changes[] = 'password';

        ActivityService::logAdminAction(
            $request->user(),
            'User Updated',
            "Admin updated user: {$user->name} ({$user->email}) - Changed: " . implode(', ', $changes),
            [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'changes' => $changes,
            ]
        );

        return response()->json([
            'message' => 'User updated successfully',
            'data' => $user,
        ]);
    }

    /**
     * Remove the specified user.
     */
    public function destroy(Request $request, User $user)
    {
        // Prevent admin from deleting themselves
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'You cannot delete your own account',
            ], 422);
        }

        // Store user info for logging before deletion
        $userName = $user->name;
        $userEmail = $user->email;

        // Delete profile image if exists
        if ($user->profile_image) {
            Storage::disk('public')->delete($user->profile_image);
        }

        $user->delete();

        // Log user deletion
        ActivityService::logAdminAction(
            $request->user(),
            'User Deleted',
            "Admin deleted user: {$userName} ({$userEmail})",
            [
                'deleted_user_name' => $userName,
                'deleted_user_email' => $userEmail,
            ]
        );

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Get user statistics for dashboard.
     */
    public function stats()
    {
        $stats = [
            'total_users' => User::count(),
            'verified_users' => User::whereNotNull('email_verified_at')->count(),
            'unverified_users' => User::whereNull('email_verified_at')->count(),
            'two_factor_users' => User::where('two_factor_enabled', true)->count(),
            'super_admins' => User::where('role', 'super-admin')->count(),
            'regular_users' => User::where('role', 'user')->count(),
            'new_users_this_month' => User::whereMonth('created_at', now()->month)->count(),
            'new_users_this_week' => User::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
        ];

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Export users to CSV.
     */
    public function export(Request $request)
    {
        $query = User::query();

        // Apply same filters as index method
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('status')) {
            if ($request->status === 'verified') {
                $query->whereNotNull('email_verified_at');
            } elseif ($request->status === 'unverified') {
                $query->whereNull('email_verified_at');
            }
        }

        $users = $query->get();
        $exportCount = $users->count();

        // Log the export action
        ActivityService::logUserExport(
            $request->user(),
            $request->only(['search', 'role', 'status']),
            $exportCount
        );

        $filename = 'users_export_' . now()->format('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($users) {
            $file = fopen('php://output', 'w');

            // CSV headers
            fputcsv($file, [
                'ID',
                'Name',
                'Email',
                'Role',
                'Email Verified',
                '2FA Enabled',
                'Phone',
                'Created At',
                'Updated At',
            ]);

            // CSV data
            foreach ($users as $user) {
                fputcsv($file, [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->role,
                    $user->email_verified_at ? 'Yes' : 'No',
                    $user->two_factor_enabled ? 'Yes' : 'No',
                    $user->phone,
                    $user->created_at,
                    $user->updated_at,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Bulk actions on users.
     */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|in:delete,verify,unverify,enable_2fa,disable_2fa,change_role',
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'role' => 'required_if:action,change_role|in:user,super-admin',
        ]);

        $userIds = $request->user_ids;
        $action = $request->action;

        // Prevent admin from performing actions on themselves
        if (in_array($request->user()->id, $userIds)) {
            return response()->json([
                'message' => 'You cannot perform actions on your own account',
            ], 422);
        }

        $users = User::whereIn('id', $userIds);
        $affectedUsers = $users->get();

        switch ($action) {
            case 'delete':
                // Delete profile images
                foreach ($affectedUsers as $user) {
                    if ($user->profile_image) {
                        Storage::disk('public')->delete($user->profile_image);
                    }
                }
                $users->delete();
                $message = 'Users deleted successfully';

                // Log bulk deletion
                ActivityService::logAdminAction(
                    $request->user(),
                    'Bulk User Deletion',
                    "Admin deleted {$affectedUsers->count()} users",
                    [
                        'deleted_count' => $affectedUsers->count(),
                        'deleted_users' => $affectedUsers->pluck('email')->toArray(),
                    ]
                );
                break;

            case 'verify':
                $users->update(['email_verified_at' => now()]);
                $message = 'Users verified successfully';

                // Log bulk verification
                ActivityService::logAdminAction(
                    $request->user(),
                    'Bulk User Verification',
                    "Admin verified {$affectedUsers->count()} users",
                    [
                        'verified_count' => $affectedUsers->count(),
                        'verified_users' => $affectedUsers->pluck('email')->toArray(),
                    ]
                );
                break;

            case 'unverify':
                $users->update(['email_verified_at' => null]);
                $message = 'Users unverified successfully';

                // Log bulk unverification
                ActivityService::logAdminAction(
                    $request->user(),
                    'Bulk User Unverification',
                    "Admin unverified {$affectedUsers->count()} users",
                    [
                        'unverified_count' => $affectedUsers->count(),
                        'unverified_users' => $affectedUsers->pluck('email')->toArray(),
                    ]
                );
                break;

            case 'enable_2fa':
                $users->update(['two_factor_enabled' => true]);
                $message = '2FA enabled for users successfully';

                // Log bulk 2FA enable
                ActivityService::logAdminAction(
                    $request->user(),
                    'Bulk 2FA Enable',
                    "Admin enabled 2FA for {$affectedUsers->count()} users",
                    [
                        'enabled_count' => $affectedUsers->count(),
                        'enabled_users' => $affectedUsers->pluck('email')->toArray(),
                    ]
                );
                break;

            case 'disable_2fa':
                $users->update([
                    'two_factor_enabled' => false,
                    'two_factor_secret' => null,
                    'two_factor_confirmed_at' => null,
                ]);
                $message = '2FA disabled for users successfully';

                // Log bulk 2FA disable
                ActivityService::logAdminAction(
                    $request->user(),
                    'Bulk 2FA Disable',
                    "Admin disabled 2FA for {$affectedUsers->count()} users",
                    [
                        'disabled_count' => $affectedUsers->count(),
                        'disabled_users' => $affectedUsers->pluck('email')->toArray(),
                    ]
                );
                break;

            case 'change_role':
                $users->update(['role' => $request->role]);
                $message = 'User roles updated successfully';

                // Log bulk role change
                ActivityService::logAdminAction(
                    $request->user(),
                    'Bulk Role Change',
                    "Admin changed role to '{$request->role}' for {$affectedUsers->count()} users",
                    [
                        'new_role' => $request->role,
                        'affected_count' => $affectedUsers->count(),
                        'affected_users' => $affectedUsers->pluck('email')->toArray(),
                    ]
                );
                break;

            default:
                return response()->json([
                    'message' => 'Invalid action',
                ], 422);
        }

        return response()->json([
            'message' => $message,
        ]);
    }
}
