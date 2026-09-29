<?php

namespace App\Support;

use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Single place that answers "which business is this request acting as?" and
 * "how do I scope a query to it?".
 *
 * Every controller currently answers both questions inline with
 * where('user_id', Auth::id()). Routing those through here means the switch
 * from user_id to business_id happens in one file rather than across nine
 * controllers, and can be reversed by a config flag instead of a revert.
 *
 * When business_members lands in Phase 3b, resolution grows a membership
 * lookup and an X-Business-Id header; nothing outside this class changes.
 */
class BusinessResolver
{
    public static function usingBusinessEntity(): bool
    {
        return (bool) config('thryft.use_business_entity', false);
    }

    /**
     * The business a user is acting as.
     *
     * Membership is the primary path. Ownership is the fallback, which covers
     * businesses created before business_members existed and any account
     * promoted to 'business' after the backfill ran.
     */
    public static function forUser(?User $user): ?Business
    {
        if (!$user) {
            return null;
        }

        $viaMembership = $user->memberBusinesses()->orderBy('businesses.id')->first();

        if ($viaMembership) {
            return $viaMembership;
        }

        return Business::where('owner_user_id', $user->id)->first();
    }

    /**
     * The business this REQUEST is acting for.
     *
     * Resolution order:
     *   1. an explicit {business} route parameter
     *   2. an X-Business-Id header
     *   3. the user's sole business
     *
     * Rule 3 is what keeps the existing frontend working unchanged: it sends
     * neither a route parameter nor a header, and every current user has at
     * most one business. Rules 1 and 2 exist so a future multi-business UI has
     * somewhere to say which one it means.
     *
     * An explicit choice the user has no standing in resolves to null rather
     * than silently falling back to their own business — otherwise passing
     * someone else's id would quietly operate on your own records.
     */
    public static function forRequest(Request $request, ?User $user): ?Business
    {
        if (!$user) {
            return null;
        }

        $explicit = $request->route('business') ?? $request->header('X-Business-Id');

        if ($explicit) {
            $business = $explicit instanceof Business
                ? $explicit
                : Business::where('id', $explicit)->orWhere('slug', $explicit)->first();

            if (!$business) {
                return null;
            }

            $permitted = $user->membershipFor($business) !== null
                || (int) $business->owner_user_id === (int) $user->id;

            return $permitted ? $business : null;
        }

        return static::forUser($user);
    }

    /**
     * Guarantee this user has a business, creating one (with an owner
     * membership) if they do not.
     *
     * Call this wherever an account becomes a business. The Phase 3a backfill
     * only covered users who were businesses AT THAT MOMENT; nothing created a
     * row for anyone promoted afterwards. On MySQL that is not a soft gap —
     * claimed_coupons.business_id has a foreign key to businesses, so every
     * claim against such an account's coupons fails outright. SQLite keeps the
     * old FK to users, which is why the test suite could not see it.
     */
    public static function ensureFor(User $user): Business
    {
        $business = static::forUser($user);

        if (!$business) {
            $business = Business::create([
                'owner_user_id' => $user->id,
                'name' => $user->business_name ?: ($user->name ?: 'Business ' . $user->id),
                'description' => $user->business_description,
                'phone' => $user->phone,
                // NOT $user->email — that is the account login, and
                // businesses.email is published publicly.
                'email' => null,
                'status' => 'active',
            ]);

            // Carry across whatever address the user record holds, so the
            // business is not location-less on its public page.
            $business->locations()->create([
                'label' => 'Main',
                'address' => $user->address,
                'city' => $user->city,
                'state' => $user->state,
                'zipcode' => $user->zipcode,
                'country' => $user->country,
                'latitude' => $user->latitude,
                'longitude' => $user->longitude,
                'is_primary' => true,
            ]);
        }

        BusinessMember::firstOrCreate(
            ['business_id' => $business->id, 'user_id' => $user->id],
            ['role' => 'owner', 'status' => 'active', 'invited_at' => now(), 'accepted_at' => now()],
        );

        return $business;
    }

    /**
     * The value that should be written to a business_id column for this user.
     * Null when no business row exists yet, which keeps dual-write safe for
     * accounts created before their business is provisioned.
     */
    public static function businessIdFor(?User $user): ?int
    {
        return static::forUser($user)?->id;
    }

    /**
     * Scope a products/coupons query to the business a user is acting as.
     *
     * With the flag off this is byte-for-byte the previous behaviour. With it
     * on, ownership comes from business_id — and falls back to user_id for any
     * row the backfill did not reach (a business user created after the
     * migration but before their business row existed), so flipping the flag
     * cannot make existing records disappear from their owner's list.
     */
    /**
     * Every business this user may act for — via membership or ownership.
     *
     * Deliberately NOT memoised in a static. Keying a process-lifetime cache on
     * a user id is unsafe: ids repeat across tests, and a revoked membership
     * has to take effect on the very next request. Lists resolve ownership once
     * through scopeOwnedBy(), so this runs a couple of times per request at
     * most — cache it on the request object if that ever stops being true.
     *
     * @return array<int>
     */
    public static function actableBusinessIds(User $user): array
    {
        return $user->memberBusinesses()->pluck('businesses.id')
            ->merge(Business::where('owner_user_id', $user->id)->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public static function scopeOwnedBy(Builder $query, ?User $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        $businessIds = static::actableBusinessIds($user);

        // Note this is membership-aware in BOTH flag positions. The flag
        // governs which COLUMN resolves ownership, not whether staff exist —
        // and since no memberships exist until someone creates one, behaviour
        // on existing data is unchanged either way.
        if (!static::usingBusinessEntity()) {
            if (empty($businessIds)) {
                return $query->where('user_id', $user->id);
            }

            return $query->where(function (Builder $inner) use ($businessIds, $user) {
                $inner->where('user_id', $user->id)
                    ->orWhereIn('business_id', $businessIds);
            });
        }

        if (empty($businessIds)) {
            return $query->where('user_id', $user->id);
        }

        return $query->where(function (Builder $inner) use ($businessIds, $user) {
            $inner->whereIn('business_id', $businessIds)
                ->orWhere(function (Builder $legacy) use ($user) {
                    $legacy->whereNull('business_id')->where('user_id', $user->id);
                });
        });
    }

    /**
     * Attributes to merge into a create() for an owned record. Always writes
     * both columns — dual-write is independent of the read flag.
     */
    public static function ownershipAttributes(?User $user): array
    {
        return [
            'user_id' => $user?->id,
            'business_id' => static::businessIdFor($user),
        ];
    }

    /**
     * Does this user own this product/coupon?
     *
     * The single-record twin of scopeOwnedBy(), and it must agree with it: a
     * record the scope returns must also pass here, or a list would show rows
     * their own detail endpoint then 404s on.
     *
     * Falls back to user_id whenever business_id is absent on either side, so
     * records predating the backfill keep working with the flag on.
     */
    public static function owns(?User $user, $record): bool
    {
        if (!$user || !$record) {
            return false;
        }

        $businessIds = static::actableBusinessIds($user);
        $recordBusinessId = $record->business_id !== null ? (int) $record->business_id : null;
        $isCreator = (int) $record->user_id === (int) $user->id;

        // Mirrors scopeOwnedBy() clause for clause. If these drift, a list
        // shows rows whose detail endpoint then 404s.
        if (!static::usingBusinessEntity()) {
            return $isCreator
                || ($recordBusinessId !== null && in_array($recordBusinessId, $businessIds, true));
        }

        if ($recordBusinessId !== null) {
            return in_array($recordBusinessId, $businessIds, true);
        }

        return $isCreator;
    }
}
