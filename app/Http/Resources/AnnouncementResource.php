<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An announcement as a shopper or a crawler sees it. Allowlist, like the
 * other public resources.
 */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'image_url' => $a->image_url,
            'link_url' => $a->link_url,
            'published_at' => $a->published_at ?? $a->created_at,
            'business' => $this->whenLoaded('business', fn () => [
                'id' => $a->business->id,
                'name' => $a->business->name,
                'owner_user_id' => $a->business->owner_user_id,
            ]),
        ];
    }
}
