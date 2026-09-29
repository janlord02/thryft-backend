<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ClaimedCoupon;
use App\Models\Coupon;
use App\Models\Product;

/**
 * The guided setup path: business details -> a product -> a published coupon
 * -> a first redemption.
 *
 * Entirely DERIVED from existing state. There is no onboarding table and no
 * per-step flags to keep in sync — a step is complete because the thing it
 * asks for exists. A stored checklist would immediately drift the first time
 * someone deleted their only product.
 */
class MerchantOnboarding
{
    public function __construct(private readonly Business $business)
    {
    }

    public static function for(Business $business): self
    {
        return new self($business);
    }

    public function toArray(): array
    {
        $steps = $this->steps();
        $completed = count(array_filter($steps, fn ($s) => $s['complete']));

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => count($steps),
            'complete' => $completed === count($steps),
            // The first outstanding step, so the UI can point at one thing
            // rather than making the merchant choose.
            'next' => collect($steps)->firstWhere('complete', false)['key'] ?? null,
        ];
    }

    /**
     * @return array<array{key:string,title:string,description:string,complete:bool}>
     */
    private function steps(): array
    {
        return [
            [
                'key' => 'business_details',
                'title' => 'Complete your business details',
                'description' => 'Add a description and an address so shoppers can find you.',
                'complete' => $this->hasBusinessDetails(),
            ],
            [
                'key' => 'add_product',
                'title' => 'Add your first product',
                'description' => 'List something you sell, so offers can point at it.',
                'complete' => Product::where('business_id', $this->business->id)->exists(),
            ],
            [
                'key' => 'publish_coupon',
                'title' => 'Publish your first offer',
                'description' => 'Create a coupon shoppers can claim.',
                'complete' => Coupon::where('business_id', $this->business->id)
                    ->where('is_active', true)
                    ->exists(),
            ],
            [
                'key' => 'test_redemption',
                'title' => 'Try a redemption',
                'description' => 'Scan a claimed coupon to see the full flow end to end.',
                // Deliberately keyed on a real redemption rather than a claim:
                // the point of the step is that the merchant has used the
                // scanner at least once.
                'complete' => ClaimedCoupon::byBusiness($this->business->id)
                    ->where('status', 'used')
                    ->exists(),
            ],
        ];
    }

    /**
     * "Details complete" means a shopper could actually find and recognise
     * this business: a description plus a location with an address.
     */
    private function hasBusinessDetails(): bool
    {
        if (blank($this->business->description)) {
            return false;
        }

        return $this->business->locations()
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->exists();
    }
}
