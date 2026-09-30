<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Referral;
use App\Services\Referrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReferralController extends Controller
{
    /** The merchant's referral panel. */
    public function panel(Request $request, Referrals $referrals): JsonResponse
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return response()->json(['status' => 'success', 'data' => $referrals->panelFor($business)]);
    }

    /** Admin: every referral, newest first, filterable by status. */
    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'qualified', 'rewarded', 'void'])],
        ]);

        $rows = Referral::query()
            ->with(['business:id,name,referral_code,owner_user_id', 'referredUser:id,name,email,role,business_name'])
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /**
     * Admin: record that a reward was applied, or void a referral. The
     * transition is conditional on the current status so two admins cannot
     * both "reward" the same row.
     */
    public function adminUpdate(Request $request, int $referral): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['rewarded', 'void', 'qualified'])],
            'reward_note' => ['nullable', 'string', 'max:255'],
        ]);

        $row = Referral::query()->findOrFail($referral);
        $target = $validated['status'];

        $allowedFrom = [
            'rewarded' => ['qualified'],
            'void' => ['pending', 'qualified'],
            'qualified' => ['pending'],
        ][$target];

        $affected = Referral::query()
            ->whereKey($row->id)
            ->whereIn('status', $allowedFrom)
            ->update(array_filter([
                'status' => $target,
                'rewarded_at' => $target === 'rewarded' ? now() : null,
                'qualified_at' => $target === 'qualified' ? now() : null,
                'reward_note' => $validated['reward_note'] ?? null,
                'updated_at' => now(),
            ], fn ($v) => $v !== null));

        if ($affected !== 1) {
            return response()->json([
                'status' => 'error',
                'message' => "This referral is {$row->status}; it cannot be marked {$target}.",
            ], 409);
        }

        return response()->json(['status' => 'success', 'data' => $row->fresh()->load('business:id,name', 'referredUser:id,name,email')]);
    }
}
