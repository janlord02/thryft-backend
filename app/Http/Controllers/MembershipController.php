<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\WalletCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Membership plans and members, paid for at the business. Merchants define
 * plans and enrol shoppers by email after taking payment (behind
 * business.manage_offers); shoppers and guests see a business's plans.
 */
class MembershipController extends Controller
{
    public function plans(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $plans = MembershipPlan::query()
            ->where('business_id', $business->id)
            ->withCount(['memberships as members_count' => fn ($q) => $q->current()])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $members = Membership::query()
            ->where('business_id', $business->id)
            ->with(['user:id,name,firstname,lastname,email', 'plan:id,name'])
            ->orderByDesc('ends_at')
            ->limit(300)
            ->get()
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'plan' => $m->plan?->name,
                'plan_id' => $m->plan_id,
                'name' => $m->user?->display_name,
                'email' => $m->user?->email,
                'is_active' => $m->isCurrent(),
                'status_note' => $m->statusNote(),
                'ends_at' => $m->ends_at,
            ]);

        return response()->json(['status' => 'success', 'data' => ['plans' => $plans, 'members' => $members]]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $plan = MembershipPlan::create(['business_id' => $business->id] + $this->validatedPlan($request));

        return response()->json(['status' => 'success', 'message' => 'Plan created.', 'data' => $plan], 201);
    }

    public function updatePlan(Request $request, int $plan): JsonResponse
    {
        $row = MembershipPlan::query()->where('business_id', $this->business($request)->id)->findOrFail($plan);
        $row->update($this->validatedPlan($request));

        return response()->json(['status' => 'success', 'message' => 'Saved.', 'data' => $row->fresh()]);
    }

    /** Enrol a shopper, or renew them: the new period starts when the current one ends. */
    public function enroll(Request $request, int $plan): JsonResponse
    {
        $business = $this->business($request);
        $row = MembershipPlan::query()->where('business_id', $business->id)->where('is_active', true)->findOrFail($plan);
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($data['email'])])->first();
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'No Thryft account uses that email yet. Ask them to sign up first.',
                'errors' => ['email' => ['No Thryft account uses that email yet. Ask them to sign up first.']],
            ], 422);
        }

        $membership = Membership::query()->where('plan_id', $row->id)->where('user_id', $user->id)->first();
        $from = $membership && $membership->status === 'active' && $membership->ends_at->isFuture() ? $membership->ends_at : now();
        $until = $from->copy()->addDays($row->duration_days);

        if ($membership) {
            $membership->update([
                'status' => 'active',
                'starts_at' => $membership->isCurrent() ? $membership->starts_at : now(),
                'ends_at' => $until,
            ]);
        } else {
            $membership = Membership::create([
                'plan_id' => $row->id,
                'business_id' => $business->id,
                'user_id' => $user->id,
                'code' => WalletCode::unique('MEM', 'memberships'),
                'starts_at' => now(),
                'ends_at' => $until,
            ]);
        }

        try {
            app(NotificationService::class)->send(
                title: "You're a {$row->name} member",
                message: "At {$business->name}, until {$until->toFormattedDateString()}. Your card is in your wallet.",
                type: 'success',
                userIds: [$user->id],
                data: ['kind' => 'membership', 'action_url' => '/user/coupons?tab=cards'],
            );
        } catch (\Throwable $e) {
            Log::warning('Membership notification failed', ['membership_id' => $membership->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'status' => 'success',
            'message' => "{$user->display_name} is a member until {$until->toFormattedDateString()}.",
            'data' => $membership->fresh(),
        ], 201);
    }

    public function cancel(Request $request, int $membership): JsonResponse
    {
        $row = Membership::query()->where('business_id', $this->business($request)->id)->findOrFail($membership);
        $row->update(['status' => 'cancelled']);

        return response()->json(['status' => 'success', 'message' => 'Membership cancelled.']);
    }

    /** A business's open plans, with the caller's membership when they have one. */
    public function forBusiness(Request $request, int $businessId): JsonResponse
    {
        $business = Business::fromAppId($businessId);
        if (!$business || $business->status !== 'active') {
            return response()->json(['status' => 'success', 'data' => ['plans' => [], 'sells_gift_certificates' => false]]);
        }

        $user = $request->user();
        $plans = MembershipPlan::query()->where('business_id', $business->id)->where('is_active', true)->orderBy('name')->get();
        $mine = $user
            ? Membership::query()->where('user_id', $user->id)->whereIn('plan_id', $plans->pluck('id'))->get()->keyBy('plan_id')
            : collect();

        return response()->json(['status' => 'success', 'data' => [
            'sells_gift_certificates' => (bool) $business->sells_gift_certificates,
            'plans' => $plans->map(fn (MembershipPlan $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'benefits' => $p->benefits,
                'price_label' => $p->price_label,
                'duration_days' => $p->duration_days,
                'mine' => ($m = $mine->get($p->id)) ? ['is_active' => $m->isCurrent(), 'status_note' => $m->statusNote()] : null,
            ])->values(),
        ]]);
    }

    private function validatedPlan(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'benefits' => ['nullable', 'string', 'max:2000'],
            'price_label' => ['nullable', 'string', 'max:60'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:730'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
