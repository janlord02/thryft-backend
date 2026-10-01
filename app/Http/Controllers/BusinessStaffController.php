<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * The team behind a business: who may act for it, and as what.
 *
 * Someone is added by the email of their existing Thryft account and is
 * active straight away; the owner knows who they are hiring, and a separate
 * accept step would only be a hoop. The 'invited' status stays in the schema
 * for an email-invite flow later.
 *
 * Every route here sits behind business:business.manage_staff, so only the
 * owner and admins reach this controller at all.
 */
class BusinessStaffController extends Controller
{
    /** Roles a manager of staff may hand out. Ownership is not assignable. */
    private const ASSIGNABLE_ROLES = ['admin', 'manager', 'staff'];

    private const ROLE_RANK = ['owner' => 0, 'admin' => 1, 'manager' => 2, 'staff' => 3];

    public function index(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $me = $request->user();

        $members = BusinessMember::query()
            ->where('business_id', $business->id)
            ->where('status', '!=', 'revoked')
            ->with('user')
            ->get()
            ->filter(fn (BusinessMember $m) => $m->user !== null)
            ->sortBy([
                fn ($a, $b) => (self::ROLE_RANK[$a->role] ?? 9) <=> (self::ROLE_RANK[$b->role] ?? 9),
                fn ($a, $b) => strcasecmp($a->user->display_name ?? '', $b->user->display_name ?? ''),
            ])
            ->values()
            ->map(fn (BusinessMember $m) => $this->present($m, $me));

        return response()->json([
            'status' => 'success',
            'data' => [
                'business' => ['id' => $business->id, 'name' => $business->name],
                'members' => $members,
                'roles' => $this->roleCatalog(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->business($request);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($validated['email'])])->first();

        if (!$user) {
            return $this->fieldError('email', "No Thryft account uses that email yet. Ask them to sign up first, then add them.");
        }

        if ((int) $user->id === (int) $business->owner_user_id) {
            return $this->fieldError('email', 'That is the owner of this business.');
        }

        $membership = BusinessMember::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership && $membership->status === 'active') {
            return $this->fieldError('email', "{$user->display_name} is already on the team.");
        }

        // A revoked or never-accepted row is reused rather than duplicated:
        // the unique index on (business_id, user_id) would refuse a second one.
        if ($membership) {
            $membership->update([
                'role' => $validated['role'],
                'permissions' => null,
                'status' => 'active',
                'invited_at' => now(),
                'accepted_at' => now(),
            ]);
        } else {
            $membership = BusinessMember::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'role' => $validated['role'],
                'status' => 'active',
                'invited_at' => now(),
                'accepted_at' => now(),
            ]);
        }

        $this->welcome($membership->load('user'), $business, $request->user());

        return response()->json([
            'status' => 'success',
            'message' => "{$user->display_name} has been added to the team.",
            'data' => $this->present($membership, $request->user()),
        ], 201);
    }

    public function update(Request $request, int $member): JsonResponse
    {
        $business = $this->business($request);
        $membership = $this->memberOf($business, $member);

        $validated = $request->validate([
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        if ($membership->isOwner()) {
            return $this->fieldError('role', "The owner's role cannot be changed.");
        }

        if ((int) $membership->user_id === (int) $request->user()->id) {
            return $this->fieldError('role', 'You cannot change your own role. Ask the owner or another admin.');
        }

        $membership->update(['role' => $validated['role'], 'permissions' => null]);

        return response()->json([
            'status' => 'success',
            'message' => 'Role updated.',
            'data' => $this->present($membership->load('user'), $request->user()),
        ]);
    }

    public function destroy(Request $request, int $member): JsonResponse
    {
        $business = $this->business($request);
        $membership = $this->memberOf($business, $member);

        if ($membership->isOwner()) {
            return $this->fieldError('member', 'The owner cannot be removed from their own business.');
        }

        if ((int) $membership->user_id === (int) $request->user()->id) {
            return $this->fieldError('member', 'You cannot remove yourself. Ask the owner or another admin.');
        }

        // Revoked rather than deleted: membership is checked on every request,
        // so this takes effect immediately, and the row keeps the history.
        $membership->update(['status' => 'revoked']);

        return response()->json([
            'status' => 'success',
            'message' => 'Removed from the team.',
        ]);
    }

    private function present(BusinessMember $m, User $me): array
    {
        return [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'name' => $m->user?->display_name,
            'email' => $m->user?->email,
            'avatar' => $m->user?->profile_image_url,
            'role' => $m->role,
            'status' => $m->status,
            'since' => optional($m->accepted_at ?? $m->created_at)->toISOString(),
            'is_you' => (int) $m->user_id === (int) $me->id,
            'is_owner' => $m->isOwner(),
        ];
    }

    /**
     * What each assignable role can do, in words a merchant would use. The
     * abilities themselves come from config/permissions.php so the two never
     * drift apart.
     */
    private function roleCatalog(): array
    {
        $blurbs = [
            'admin' => ['label' => 'Admin', 'description' => 'Everything except billing, including managing the team.'],
            'manager' => ['label' => 'Manager', 'description' => 'Items, coupons, redemptions and the dashboard.'],
            'staff' => ['label' => 'Staff', 'description' => 'Scan and redeem coupons at the till. Nothing else.'],
        ];

        return array_map(fn (string $role) => [
            'value' => $role,
            'label' => $blurbs[$role]['label'],
            'description' => $blurbs[$role]['description'],
            'abilities' => config("permissions.roles.{$role}", []),
        ], self::ASSIGNABLE_ROLES);
    }

    private function welcome(BusinessMember $membership, Business $business, User $addedBy): void
    {
        try {
            $role = $membership->role;
            $landing = $membership->hasAbility('business.view_analytics') ? '/business/dashboard' : '/scan';

            app(NotificationService::class)->send(
                title: "You've joined {$business->name}",
                message: "{$addedBy->display_name} added you as {$role}. Sign in again if you don't see the business tools yet.",
                type: 'success',
                userIds: [$membership->user_id],
                data: [
                    'kind' => 'team_added',
                    'business_id' => $business->id,
                    'business_name' => $business->name,
                    'role' => $role,
                    'action_url' => $landing,
                ],
                channel: 'business',
            );
        } catch (\Throwable $e) {
            Log::error('Team welcome notification failed', [
                'business_id' => $business->id,
                'user_id' => $membership->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function memberOf(Business $business, int $id): BusinessMember
    {
        return BusinessMember::query()
            ->where('business_id', $business->id)
            ->where('status', '!=', 'revoked')
            ->findOrFail($id);
    }

    private function fieldError(string $field, string $message): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');

        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
