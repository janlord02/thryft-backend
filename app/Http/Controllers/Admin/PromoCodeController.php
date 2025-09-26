<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\UserPromoCode;
use App\Models\User;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class PromoCodeController extends Controller
{
    /**
     * Display a listing of promo codes.
     */
    public function index(Request $request)
    {
        $query = PromoCode::withCount(['userPromoCodes', 'users']);

        // Search filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'active':
                    $query->active()->valid();
                    break;
                case 'inactive':
                    $query->where('is_active', false);
                    break;
                case 'expired':
                    $query->expired();
                    break;
                case 'scheduled':
                    $query->scheduled();
                    break;
            }
        }

        // Discount type filter
        if ($request->filled('discount_type')) {
            $query->where('discount_type', $request->discount_type);
        }

        // Visibility filter
        if ($request->filled('visibility')) {
            if ($request->visibility === 'public') {
                $query->public();
            } elseif ($request->visibility === 'private') {
                $query->private();
            }
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->where('starts_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('expires_at', '<=', $request->date_to);
        }

        // Sorting
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 25);
        $promoCodes = $query->paginate($perPage);

        // Add additional computed data
        $promoCodes->getCollection()->transform(function ($promoCode) {
            $promoCode->active_assignments = $promoCode->userPromoCodes()
                ->where('status', 'assigned')
                ->count();
            $promoCode->total_discount_given = $promoCode->userPromoCodes()
                ->where('status', 'used')
                ->sum('discount_applied');
            return $promoCode;
        });

        return response()->json([
            'data' => $promoCodes->items(),
            'pagination' => [
                'current_page' => $promoCodes->currentPage(),
                'last_page' => $promoCodes->lastPage(),
                'per_page' => $promoCodes->perPage(),
                'total' => $promoCodes->total(),
                'from' => $promoCodes->firstItem(),
                'to' => $promoCodes->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created promo code.
     */
    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:50|unique:promo_codes,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'discount_type' => 'required|in:percentage,fixed_amount,free_access',
            'discount_value' => 'nullable|required_unless:discount_type,free_access|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'starts_at' => 'required|date',
            'expires_at' => 'required|date|after:starts_at',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_user' => 'required|integer|min:1',
            'applicable_subscriptions' => 'nullable|array',
            'applicable_subscriptions.*' => 'exists:subscriptions,id',
            'minimum_amount' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ]);

        // Additional validation for discount_value based on type
        if ($request->discount_type === 'percentage' && $request->discount_value > 100) {
            return response()->json([
                'message' => 'Percentage discount cannot exceed 100%',
                'errors' => ['discount_value' => ['Percentage discount cannot exceed 100%']]
            ], 422);
        }

        $promoCode = PromoCode::create([
            'code' => strtoupper($request->code),
            'name' => $request->name,
            'description' => $request->description,
            'discount_type' => $request->discount_type,
            'discount_value' => $request->discount_type === 'free_access' ? null : $request->discount_value,
            'max_discount_amount' => $request->max_discount_amount,
            'starts_at' => $request->starts_at,
            'expires_at' => $request->expires_at,
            'max_uses' => $request->max_uses,
            'max_uses_per_user' => $request->max_uses_per_user,
            'applicable_subscriptions' => $request->applicable_subscriptions,
            'minimum_amount' => $request->minimum_amount,
            'is_active' => $request->is_active ?? true,
            'is_public' => $request->is_public ?? true,
        ]);

        return response()->json([
            'message' => 'Promo code created successfully',
            'data' => $promoCode->load(['userPromoCodes', 'users']),
        ], 201);
    }

    /**
     * Display the specified promo code.
     */
    public function show(PromoCode $promoCode)
    {
        $promoCode->load(['userPromoCodes.user', 'userPromoCodes.subscription', 'userPromoCodes.assignedBy']);

        // Add statistics
        $promoCode->statistics = [
            'total_assignments' => $promoCode->userPromoCodes->count(),
            'active_assignments' => $promoCode->userPromoCodes->where('status', 'assigned')->count(),
            'used_assignments' => $promoCode->userPromoCodes->where('status', 'used')->count(),
            'total_discount_given' => $promoCode->userPromoCodes->where('status', 'used')->sum('discount_applied'),
            'average_discount' => $promoCode->userPromoCodes->where('status', 'used')->avg('discount_applied'),
            'usage_by_month' => $this->getUsageByMonth($promoCode),
        ];

        return response()->json(['data' => $promoCode]);
    }

    /**
     * Update the specified promo code.
     */
    public function update(Request $request, PromoCode $promoCode)
    {
        $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('promo_codes')->ignore($promoCode->id)],
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'discount_type' => 'sometimes|required|in:percentage,fixed_amount,free_access',
            'discount_value' => 'nullable|required_unless:discount_type,free_access|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'starts_at' => 'sometimes|required|date',
            'expires_at' => 'sometimes|required|date|after:starts_at',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_user' => 'sometimes|required|integer|min:1',
            'applicable_subscriptions' => 'nullable|array',
            'applicable_subscriptions.*' => 'exists:subscriptions,id',
            'minimum_amount' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ]);

        // Additional validation for discount_value based on type
        $discountType = $request->discount_type ?? $promoCode->discount_type;
        $discountValue = $request->discount_value ?? $promoCode->discount_value;

        if ($discountType === 'percentage' && $discountValue > 100) {
            return response()->json([
                'message' => 'Percentage discount cannot exceed 100%',
                'errors' => ['discount_value' => ['Percentage discount cannot exceed 100%']]
            ], 422);
        }

        $updateData = $request->only([
            'name',
            'description',
            'discount_type',
            'discount_value',
            'max_discount_amount',
            'starts_at',
            'expires_at',
            'max_uses',
            'max_uses_per_user',
            'applicable_subscriptions',
            'minimum_amount',
            'is_active',
            'is_public'
        ]);

        if ($request->has('code')) {
            $updateData['code'] = strtoupper($request->code);
        }

        if ($discountType === 'free_access') {
            $updateData['discount_value'] = null;
        }

        $promoCode->update($updateData);

        return response()->json([
            'message' => 'Promo code updated successfully',
            'data' => $promoCode->fresh(['userPromoCodes', 'users']),
        ]);
    }

    /**
     * Remove the specified promo code.
     */
    public function destroy(PromoCode $promoCode)
    {
        // Check if promo code has been used
        if ($promoCode->userPromoCodes()->where('status', 'used')->exists()) {
            return response()->json([
                'message' => 'Cannot delete promo code that has been used by customers'
            ], 422);
        }

        // Revoke all assigned promo codes
        $promoCode->userPromoCodes()
            ->where('status', 'assigned')
            ->update([
                'status' => 'revoked',
                'metadata' => ['revoked_reason' => 'Promo code deleted by admin']
            ]);

        $promoCode->delete();

        return response()->json([
            'message' => 'Promo code deleted successfully'
        ]);
    }

    /**
     * Get promo code statistics.
     */
    public function stats()
    {
        $stats = [
            'total_codes' => PromoCode::count(),
            'active_codes' => PromoCode::valid()->count(),
            'expired_codes' => PromoCode::expired()->count(),
            'total_assignments' => UserPromoCode::count(),
            'used_assignments' => UserPromoCode::used()->count(),
            'total_discount_given' => UserPromoCode::used()->sum('discount_applied'),
            'top_codes' => PromoCode::withCount([
                'userPromoCodes as used_count' => function ($query) {
                    $query->where('status', 'used');
                }
            ])
                ->orderByDesc('used_count')
                ->take(5)
                ->get(['id', 'code', 'name', 'discount_display']),
        ];

        return response()->json(['data' => $stats]);
    }

    /**
     * Assign promo code to users.
     */
    public function assign(Request $request, PromoCode $promoCode)
    {
        $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
            'expires_at' => 'nullable|date|after:now',
            'notes' => 'nullable|string|max:500',
        ]);

        $assignedUsers = [];
        $errors = [];
        $adminId = Auth::id();

        foreach ($request->user_ids as $userId) {
            // Check if user already has this promo code
            $existingAssignment = UserPromoCode::where('user_id', $userId)
                ->where('promo_code_id', $promoCode->id)
                ->first();

            if ($existingAssignment) {
                $user = User::find($userId);
                $errors[] = "User {$user->name} already has this promo code assigned";
                continue;
            }

            UserPromoCode::create([
                'user_id' => $userId,
                'promo_code_id' => $promoCode->id,
                'status' => 'assigned',
                'assigned_at' => now(),
                'expires_at' => $request->expires_at,
                'assigned_by' => $adminId,
                'assignment_notes' => $request->notes,
            ]);

            $assignedUsers[] = $userId;
        }

        return response()->json([
            'message' => count($assignedUsers) . ' users assigned successfully',
            'assigned_users' => $assignedUsers,
            'errors' => $errors,
        ]);
    }

    /**
     * Revoke promo code from users.
     */
    public function revoke(Request $request, PromoCode $promoCode)
    {
        $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $revokedCount = UserPromoCode::where('promo_code_id', $promoCode->id)
            ->whereIn('user_id', $request->user_ids)
            ->where('status', 'assigned')
            ->update([
                'status' => 'revoked',
                'metadata' => [
                    'revoked_at' => now()->toISOString(),
                    'revoked_reason' => $request->reason ?? 'Revoked by admin',
                    'revoked_by' => Auth::id(),
                ]
            ]);

        return response()->json([
            'message' => "{$revokedCount} assignments revoked successfully"
        ]);
    }

    /**
     * Get users who have this promo code.
     */
    public function assignedUsers(PromoCode $promoCode, Request $request)
    {
        $query = UserPromoCode::with(['user', 'subscription', 'assignedBy'])
            ->where('promo_code_id', $promoCode->id);

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
        $sortField = $request->get('sort_by', 'assigned_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 25);
        $assignments = $query->paginate($perPage);

        return response()->json([
            'data' => $assignments->items(),
            'pagination' => [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'per_page' => $assignments->perPage(),
                'total' => $assignments->total(),
                'from' => $assignments->firstItem(),
                'to' => $assignments->lastItem(),
            ],
        ]);
    }

    /**
     * Get business users for assignment.
     */
    public function businessUsers(Request $request)
    {
        $query = User::where('role', 'business');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->select('id', 'name', 'email', 'created_at')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users]);
    }

    /**
     * Toggle promo code active status.
     */
    public function toggleStatus(PromoCode $promoCode)
    {
        $promoCode->update(['is_active' => !$promoCode->is_active]);

        return response()->json([
            'message' => 'Promo code status updated successfully',
            'data' => $promoCode->fresh(),
        ]);
    }

    /**
     * Toggle promo code visibility.
     */
    public function toggleVisibility(PromoCode $promoCode)
    {
        $promoCode->update(['is_public' => !$promoCode->is_public]);

        return response()->json([
            'message' => 'Promo code visibility updated successfully',
            'data' => $promoCode->fresh(),
        ]);
    }

    /**
     * Get usage statistics by month for a promo code.
     */
    private function getUsageByMonth(PromoCode $promoCode)
    {
        return UserPromoCode::where('promo_code_id', $promoCode->id)
            ->where('status', 'used')
            ->selectRaw('DATE_FORMAT(used_at, "%Y-%m") as month, COUNT(*) as count, SUM(discount_applied) as total_discount')
            ->groupBy('month')
            ->orderBy('month')
            ->get();
    }

    /**
     * Duplicate an existing promo code.
     */
    public function duplicate(PromoCode $promoCode)
    {
        $newCode = $promoCode->replicate();
        $newCode->code = $promoCode->code . '_COPY_' . now()->format('mdHi');
        $newCode->name = $promoCode->name . ' (Copy)';
        $newCode->total_uses = 0;
        $newCode->save();

        return response()->json([
            'message' => 'Promo code duplicated successfully',
            'data' => $newCode,
        ]);
    }
}
