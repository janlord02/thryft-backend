<?php

namespace App\Exceptions;

use Exception;

/**
 * A coupon claim or redemption could not proceed because the world changed
 * underneath the request — the slot was taken, the claim already existed, the
 * snapshot had expired, or the offer was withdrawn.
 *
 * These are thrown from inside DB::transaction() specifically so the enclosing
 * transaction rolls back: that is how a failed counter increment undoes the
 * row it was paired with. The machine-readable $reason is what clients should
 * branch on; the message is for humans and may be reworded freely.
 */
class CouponConflictException extends Exception
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public static function alreadyClaimed(): self
    {
        return new self('already_claimed', 'You have already claimed this coupon.');
    }

    public static function claimLimitReached(): self
    {
        return new self('claim_limit_reached', 'This coupon is no longer available.');
    }

    public static function redemptionLimitReached(): self
    {
        return new self('redemption_limit_reached', 'This coupon has reached its redemption limit.');
    }

    /**
     * The claim's snapshotted expiry has passed. Distinct from offerWithdrawn()
     * so support can tell "the customer ran out of time" apart from "the
     * merchant pulled the offer".
     */
    public static function couponExpired(): self
    {
        return new self('coupon_expired', 'This coupon has expired.');
    }

    public static function offerWithdrawn(): self
    {
        return new self('offer_withdrawn', 'This offer is no longer available from the business.');
    }

    public static function notRedeemable(): self
    {
        return new self('not_redeemable', 'Coupon not found or already used.', 404);
    }
}
