<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicBusinessResource;
use App\Http\Resources\PublicCouponResource;
use App\Http\Resources\PublicProductResource;
use App\Models\Business;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * Guest mode: browse businesses and deals without an account.
 *
 * Read-only by design. Anything tied to identity — claiming a deal, favouriting,
 * redeeming — stays behind auth. These endpoints exist so the app can show
 * something useful before signup, and so the SPA has a data source for the
 * same content the Blade pages render for crawlers.
 *
 * Every response goes through the public allowlist resources. That is the
 * whole reason those classes exist: this controller is the first place where
 * an anonymous caller receives business data as JSON, and the codebase's
 * hand-built arrays had already leaked account emails through /api/search.
 */
class GuestBrowseController extends Controller
{
    private const MAX_PER_PAGE = 50;

    public function businesses(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ]);

        $query = Business::active()->with('primaryLocation');

        if ($term = $validated['q'] ?? null) {
            $pattern = '%' . $this->escapeLike($term) . '%';

            // ESCAPE is required for the escaping in $pattern to mean anything:
            // Laravel's ->where(…, 'like', …) emits no ESCAPE clause, so
            // without this a term like "a_b" silently matches the wrong rows.
            //
            // '!' rather than a backslash, because backslash is not portable
            // here — MySQL treats it as an escape inside the string literal
            // (ESCAPE '\' is a syntax error, ESCAPE '\\' is needed), while
            // SQLite wants exactly one character and rejects '\\'. '!' needs
            // no escaping in either dialect.
            $query->where(function ($q) use ($pattern) {
                $q->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if ($city = $validated['city'] ?? null) {
            $query->whereHas('locations', fn ($q) => $q->where('city', $city));
        }

        $businesses = $query->orderBy('name')->paginate($validated['per_page'] ?? 20);

        return PublicBusinessResource::collection($businesses);
    }

    public function business(string $business)
    {
        $business = $this->resolveBusiness($business);
        $business->load('primaryLocation');

        return (new PublicBusinessResource($business))->additional([
            'page_blocks' => \App\Support\PageBlocks::resolve($business),
            'community' => $business->communityPayload(),
            'deals' => PublicCouponResource::collection(
                Coupon::where('business_id', $business->id)->active()->valid()
                    ->orderByDesc('is_featured')->limit(50)->get()
            ),
            'products' => PublicProductResource::collection(
                Product::where('business_id', $business->id)->where('is_active', true)
                    ->with(['category', 'tags'])->limit(50)->get()
            ),
        ]);
    }

    public function deals(Request $request)
    {
        $validated = $request->validate([
            'featured' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_PER_PAGE],
        ]);

        $query = Coupon::active()->valid()
            ->whereNotNull('business_id')
            // Suspended and soft-deleted businesses are hidden by every other
            // public surface; without this their deals stayed in the guest
            // feed, carrying the business name, phone and address with them.
            ->whereHas('business', fn ($q) => $q->where('status', 'active'))
            ->with(['business' => fn ($q) => $q->with('primaryLocation')]);

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $deals = $query->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return PublicCouponResource::collection($deals);
    }

    public function deal(string $business, string $couponSlug)
    {
        $business = $this->resolveBusiness($business);

        $coupon = Coupon::where('slug', $couponSlug)
            ->where('business_id', $business->id)
            ->active()
            ->firstOrFail();

        $coupon->setRelation('business', $business->load('primaryLocation'));

        return new PublicCouponResource($coupon);
    }

    /**
     * Accepts an id or a slug.
     *
     * The mobile app holds ids; a shared or crawled link carries a slug. Route
     * model binding could only honour one of them (Business::getRouteKeyName()
     * is 'slug', for the sake of the public page URLs), so resolution is
     * explicit here.
     *
     * Suspended and soft-deleted businesses 404 rather than 403 — a guest has
     * no business knowing the difference between "withdrawn" and "never existed".
     */
    private function resolveBusiness(string $identifier): Business
    {
        $business = Business::query()
            ->when(
                ctype_digit($identifier),
                fn ($q) => $q->where('id', (int) $identifier),
                fn ($q) => $q->where('slug', $identifier),
            )
            ->first();

        abort_if(!$business || $business->status !== 'active', 404);

        return $business;
    }

    /**
     * % and _ are wildcards in LIKE. Bound parameters stop injection but not a
     * caller passing '%' to match every row.
     *
     * Paired with the ESCAPE '!' clause at the call site — the escaping is
     * inert without one. '!' itself is escaped first, or a term containing it
     * would produce a dangling escape.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
