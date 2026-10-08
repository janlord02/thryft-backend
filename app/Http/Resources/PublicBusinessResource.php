<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A business as seen by an anonymous visitor.
 *
 * ALLOWLIST, not a blocklist. Every field a guest can see is named here, so
 * adding a column to users or businesses cannot silently publish it.
 *
 * Deliberately absent: the owner's login email. GET /api/search is
 * unauthenticated and was returning users.email — the account login, not a
 * contact address — which hands out a credential-stuffing target and a spam
 * list. A business contact email is a separate, opt-in field on businesses.
 *
 * Accepts either a Business model or a legacy business User row, since the
 * extraction is mid-rollout and callers have one or the other.
 */
class PublicBusinessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource instanceof \App\Models\Business
            ? $this->fromBusiness()
            : $this->fromLegacyUser();
    }

    private function fromBusiness(): array
    {
        $location = $this->resource->relationLoaded('primaryLocation')
            ? $this->resource->primaryLocation
            : $this->resource->primaryLocation()->first();

        return [
            'id' => $this->resource->id,
            'slug' => $this->resource->slug,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'logo_url' => $this->resource->logo_path ? asset('storage/' . $this->resource->logo_path) : null,
            'cover_url' => $this->resource->cover_path ? asset('storage/' . $this->resource->cover_path) : null,
            // Contact fields on `businesses` are business-published, unlike the
            // owner's account email.
            'phone' => $this->resource->phone,
            'email' => $this->resource->email,
            'website' => $this->resource->website,
            'location' => $location ? [
                'address' => $location->address,
                'city' => $location->city,
                'state' => $location->state,
                'zipcode' => $location->zipcode,
                'country' => $location->country,
                'latitude' => $location->latitude,
                'longitude' => $location->longitude,
            ] : null,
            'locations' => $this->resource->publicLocations(),
        ];
    }

    private function fromLegacyUser(): array
    {
        return [
            'id' => $this->resource->id,
            'slug' => null,
            'name' => $this->resource->business_name ?: $this->resource->name,
            'description' => $this->resource->business_description ?: $this->resource->bio,
            'logo_url' => $this->resource->profile_image_url,
            'cover_url' => null,
            'phone' => $this->resource->phone,
            // NOT $this->resource->email — that is the account login.
            'email' => null,
            'website' => null,
            'location' => [
                'address' => $this->resource->address,
                'city' => $this->resource->city,
                'state' => $this->resource->state,
                'zipcode' => $this->resource->zipcode,
                'country' => $this->resource->country,
                'latitude' => $this->resource->latitude,
                'longitude' => $this->resource->longitude,
            ],
        ];
    }
}
