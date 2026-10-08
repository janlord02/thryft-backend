<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\Promotion;
use App\Models\PromotionParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Joint promotions: the organizer's and participants' side (behind
 * business.manage_offers) and the shopper's view.
 *
 * Each participant brings one of its own coupons, so claiming and
 * redeeming work exactly as they do for any coupon. A partner promotion
 * can lock a participant's offer until the shopper has redeemed another
 * participant's — "eat at the bistro, then the cinema offer opens".
 */
class PromotionController extends Controller
{
    // -----------------------------------------------------------------
    // Merchant side
    // -----------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        $b = $this->business($request);

        $ids = PromotionParticipant::query()->where('business_id', $b->id)->pluck('promotion_id')
            ->merge(Promotion::query()->where('organizer_business_id', $b->id)->pluck('id'))->unique();

        $rows = Promotion::query()->whereIn('id', $ids)
            ->with(['organizer', 'participants.business', 'participants.coupon'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Promotion $p) => $this->merchantView($p, $b));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $b = $this->business($request);
        $data = $this->validated($request);
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('promotions', 'public');
        }

        $promotion = DB::transaction(function () use ($b, $data) {
            $p = Promotion::create(['organizer_business_id' => $b->id, 'status' => 'draft'] + $data);
            // An organization runs a campaign without taking part itself;
            // anyone else organizing is the first participant.
            if (!$b->isOrganization()) {
                $p->participants()->create(['business_id' => $b->id, 'status' => 'accepted', 'sort' => 0]);
            }

            return $p;
        });

        return response()->json(['status' => 'success', 'message' => 'Promotion created. Invite the other businesses next.', 'data' => $this->merchantView($promotion->fresh(['organizer', 'participants.business', 'participants.coupon']), $b)], 201);
    }

    public function update(Request $request, int $promotion): JsonResponse
    {
        $b = $this->business($request);
        $p = $this->organizedBy($b, $promotion);
        $data = $this->validated($request, $p);
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('promotions', 'public');
        }
        $p->update($data);

        return response()->json(['status' => 'success', 'message' => 'Saved.', 'data' => $this->merchantView($p->fresh(['organizer', 'participants.business', 'participants.coupon']), $b)]);
    }

    public function invite(Request $request, int $promotion): JsonResponse
    {
        $b = $this->business($request);
        $p = $this->organizedBy($b, $promotion);
        $data = $request->validate([
            'business_ids' => ['nullable', 'array', 'max:50'],
            'business_ids.*' => ['integer'],
            'all_members' => ['nullable', 'boolean'],
        ]);

        $ids = collect($data['business_ids'] ?? []);
        if (!empty($data['all_members']) && $b->isOrganization()) {
            $ids = $ids->merge($b->memberBusinesses()->wherePivot('status', 'active')->pluck('businesses.id'));
        }

        $invited = 0;
        foreach (Business::query()->active()->whereIn('id', $ids->unique())->whereKeyNot($b->id)->get() as $target) {
            $row = PromotionParticipant::query()->firstOrCreate(
                ['promotion_id' => $p->id, 'business_id' => $target->id],
                ['status' => 'invited', 'sort' => $p->participants()->count()],
            );
            if ($row->wasRecentlyCreated) {
                $invited++;
                PartnershipController::tell($target, "{$b->name} invited you to a promotion", "\"{$p->title}\" — pick one of your offers to take part.", '/business/promotions');
            }
        }

        return response()->json(['status' => 'success', 'message' => $invited ? "Invited {$invited} " . ($invited === 1 ? 'business' : 'businesses') . '.' : 'Nobody new to invite.', 'data' => $this->merchantView($p->fresh(['organizer', 'participants.business', 'participants.coupon']), $b)]);
    }

    /** A participant accepts (choosing their offer) or declines. */
    public function participate(Request $request, int $promotion): JsonResponse
    {
        $b = $this->business($request);
        $row = PromotionParticipant::query()->where('promotion_id', $promotion)->where('business_id', $b->id)->firstOrFail();
        $data = $request->validate([
            'accept' => ['required', 'boolean'],
            'coupon_id' => ['required_if:accept,true', 'nullable', 'integer', Rule::exists('coupons', 'id')->where('business_id', $b->id)],
            'role' => ['nullable', 'string', 'max:60'],
        ]);

        $row->update($data['accept']
            ? ['status' => 'accepted', 'coupon_id' => $data['coupon_id'], 'role' => $data['role'] ?? $row->role]
            : ['status' => 'declined', 'coupon_id' => null]);

        return response()->json(['status' => 'success', 'message' => $data['accept'] ? "You're in." : 'Declined.']);
    }

    /** Organizer: set a participant's role label and what unlocks it. */
    public function arrange(Request $request, int $promotion, int $participant): JsonResponse
    {
        $b = $this->business($request);
        $p = $this->organizedBy($b, $promotion);
        $row = $p->participants()->findOrFail($participant);
        $data = $request->validate([
            'role' => ['nullable', 'string', 'max:60'],
            'unlocked_by_participant_id' => ['nullable', 'integer', Rule::exists('promotion_participants', 'id')->where('promotion_id', $p->id)],
        ]);
        abort_if(($data['unlocked_by_participant_id'] ?? null) === $row->id, 422, 'An offer cannot unlock itself.');
        abort_if(!empty($data['unlocked_by_participant_id']) && $p->type !== 'partner', 422, 'Only partner promotions unlock one offer with another.');

        $row->update($data);

        return response()->json(['status' => 'success', 'message' => 'Saved.', 'data' => $this->merchantView($p->fresh(['organizer', 'participants.business', 'participants.coupon']), $b)]);
    }

    public function setStatus(Request $request, int $promotion): JsonResponse
    {
        $b = $this->business($request);
        $p = $this->organizedBy($b, $promotion);
        $status = $request->validate(['status' => ['required', Rule::in(['draft', 'live', 'ended'])]])['status'];

        if ($status === 'live') {
            $ready = $p->participants()->where('status', 'accepted')->whereNotNull('coupon_id')->count();
            abort_if($ready < 2, 422, 'At least two businesses need to have accepted with an offer before it goes live.');
        }
        $p->update(['status' => $status]);

        return response()->json(['status' => 'success', 'message' => ['draft' => 'Back to draft.', 'live' => "It's live.", 'ended' => 'Ended.'][$status]]);
    }

    // -----------------------------------------------------------------
    // Shopper side
    // -----------------------------------------------------------------

    public function running(Request $request): JsonResponse
    {
        $rows = Promotion::query()->running()
            ->whereHas('organizer', fn ($q) => $q->active())
            ->with(['organizer', 'participants' => fn ($q) => $q->where('status', 'accepted')->whereNotNull('coupon_id'), 'participants.business'])
            ->orderByRaw('ends_at IS NULL, ends_at')
            ->limit(30)
            ->get()
            ->map(fn (Promotion $p) => $this->summary($p));

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $p = Promotion::query()->where('slug', $slug)->whereIn('status', ['live', 'ended'])->firstOrFail();

        return response()->json(['status' => 'success', 'data' => self::detail($p, $request->user()?->id)]);
    }

    /** Shared by the API and the crawlable page. */
    public static function detail(Promotion $p, ?int $userId): array
    {
        $p->loadMissing(['organizer', 'participants.business', 'participants.coupon']);
        $offers = $p->participants->filter(fn ($x) => $x->status === 'accepted' && $x->coupon && $x->business?->status === 'active');
        $locked = self::lockedFor($p, $userId);

        return (new self())->summary($p) + [
            'description' => $p->description,
            'is_running' => Promotion::query()->whereKey($p->id)->running()->exists(),
            'offers' => $offers->values()->map(fn (PromotionParticipant $x) => [
                'participant_id' => $x->id,
                'role' => $x->role,
                'business' => PartnershipController::cardFor($x->business),
                'coupon' => [
                    'id' => $x->coupon->id,
                    'slug' => $x->coupon->slug,
                    'title' => $x->coupon->title,
                    'description' => $x->coupon->description,
                    'discount_type' => $x->coupon->discount_type,
                    'discount_amount' => $x->coupon->discount_amount,
                    'discount_percentage' => $x->coupon->discount_percentage,
                    'formatted_discount' => $x->coupon->formatted_discount,
                    'banner_image_url' => $x->coupon->banner_image_url,
                    'expires_at' => $x->coupon->expires_at,
                    'can_be_claimed' => $x->coupon->canBeClaimed(),
                ],
                'locked' => in_array($x->id, $locked, true),
                'unlocked_by' => $x->unlocked_by_participant_id
                    ? optional($offers->firstWhere('id', $x->unlocked_by_participant_id))->business?->name
                    : null,
            ])->all(),
        ];
    }

    /**
     * Participant ids whose offer this shopper cannot claim yet, because they
     * have not redeemed the offer that unlocks it.
     *
     * @return int[]
     */
    public static function lockedFor(Promotion $p, ?int $userId): array
    {
        $p->loadMissing('participants');
        $gated = $p->participants->whereNotNull('unlocked_by_participant_id');
        if ($gated->isEmpty()) {
            return [];
        }
        $redeemedCoupons = $userId
            ? ClaimedCoupon::query()->where('user_id', $userId)->where('status', 'used')->pluck('coupon_id')->all()
            : [];

        return $gated->filter(function (PromotionParticipant $x) use ($p, $redeemedCoupons) {
            $key = $p->participants->firstWhere('id', $x->unlocked_by_participant_id);

            return !$key || !$key->coupon_id || !in_array($key->coupon_id, $redeemedCoupons);
        })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** Why this shopper may not claim this coupon yet, if a running promotion locks it. */
    public static function lockReason(Coupon $coupon, int $userId): ?string
    {
        $rows = PromotionParticipant::query()->where('coupon_id', $coupon->id)->whereNotNull('unlocked_by_participant_id')
            ->whereHas('promotion', fn ($q) => $q->running())->with('promotion.participants.business')->get();

        foreach ($rows as $row) {
            if (in_array($row->id, self::lockedFor($row->promotion, $userId), true)) {
                $key = $row->promotion->participants->firstWhere('id', $row->unlocked_by_participant_id);

                return "Part of \"{$row->promotion->title}\": use the offer at " . ($key?->business?->name ?? 'the partner business') . ' first.';
            }
        }

        return null;
    }

    // -----------------------------------------------------------------

    private function summary(Promotion $p): array
    {
        $taking = $p->participants->filter(fn ($x) => $x->status === 'accepted' && $x->coupon_id);

        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'type' => $p->type,
            'title' => $p->title,
            'image_url' => $p->image_url,
            'starts_at' => $p->starts_at,
            'ends_at' => $p->ends_at,
            'status' => $p->status,
            'organizer' => ['id' => $p->organizer->id, 'name' => $p->organizer->name, 'kind' => $p->organizer->kind, 'owner_user_id' => $p->organizer->owner_user_id],
            'business_names' => $taking->map(fn ($x) => $x->business?->name)->filter()->values()->all(),
            'public_url' => $p->public_url,
        ];
    }

    private function merchantView(Promotion $p, Business $me): array
    {
        $mine = $p->participants->firstWhere('business_id', $me->id);

        return $this->summary($p) + [
            'description' => $p->description,
            'is_organizer' => $p->organizer_business_id === $me->id,
            'my_participation' => $mine ? ['id' => $mine->id, 'status' => $mine->status, 'coupon_id' => $mine->coupon_id, 'role' => $mine->role] : null,
            'participants' => $p->participants->map(fn (PromotionParticipant $x) => [
                'id' => $x->id,
                'business' => ['id' => $x->business->id, 'name' => $x->business->name],
                'status' => $x->status,
                'role' => $x->role,
                'coupon' => $x->coupon ? ['id' => $x->coupon->id, 'title' => $x->coupon->title, 'formatted_discount' => $x->coupon->formatted_discount] : null,
                'unlocked_by_participant_id' => $x->unlocked_by_participant_id,
            ])->values()->all(),
        ];
    }

    private function validated(Request $request, ?Promotion $existing = null): array
    {
        $data = $request->validate([
            'type' => [$existing ? 'sometimes' : 'required', Rule::in(Promotion::TYPES)],
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:4096'],
        ]);
        unset($data['image']);

        return $data;
    }

    private function organizedBy(Business $b, int $id): Promotion
    {
        return Promotion::query()->where('organizer_business_id', $b->id)->findOrFail($id);
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
