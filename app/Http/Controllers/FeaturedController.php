<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Coupon;
use App\Models\Event;
use App\Models\FeaturedPlacement;
use App\Services\StripePayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Featured placement: a business pays to show one of its business page,
 * deals or events in the Featured row for shoppers nearby. Buying is behind
 * business.manage_billing; the row itself is public.
 */
class FeaturedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $b = $this->business($request);

        return response()->json(['status' => 'success', 'data' => [
            'price_per_day_cents' => config('featured.price_per_day_cents'),
            'max_days' => config('featured.max_days'),
            'radius_options' => config('featured.radius_options'),
            'has_location' => (bool) $this->centre($b),
            'targets' => $this->targets($b),
            'placements' => FeaturedPlacement::query()->where('business_id', $b->id)->orderByDesc('created_at')->limit(50)->get()
                ->map(fn (FeaturedPlacement $p) => $p->only(['id', 'target_type', 'target_id', 'radius_miles', 'days', 'amount_cents', 'status', 'starts_at', 'ends_at', 'business_tag_id'])
                    + ['label' => $this->labelOf($p)]),
        ]]);
    }

    public function store(Request $request, StripePayments $stripe): JsonResponse
    {
        $b = $this->business($request);
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['business', 'coupon', 'event'])],
            'target_id' => ['required', 'integer'],
            'business_tag_id' => ['nullable', 'integer', Rule::exists('business_tags', 'id')],
            'radius_miles' => ['required', Rule::in(config('featured.radius_options'))],
            'days' => ['required', 'integer', 'min:1', 'max:' . config('featured.max_days')],
            'starts_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        abort_unless($this->owns($b, $data['target_type'], (int) $data['target_id']), 422, 'You can only feature your own business, deals and events.');
        $centre = $this->centre($b);
        abort_unless($centre, 422, 'Add an address to your main location first, so we know where to show it.');

        $cents = $data['days'] * (int) config('featured.price_per_day_cents');
        $placement = FeaturedPlacement::create($data + [
            'business_id' => $b->id,
            'latitude' => $centre[0],
            'longitude' => $centre[1],
            'amount_cents' => $cents,
            'status' => 'pending_payment',
        ]);

        $intent = $stripe->create(
            $cents,
            "Thryft featured placement ({$data['days']} days)",
            ['featured_placement_id' => $placement->id, 'business_id' => $b->id],
            $b->owner?->email,
        );
        $placement->update(['stripe_payment_intent_id' => $intent['id']]);

        return response()->json(['status' => 'success', 'data' => [
            'placement_id' => $placement->id,
            'client_secret' => $intent['client_secret'],
            'amount_cents' => $cents,
        ]], 201);
    }

    /** After the card form: ask Stripe, and switch on if it was paid. The webhook does the same. */
    public function confirm(Request $request, int $placement, StripePayments $stripe): JsonResponse
    {
        $b = $this->business($request);
        $row = FeaturedPlacement::query()->where('business_id', $b->id)->findOrFail($placement);

        if ($row->status === 'pending_payment' && $row->stripe_payment_intent_id
            && $stripe->status($row->stripe_payment_intent_id) === 'succeeded') {
            $row->activate();
        }
        $row->refresh();

        return response()->json([
            'status' => $row->status === 'active' ? 'success' : 'error',
            'message' => $row->status === 'active'
                ? 'Paid. You\'re featured until ' . $row->ends_at->toFormattedDateString() . '.'
                : 'The payment has not gone through yet.',
        ], $row->status === 'active' ? 200 : 402);
    }

    /** The shopper's Featured row: placements showing now whose area covers them. */
    public function showing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'business_tag_id' => ['nullable', 'integer'],
        ]);

        $rows = FeaturedPlacement::query()->showing()
            ->whereHas('business', fn ($q) => $q->active())
            ->when($data['business_tag_id'] ?? null, fn ($q, $tag) => $q->where(fn ($w) => $w->whereNull('business_tag_id')->orWhere('business_tag_id', $tag)))
            ->with('business.owner')
            ->get()
            ->filter(fn (FeaturedPlacement $p) => $p->latitude !== null && $p->milesFrom((float) $data['latitude'], (float) $data['longitude']) <= $p->radius_miles)
            ->shuffle()
            ->take(10)
            ->map(fn (FeaturedPlacement $p) => $this->card($p))
            ->filter()
            ->values();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /** Called by the Stripe webhook. */
    public static function onPaymentSucceeded(?string $placementId, string $paymentIntentId): void
    {
        if (!$placementId) {
            return;
        }
        FeaturedPlacement::query()->whereKey($placementId)->where('stripe_payment_intent_id', $paymentIntentId)->first()?->activate();
    }

    // -----------------------------------------------------------------

    private function card(FeaturedPlacement $p): ?array
    {
        $b = $p->business;
        $base = ['id' => $p->id, 'type' => $p->target_type, 'sponsored' => true, 'business' => $b->walletSummary()];

        return match ($p->target_type) {
            'business' => $base + ['title' => $b->name, 'subtitle' => $b->description, 'image_url' => $b->cover_path ? asset('storage/' . $b->cover_path) : $b->profile_image_url],
            'coupon' => ($c = Coupon::query()->whereKey($p->target_id)->active()->valid()->first())
                ? $base + ['title' => $c->title, 'subtitle' => $b->name, 'image_url' => $c->banner_image_url, 'coupon' => ['id' => $c->id, 'title' => $c->title, 'description' => $c->description, 'discount_type' => $c->discount_type, 'discount_amount' => $c->discount_amount, 'discount_percentage' => $c->discount_percentage, 'expires_at' => $c->expires_at, 'banner_image_url' => $c->banner_image_url]]
                : null,
            'event' => ($e = Event::query()->whereKey($p->target_id)->published()->upcoming()->first())
                ? $base + ['title' => $e->title, 'subtitle' => $b->name, 'image_url' => $e->image_url, 'event_slug' => $e->slug, 'starts_at' => $e->starts_at, 'timezone' => $e->zone()]
                : null,
        };
    }

    private function targets(Business $b): array
    {
        return [
            ['type' => 'business', 'id' => $b->id, 'label' => "Your business page ({$b->name})"],
            ...Coupon::query()->where('business_id', $b->id)->active()->valid()->orderByDesc('created_at')->limit(50)->get()
                ->map(fn ($c) => ['type' => 'coupon', 'id' => $c->id, 'label' => "Deal: {$c->title}"])->all(),
            ...Event::query()->where('business_id', $b->id)->published()->upcoming()->orderBy('starts_at')->limit(50)->get()
                ->map(fn ($e) => ['type' => 'event', 'id' => $e->id, 'label' => "Event: {$e->title}"])->all(),
        ];
    }

    private function labelOf(FeaturedPlacement $p): string
    {
        return collect($this->targets($p->business))->first(fn ($t) => $t['type'] === $p->target_type && $t['id'] === $p->target_id)['label']
            ?? ucfirst($p->target_type);
    }

    private function owns(Business $b, string $type, int $id): bool
    {
        return match ($type) {
            'business' => $id === $b->id,
            'coupon' => Coupon::query()->whereKey($id)->where('business_id', $b->id)->exists(),
            'event' => Event::query()->whereKey($id)->where('business_id', $b->id)->exists(),
        };
    }

    /** @return array{0: float, 1: float}|null */
    private function centre(Business $b): ?array
    {
        $loc = $b->primaryLocation;
        $lat = $loc?->latitude ?? $b->owner?->latitude;
        $lng = $loc?->longitude ?? $b->owner?->longitude;

        return $lat !== null && $lng !== null ? [(float) $lat, (float) $lng] : null;
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get('business');
        abort_unless($business instanceof Business, 404, 'No business record found for this account.');

        return $business;
    }
}
