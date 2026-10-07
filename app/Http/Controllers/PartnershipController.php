<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Businesses finding each other: the partnership profile, the directory of
 * businesses open to working together, and organizations with their member
 * businesses. Behind business.manage_offers.
 */
class PartnershipController extends Controller
{
    public const INTERESTS = ['promotions', 'bundles', 'events', 'referrals'];

    public function profile(Request $request): JsonResponse
    {
        $b = $this->business($request);

        return response()->json(['status' => 'success', 'data' => [
            'kind' => $b->kind,
            'open_to_partnerships' => (bool) $b->open_to_partnerships,
            'partnership_interests' => $b->partnership_interests ?: [],
            'partnership_pitch' => $b->partnership_pitch,
            'interests' => self::INTERESTS,
        ]]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $b = $this->business($request);
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(['business', 'organization'])],
            'open_to_partnerships' => ['required', 'boolean'],
            'partnership_interests' => ['nullable', 'array'],
            'partnership_interests.*' => [Rule::in(self::INTERESTS)],
            'partnership_pitch' => ['nullable', 'string', 'max:300'],
        ]);
        $b->update($data);

        return response()->json(['status' => 'success', 'message' => 'Saved.']);
    }

    /** Other active businesses open to partnerships, nearest city first when given. */
    public function directory(Request $request): JsonResponse
    {
        $b = $this->business($request);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'interest' => ['nullable', Rule::in(self::INTERESTS)],
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        $rows = Business::query()
            ->active()
            ->whereKeyNot($b->id)
            ->where('open_to_partnerships', true)
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%' . addcslashes($term, '%_\\') . '%'))
            ->when($data['interest'] ?? null, fn ($q, $i) => $q->whereJsonContains('partnership_interests', $i))
            ->when($data['city'] ?? null, fn ($q, $c) => $q->whereHas('locations', fn ($l) => $l->where('city', $c)))
            ->with(['primaryLocation', 'owner'])
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(fn (Business $x) => $this->card($x));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /** For pickers: any active business by name (organizations invite members who may not be "open"). */
    public function search(Request $request): JsonResponse
    {
        $b = $this->business($request);
        $term = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q'];

        $rows = Business::query()->active()->whereKeyNot($b->id)
            ->where('name', 'like', '%' . addcslashes($term, '%_\\') . '%')
            ->with(['primaryLocation', 'owner'])->orderBy('name')->limit(20)->get()
            ->map(fn (Business $x) => $this->card($x));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    // -----------------------------------------------------------------
    // Organizations
    // -----------------------------------------------------------------

    public function members(Request $request): JsonResponse
    {
        $org = $this->business($request);

        return response()->json(['status' => 'success', 'data' => $org->memberBusinesses()->with(['primaryLocation', 'owner'])->orderBy('name')->get()
            ->map(fn (Business $x) => $this->card($x) + ['status' => $x->pivot->status])]);
    }

    public function invite(Request $request): JsonResponse
    {
        $org = $this->business($request);
        abort_unless($org->isOrganization(), 422, 'Only organizations have member businesses. Switch your account type first.');
        $data = $request->validate(['business_id' => ['required', 'integer']]);
        $target = Business::query()->active()->whereKeyNot($org->id)->findOrFail($data['business_id']);

        $existing = DB::table('organization_members')->where('organization_id', $org->id)->where('business_id', $target->id)->first();
        if (!$existing) {
            $org->memberBusinesses()->attach($target->id, ['status' => 'invited']);
            $this->tell($target, "{$org->name} invited you to join", "Accept to be listed on their page with your deals and events.", '/business/partners');
        }

        return response()->json(['status' => 'success', 'message' => $existing ? 'Already invited.' : "Invited {$target->name}."], $existing ? 200 : 201);
    }

    public function removeMember(Request $request, int $business): JsonResponse
    {
        $org = $this->business($request);
        $org->memberBusinesses()->detach($business);

        return response()->json(['status' => 'success', 'message' => 'Removed.']);
    }

    /** The organizations this business belongs to or is invited to. */
    public function myOrganizations(Request $request): JsonResponse
    {
        $b = $this->business($request);

        return response()->json(['status' => 'success', 'data' => $b->organizations()->with(['primaryLocation', 'owner'])->get()
            ->map(fn (Business $x) => $this->card($x) + ['status' => $x->pivot->status])]);
    }

    public function respond(Request $request, int $organization): JsonResponse
    {
        $b = $this->business($request);
        $accept = $request->validate(['accept' => ['required', 'boolean']])['accept'];

        $q = DB::table('organization_members')->where('organization_id', $organization)->where('business_id', $b->id);
        abort_unless($q->exists(), 404);
        $accept ? $q->update(['status' => 'active', 'updated_at' => now()]) : $q->delete();

        return response()->json(['status' => 'success', 'message' => $accept ? 'You are now a member.' : 'Done.']);
    }

    // -----------------------------------------------------------------

    public static function cardFor(Business $x): array
    {
        $loc = $x->primaryLocation;

        return [
            'id' => $x->id,
            'slug' => $x->slug,
            'name' => $x->name,
            'kind' => $x->kind,
            'owner_user_id' => $x->owner_user_id,
            'logo_url' => $x->logo_path ? asset('storage/' . $x->logo_path) : null,
            'city' => $loc?->city,
            'pitch' => $x->partnership_pitch,
            'interests' => $x->partnership_interests ?: [],
        ];
    }

    private function card(Business $x): array
    {
        return self::cardFor($x);
    }

    public static function tell(Business $to, string $title, string $message, string $url): void
    {
        try {
            app(NotificationService::class)->send(
                title: $title,
                message: $message,
                type: 'info',
                userIds: [$to->owner_user_id],
                data: ['kind' => 'partnership', 'action_url' => $url],
                channel: 'business',
            );
        } catch (\Throwable $e) {
            Log::warning('Partnership notification failed', ['business_id' => $to->id, 'error' => $e->getMessage()]);
        }
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
