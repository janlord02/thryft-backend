<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A coupon as seen by an anonymous visitor.
 *
 * ALLOWLIST. Note what is withheld and why:
 *
 *  - `code` is the redemption code. Publishing it would let anyone present a
 *    coupon they never claimed; it is only revealed to the customer who holds
 *    the claim.
 *  - claimed_count / redeemed_count / usage_limit are commercial data about
 *    how an offer is performing. A competitor should not be able to read a
 *    rival's take-up rate off a public page.
 *
 * `remaining` is exposed instead — scarcity is useful to a shopper and gives
 * away nothing about volume.
 */
class PublicCouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'slug' => $this->resource->slug,
            'title' => $this->resource->title,
            'description' => $this->resource->description,
            'discount_type' => $this->resource->discount_type,
            'discount_amount' => $this->resource->discount_amount,
            'discount_percentage' => $this->resource->discount_percentage,
            'formatted_discount' => $this->resource->formatted_discount,
            'minimum_amount' => $this->resource->minimum_amount,
            'banner_url' => $this->resource->banner_image
                ? asset('storage/' . $this->resource->banner_image)
                : null,
            'terms_conditions' => $this->resource->terms_conditions,
            'starts_at' => $this->resource->starts_at,
            'expires_at' => $this->resource->expires_at,
            'is_featured' => (bool) $this->resource->is_featured,
            'status' => $this->resource->status,
            'remaining' => $this->remaining(),
            'business' => $this->whenLoaded('business', fn () => new PublicBusinessResource($this->resource->business)),
        ];
    }

    /**
     * Claims still available, or null when the offer is uncapped. Null rather
     * than a large number so clients can distinguish "unlimited" from "loads
     * left" without inferring it from a threshold.
     */
    private function remaining(): ?int
    {
        if (!$this->resource->claim_limit) {
            return null;
        }

        return max(0, (int) $this->resource->claim_limit - (int) $this->resource->claimed_count);
    }
}
