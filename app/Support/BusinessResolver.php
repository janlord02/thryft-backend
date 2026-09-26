<?php

namespace App\Support;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

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
     * Today a user owns at most one business, created by the backfill with
     * id === users.id. Phase 3b replaces this with a membership lookup.
     */
    public static function forUser(?User $user): ?Business
    {
        if (!$user) {
            return null;
        }

        return Business::where('owner_user_id', $user->id)->first();
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
    public static function scopeOwnedBy(Builder $query, ?User $user): Builder
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if (!static::usingBusinessEntity()) {
            return $query->where('user_id', $user->id);
        }

        $businessId = static::businessIdFor($user);

        if ($businessId === null) {
            return $query->where('user_id', $user->id);
        }

        return $query->where(function (Builder $inner) use ($businessId, $user) {
            $inner->where('business_id', $businessId)
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

        if (!static::usingBusinessEntity()) {
            return (int) $record->user_id === (int) $user->id;
        }

        $businessId = static::businessIdFor($user);

        if ($businessId !== null && $record->business_id !== null) {
            return (int) $record->business_id === $businessId;
        }

        return (int) $record->user_id === (int) $user->id;
    }
}
