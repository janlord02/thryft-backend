<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An event as a shopper or a crawler sees it.
 *
 * ALLOWLIST, like the other public resources. The attendee list and the raw
 * registered_count stay with the host; a visitor gets `spots_left`, which is
 * what they need to decide, and nothing about who else is going.
 */
class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $event = $this->resource;
        $user = $request->user();

        return [
            'id' => $event->id,
            'slug' => $event->slug,
            'title' => $event->title,
            'description' => $event->description,
            'image_url' => $event->image_url,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            'venue_name' => $event->venue_name,
            'address' => $event->address,
            'city' => $event->city,
            'latitude' => $event->latitude,
            'longitude' => $event->longitude,
            'registration_enabled' => (bool) $event->registration_enabled,
            'spots_left' => $event->spotsLeft(),
            'is_full' => $event->isFull(),
            'is_over' => $event->isOver(),
            'status' => $event->status,
            'public_url' => $event->public_url,
            'distance' => $this->when(isset($event->distance), fn () => round((float) $event->distance, 1)),
            'business' => $this->whenLoaded('business', fn () => [
                'id' => $event->business->id,
                'slug' => $event->business->slug,
                'name' => $event->business->name,
                'logo_url' => $event->business->profile_image_url,
                // The consumer app addresses a business by its owner's id.
                'owner_user_id' => $event->business->owner_user_id,
            ]),
            'is_registered' => $user
                ? $event->registrations()->where('user_id', $user->id)->where('status', 'registered')->exists()
                : false,
        ];
    }
}
